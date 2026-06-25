<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_Network;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core-function T1 read ability: `og-network/get-network`.
 *
 * Returns one multisite network's identity — its id, domain, path, display
 * name, cookie domain, and the blog_id of its main site — defaulting to the
 * current network. The network-level companion to `og-network/get-site`. Built on
 * the core accessor `get_network()` (wp-includes/ms-network.php:23 →
 * `WP_Network|null`; an empty argument resolves to the current network), since
 * core exposes no REST route for networks. No wp-admin includes are loaded.
 *
 * `WP_Network` public props (`domain`, `path`, `cookie_domain`, `site_name`)
 * are strings; `id` and the main site id are read through the magic getter
 * (wp-includes/class-wp-network.php:151), which casts `id` to int and resolves
 * `site_id` via `get_main_site_id()` (:219, returns int). So `main_site_id` is
 * `(int) $network->site_id`.
 *
 * Multisite only: on a single site the `wp_site`/`wp_sitemeta` tables the
 * `ms-*` functions read do not exist, so both the `permission_callback` and the
 * top of `execute()` hard-guard on `is_multisite()` before any network read.
 *
 * @since 0.1.0
 */
final class GetNetwork implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-network/get-network';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Network', 'abilities-catalog' ),
			'description'         => __( 'Returns a multisite network\'s identity: id, domain, path, display name (site_name), cookie domain, and the blog_id of its main site (main_site_id). Omit network_id for the current network. An unknown network_id returns a 404 rest_network_invalid_id error. Use og-network/list-networks to enumerate networks. Requires a multisite install and the manage_network (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'network_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The network ID to fetch (multi-network installs). Discover IDs with og-network/list-networks. Omit for the current network.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'domain', 'path', 'site_name', 'cookie_domain', 'main_site_id' ),
				'properties'           => array(
					'id'            => array(
						'type'        => 'integer',
						'description' => __( 'The network ID.', 'abilities-catalog' ),
					),
					'domain'        => array(
						'type'        => 'string',
						'description' => __( 'The network\'s domain.', 'abilities-catalog' ),
					),
					'path'          => array(
						'type'        => 'string',
						'description' => __( 'The network\'s path.', 'abilities-catalog' ),
					),
					'site_name'     => array(
						'type'        => 'string',
						'description' => __( 'The network\'s display name.', 'abilities-catalog' ),
					),
					'cookie_domain' => array(
						'type'        => 'string',
						'description' => __( 'The domain used to set cookies for this network.', 'abilities-catalog' ),
					),
					'main_site_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The blog_id of the network\'s main site.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'network',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: the caller must be on multisite and a super admin.
	 *
	 * `manage_network` is a network/super-admin capability; a plain site
	 * administrator does not hold it. The `is_multisite()` test is a hard
	 * precondition — on a single site this ability reads tables that do not
	 * exist, so it returns false for everyone there. The guard is
	 * object-independent: an unknown network_id surfaces as the specific 404
	 * from execute(), never as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if multisite and the current user can manage the network.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_network' );
	}

	/**
	 * Executes the ability by reading one network and projecting it.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The network row, a 400 on single-site, or a 404 if the network is missing.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$input = is_array( $input ) ? $input : array();

		$network = isset( $input['network_id'] )
			? get_network( absint( $input['network_id'] ) )
			: get_network();

		if ( ! $network instanceof WP_Network ) {
			return new WP_Error(
				'rest_network_invalid_id',
				__( 'Invalid network ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'id'            => (int) $network->id,
			'domain'        => (string) $network->domain,
			'path'          => (string) $network->path,
			'site_name'     => (string) $network->site_name,
			'cookie_domain' => (string) $network->cookie_domain,
			'main_site_id'  => (int) $network->site_id,
		);
	}
}
