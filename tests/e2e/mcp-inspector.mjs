import { spawn } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
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

const CURATED_TOOLS = [ ...CURATED_DOMAINS, 'knowledge' ];

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

	throw new Error(
		'Set MCP_AUTH_HEADER, or set WP_API_USERNAME and WP_API_PASSWORD to a WordPress application-password credential.'
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
			if ( code !== 0 ) {
				reject(
					new Error(
						`Inspector ${ selectedServer } run exited with ${ code }. ${ stderr.trim() }`
					)
				);
				return;
			}

			try {
				resolve( JSON.parse( stdout ).result );
			} catch ( error ) {
				reject(
					new Error(
						`Inspector ${ selectedServer } run did not return JSON: ${ error.message }`
					)
				);
			}
		} );
	} );
}

function assertExactNames( label, values, expected ) {
	const names = [ ...values ].sort();
	const wanted = [ ...expected ].sort();
	if ( JSON.stringify( names ) !== JSON.stringify( wanted ) ) {
		throw new Error(
			`${ label } returned [${ names.join(
				', '
			) }], expected [${ wanted.join( ', ' ) }].`
		);
	}
}

function assertToolSuccess( selectedServer, toolName, result ) {
	if ( result?.isError === true ) {
		throw new Error(
			`${ selectedServer } tool ${ toolName } returned isError=true.`
		);
	}

	if ( ! Array.isArray( result?.content ) || result.content.length === 0 ) {
		throw new Error(
			`${ selectedServer } tool ${ toolName } returned no content.`
		);
	}
}

function structuredContent( selectedServer, toolName, result ) {
	assertToolSuccess( selectedServer, toolName, result );
	if (
		result.structuredContent &&
		typeof result.structuredContent === 'object'
	) {
		return result.structuredContent;
	}

	const text = result.content.find( ( item ) => item?.type === 'text' )?.text;
	if ( typeof text === 'string' ) {
		try {
			return JSON.parse( text );
		} catch {
			// Fall through to the contract error below.
		}
	}

	throw new Error(
		`${ selectedServer } tool ${ toolName } returned no structured JSON content.`
	);
}

async function callTool( configPath, selectedServer, toolName, args = {} ) {
	return runInspector( configPath, selectedServer, [
		'--method',
		'tools/call',
		'--tool-name',
		toolName,
		'--tool-args-json',
		JSON.stringify( args ),
	] );
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
		Array.from( { length: Math.min( limit, items.length ) }, () =>
			worker()
		)
	);
	return results;
}

async function assertRevision( configPath, endpointName, era ) {
	const selectedServer = serverName( endpointName, era );
	const result = await runInspector( configPath, selectedServer, [
		'--method',
		'initialize',
	] );

	if ( result?.protocolVersion !== PROTOCOLS[ era ] ) {
		throw new Error(
			`${ selectedServer } negotiated ${
				result?.protocolVersion || '<none>'
			}, expected ${ PROTOCOLS[ era ] }.`
		);
	}

	for ( const capability of [ 'tools', 'resources', 'prompts' ] ) {
		if (
			! result.capabilities ||
			typeof result.capabilities[ capability ] !== 'object'
		) {
			throw new Error(
				`${ selectedServer } did not advertise ${ capability } capability.`
			);
		}
	}

	if ( ! result.serverInfo?.name ) {
		throw new Error( `${ selectedServer } returned no server identity.` );
	}
}

async function assertTools( configPath, selectedServer, expected ) {
	const result = await runInspector( configPath, selectedServer, [
		'--method',
		'tools/list',
	] );
	if ( ! Array.isArray( result?.tools ) ) {
		throw new Error(
			`${ selectedServer } tools/list returned no tools array.`
		);
	}

	assertExactNames(
		`${ selectedServer } tools/list`,
		result.tools.map( ( tool ) => tool.name ),
		expected
	);
}

async function assertSharedResourcesAndPrompt( configPath, selectedServer ) {
	const listed = await runInspector( configPath, selectedServer, [
		'--method',
		'resources/list',
	] );
	assertExactNames(
		`${ selectedServer } resources/list`,
		( listed.resources || [] ).map( ( resource ) => resource.uri ),
		SHARED_RESOURCES
	);
	const resourceTypes = Object.fromEntries(
		listed.resources.map( ( resource ) => [
			resource.uri,
			resource.mimeType,
		] )
	);
	if (
		resourceTypes[ SHARED_RESOURCES[ 0 ] ] !== 'application/json' ||
		resourceTypes[ SHARED_RESOURCES[ 1 ] ] !== 'text/markdown'
	) {
		throw new Error(
			`${ selectedServer } resources/list returned incorrect MIME types.`
		);
	}

	const templates = await runInspector( configPath, selectedServer, [
		'--method',
		'resources/templates/list',
	] );
	assertExactNames(
		`${ selectedServer } resources/templates/list`,
		( templates.resourceTemplates || [] ).map(
			( template ) => template.uriTemplate
		),
		[]
	);

	const capabilities = await runInspector( configPath, selectedServer, [
		'--method',
		'resources/read',
		'--uri',
		SHARED_RESOURCES[ 0 ],
	] );
	const capabilityText = capabilities?.contents?.[ 0 ]?.text;
	const capabilityMap = JSON.parse( capabilityText || '{}' );
	if (
		! Array.isArray( capabilityMap.categories ) ||
		capabilityMap.categories.length < 10 ||
		capabilityMap.total_enabled < CORE_READS.length
	) {
		throw new Error(
			`${ selectedServer } capability resource returned an incomplete map.`
		);
	}

	const knowledge = await runInspector( configPath, selectedServer, [
		'--method',
		'resources/read',
		'--uri',
		SHARED_RESOURCES[ 1 ],
	] );
	if (
		! knowledge?.contents?.[ 0 ]?.text?.includes( 'core/create-content' )
	) {
		throw new Error(
			`${ selectedServer } knowledge resource omitted core/create-content.`
		);
	}

	const prompts = await runInspector( configPath, selectedServer, [
		'--method',
		'prompts/list',
	] );
	assertExactNames(
		`${ selectedServer } prompts/list`,
		( prompts.prompts || [] ).map( ( prompt ) => prompt.name ),
		[ WORKFLOW_PROMPT ]
	);
	const workflowDescriptor = prompts.prompts.find(
		( prompt ) => prompt.name === WORKFLOW_PROMPT
	);
	const taskArgument = workflowDescriptor?.arguments?.find(
		( argument ) => argument.name === 'task'
	);
	if ( taskArgument?.required !== true ) {
		throw new Error(
			`${ selectedServer } workflow prompt did not require its task argument.`
		);
	}

	const prompt = await runInspector( configPath, selectedServer, [
		'--method',
		'prompts/get',
		'--prompt-name',
		WORKFLOW_PROMPT,
		'--prompt-args',
		'task=Inspect installed plugins',
	] );
	const promptText = prompt?.messages?.[ 0 ]?.content?.text;
	if ( ! promptText?.includes( 'Inspect installed plugins' ) ) {
		throw new Error(
			`${ selectedServer } workflow prompt omitted the task argument.`
		);
	}
}

async function assertEmptyResourcesAndPrompts( configPath, selectedServer ) {
	const resources = await runInspector( configPath, selectedServer, [
		'--method',
		'resources/list',
	] );
	assertExactNames(
		`${ selectedServer } resources/list`,
		( resources.resources || [] ).map( ( resource ) => resource.uri ),
		[]
	);

	const templates = await runInspector( configPath, selectedServer, [
		'--method',
		'resources/templates/list',
	] );
	assertExactNames(
		`${ selectedServer } resources/templates/list`,
		( templates.resourceTemplates || [] ).map(
			( template ) => template.uriTemplate
		),
		[]
	);

	const prompts = await runInspector( configPath, selectedServer, [
		'--method',
		'prompts/list',
	] );
	assertExactNames(
		`${ selectedServer } prompts/list`,
		( prompts.prompts || [] ).map( ( prompt ) => prompt.name ),
		[]
	);
}

async function testSearchServer( configPath, era ) {
	const selectedServer = serverName( 'search', era );
	await assertRevision( configPath, 'search', era );
	await assertTools( configPath, selectedServer, SEARCH_TOOLS );

	const overview = structuredContent(
		selectedServer,
		'overview',
		await callTool( configPath, selectedServer, 'overview' )
	);
	if (
		! Array.isArray( overview.categories ) ||
		overview.categories.length < 10
	) {
		throw new Error(
			`${ selectedServer } overview returned too few categories.`
		);
	}

	const search = structuredContent(
		selectedServer,
		'search-abilities',
		await callTool( configPath, selectedServer, 'search-abilities', {
			query: 'list installed plugins',
			limit: 10,
		} )
	);
	if (
		! search.abilities?.some(
			( ability ) => ability.name === 'og-plugins/list-plugins'
		)
	) {
		throw new Error(
			`${ selectedServer } search did not find og-plugins/list-plugins.`
		);
	}

	const noMatch = structuredContent(
		selectedServer,
		'search-abilities',
		await callTool( configPath, selectedServer, 'search-abilities', {
			query: 'zzzxxyyqqqvvv',
		} )
	);
	if ( noMatch.no_match !== true || ! Array.isArray( noMatch.categories ) ) {
		throw new Error(
			`${ selectedServer } no-match recovery is incomplete.`
		);
	}

	await mapLimit( CORE_READS, 4, async ( scenario ) => {
		const described = structuredContent(
			selectedServer,
			'describe-ability',
			await callTool( configPath, selectedServer, 'describe-ability', {
				name: scenario.ability,
			} )
		);
		if (
			described.name !== scenario.ability ||
			described.annotations?.readonly !== true ||
			described.enabled !== true
		) {
			throw new Error(
				`${ selectedServer } describe returned an unsafe or disabled contract for ${ scenario.ability }.`
			);
		}
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

	await assertSharedResourcesAndPrompt( configPath, selectedServer );
	process.stdout.write(
		`${ selectedServer }: ${ SEARCH_TOOLS.length } tools; ${ CORE_READS.length } core reads; resources and prompt passed\n`
	);
}

async function testCuratedServer( configPath, era ) {
	const selectedServer = serverName( 'curated', era );
	await assertRevision( configPath, 'curated', era );
	await assertTools( configPath, selectedServer, CURATED_TOOLS );

	await mapLimit( CURATED_DOMAINS, 4, async ( domain ) => {
		const listed = structuredContent(
			selectedServer,
			domain,
			await callTool( configPath, selectedServer, domain, {
				action: 'list',
			} )
		);
		if (
			! Array.isArray( listed.abilities ) ||
			listed.abilities.length === 0
		) {
			throw new Error(
				`${ selectedServer } domain ${ domain } listed nothing.`
			);
		}
	} );

	const described = structuredContent(
		selectedServer,
		'dashboard',
		await callTool( configPath, selectedServer, 'dashboard', {
			action: 'describe',
			ability: 'og-dashboard/get-at-a-glance',
		} )
	);
	if ( described.name !== 'og-dashboard/get-at-a-glance' ) {
		throw new Error(
			`${ selectedServer } domain describe returned the wrong ability.`
		);
	}

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

	await assertSharedResourcesAndPrompt( configPath, selectedServer );
	process.stdout.write(
		`${ selectedServer }: ${ CURATED_TOOLS.length } tools; ${ CURATED_DOMAINS.length } domain lists; ${ CORE_READS.length } core reads; resources and prompt passed\n`
	);
}

async function testDefaultServer( configPath, era ) {
	const selectedServer = serverName( 'default', era );
	await assertRevision( configPath, 'default', era );
	await assertTools( configPath, selectedServer, DEFAULT_TOOLS );

	const discovered = structuredContent(
		selectedServer,
		DEFAULT_TOOLS[ 0 ],
		await callTool( configPath, selectedServer, DEFAULT_TOOLS[ 0 ] )
	);
	const discoveredNames = ( discovered.abilities || [] ).map(
		( ability ) => ability.name
	);
	for ( const scenario of CORE_READS ) {
		if ( ! discoveredNames.includes( scenario.ability ) ) {
			throw new Error(
				`${ selectedServer } discovery omitted ${ scenario.ability }.`
			);
		}
	}

	await mapLimit( CORE_READS, 3, async ( scenario ) => {
		const info = structuredContent(
			selectedServer,
			DEFAULT_TOOLS[ 1 ],
			await callTool( configPath, selectedServer, DEFAULT_TOOLS[ 1 ], {
				ability_name: scenario.ability,
			} )
		);
		if ( info.name !== scenario.ability ) {
			throw new Error(
				`${ selectedServer } ability info returned the wrong ability.`
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
		if ( executed.success !== true ) {
			throw new Error(
				`${ selectedServer } failed to execute ${ scenario.ability }.`
			);
		}
	} );

	await assertEmptyResourcesAndPrompts( configPath, selectedServer );
	process.stdout.write(
		`${ selectedServer }: ${ DEFAULT_TOOLS.length } tools; ${ CORE_READS.length } ability-info calls; ${ CORE_READS.length } core reads; empty resource/prompt lists passed\n`
	);
}

function buildConfig( authHeader ) {
	const mcpServers = {};
	for ( const [ endpointName, url ] of Object.entries( endpoints ) ) {
		for ( const era of Object.keys( PROTOCOLS ) ) {
			mcpServers[ serverName( endpointName, era ) ] = {
				type: 'streamable-http',
				url,
				headers: { Authorization: authHeader },
				protocolEra: era,
				...( era === 'modern' ? { modernLogLevel: 'off' } : {} ),
			};
		}
	}

	return { mcpServers };
}

async function main() {
	const authHeader = authorizationHeader();
	const workDir = await mkdtemp(
		join( tmpdir(), 'abilities-catalog-mcp-e2e-' )
	);
	const configPath = join( workDir, 'mcp.json' );

	await writeFile(
		configPath,
		`${ JSON.stringify( buildConfig( authHeader ), null, 2 ) }\n`,
		{ mode: 0o600 }
	);

	try {
		for ( const era of Object.keys( PROTOCOLS ) ) {
			await testSearchServer( configPath, era );
			await testCuratedServer( configPath, era );
			await testDefaultServer( configPath, era );
		}
	} finally {
		await rm( workDir, { recursive: true, force: true } );
	}
}

main().catch( ( error ) => {
	process.stderr.write( `${ error.message }\n` );
	process.exitCode = 1;
} );
