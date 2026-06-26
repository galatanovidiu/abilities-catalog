/**
 * Abilities Catalog — MCP Server settings app.
 *
 * A no-build React app: it runs on WordPress's own `wp-element` (React) and
 * `wp-components` handles, so the plugin ships no compiled asset. It renders the
 * per-ability exposure gate as an accordion of domains and saves every toggle to the
 * REST controller on the spot — there is no Save button.
 */
( function ( wp ) {
	'use strict';

	if (
		! wp ||
		! wp.element ||
		! wp.components ||
		! wp.apiFetch ||
		! wp.i18n
	) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	var c = wp.components;
	var Card = c.Card;
	var CardBody = c.CardBody;
	var ToggleControl = c.ToggleControl;
	var SearchControl = c.SearchControl;
	var Panel = c.Panel;
	var PanelBody = c.PanelBody;
	var Button = c.Button;
	var Notice = c.Notice;
	var Snackbar = c.Snackbar;
	var Spinner = c.Spinner;

	// ABILITIES_CATALOG_REST_URL is injected by SettingsPage::enqueue() as the full
	// ?rest_route= URL so apiFetch works regardless of permalink configuration.
	var REST_URL = ( typeof ABILITIES_CATALOG_REST_URL !== 'undefined' )
		? ABILITIES_CATALOG_REST_URL
		: '/wp-json/abilities-catalog/v1/exposure';

	/**
	 * Classifies an ability into a risk badge.
	 *
	 * @param {Object} ability Ability summary with risk flags.
	 * @return {{label: string, bg: string, fg: string}} The badge styling.
	 */
	function riskOf( ability ) {
		if ( ability.dangerous ) {
			return {
				label: __( 'dangerous', 'abilities-catalog' ),
				bg: '#f4c7c3',
				fg: '#8a1f1f',
			};
		}
		if ( ability.destructive ) {
			return {
				label: __( 'destructive', 'abilities-catalog' ),
				bg: '#fbdcc8',
				fg: '#9c3a1a',
			};
		}
		if ( ability.readonly ) {
			return {
				label: __( 'read', 'abilities-catalog' ),
				bg: '#d7ecd9',
				fg: '#1e4620',
			};
		}
		return {
			label: __( 'write', 'abilities-catalog' ),
			bg: '#ffe7bd',
			fg: '#7a4e00',
		};
	}

	/**
	 * Renders an ability's risk badge.
	 *
	 * @param {Object} ability Ability summary.
	 * @return {Object} A span element.
	 */
	function RiskBadge( ability ) {
		var risk = riskOf( ability );
		return el(
			'span',
			{
				style: {
					display: 'inline-block',
					padding: '1px 8px',
					borderRadius: '10px',
					fontSize: '11px',
					fontWeight: 600,
					textTransform: 'uppercase',
					letterSpacing: '0.02em',
					background: risk.bg,
					color: risk.fg,
				},
			},
			risk.label
		);
	}

	/**
	 * Recomputes a state object with a set of ability changes applied locally.
	 *
	 * Used for optimistic updates so a toggle feels instant before the save returns.
	 *
	 * @param {Object} state   The current state.
	 * @param {Object} changes Map of ability name to desired enabled state.
	 * @return {Object} A new state with the changes applied and counts recomputed.
	 */
	function applyLocal( state, changes ) {
		var enabled = 0;
		var total = 0;
		var domains = state.domains.map( function ( domain ) {
			var abilities = domain.abilities.map( function ( ability ) {
				if (
					Object.prototype.hasOwnProperty.call(
						changes,
						ability.name
					)
				) {
					return Object.assign( {}, ability, {
						enabled: changes[ ability.name ],
					} );
				}
				return ability;
			} );
			return Object.assign( {}, domain, { abilities: abilities } );
		} );

		domains.forEach( function ( domain ) {
			domain.abilities.forEach( function ( ability ) {
				total++;
				if ( ability.enabled ) {
					enabled++;
				}
			} );
		} );

		return Object.assign( {}, state, {
			domains: domains,
			enabled_count: enabled,
			total_count: total,
		} );
	}

	/**
	 * Filters a domain's abilities by a search query over name and label.
	 *
	 * @param {Object} domain The domain.
	 * @param {string} query  The lower-cased search query.
	 * @return {Array} The matching abilities.
	 */
	function matchingAbilities( domain, query ) {
		if ( ! query ) {
			return domain.abilities;
		}
		return domain.abilities.filter( function ( ability ) {
			return (
				ability.name.toLowerCase().indexOf( query ) !== -1 ||
				( ability.label || '' ).toLowerCase().indexOf( query ) !== -1
			);
		} );
	}

	/**
	 * Counts how many of a domain's abilities are enabled.
	 *
	 * @param {Object} domain The domain.
	 * @return {number} The enabled count.
	 */
	function enabledCount( domain ) {
		return domain.abilities.filter( function ( ability ) {
			return ability.enabled;
		} ).length;
	}

	/**
	 * Copies text via a temporary textarea, for contexts without the async Clipboard API.
	 *
	 * `navigator.clipboard` is undefined on a non-secure context (plain-HTTP wp-admin,
	 * common on local and intranet installs), so this is the fallback that still works there.
	 *
	 * @param {string} text The text to copy.
	 * @return {boolean} Whether the copy succeeded.
	 */
	function fallbackCopy( text ) {
		try {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.focus();
			area.select();
			var done = document.execCommand( 'copy' );
			document.body.removeChild( area );
			return done;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * The settings app.
	 *
	 * @return {Object} The root element.
	 */
	function App() {
		var stateHook = useState( null );
		var state = stateHook[ 0 ];
		var setState = stateHook[ 1 ];

		var loadingHook = useState( true );
		var loading = loadingHook[ 0 ];
		var setLoading = loadingHook[ 1 ];

		var errorHook = useState( null );
		var error = errorHook[ 0 ];
		var setError = errorHook[ 1 ];

		var searchHook = useState( '' );
		var search = searchHook[ 0 ];
		var setSearch = searchHook[ 1 ];

		var noticeHook = useState( null );
		var notice = noticeHook[ 0 ];
		var setNotice = noticeHook[ 1 ];

		var copiedHook = useState( false );
		var copied = copiedHook[ 0 ];
		var setCopied = copiedHook[ 1 ];

		// How many dangerous abilities the last bulk "Enable all" left off, or null.
		// Bulk enable never arms the dangerous tier (it must be a deliberate, per-ability
		// choice), so we tell the admin which ones still need enabling by hand.
		var skippedHook = useState( null );
		var dangerousSkipped = skippedHook[ 0 ];
		var setDangerousSkipped = skippedHook[ 1 ];

		// Monotonic id of the latest save, so an out-of-order response from an earlier
		// save never overwrites the newer optimistic state.
		var seqRef = useRef( 0 );

		useEffect( function () {
			apiFetch( { url: REST_URL } )
				.then( function ( data ) {
					setState( data );
					setLoading( false );
				} )
				.catch( function ( err ) {
					setError(
						( err && err.message ) ||
							__(
								'Could not load settings.',
								'abilities-catalog'
							)
					);
					setLoading( false );
				} );
		}, [] );

		/**
		 * Persists a change, reconciling with the authoritative server state.
		 *
		 * @param {Object} payload The REST body (server_enabled and/or abilities).
		 * @return {void}
		 */
		function save( payload ) {
			seqRef.current += 1;
			var seq = seqRef.current;

			// Apply a server snapshot only if no newer save has started since — and never
			// leave a promise unhandled (the same outage that fails the POST can fail the
			// recovery GET, the exact case AGENTS.JS warns about).
			var reconcile = function ( fresh ) {
				if ( seq === seqRef.current ) {
					setState( fresh );
				}
			};

			apiFetch( { url: REST_URL, method: 'POST', data: payload } )
				.then( function ( fresh ) {
					reconcile( fresh );
					flash( __( 'Saved.', 'abilities-catalog' ) );
				} )
				.catch( function ( err ) {
					flash(
						( err && err.message ) ||
							__( 'Save failed.', 'abilities-catalog' )
					);
					return apiFetch( { url: REST_URL } )
						.then( reconcile )
						.catch( function () {
							flash(
								__(
									'Could not confirm the saved state — reload to be sure.',
									'abilities-catalog'
								)
							);
						} );
				} );
		}

		/**
		 * Shows a transient Snackbar message.
		 *
		 * @param {string} message The message.
		 * @return {void}
		 */
		function flash( message ) {
			setNotice( message );
			window.setTimeout( function () {
				setNotice( null );
			}, 2000 );
		}

		function toggleServer( value ) {
			setState( Object.assign( {}, state, { server_enabled: value } ) );
			save( { server_enabled: value } );
		}

		function toggleAbility( name, value ) {
			var changes = {};
			changes[ name ] = value;
			setState( applyLocal( state, changes ) );
			save( { abilities: changes } );
		}

		// All bulk actions operate on what is currently shown: with no search that is
		// every ability in scope (so "Enable all" means all), and while searching it is
		// the matches (so you can act on a search result).
		function applyBulkNames( names, value ) {
			if ( names.length === 0 ) {
				return;
			}
			var changes = {};
			names.forEach( function ( name ) {
				changes[ name ] = value;
			} );
			setState( applyLocal( state, changes ) );
			save( { abilities: changes } );
		}

		function shownNames( domains ) {
			var query = search.trim().toLowerCase();
			var names = [];
			domains.forEach( function ( domain ) {
				matchingAbilities( domain, query ).forEach(
					function ( ability ) {
						names.push( ability.name );
					}
				);
			} );
			return names;
		}

		// Bulk "Enable all" turns on every shown ability except the dangerous tier, which
		// stays off by deny-by-default and must be armed one at a time. We report how many
		// were skipped so the admin knows they still need a deliberate per-ability choice.
		function enableShown( domains ) {
			var query = search.trim().toLowerCase();
			var enable = [];
			var skipped = 0;
			domains.forEach( function ( domain ) {
				matchingAbilities( domain, query ).forEach(
					function ( ability ) {
						if ( ability.dangerous ) {
							skipped++;
						} else {
							enable.push( ability.name );
						}
					}
				);
			} );
			applyBulkNames( enable, true );
			setDangerousSkipped( skipped > 0 ? skipped : null );
		}

		function bulkDomain( domain, value ) {
			if ( value ) {
				enableShown( [ domain ] );
				return;
			}
			applyBulkNames( shownNames( [ domain ] ), false );
		}

		function enableAllShown() {
			enableShown( state.domains );
		}

		function disableAllShown() {
			setDangerousSkipped( null );
			applyBulkNames( shownNames( state.domains ), false );
		}

		function copyEndpoint() {
			var text = state.endpoint;
			var ok = function () {
				setCopied( true );
				window.setTimeout( function () {
					setCopied( false );
				}, 1500 );
			};
			var fail = function () {
				flash(
					__(
						'Copy is unavailable here — select the endpoint and copy it manually.',
						'abilities-catalog'
					)
				);
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( ok, function () {
					if ( fallbackCopy( text ) ) {
						ok();
					} else {
						fail();
					}
				} );
				return;
			}

			if ( fallbackCopy( text ) ) {
				ok();
			} else {
				fail();
			}
		}

		if ( loading ) {
			return el(
				'div',
				{ style: { padding: '24px' } },
				el( Spinner, null )
			);
		}

		if ( error ) {
			return el(
				Notice,
				{ status: 'error', isDismissible: false },
				error
			);
		}

		var query = search.trim().toLowerCase();

		return el(
			Fragment,
			null,
			el( 'h1', null, __( 'MCP Server', 'abilities-catalog' ) ),
			el(
				'p',
				{ style: { maxWidth: '720px', color: '#50575e' } },
				__(
					'The server exposes the ability catalog over the Model Context Protocol. Every ability is disabled by default; enable only the ones an agent should be allowed to run. Disabled abilities stay visible to a connected agent but refuse to execute. Capability checks still apply on top of these settings.',
					'abilities-catalog'
				)
			),
			serverCard( state, toggleServer, copyEndpoint, copied ),
			connectPanel( state ),
			el(
				'div',
				{ style: { margin: '16px 0', maxWidth: '420px' } },
				el( SearchControl, {
					__nextHasNoMarginBottom: true,
					value: search,
					onChange: setSearch,
					label: __( 'Search abilities', 'abilities-catalog' ),
					placeholder: __( 'Search abilities…', 'abilities-catalog' ),
				} )
			),
			el(
				'div',
				{
					style: {
						display: 'flex',
						alignItems: 'center',
						gap: '12px',
						flexWrap: 'wrap',
						margin: '4px 0 12px',
					},
				},
				el(
					'span',
					{ style: { color: '#50575e' } },
					sprintf(
						/* translators: 1: number of enabled abilities, 2: total abilities. */
						__(
							'%1$d of %2$d abilities enabled.',
							'abilities-catalog'
						),
						state.enabled_count,
						state.total_count
					)
				),
				el(
					Button,
					{
						variant: 'secondary',
						size: 'small',
						onClick: enableAllShown,
					},
					__( 'Enable all', 'abilities-catalog' )
				),
				el(
					Button,
					{
						variant: 'tertiary',
						size: 'small',
						onClick: disableAllShown,
					},
					__( 'Disable all', 'abilities-catalog' )
				)
			),
			dangerousSkipped
				? el(
						'div',
						{ style: { margin: '0 0 12px', maxWidth: '720px' } },
						el(
							Notice,
							{
								status: 'warning',
								onRemove: function () {
									setDangerousSkipped( null );
								},
							},
							sprintf(
								/* translators: %d: number of dangerous abilities left off. */
								_n(
									'Enabled every shown ability except %d dangerous one — enable it by hand if an agent should run it. Bulk actions never turn on dangerous abilities.',
									'Enabled every shown ability except %d dangerous ones — enable them by hand if an agent should run them. Bulk actions never turn on dangerous abilities.',
									dangerousSkipped,
									'abilities-catalog'
								),
								dangerousSkipped
							)
						)
				  )
				: null,
			el(
				Panel,
				null,
				state.domains.map( function ( domain ) {
					return domainPanel(
						domain,
						query,
						toggleAbility,
						bulkDomain
					);
				} )
			),
			notice
				? el(
						'div',
						{
							style: {
								position: 'fixed',
								bottom: '24px',
								left: '180px',
								zIndex: 99999,
							},
						},
						el( Snackbar, null, notice )
				  )
				: null
		);
	}

	/**
	 * Renders the server enable + endpoint card.
	 *
	 * @param {Object}   state        The state.
	 * @param {Function} toggleServer Enable-flag handler.
	 * @param {Function} copyEndpoint Copy handler.
	 * @param {boolean}  copied       Whether the endpoint was just copied.
	 * @return {Object} The card element.
	 */
	function serverCard( state, toggleServer, copyEndpoint, copied ) {
		var help = state.server_enabled_locked
			? __(
					'Locked by the ABILITIES_CATALOG_MCP_ENABLED constant in wp-config.php.',
					'abilities-catalog'
			  )
			: __(
					'Off by default. When on, the enabled abilities are reachable by an authenticated agent at the endpoint below.',
					'abilities-catalog'
			  );

		return el(
			Card,
			{ style: { marginBottom: '16px' } },
			el(
				CardBody,
				null,
				el( ToggleControl, {
					__nextHasNoMarginBottom: true,
					label: __( 'Enable MCP server', 'abilities-catalog' ),
					checked: !! state.server_enabled,
					disabled: !! state.server_enabled_locked,
					onChange: toggleServer,
					help: help,
				} ),
				el(
					'div',
					{
						style: {
							marginTop: '12px',
							display: 'flex',
							alignItems: 'center',
							gap: '8px',
							flexWrap: 'wrap',
						},
					},
					el(
						'span',
						{ style: { color: '#50575e' } },
						__( 'Endpoint:', 'abilities-catalog' )
					),
					el(
						'code',
						{
							style: {
								padding: '2px 6px',
								background: '#f0f0f1',
								borderRadius: '3px',
								wordBreak: 'break-all',
							},
						},
						state.endpoint
					),
					el(
						Button,
						{
							variant: 'secondary',
							size: 'small',
							onClick: copyEndpoint,
						},
						copied
							? __( 'Copied', 'abilities-catalog' )
							: __( 'Copy', 'abilities-catalog' )
					)
				),
				! state.server_enabled
					? el(
							'div',
							{ style: { marginTop: '12px' } },
							el(
								Notice,
								{ status: 'warning', isDismissible: false },
								__(
									'The server is disabled. These exposure settings take effect once it is enabled.',
									'abilities-catalog'
								)
							)
					  )
					: null
			)
		);
	}

	/**
	 * Renders the "Connect a client" help accordion.
	 *
	 * Static how-to for pointing an MCP client at this server: the client connects to
	 * the endpoint and authenticates as a WordPress user with an Application Password
	 * (HTTP Basic auth). The site's own endpoint is woven into the example so it is
	 * copy-ready. No secret is rendered — the admin supplies the username and password.
	 *
	 * @param {Object} state The state (uses state.endpoint).
	 * @return {Object} The panel element.
	 */
	function connectPanel( state ) {
		var config =
			'{\n' +
			'  "mcpServers": {\n' +
			'    "wordpress": {\n' +
			'      "command": "npx",\n' +
			'      "args": [ "-y", "@automattic/mcp-wordpress-remote@latest" ],\n' +
			'      "env": {\n' +
			'        "WP_API_URL": "' +
			state.endpoint +
			'",\n' +
			'        "WP_API_USERNAME": "your-username",\n' +
			'        "WP_API_PASSWORD": "your application password"\n' +
			'      }\n' +
			'    }\n' +
			'  }\n' +
			'}';

		return el(
			Panel,
			{ style: { marginBottom: '16px' } },
			el(
				PanelBody,
				{
					title: __( 'Connect a client', 'abilities-catalog' ),
					initialOpen: false,
				},
				el(
					'p',
					{ style: { marginTop: 0 } },
					__(
						'An MCP client connects to the endpoint above and signs in as a WordPress user with an Application Password. You need three things:',
						'abilities-catalog'
					)
				),
				el(
					'ol',
					{ style: { margin: '0 0 12px', paddingLeft: '20px' } },
					el(
						'li',
						null,
						__(
							'The endpoint URL shown above.',
							'abilities-catalog'
						)
					),
					el(
						'li',
						null,
						__(
							'A WordPress username — the agent acts as this user.',
							'abilities-catalog'
						)
					),
					el(
						'li',
						null,
						el(
							'a',
							{ href: 'profile.php#application-passwords' },
							__(
								'An Application Password for that user (Users → Profile → Application Passwords).',
								'abilities-catalog'
							)
						),
						' ',
						__(
							'Creating one requires the site to run over HTTPS.',
							'abilities-catalog'
						)
					)
				),
				el(
					'p',
					{ style: { marginBottom: '4px' } },
					__(
						'Most clients (such as Claude Desktop or Cursor) connect through the @automattic/mcp-wordpress-remote proxy. Add it to your MCP client config:',
						'abilities-catalog'
					)
				),
				el(
					'pre',
					{
						style: {
							padding: '12px',
							background: '#f6f7f7',
							border: '1px solid #dcdcde',
							borderRadius: '3px',
							overflowX: 'auto',
							fontSize: '12px',
							margin: '0 0 12px',
						},
					},
					el( 'code', null, config )
				),
				el(
					'p',
					{ style: { marginBottom: 0, color: '#50575e' } },
					__(
						'A client that speaks remote (Streamable HTTP) MCP can call the endpoint directly, authenticating with the header Authorization: Basic <base64 of "username:application-password">. Either way the agent acts as the chosen user, so enable only the abilities it needs — and back up your site first.',
						'abilities-catalog'
					)
				)
			)
		);
	}

	/**
	 * Renders one category's collapsible panel.
	 *
	 * Opens with the category description, then a master switch that is on only when every
	 * shown ability is enabled and that toggles them all, then the per-ability switches.
	 *
	 * @param {Object}   domain        The category (response key kept as `domain`).
	 * @param {string}   query         The lower-cased search query.
	 * @param {Function} toggleAbility Per-ability handler.
	 * @param {Function} bulkDomain    Per-category bulk handler.
	 * @return {Object|null} The panel element, or null when search hides it.
	 */
	/**
	 * Builds the PanelBody title: label + one colored chip per risk tier.
	 *
	 * @param {Object} domain The domain object.
	 * @return {Object} A React element.
	 */
	function domainTitle( domain ) {
		var TIERS = [
			{ key: 'read',        match: function ( a ) { return a.readonly; } },
			{ key: 'write',       match: function ( a ) { return ! a.readonly && ! a.destructive && ! a.dangerous; } },
			{ key: 'destructive', match: function ( a ) { return a.destructive; } },
			{ key: 'dangerous',   match: function ( a ) { return a.dangerous; } },
		];

		var chips = TIERS.map( function ( tier ) {
			var abilities = domain.abilities.filter( tier.match );
			if ( abilities.length === 0 ) {
				return null;
			}
			var enabled = abilities.filter( function ( a ) { return a.enabled; } ).length;
			// Reuse riskOf colors via a synthetic ability flag.
			var synthetic = {
				readonly:    tier.key === 'read',
				destructive: tier.key === 'destructive',
				dangerous:   tier.key === 'dangerous',
			};
			var risk = riskOf( synthetic );
			return el(
				'span',
				{
					key: tier.key,
					style: {
						display: 'inline-block',
						padding: '1px 6px',
						borderRadius: '3px',
						fontSize: '11px',
						fontWeight: 'normal',
						lineHeight: '18px',
						background: risk.bg,
						color: risk.fg,
						whiteSpace: 'nowrap',
					},
				},
				risk.label + ' ' + enabled + '/' + abilities.length
			);
		} ).filter( Boolean );

		return el(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' } },
			domain.label,
			el( 'span', { style: { display: 'inline-flex', gap: '4px', flexWrap: 'wrap' } }, chips )
		);
	}

	function domainPanel( domain, query, toggleAbility, bulkDomain ) {
		var visible = matchingAbilities( domain, query );
		if ( visible.length === 0 ) {
			return null;
		}

		var title = domainTitle( domain );

		// The master switch reads as on only when nothing shown is still disabled, so a
		// single off ability leaves it off — and toggling it acts on the shown set.
		var allEnabled = visible.every( function ( ability ) {
			return ability.enabled;
		} );

		// Fold the search state into the key so the panel remounts (and re-reads
		// initialOpen) when search toggles on or off — PanelBody is uncontrolled and
		// reads initialOpen only at mount, so matching domains auto-open while searching
		// and collapse again when the query is cleared.
		return el(
			PanelBody,
			{
				key: domain.slug + ':' + ( query ? 'q' : '' ),
				title: title,
				initialOpen: !! query,
			},
			domain.description
				? el(
						'p',
						{
							style: {
								marginTop: 0,
								marginBottom: '12px',
								color: '#50575e',
							},
						},
						domain.description
				  )
				: null,
			el(
				'div',
				{
					style: {
						paddingBottom: '8px',
						marginBottom: '4px',
						borderBottom: '1px solid #dcdcde',
					},
				},
				el( ToggleControl, {
					__nextHasNoMarginBottom: true,
					checked: allEnabled,
					onChange: function ( value ) {
						bulkDomain( domain, value );
					},
					label: el(
						'strong',
						null,
						__( 'Enable all', 'abilities-catalog' )
					),
				} )
			),
			visible.map( function ( ability ) {
				return el(
					'div',
					{
						key: ability.name,
						style: {
							padding: '6px 0',
							borderTop: '1px solid #f0f0f1',
						},
					},
					el( ToggleControl, {
						__nextHasNoMarginBottom: true,
						checked: !! ability.enabled,
						onChange: function ( value ) {
							toggleAbility( ability.name, value );
						},
						label: el(
							'span',
							{
								style: {
									display: 'inline-flex',
									alignItems: 'center',
									gap: '8px',
									flexWrap: 'wrap',
								},
							},
							el( 'strong', null, ability.label || ability.name ),
							RiskBadge( ability )
						),
						help: el(
							Fragment,
							null,
							el(
								'code',
								{
									style: {
										fontSize: '11px',
										color: '#646970',
									},
								},
								ability.name
							),
							ability.description
								? el(
										'div',
										{
											style: {
												marginTop: '2px',
												color: '#50575e',
											},
										},
										ability.description
								  )
								: null
						),
					} )
				);
			} )
		);
	}

	var root = document.getElementById( 'abilities-catalog-mcp-settings' );
	if ( ! root ) {
		return;
	}

	if ( wp.element.createRoot ) {
		wp.element.createRoot( root ).render( el( App ) );
	} else {
		wp.element.render( el( App ), root );
	}
} )( window.wp );
