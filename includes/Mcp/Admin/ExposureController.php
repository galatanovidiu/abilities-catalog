<?php
/**
 * REST controller backing the MCP server settings page.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Admin;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter;
use GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the MCP server's enable flag and per-ability exposure over REST.
 *
 * The settings page is a React app; this controller is its only data path. `GET`
 * returns the whole screen state — the server enable flag, whether a constant locks
 * it, the endpoint URL, and every ability grouped by domain with its risk flags and
 * exposure state. `POST` applies a partial change (a single toggle the page saves on
 * the spot, or a per-domain bulk) and returns the fresh state. Both require
 * `manage_options`, since exposing an admin-power ability over MCP is itself an
 * admin-power act.
 *
 * The controller is a thin adapter: {@see ExposurePolicy} owns the storage and the
 * deny-by-default rule, {@see DomainMap}/{@see DomainRouter} own the taxonomy and the
 * per-ability metadata. It registers unconditionally (independent of the server's
 * enable flag) so the page can be configured, and the server turned on, from one place.
 *
 * @since 0.2.0
 */
final class ExposureController {

	/**
	 * REST namespace for the settings endpoint.
	 */
	public const REST_NAMESPACE = 'abilities-catalog/v1';

	/**
	 * REST route for the settings endpoint, relative to the namespace.
	 */
	public const REST_ROUTE = '/exposure';

	/**
	 * Hooks the route registration onto the REST init cycle.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	/**
	 * Registers the `GET`/`POST` exposure route.
	 *
	 * @return void
	 */
	public static function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get' ),
					'permission_callback' => array( self::class, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update' ),
					'permission_callback' => array( self::class, 'permission' ),
					'args'                => array(
						'server_enabled' => array(
							'type'        => 'boolean',
							'required'    => false,
							'description' => __( 'Turn the MCP server on or off. Ignored when a constant locks the flag.', 'abilities-catalog' ),
						),
						'abilities'      => array(
							'type'                 => 'object',
							'required'             => false,
							'additionalProperties' => array( 'type' => 'boolean' ),
							'description'          => __( 'A map of ability name to its desired exposure state. Unmentioned abilities keep their current state.', 'abilities-catalog' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Authorizes a request: only an administrator may read or change exposure.
	 *
	 * @return bool True when the current user may manage options.
	 */
	public static function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns the full settings-screen state.
	 *
	 * @return \WP_REST_Response The current state.
	 */
	public static function get(): WP_REST_Response {
		return rest_ensure_response( self::state() );
	}

	/**
	 * Applies a partial change and returns the fresh state.
	 *
	 * The enable flag is written only when a constant does not lock it. Exposure changes
	 * merge onto the stored set and are validated against the registry before saving, so a
	 * stale or forged ability name can never be persisted.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The state after the change.
	 */
	public static function update( WP_REST_Request $request ): WP_REST_Response {
		$server_enabled = $request->get_param( 'server_enabled' );
		if ( null !== $server_enabled && ! abilities_catalog_mcp_is_enable_locked() ) {
			update_option( ABILITIES_CATALOG_MCP_ENABLED_OPTION, $server_enabled ? '1' : '0' );
		}

		$abilities = $request->get_param( 'abilities' );
		if ( is_array( $abilities ) ) {
			$changes = array();
			foreach ( $abilities as $name => $on ) {
				$changes[ (string) $name ] = (bool) $on;
			}

			$known = array_map( 'strval', array_keys( wp_get_abilities() ) );
			$next  = ExposurePolicy::applyChanges( ExposurePolicy::stored(), $changes );
			ExposurePolicy::save( $next, $known );
		}

		return rest_ensure_response( self::state() );
	}

	/**
	 * Builds the screen state from the live registry and the stored exposure set.
	 *
	 * @return array{server_enabled:bool,server_enabled_locked:bool,endpoint:string,enabled_count:int,total_count:int,domains:list<array{slug:string,label:string,abilities:list<array{name:string,label:string,description:string,readonly:bool,destructive:bool,dangerous:bool,enabled:bool}>}>}
	 *         The settings-screen state.
	 */
	private static function state(): array {
		$map    = new DomainMap();
		$router = new DomainRouter( $map, new ExposurePolicy() );

		$domains       = array();
		$enabled_count = 0;
		$total_count   = 0;
		foreach ( $map->domains() as $slug ) {
			$abilities = $router->list( $slug );

			$total_count += count( $abilities );
			foreach ( $abilities as $ability ) {
				if ( ! $ability['enabled'] ) {
					continue;
				}

				++$enabled_count;
			}

			$domains[] = array(
				'slug'      => $slug,
				'label'     => self::domainLabel( $slug ),
				'abilities' => $abilities,
			);
		}

		return array(
			'server_enabled'        => abilities_catalog_mcp_is_enabled(),
			'server_enabled_locked' => abilities_catalog_mcp_is_enable_locked(),
			'endpoint'              => rest_url( ltrim( Server::restRoute(), '/' ) ),
			'enabled_count'         => $enabled_count,
			'total_count'           => $total_count,
			'domains'               => $domains,
		);
	}

	/**
	 * Turns a domain slug into a human label for the accordion header.
	 *
	 * @param string $slug The domain slug, e.g. `site-health`.
	 * @return string The label, e.g. `Site Health`.
	 */
	private static function domainLabel( string $slug ): string {
		return ucwords( str_replace( '-', ' ', $slug ) );
	}
}
