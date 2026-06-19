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

	if ( ! wp || ! wp.element || ! wp.components || ! wp.apiFetch || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
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
	var Modal = c.Modal;

	var REST_PATH = '/abilities-catalog/v1/exposure';

	/**
	 * Classifies an ability into a risk badge.
	 *
	 * @param {Object} ability Ability summary with risk flags.
	 * @return {{label: string, bg: string, fg: string}} The badge styling.
	 */
	function riskOf( ability ) {
		if ( ability.dangerous ) {
			return { label: __( 'dangerous', 'abilities-catalog' ), bg: '#f4c7c3', fg: '#8a1f1f' };
		}
		if ( ability.destructive ) {
			return { label: __( 'destructive', 'abilities-catalog' ), bg: '#fbdcc8', fg: '#9c3a1a' };
		}
		if ( ability.readonly ) {
			return { label: __( 'read', 'abilities-catalog' ), bg: '#d7ecd9', fg: '#1e4620' };
		}
		return { label: __( 'write', 'abilities-catalog' ), bg: '#ffe7bd', fg: '#7a4e00' };
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
				if ( Object.prototype.hasOwnProperty.call( changes, ability.name ) ) {
					return Object.assign( {}, ability, { enabled: changes[ ability.name ] } );
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

		// The pending "enable all" confirmation, or null. Set only when the bulk would
		// turn on destructive or dangerous abilities, so the admin sees the risk first.
		var pendingHook = useState( null );
		var pendingEnable = pendingHook[ 0 ];
		var setPendingEnable = pendingHook[ 1 ];

		// Monotonic id of the latest save, so an out-of-order response from an earlier
		// save never overwrites the newer optimistic state.
		var seqRef = useRef( 0 );

		useEffect( function () {
			apiFetch( { path: REST_PATH } )
				.then( function ( data ) {
					setState( data );
					setLoading( false );
				} )
				.catch( function ( err ) {
					setError( ( err && err.message ) || __( 'Could not load settings.', 'abilities-catalog' ) );
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

			apiFetch( { path: REST_PATH, method: 'POST', data: payload } )
				.then( function ( fresh ) {
					reconcile( fresh );
					flash( __( 'Saved.', 'abilities-catalog' ) );
				} )
				.catch( function ( err ) {
					flash( ( err && err.message ) || __( 'Save failed.', 'abilities-catalog' ) );
					return apiFetch( { path: REST_PATH } )
						.then( reconcile )
						.catch( function () {
							flash( __( 'Could not confirm the saved state — reload to be sure.', 'abilities-catalog' ) );
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
				matchingAbilities( domain, query ).forEach( function ( ability ) {
					names.push( ability.name );
				} );
			} );
			return names;
		}

		function bulkDomain( domain, value ) {
			applyBulkNames( shownNames( [ domain ] ), value );
		}

		function bulkAll( value ) {
			applyBulkNames( shownNames( state.domains ), value );
		}

		// Enabling in bulk needs a guard: confirm first when the shown set turns on any
		// destructive or dangerous ability (the deny-by-default gate exists exactly to keep
		// those off by accident). A read-only-only set enables straight away.
		function requestEnableAll() {
			var query = search.trim().toLowerCase();
			var names = [];
			var dangerous = 0;
			var destructive = 0;
			state.domains.forEach( function ( domain ) {
				matchingAbilities( domain, query ).forEach( function ( ability ) {
					names.push( ability.name );
					if ( ability.dangerous ) {
						dangerous++;
					} else if ( ability.destructive ) {
						destructive++;
					}
				} );
			} );

			if ( names.length === 0 ) {
				return;
			}

			if ( dangerous === 0 && destructive === 0 ) {
				applyBulkNames( names, true );
				return;
			}

			setPendingEnable( { names: names, total: names.length, dangerous: dangerous, destructive: destructive } );
		}

		function confirmEnableAll() {
			var names = pendingEnable.names;
			setPendingEnable( null );
			applyBulkNames( names, true );
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
				flash( __( 'Copy is unavailable here — select the endpoint and copy it manually.', 'abilities-catalog' ) );
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
			return el( 'div', { style: { padding: '24px' } }, el( Spinner, null ) );
		}

		if ( error ) {
			return el( Notice, { status: 'error', isDismissible: false }, error );
		}

		var query = search.trim().toLowerCase();

		return el(
			Fragment,
			null,
			el( 'h1', null, __( 'MCP Server', 'abilities-catalog' ) ),
			el(
				'p',
				{ style: { maxWidth: '720px', color: '#50575e' } },
				__( 'The server exposes the ability catalog over the Model Context Protocol. Every ability is disabled by default; enable only the ones an agent should be allowed to run. Disabled abilities stay visible to a connected agent but refuse to execute. Capability checks still apply on top of these settings.', 'abilities-catalog' )
			),
			serverCard( state, toggleServer, copyEndpoint, copied ),
			el( 'div', { style: { margin: '16px 0', maxWidth: '420px' } },
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
				{ style: { display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap', margin: '4px 0 12px' } },
				el(
					'span',
					{ style: { color: '#50575e' } },
					sprintf(
						/* translators: 1: number of enabled abilities, 2: total abilities. */
						__( '%1$d of %2$d abilities enabled.', 'abilities-catalog' ),
						state.enabled_count,
						state.total_count
					)
				),
				el(
					Button,
					{ variant: 'secondary', size: 'small', onClick: requestEnableAll },
					__( 'Enable all', 'abilities-catalog' )
				),
				el(
					Button,
					{ variant: 'tertiary', size: 'small', onClick: function () { bulkAll( false ); } },
					__( 'Disable all', 'abilities-catalog' )
				)
			),
			el(
				Panel,
				null,
				state.domains.map( function ( domain ) {
					return domainPanel( domain, query, toggleAbility, bulkDomain );
				} )
			),
			pendingEnable ? enableAllModal( pendingEnable, confirmEnableAll, function () { setPendingEnable( null ); } ) : null,
			notice
				? el(
						'div',
						{ style: { position: 'fixed', bottom: '24px', left: '180px', zIndex: 99999 } },
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
			? __( 'Locked by the ABILITIES_CATALOG_MCP_ENABLED constant in wp-config.php.', 'abilities-catalog' )
			: __( 'Off by default. When on, the enabled abilities are reachable by an authenticated agent at the endpoint below.', 'abilities-catalog' );

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
					{ style: { marginTop: '12px', display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' } },
					el( 'span', { style: { color: '#50575e' } }, __( 'Endpoint:', 'abilities-catalog' ) ),
					el(
						'code',
						{ style: { padding: '2px 6px', background: '#f0f0f1', borderRadius: '3px', wordBreak: 'break-all' } },
						state.endpoint
					),
					el(
						Button,
						{ variant: 'secondary', size: 'small', onClick: copyEndpoint },
						copied ? __( 'Copied', 'abilities-catalog' ) : __( 'Copy', 'abilities-catalog' )
					)
				),
				! state.server_enabled
					? el(
							'div',
							{ style: { marginTop: '12px' } },
							el(
								Notice,
								{ status: 'warning', isDismissible: false },
								__( 'The server is disabled. These exposure settings take effect once it is enabled.', 'abilities-catalog' )
							)
					  )
					: null
			)
		);
	}

	/**
	 * Renders the "enable all" confirmation modal.
	 *
	 * Shown only when the bulk would turn on destructive or dangerous abilities, so the
	 * warning always describes a real risk.
	 *
	 * @param {Object}   pending   The pending bulk: { total, dangerous, destructive }.
	 * @param {Function} onConfirm Confirm handler.
	 * @param {Function} onCancel  Cancel handler.
	 * @return {Object} The modal element.
	 */
	function enableAllModal( pending, onConfirm, onCancel ) {
		return el(
			Modal,
			{ title: __( 'Enable all shown abilities?', 'abilities-catalog' ), onRequestClose: onCancel },
			el(
				'p',
				null,
				sprintf(
					/* translators: %d: number of abilities. */
					__( 'This enables %d abilities for execution over MCP.', 'abilities-catalog' ),
					pending.total
				)
			),
			el(
				Notice,
				{ status: 'warning', isDismissible: false },
				sprintf(
					/* translators: 1: dangerous count, 2: destructive count. */
					__( 'Includes %1$d dangerous and %2$d destructive abilities (for example installing plugins or themes) that any authenticated agent could then run over the network.', 'abilities-catalog' ),
					pending.dangerous,
					pending.destructive
				)
			),
			el(
				'div',
				{ style: { display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' } },
				el( Button, { variant: 'tertiary', onClick: onCancel }, __( 'Cancel', 'abilities-catalog' ) ),
				el(
					Button,
					{ variant: 'primary', isDestructive: pending.dangerous > 0, onClick: onConfirm },
					__( 'Enable all', 'abilities-catalog' )
				)
			)
		);
	}

	/**
	 * Renders one domain's collapsible panel.
	 *
	 * @param {Object}   domain        The domain.
	 * @param {string}   query         The lower-cased search query.
	 * @param {Function} toggleAbility Per-ability handler.
	 * @param {Function} bulkDomain    Per-domain bulk handler.
	 * @return {Object|null} The panel element, or null when search hides it.
	 */
	function domainPanel( domain, query, toggleAbility, bulkDomain ) {
		var visible = matchingAbilities( domain, query );
		if ( visible.length === 0 ) {
			return null;
		}

		var title = sprintf(
			/* translators: 1: domain label, 2: enabled count, 3: total count. */
			__( '%1$s — %2$d/%3$d enabled', 'abilities-catalog' ),
			domain.label,
			enabledCount( domain ),
			domain.abilities.length
		);

		// Fold the search state into the key so the panel remounts (and re-reads
		// initialOpen) when search toggles on or off — PanelBody is uncontrolled and
		// reads initialOpen only at mount, so matching domains auto-open while searching
		// and collapse again when the query is cleared.
		return el(
			PanelBody,
			{ key: domain.slug + ':' + ( query ? 'q' : '' ), title: title, initialOpen: !! query },
			el(
				'div',
				{ style: { display: 'flex', gap: '8px', marginBottom: '8px' } },
				el(
					Button,
					{ variant: 'secondary', size: 'small', onClick: function () { bulkDomain( domain, true ); } },
					__( 'Enable all', 'abilities-catalog' )
				),
				el(
					Button,
					{ variant: 'tertiary', size: 'small', onClick: function () { bulkDomain( domain, false ); } },
					__( 'Disable all', 'abilities-catalog' )
				)
			),
			visible.map( function ( ability ) {
				return el(
					'div',
					{ key: ability.name, style: { padding: '6px 0', borderTop: '1px solid #f0f0f1' } },
					el( ToggleControl, {
						__nextHasNoMarginBottom: true,
						checked: !! ability.enabled,
						onChange: function ( value ) { toggleAbility( ability.name, value ); },
						label: el(
							'span',
							{ style: { display: 'inline-flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' } },
							el( 'strong', null, ability.label || ability.name ),
							RiskBadge( ability )
						),
						help: el(
							Fragment,
							null,
							el( 'code', { style: { fontSize: '11px', color: '#646970' } }, ability.name ),
							ability.description
								? el( 'div', { style: { marginTop: '2px', color: '#50575e' } }, ability.description )
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
