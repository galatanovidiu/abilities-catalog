import { spawn } from 'node:child_process';
import { readFile, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { createServer, request as httpRequest } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PROTOCOLS = {
	legacy: '2025-11-25',
	modern: '2026-07-28',
};

const SEARCH_TOOLS = [
	'overview',
	'search-abilities',
	'describe-ability',
	'execute-ability',
	'knowledge',
];

const CURATED_DOMAINS = [
	'content',
	'media',
	'appearance',
	'design',
	'plugins',
	'users',
	'settings',
	'tools',
	'site-health',
	'updates',
	'dashboard',
	'network',
];

const DEFAULT_TOOLS = [
	'mcp-adapter-discover-abilities',
	'mcp-adapter-get-ability-info',
	'mcp-adapter-execute-ability',
];

const SHARED_RESOURCES = [
	'abilities-catalog://capabilities',
	'abilities-catalog://knowledge',
];

const WORKFLOW_PROMPT = 'find-wordpress-ability';
const HEADER_TOOL = 'header-echo';
const LEGACY_ONLY_TOOL = 'legacy-only-header-tool';

const CORE_READS = [
	{ ability: 'og-content/list-post-types', domain: 'content', input: {} },
	{ ability: 'og-media/list-image-sizes', domain: 'media', input: {} },
	{ ability: 'og-themes/get-active-theme', domain: 'appearance', input: {} },
	{ ability: 'og-templates/list-block-types', domain: 'design', input: {} },
	{ ability: 'og-plugins/list-plugins', domain: 'plugins', input: {} },
	{ ability: 'og-users/get-current-user', domain: 'users', input: {} },
	{ ability: 'og-settings/get-reading', domain: 'settings', input: {} },
	{ ability: 'og-cron/list-schedules', domain: 'tools', input: {} },
	{ ability: 'og-site-health/get-status', domain: 'site-health', input: {} },
	{
		ability: 'og-updates/list-available-updates',
		domain: 'updates',
		input: {},
	},
	{ ability: 'og-dashboard/get-at-a-glance', domain: 'dashboard', input: {} },
	{ ability: 'og-terms/list-taxonomies', domain: 'content', input: {} },
];

const baseUrl = ( process.env.MCP_BASE_URL || 'http://localhost:8890' ).replace(
	/\/$/,
	''
);

const endpoints = {
	search:
		process.env.MCP_SEARCH_ENDPOINT ||
		process.env.MCP_ENDPOINT ||
		`${ baseUrl }/?rest_route=/abilities-catalog/v1/mcp-search`,
	curated:
		process.env.MCP_CURATED_ENDPOINT ||
		`${ baseUrl }/?rest_route=/abilities-catalog/v1/mcp`,
	default:
		process.env.MCP_DEFAULT_ENDPOINT ||
		`${ baseUrl }/?rest_route=/mcp/mcp-adapter-default-server`,
};

function fail( message ) {
	throw new Error( message );
}

function assert( condition, message ) {
	if ( ! condition ) {
		fail( message );
	}
}

function assertSame( actual, expected, label ) {
	if ( JSON.stringify( actual ) !== JSON.stringify( expected ) ) {
		fail(
			`${ label }: got ${ JSON.stringify( actual ) }, expected ${ JSON.stringify(
				expected
			) }.`
		);
	}
}

function assertExactNames( label, values, expected ) {
	assertSame( [ ...values ].sort(), [ ...expected ].sort(), label );
}

function authorizationHeader() {
	if ( process.env.MCP_AUTH_HEADER ) {
		return process.env.MCP_AUTH_HEADER;
	}

	const username = process.env.WP_API_USERNAME;
	const password = process.env.WP_API_PASSWORD;
	if ( username && password ) {
		return `Basic ${ Buffer.from( `${ username }:${ password }` ).toString(
			'base64'
		) }`;
	}

	fail(
		'Set MCP_AUTH_HEADER, or set WP_API_USERNAME and WP_API_PASSWORD to a temporary WordPress application-password credential.'
	);
}

function inspectorPath() {
	const executable =
		process.platform === 'win32' ? 'mcp-inspector.cmd' : 'mcp-inspector';
	return join( process.cwd(), 'node_modules', '.bin', executable );
}

function serverName( endpointName, era ) {
	return `${ endpointName }-${ era }`;
}

function parseWirePayload( text ) {
	const trimmed = text.trim();
	if ( '' === trimmed ) {
		return null;
	}

	if ( trimmed.startsWith( '{' ) || trimmed.startsWith( '[' ) ) {
		return JSON.parse( trimmed );
	}

	const data = trimmed
		.split( /\r?\n/ )
		.filter( ( line ) => line.startsWith( 'data:' ) )
		.map( ( line ) => line.slice( 5 ).trim() )
		.filter( Boolean );
	return data.length > 0 ? JSON.parse( data.at( -1 ) ) : null;
}

function sanitizedHeaders( headers ) {
	const result = {};
	for ( const [ key, value ] of Object.entries( headers ) ) {
		if ( 'authorization' !== key.toLowerCase() ) {
			result[ key.toLowerCase() ] = value;
		}
	}
	return result;
}

async function startRecordingProxy() {
	let records = [];
	const server = createServer( async ( request, response ) => {
		const endpointName = new URL(
			request.url || '/',
			'http://127.0.0.1'
		).pathname.slice( 1 );
		const target = endpoints[ endpointName ];
		if ( ! target ) {
			response.writeHead( 404 );
			response.end();
			return;
		}

		const chunks = [];
		for await ( const chunk of request ) {
			chunks.push( chunk );
		}
		const body = Buffer.concat( chunks ).toString( 'utf8' );
		const forwardedHeaders = { ...request.headers };
		delete forwardedHeaders.host;
		delete forwardedHeaders[ 'content-length' ];

		try {
			const upstream = await fetch( target, {
				method: request.method,
				headers: forwardedHeaders,
				body: [ 'GET', 'HEAD' ].includes( request.method || '' )
					? undefined
					: body,
				redirect: 'manual',
			} );
			const responseBody = Buffer.from( await upstream.arrayBuffer() );
			const responseHeaders = Object.fromEntries( upstream.headers.entries() );
			const record = {
				endpoint: endpointName,
				method: request.method,
				requestHeaders: sanitizedHeaders( request.headers ),
				requestBody: '' === body ? null : JSON.parse( body ),
				responseStatus: upstream.status,
				responseHeaders: sanitizedHeaders( responseHeaders ),
				responseBody: responseBody.toString( 'utf8' ),
				responsePayload: parseWirePayload( responseBody.toString( 'utf8' ) ),
			};
			records.push( record );

			for ( const [ key, value ] of upstream.headers.entries() ) {
				if (
					![
						'connection',
						'content-encoding',
						'content-length',
						'transfer-encoding',
					].includes( key.toLowerCase() )
				) {
					response.setHeader( key, value );
				}
			}
			response.writeHead( upstream.status );
			response.end( responseBody );
		} catch ( error ) {
			response.writeHead( 502, { 'Content-Type': 'application/json' } );
			response.end(
				JSON.stringify( {
					error: error instanceof Error ? error.message : String( error ),
				} )
			);
		}
	} );

	await new Promise( ( resolve, reject ) => {
		server.once( 'error', reject );
		server.listen( 0, '127.0.0.1', resolve );
	} );
	const address = server.address();
	assert( address && 'object' === typeof address, 'Recording proxy did not bind.' );

	return {
		baseUrl: `http://127.0.0.1:${ address.port }`,
		clear() {
			records = [];
		},
		take() {
			return [ ...records ];
		},
		async close() {
			await new Promise( ( resolve ) => server.close( resolve ) );
		},
	};
}

function runInspector( configPath, selectedServer, args ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn(
			inspectorPath(),
			[
				'--cli',
				'--config',
				configPath,
				'--server',
				selectedServer,
				...args,
				'--format',
				'json',
			],
			{
				cwd: process.cwd(),
				env: process.env,
				stdio: [ 'ignore', 'pipe', 'pipe' ],
			}
		);

		let stdout = '';
		let stderr = '';
		child.stdout.setEncoding( 'utf8' );
		child.stderr.setEncoding( 'utf8' );
		child.stdout.on( 'data', ( chunk ) => {
			stdout += chunk;
		} );
		child.stderr.on( 'data', ( chunk ) => {
			stderr += chunk;
		} );
		child.on( 'error', reject );
		child.on( 'close', ( code ) => {
			if ( 0 !== code ) {
				reject(
					new Error(
						`Inspector ${ selectedServer } exited with ${ code }: ${ stderr.trim() }`
					)
				);
				return;
			}

			try {
				const line = stdout
					.trim()
					.split( /\r?\n/ )
					.filter( Boolean )
					.at( -1 );
				resolve( JSON.parse( line ).result );
			} catch ( error ) {
				reject(
					new Error(
						`Inspector ${ selectedServer } did not return JSON: ${
							error instanceof Error ? error.message : String( error )
						}`
					)
				);
			}
		} );
	} );
}

async function expectInspectorError( configPath, selectedServer, args ) {
	try {
		await runInspector( configPath, selectedServer, args );
	} catch {
		return;
	}
	fail( `Inspector ${ selectedServer } unexpectedly accepted ${ args.join( ' ' ) }.` );
}

function callTool( configPath, selectedServer, toolName, args = {} ) {
	return runInspector( configPath, selectedServer, [
		'--method',
		'tools/call',
		'--tool-name',
		toolName,
		'--tool-args-json',
		JSON.stringify( args ),
	] );
}

function assertToolSuccess( selectedServer, toolName, result ) {
	assert(
		true !== result?.isError,
		`${ selectedServer } tool ${ toolName } returned isError=true.`
	);
	assert(
		Array.isArray( result?.content ) && result.content.length > 0,
		`${ selectedServer } tool ${ toolName } returned no content.`
	);
}

function structuredContent( selectedServer, toolName, result ) {
	assertToolSuccess( selectedServer, toolName, result );
	if (
		result.structuredContent &&
		'object' === typeof result.structuredContent &&
		! Array.isArray( result.structuredContent )
	) {
		return result.structuredContent;
	}

	const text = result.content.find( ( item ) => 'text' === item?.type )?.text;
	if ( 'string' === typeof text ) {
		try {
			return JSON.parse( text );
		} catch {
			// The assertion below names the contract failure.
		}
	}

	fail( `${ selectedServer } tool ${ toolName } returned no structured object.` );
}

async function mapLimit( items, limit, callback ) {
	const results = new Array( items.length );
	let next = 0;

	async function worker() {
		while ( next < items.length ) {
			const index = next;
			next += 1;
			results[ index ] = await callback( items[ index ], index );
		}
	}

	await Promise.all(
		Array.from( { length: Math.min( limit, items.length ) }, () => worker() )
	);
	return results;
}

function rpcRecord( records, method ) {
	const record = records.find( ( candidate ) => candidate.requestBody?.method === method );
	assert( record, `Recording proxy captured no ${ method } request.` );
	return record;
}

function assertModernRequest( record, method, name = null ) {
	assertSame(
		record.requestHeaders[ 'mcp-protocol-version' ],
		PROTOCOLS.modern,
		`${ method } MCP-Protocol-Version`
	);
	assertSame(
		record.requestHeaders[ 'mcp-method' ],
		method,
		`${ method } Mcp-Method`
	);
	assert(
		! record.requestHeaders[ 'mcp-session-id' ],
		`${ method } unexpectedly sent Mcp-Session-Id in modern mode.`
	);
	if ( null !== name ) {
		assertSame(
			record.requestHeaders[ 'mcp-name' ],
			name,
			`${ method } Mcp-Name`
		);
	}
	const meta = record.requestBody?.params?._meta;
	assertSame(
		meta?.[ 'io.modelcontextprotocol/protocolVersion' ],
		PROTOCOLS.modern,
		`${ method } body protocol revision`
	);
	assert(
		meta?.[ 'io.modelcontextprotocol/clientCapabilities' ] &&
			'object' === typeof meta[ 'io.modelcontextprotocol/clientCapabilities' ],
		`${ method } omitted object client capabilities.`
	);
}

function assertModernResult( record, method, cacheFields ) {
	const result = record.responsePayload?.result;
	assert( result && 'object' === typeof result, `${ method } returned no result.` );
	assertSame( result.resultType, 'complete', `${ method } resultType` );
	if ( cacheFields ) {
		assertSame( result.ttlMs, 0, `${ method } ttlMs` );
		assertSame( result.cacheScope, 'private', `${ method } cacheScope` );
	} else {
		assert( !( 'ttlMs' in result ), `${ method } unexpectedly returned ttlMs.` );
		assert(
			!( 'cacheScope' in result ),
			`${ method } unexpectedly returned cacheScope.`
		);
	}
}

async function runCaptured( proxy, configPath, selectedServer, args ) {
	proxy.clear();
	const result = await runInspector( configPath, selectedServer, args );
	return { result, records: proxy.take() };
}

async function assertNegotiation( proxy, configPath, endpointName, era ) {
	const selectedServer = serverName( endpointName, era );
	const { result, records } = await runCaptured( proxy, configPath, selectedServer, [
		'--method',
		'initialize',
	] );
	assertSame(
		result?.protocolVersion,
		PROTOCOLS[ era ],
		`${ selectedServer } negotiated revision`
	);
	for ( const capability of [ 'tools', 'resources', 'prompts' ] ) {
		assert(
			result.capabilities && 'object' === typeof result.capabilities[ capability ],
			`${ selectedServer } omitted ${ capability } capability.`
		);
	}

	if ( 'legacy' === era ) {
		const initialized = rpcRecord( records, 'initialize' );
		const notification = rpcRecord( records, 'notifications/initialized' );
		const session = initialized.responseHeaders[ 'mcp-session-id' ];
		assert( session, `${ selectedServer } initialize returned no session id.` );
		assertSame(
			notification.requestHeaders[ 'mcp-session-id' ],
			session,
			`${ selectedServer } initialized session`
		);
		assertSame(
			notification.requestHeaders[ 'mcp-protocol-version' ],
			PROTOCOLS.legacy,
			`${ selectedServer } initialized protocol header`
		);
		assert(
			! records.some( ( record ) => 'server/discover' === record.requestBody?.method ),
			`${ selectedServer } sent server/discover in legacy mode.`
		);
	} else {
		assertSame(
			records.map( ( record ) => record.requestBody?.method ),
			[ 'server/discover' ],
			`${ selectedServer } connect-time request order`
		);
		const discover = rpcRecord( records, 'server/discover' );
		assertModernRequest( discover, 'server/discover' );
		assertModernResult( discover, 'server/discover', true );
		assert(
			! discover.responseHeaders[ 'mcp-session-id' ],
			`${ selectedServer } discover returned a session id.`
		);
	}
}

function curatedTools( era ) {
	return [
		...CURATED_DOMAINS,
		'knowledge',
		HEADER_TOOL,
		...( 'legacy' === era ? [ LEGACY_ONLY_TOOL ] : [] ),
	];
}

async function assertTools( proxy, configPath, endpointName, era, expected ) {
	const selectedServer = serverName( endpointName, era );
	const { result, records } = await runCaptured( proxy, configPath, selectedServer, [
		'--method',
		'tools/list',
	] );
	assert( Array.isArray( result?.tools ), `${ selectedServer } returned no tools.` );
	assertExactNames(
		`${ selectedServer } tools/list`,
		result.tools.map( ( tool ) => tool.name ),
		expected
	);

	const listRecord = rpcRecord( records, 'tools/list' );
	if ( 'modern' === era ) {
		assertModernRequest( listRecord, 'tools/list' );
		assertModernResult( listRecord, 'tools/list', true );
	} else {
		assert(
			Boolean( listRecord.requestHeaders[ 'mcp-session-id' ] ),
			`${ selectedServer } tools/list was not session-backed.`
		);
	}

	if ( 'curated' === endpointName ) {
		const rawTools = listRecord.responsePayload?.result?.tools || [];
		const headerDescriptor = rawTools.find( ( tool ) => tool.name === HEADER_TOOL );
		assert( headerDescriptor, `${ selectedServer } omitted ${ HEADER_TOOL }.` );
		if ( 'legacy' === era ) {
			assertSame(
				headerDescriptor.execution,
				{ taskSupport: 'forbidden' },
				`${ selectedServer } Tool.execution`
			);
			assert(
				rawTools.some( ( tool ) => tool.name === LEGACY_ONLY_TOOL ),
				`${ selectedServer } lost the revision-valid legacy-only component.`
			);
		} else {
			assert(
				!( 'execution' in headerDescriptor ),
				`${ selectedServer } retained removed Tool.execution.`
			);
			assert(
				! rawTools.some( ( tool ) => tool.name === LEGACY_ONLY_TOOL ),
				`${ selectedServer } exposed the invalid modern projection.`
			);
		}
	}
}

async function assertSharedResourcesAndPrompt(
	proxy,
	configPath,
	selectedServer,
	era
) {
	let captured = await runCaptured( proxy, configPath, selectedServer, [
		'--method',
		'resources/list',
	] );
	assertExactNames(
		`${ selectedServer } resources/list`,
		( captured.result.resources || [] ).map( ( resource ) => resource.uri ),
		SHARED_RESOURCES
	);
	if ( 'modern' === era ) {
		const record = rpcRecord( captured.records, 'resources/list' );
		assertModernRequest( record, 'resources/list' );
		assertModernResult( record, 'resources/list', true );
	}

	captured = await runCaptured( proxy, configPath, selectedServer, [
		'--method',
		'resources/templates/list',
	] );
	assertExactNames(
		`${ selectedServer } resources/templates/list`,
		( captured.result.resourceTemplates || [] ).map(
			( template ) => template.uriTemplate
		),
		[]
	);
	if ( 'modern' === era ) {
		const record = rpcRecord( captured.records, 'resources/templates/list' );
		assertModernRequest( record, 'resources/templates/list' );
		assertModernResult( record, 'resources/templates/list', true );
	}

	for ( const uri of SHARED_RESOURCES ) {
		captured = await runCaptured( proxy, configPath, selectedServer, [
			'--method',
			'resources/read',
			'--uri',
			uri,
		] );
		assertSame(
			captured.result?.contents?.[ 0 ]?.uri,
			uri,
			`${ selectedServer } resources/read uri`
		);
		if ( 'modern' === era ) {
			const record = rpcRecord( captured.records, 'resources/read' );
			assertModernRequest( record, 'resources/read', uri );
			assertModernResult( record, 'resources/read', true );
		}
	}

	captured = await runCaptured( proxy, configPath, selectedServer, [
		'--method',
		'prompts/list',
	] );
	assertExactNames(
		`${ selectedServer } prompts/list`,
		( captured.result.prompts || [] ).map( ( prompt ) => prompt.name ),
		[ WORKFLOW_PROMPT ]
	);
	if ( 'modern' === era ) {
		const record = rpcRecord( captured.records, 'prompts/list' );
		assertModernRequest( record, 'prompts/list' );
		assertModernResult( record, 'prompts/list', true );
	}

	captured = await runCaptured( proxy, configPath, selectedServer, [
		'--method',
		'prompts/get',
		'--prompt-name',
		WORKFLOW_PROMPT,
		'--prompt-args',
		'task=Inspect installed plugins',
	] );
	assert(
		captured.result?.messages?.[ 0 ]?.content?.text?.includes(
			'Inspect installed plugins'
		),
		`${ selectedServer } prompt omitted its task.`
	);
	if ( 'modern' === era ) {
		const record = rpcRecord( captured.records, 'prompts/get' );
		assertModernRequest( record, 'prompts/get', WORKFLOW_PROMPT );
		assertModernResult( record, 'prompts/get', false );
	}
}

async function assertDefaultEmptyContent( proxy, configPath, selectedServer, era ) {
	for ( const [ method, key ] of [
		[ 'resources/list', 'resources' ],
		[ 'resources/templates/list', 'resourceTemplates' ],
		[ 'prompts/list', 'prompts' ],
	] ) {
		const captured = await runCaptured( proxy, configPath, selectedServer, [
			'--method',
			method,
		] );
		assertExactNames( `${ selectedServer } ${ method }`, captured.result[ key ] || [], [] );
		if ( 'modern' === era ) {
			const record = rpcRecord( captured.records, method );
			assertModernRequest( record, method );
			assertModernResult( record, method, true );
		}
	}

	await expectInspectorError( configPath, selectedServer, [
		'--method',
		'resources/read',
		'--uri',
		'abilities-catalog://missing',
	] );
	await expectInspectorError( configPath, selectedServer, [
		'--method',
		'prompts/get',
		'--prompt-name',
		'missing',
	] );
}

async function testSearchServer( proxy, configPath, era ) {
	const selectedServer = serverName( 'search', era );
	await assertNegotiation( proxy, configPath, 'search', era );
	await assertTools( proxy, configPath, 'search', era, SEARCH_TOOLS );

	let result = structuredContent(
		selectedServer,
		'overview',
		await callTool( configPath, selectedServer, 'overview' )
	);
	assert( Array.isArray( result.categories ), `${ selectedServer } overview failed.` );

	result = structuredContent(
		selectedServer,
		'search-abilities',
		await callTool( configPath, selectedServer, 'search-abilities', {
			query: 'list installed plugins',
			limit: 10,
		} )
	);
	assert(
		result.abilities?.some(
			( ability ) => 'og-plugins/list-plugins' === ability.name
		),
		`${ selectedServer } search missed the plugin ability.`
	);

	await mapLimit( CORE_READS, 4, async ( scenario ) => {
		const described = structuredContent(
			selectedServer,
			'describe-ability',
			await callTool( configPath, selectedServer, 'describe-ability', {
				name: scenario.ability,
			} )
		);
		assertSame( described.name, scenario.ability, 'described ability' );
		assertSame( described.annotations?.readonly, true, 'readonly annotation' );
		assertSame( described.enabled, true, 'exposure state' );
	} );

	await mapLimit( CORE_READS, 4, async ( scenario ) => {
		structuredContent(
			selectedServer,
			'execute-ability',
			await callTool( configPath, selectedServer, 'execute-ability', {
				name: scenario.ability,
				input: scenario.input,
			} )
		);
	} );

	const general = structuredContent(
		selectedServer,
		'execute-ability',
		await callTool( configPath, selectedServer, 'execute-ability', {
			name: 'og-settings/get-general',
			input: {},
		} )
	);
	for ( const field of [
		'title',
		'description',
		'url',
		'wpurl',
		'admin_email',
		'timezone',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'language',
	] ) {
		assert( field in general, `${ selectedServer } general settings lost ${ field }.` );
	}

	await assertSharedResourcesAndPrompt( proxy, configPath, selectedServer, era );
	process.stdout.write(
		`${ selectedServer }: tools, ${ CORE_READS.length } core reads, settings contract, resources, and prompt passed\n`
	);
}

async function testCuratedServer( proxy, configPath, era ) {
	const selectedServer = serverName( 'curated', era );
	await assertNegotiation( proxy, configPath, 'curated', era );
	await assertTools( proxy, configPath, 'curated', era, curatedTools( era ) );

	await mapLimit( CURATED_DOMAINS, 4, async ( domain ) => {
		const listed = structuredContent(
			selectedServer,
			domain,
			await callTool( configPath, selectedServer, domain, { action: 'list' } )
		);
		assert(
			Array.isArray( listed.abilities ) && listed.abilities.length > 0,
			`${ selectedServer } ${ domain } listed no abilities.`
		);
	} );

	await mapLimit( CORE_READS, 4, async ( scenario ) => {
		structuredContent(
			selectedServer,
			scenario.domain,
			await callTool( configPath, selectedServer, scenario.domain, {
				action: 'execute',
				ability: scenario.ability,
				input: scenario.input,
			} )
		);
	} );

	await assertSharedResourcesAndPrompt( proxy, configPath, selectedServer, era );
	process.stdout.write(
		`${ selectedServer }: tools, ${ CURATED_DOMAINS.length } domain lists, ${ CORE_READS.length } core reads, resources, and prompt passed\n`
	);
}

async function testDefaultServer( proxy, configPath, era ) {
	const selectedServer = serverName( 'default', era );
	await assertNegotiation( proxy, configPath, 'default', era );
	await assertTools( proxy, configPath, 'default', era, DEFAULT_TOOLS );

	const discovered = structuredContent(
		selectedServer,
		DEFAULT_TOOLS[ 0 ],
		await callTool( configPath, selectedServer, DEFAULT_TOOLS[ 0 ] )
	);
	const discoveredNames = ( discovered.abilities || [] ).map(
		( ability ) => ability.name
	);
	for ( const scenario of CORE_READS ) {
		assert(
			discoveredNames.includes( scenario.ability ),
			`${ selectedServer } discovery omitted ${ scenario.ability }.`
		);
	}

	await mapLimit( CORE_READS, 3, async ( scenario ) => {
		const info = structuredContent(
			selectedServer,
			DEFAULT_TOOLS[ 1 ],
			await callTool( configPath, selectedServer, DEFAULT_TOOLS[ 1 ], {
				ability_name: scenario.ability,
			} )
		);
		assertSame( info.name, scenario.ability, 'ability-info name' );
		if ( Object.keys( scenario.input ).length === 0 ) {
			assert(
				info.input_schema &&
					'object' === typeof info.input_schema &&
					! Array.isArray( info.input_schema ),
				`${ selectedServer } serialized no-input input_schema as an array.`
			);
		}
	} );

	await mapLimit( CORE_READS, 4, async ( scenario ) => {
		const executed = structuredContent(
			selectedServer,
			DEFAULT_TOOLS[ 2 ],
			await callTool( configPath, selectedServer, DEFAULT_TOOLS[ 2 ], {
				ability_name: scenario.ability,
				parameters: scenario.input,
			} )
		);
		assertSame( executed.success, true, `${ scenario.ability } execution` );
	} );

	await assertDefaultEmptyContent( proxy, configPath, selectedServer, era );
	process.stdout.write(
		`${ selectedServer }: tools, ${ CORE_READS.length } ability-info calls and core reads, empty native content passed\n`
	);
}

async function assertInspectorHeaderMirroring( proxy, configPath ) {
	const selectedServer = serverName( 'curated', 'modern' );
	const { result, records } = await runCaptured( proxy, configPath, selectedServer, [
		'--method',
		'tools/call',
		'--tool-name',
		HEADER_TOOL,
		'--tool-args-json',
		JSON.stringify( { value: 'ocean' } ),
	] );
	const body = structuredContent( selectedServer, HEADER_TOOL, result );
	assertSame( body.value, 'ocean', `${ selectedServer } header tool result` );
	const record = rpcRecord( records, 'tools/call' );
	assertModernRequest( record, 'tools/call', HEADER_TOOL );
	assertSame(
		record.requestHeaders[ 'mcp-param-echo-value' ],
		'ocean',
		'Inspector 2.4.0 Mcp-Param-Echo-Value mirror'
	);
	assertModernResult( record, 'tools/call', false );
}

function rawHttp( url, { method = 'POST', headers = {}, body = null } = {} ) {
	return new Promise( ( resolve, reject ) => {
		const parsed = new URL( url );
		const request = httpRequest(
			{
				hostname: parsed.hostname,
				port: parsed.port,
				path: `${ parsed.pathname }${ parsed.search }`,
				method,
				headers,
			},
			( response ) => {
				const chunks = [];
				response.on( 'data', ( chunk ) => chunks.push( chunk ) );
				response.on( 'end', () => {
					const text = Buffer.concat( chunks ).toString( 'utf8' );
					resolve( {
						status: response.statusCode,
						headers: sanitizedHeaders( response.headers ),
						text,
						payload: parseWirePayload( text ),
					} );
				} );
			}
		);
		request.on( 'error', reject );
		if ( null !== body ) {
			request.write( body );
		}
		request.end();
	} );
}

function jsonHeaders( authorization, extra = {} ) {
	return {
		Authorization: authorization,
		Accept: 'application/json, text/event-stream',
		'Content-Type': 'application/json',
		...extra,
	};
}

function modernMeta( revision = PROTOCOLS.modern ) {
	return {
		'io.modelcontextprotocol/protocolVersion': revision,
		'io.modelcontextprotocol/clientCapabilities': {},
		'io.modelcontextprotocol/clientInfo': {
			name: 'abilities-catalog-e2e',
			version: '1.0.0',
		},
	};
}

function modernBody( id, method, params = {}, revision = PROTOCOLS.modern ) {
	return {
		jsonrpc: '2.0',
		id,
		method,
		params: { ...params, _meta: modernMeta( revision ) },
	};
}

function assertRpcError( response, code, label, status = 400 ) {
	assertSame( response.status, status, `${ label } HTTP status` );
	assertSame( response.payload?.error?.code, code, `${ label } error code` );
}

async function rawPost( endpoint, authorization, body, extraHeaders = {} ) {
	return rawHttp( endpoint, {
		headers: jsonHeaders( authorization, extraHeaders ),
		body: JSON.stringify( body ),
	} );
}

async function assertRawHttpMatrix( authorization ) {
	const endpoint = endpoints.curated;
	const initialize = await rawPost( endpoint, authorization, {
		jsonrpc: '2.0',
		id: 500,
		method: 'initialize',
		params: {
			protocolVersion: PROTOCOLS.legacy,
			capabilities: {},
			clientInfo: { name: 'raw-e2e', version: '1.0.0' },
		},
	} );
	assertSame( initialize.status, 200, 'legacy initialize HTTP status' );
	assertSame(
		initialize.payload?.result?.protocolVersion,
		PROTOCOLS.legacy,
		'legacy initialize revision'
	);
	const session = initialize.headers[ 'mcp-session-id' ];
	assert( session, 'Legacy initialize returned no Mcp-Session-Id.' );
	const legacyHeaders = {
		'MCP-Protocol-Version': PROTOCOLS.legacy,
		'Mcp-Session-Id': session,
	};

	let response = await rawPost(
		endpoint,
		authorization,
		{ jsonrpc: '2.0', method: 'notifications/initialized' },
		legacyHeaders
	);
	assertSame( response.status, 202, 'legacy initialized notification status' );
	assertSame( response.text, '', 'legacy initialized notification body' );

	response = await rawPost(
		endpoint,
		authorization,
		{ jsonrpc: '2.0', id: 501, method: 'ping' },
		legacyHeaders
	);
	assertSame( response.status, 200, 'legacy ping status' );
	assertSame( response.payload?.result, {}, 'legacy ping result' );

	response = await rawPost(
		endpoint,
		authorization,
		{ jsonrpc: '2.0', id: 502, method: 'server/discover', params: {} },
		legacyHeaders
	);
	assertRpcError( response, -32601, 'legacy server/discover', 404 );

	response = await rawPost(
		endpoint,
		authorization,
		{ jsonrpc: '2.0', id: 503, method: 'ping' },
		{ 'MCP-Protocol-Version': PROTOCOLS.legacy }
	);
	assertRpcError( response, -32600, 'missing legacy session' );

	const unsupported = '2099-01-01';
	response = await rawPost(
		endpoint,
		authorization,
		modernBody( 510, 'server/discover', {}, unsupported ),
		{
			'MCP-Protocol-Version': unsupported,
			'Mcp-Method': 'server/discover',
		}
	);
	assertRpcError( response, -32022, 'unsupported modern revision' );
	assertSame(
		response.payload.error.data,
		{
			requested: unsupported,
			// The adapter advertises its supported revisions newest-first.
			supported: [ PROTOCOLS.modern, PROTOCOLS.legacy ],
		},
		'unsupported revision data'
	);

	response = await rawPost(
		endpoint,
		authorization,
		modernBody( 511, 'tools/list' ),
		{ 'Mcp-Method': 'tools/list' }
	);
	assertRpcError( response, -32020, 'missing MCP-Protocol-Version' );

	response = await rawPost(
		endpoint,
		authorization,
		{ jsonrpc: '2.0', id: 512, method: 'tools/list', params: {} },
		{
			'MCP-Protocol-Version': PROTOCOLS.modern,
			'Mcp-Method': 'tools/list',
		}
	);
	assertRpcError( response, -32602, 'missing modern body metadata' );

	response = await rawPost(
		endpoint,
		authorization,
		modernBody( 513, 'tools/list' ),
		{ 'MCP-Protocol-Version': PROTOCOLS.modern }
	);
	assertRpcError( response, -32020, 'missing Mcp-Method' );

	response = await rawPost(
		endpoint,
		authorization,
		modernBody( 514, 'tools/list' ),
		{
			'MCP-Protocol-Version': PROTOCOLS.modern,
			'Mcp-Method': 'prompts/list',
		}
	);
	assertRpcError( response, -32020, 'mismatched Mcp-Method' );

	response = await rawPost(
		endpoint,
		authorization,
		modernBody( 515, 'tools/list' ),
		{
			'MCP-Protocol-Version': PROTOCOLS.legacy,
			'Mcp-Method': 'tools/list',
		}
	);
	assertRpcError( response, -32020, 'mismatched body and protocol header' );

	const callBody = modernBody( 516, 'tools/call', {
		name: HEADER_TOOL,
		arguments: { value: 'ocean' },
	} );
	for ( const [ label, headers ] of [
		[
			'missing Mcp-Name',
			{
				'MCP-Protocol-Version': PROTOCOLS.modern,
				'Mcp-Method': 'tools/call',
				'Mcp-Param-Echo-Value': 'ocean',
			},
		],
		[
			'mismatched Mcp-Name',
			{
				'MCP-Protocol-Version': PROTOCOLS.modern,
				'Mcp-Method': 'tools/call',
				'Mcp-Name': 'wrong-tool',
				'Mcp-Param-Echo-Value': 'ocean',
			},
		],
		[
			'missing Mcp-Param',
			{
				'MCP-Protocol-Version': PROTOCOLS.modern,
				'Mcp-Method': 'tools/call',
				'Mcp-Name': HEADER_TOOL,
			},
		],
		[
			'mismatched Mcp-Param',
			{
				'MCP-Protocol-Version': PROTOCOLS.modern,
				'Mcp-Method': 'tools/call',
				'Mcp-Name': HEADER_TOOL,
				'Mcp-Param-Echo-Value': 'wrong',
			},
		],
		[
			'unsafe Mcp-Param',
			{
				'MCP-Protocol-Version': PROTOCOLS.modern,
				'Mcp-Method': 'tools/call',
				'Mcp-Name': HEADER_TOOL,
				'Mcp-Param-Echo-Value': 'München',
			},
		],
	] ) {
		response = await rawPost( endpoint, authorization, callBody, headers );
		assertRpcError( response, -32020, label );
	}

	for ( const method of [
		'initialize',
		'notifications/initialized',
		'ping',
		'tools/list/all',
	] ) {
		response = await rawPost(
			endpoint,
			authorization,
			modernBody( 520, method ),
			{
				'MCP-Protocol-Version': PROTOCOLS.modern,
				'Mcp-Method': method,
			}
		);
		assertRpcError( response, -32601, `modern ${ method }`, 404 );
	}

	const missingUri = 'abilities-catalog://does-not-exist';
	response = await rawPost(
		endpoint,
		authorization,
		modernBody( 530, 'resources/read', { uri: missingUri } ),
		{
			'MCP-Protocol-Version': PROTOCOLS.modern,
			'Mcp-Method': 'resources/read',
			'Mcp-Name': missingUri,
		}
	);
	assertRpcError( response, -32602, 'modern resource not found' );
	assertSame( response.payload.error.data?.uri, missingUri, 'missing resource uri' );

	response = await rawPost(
		endpoint,
		authorization,
		[
			modernBody( 540, 'tools/list' ),
			modernBody( 541, 'prompts/list' ),
		],
		{
			'MCP-Protocol-Version': PROTOCOLS.modern,
			'Mcp-Method': 'tools/list',
		}
	);
	assertRpcError( response, -32600, 'batch rejection' );

	response = await rawPost(
		endpoint,
		authorization,
		modernBody( 550, 'tools/list' ),
		{
			Origin: 'https://invalid.example',
			'MCP-Protocol-Version': PROTOCOLS.modern,
			'Mcp-Method': 'tools/list',
		}
	);
	assertRpcError( response, -32008, 'invalid Origin', 403 );

	for ( const method of [ 'GET', 'DELETE' ] ) {
		response = await rawHttp( endpoint, {
			method,
			headers: {
				Authorization: authorization,
				'MCP-Protocol-Version': PROTOCOLS.modern,
				'Mcp-Method': 'tools/list',
			},
		} );
		assertSame( response.status, 405, `modern ${ method } policy` );
	}

	process.stdout.write( 'raw HTTP: revision, header, lifecycle, method, origin, and error matrix passed\n' );
}

function buildConfig( authorization, proxyBaseUrl ) {
	const mcpServers = {};
	for ( const endpointName of Object.keys( endpoints ) ) {
		for ( const era of Object.keys( PROTOCOLS ) ) {
			mcpServers[ serverName( endpointName, era ) ] = {
				type: 'streamable-http',
				url: `${ proxyBaseUrl }/${ endpointName }`,
				headers: { Authorization: authorization },
				protocolEra: era,
				...( 'modern' === era ? { modernLogLevel: 'off' } : {} ),
			};
		}
	}
	return { mcpServers };
}

function waitForStdioResponse( child, pending, id, body ) {
	return new Promise( ( resolve, reject ) => {
		const timeout = setTimeout( () => {
			pending.delete( String( id ) );
			reject( new Error( `STDIO timed out waiting for response ${ id }.` ) );
		}, 20_000 );
		pending.set( String( id ), ( payload ) => {
			clearTimeout( timeout );
			resolve( payload );
		} );
		child.stdin.write( `${ JSON.stringify( body ) }\n` );
	} );
}

async function assertMixedRevisionStdio() {
	const executable = join( process.cwd(), 'node_modules', '.bin', 'wp-env' );
	const child = spawn(
		executable,
		[
			'--config=.wp-env.test.json',
			'run',
			'cli',
			'--env-cwd=wp-content/plugins/abilities-catalog/',
			'wp',
			'mcp-adapter',
			'serve',
			'--user=admin',
			'--server=abilities-catalog-search',
		],
		{ cwd: process.cwd(), stdio: [ 'pipe', 'pipe', 'pipe' ] }
	);
	const pending = new Map();
	let buffer = '';
	let stderr = '';
	child.stdout.setEncoding( 'utf8' );
	child.stderr.setEncoding( 'utf8' );
	child.stderr.on( 'data', ( chunk ) => {
		stderr += chunk;
	} );
	child.stdout.on( 'data', ( chunk ) => {
		buffer += chunk;
		const lines = buffer.split( /\r?\n/ );
		buffer = lines.pop() || '';
		for ( const line of lines ) {
			if ( ! line.trim().startsWith( '{' ) ) {
				continue;
			}
			try {
				const payload = JSON.parse( line );
				const resolver = pending.get( String( payload.id ) );
				if ( resolver ) {
					pending.delete( String( payload.id ) );
					resolver( payload );
				}
			} catch {
				// Ignore non-protocol command output.
			}
		}
	} );

	try {
		let response = await waitForStdioResponse( child, pending, 601, {
			jsonrpc: '2.0',
			id: 601,
			method: 'initialize',
			params: {
				protocolVersion: PROTOCOLS.legacy,
				capabilities: {},
				clientInfo: { name: 'stdio-e2e', version: '1.0.0' },
			},
		} );
		assertSame( response.result?.protocolVersion, PROTOCOLS.legacy, 'STDIO initialize' );

		child.stdin.write(
			`${ JSON.stringify( {
				jsonrpc: '2.0',
				method: 'notifications/initialized',
			} ) }\n`
		);

		response = await waitForStdioResponse(
			child,
			pending,
			602,
			modernBody( 602, 'server/discover' )
		);
		assertSame( response.result?.resultType, 'complete', 'STDIO modern discover' );

		response = await waitForStdioResponse(
			child,
			pending,
			603,
			modernBody( 603, 'tools/list' )
		);
		assertSame( response.result?.resultType, 'complete', 'STDIO modern tools/list' );

		response = await waitForStdioResponse( child, pending, 604, {
			jsonrpc: '2.0',
			id: 604,
			method: 'ping',
		} );
		assertSame( response.result, {}, 'STDIO legacy ping after modern calls' );
		process.stdout.write( 'raw STDIO: one process alternated exact 2025 and 2026 requests\n' );
	} catch ( error ) {
		throw new Error(
			`${ error instanceof Error ? error.message : String( error ) } ${ stderr.trim() }`
		);
	} finally {
		child.kill( 'SIGTERM' );
		await new Promise( ( resolve ) => {
			const timeout = setTimeout( resolve, 5_000 );
			child.once( 'close', () => {
				clearTimeout( timeout );
				resolve();
			} );
		} );
	}
}

async function assertPinnedInspectorVersion() {
	const packageJson = JSON.parse(
		await readFile(
			join(
				process.cwd(),
				'node_modules',
				'@modelcontextprotocol',
				'inspector',
				'package.json'
			),
			'utf8'
		)
	);
	assertSame( packageJson.version, '2.4.0', 'Inspector package version' );
}

async function main() {
	await assertPinnedInspectorVersion();
	const authorization = authorizationHeader();
	const proxy = await startRecordingProxy();
	const workDir = await mkdtemp( join( tmpdir(), 'abilities-catalog-mcp-e2e-' ) );
	const configPath = join( workDir, 'mcp.json' );
	await writeFile(
		configPath,
		`${ JSON.stringify( buildConfig( authorization, proxy.baseUrl ), null, 2 ) }\n`,
		{ mode: 0o600 }
	);

	try {
		for ( const era of Object.keys( PROTOCOLS ) ) {
			await testSearchServer( proxy, configPath, era );
			await testCuratedServer( proxy, configPath, era );
			await testDefaultServer( proxy, configPath, era );
		}
		await assertInspectorHeaderMirroring( proxy, configPath );
		await assertRawHttpMatrix( authorization );
		await assertMixedRevisionStdio();
	} finally {
		await proxy.close();
		await rm( workDir, { recursive: true, force: true } );
	}
}

main().catch( ( error ) => {
	process.stderr.write( `${ error instanceof Error ? error.message : String( error ) }\n` );
	process.exitCode = 1;
} );
