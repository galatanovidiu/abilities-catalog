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
 * T1 read ability: `network/list-networks`.
 *
 * Lists the networks in a WordPress multisite installation, one flat row per
 * network with its id, domain, path, and display name (site_name). The
 * network-level companion list to `network/get-network`. Most installs have a
 * single network; a multi-network install has more.
 *
 * Built on `get_networks()` (wp-includes/ms-network.php:63 → `WP_Network[]`, or
 * an `int` when `'count' => true` is passed). No core REST route exists for
 * networks, so this uses the core-function idiom; it touches no wp-admin-only
 * code, so no admin includes are loaded.
 *
 * Runtime notes:
 * - Only meaningful on multisite. `execute()` returns a 400 WP_Error on a
 *   single-site install before touching any `ms-*` function, because the
 *   `wp_site` table the network query reads does not exist there. The
 *   `permission_callback` also fails on single-site (`is_multisite()` is false),
 *   so this branch is normally unreachable; it keeps `execute()` safe if called
 *   directly.
 * - `total` is a SEPARATE count query (`get_networks()` with `'count' => true`
 *   returns an `int`), never `count()` of the returned page — a `number`-capped
 *   page can be smaller than the matching total.
 * - `WP_Network` public props (`domain`, `path`, `site_name`) are strings; `id`
 *   resolves to an `int` through the magic getter (class-wp-network.php:151).
 *   Each projected field is cast deliberately.
 *
 * @since 0.1.0
 */
final class ListNetworks implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/list-networks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Networks', 'abilities-catalog' ),
			'description'         => __( 'Lists the networks in a WordPress multisite installation (usually one; more on a multi-network install), one row per network with its id, domain, path, and display name (site_name). Use network/get-network for one network\'s full detail including its main site id. Requires a multisite install and the manage_network (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'number' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 200,
						'default'     => 100,
						'description' => __( 'How many networks to return (page size).', 'abilities-catalog' ),
					),
					'offset' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'How many matching networks to skip (for paging).', 'abilities-catalog' ),
					),
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Optional substring to match against network domain/path.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'networks', 'total' ),
				'properties'           => array(
					'networks' => array(
						'type'        => 'array',
						'description' => __( 'The networks matching the query, one flat row per network. Use network/get-network for a single network\'s full detail.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id', 'domain', 'path', 'site_name' ),
							'properties'           => array(
								'id'        => array(
									'type'        => 'integer',
									'description' => __( 'The network ID; pass to network/get-network.', 'abilities-catalog' ),
								),
								'domain'    => array(
									'type'        => 'string',
									'description' => __( 'The network\'s domain.', 'abilities-catalog' ),
								),
								'path'      => array(
									'type'        => 'string',
									'description' => __( 'The network\'s path.', 'abilities-catalog' ),
								),
								'site_name' => array(
									'type'        => 'string',
									'description' => __( 'The network\'s display name.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total'    => array(
						'type'        => 'integer',
						'description' => __( 'Total number of networks matching the query across all pages.', 'abilities-catalog' ),
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
	 * Permission check: a super admin who can manage the network.
	 *
	 * The capability is the hard server-side guard. `manage_network` is the
	 * super-admin capability the network admin screens require to read
	 * network-wide state; a plain site administrator does not hold it. The
	 * `is_multisite()` test makes this a hard precondition: on a single-site
	 * install the capability is granted to no one and the network tables do not
	 * exist, so the ability is meaningless there and the callback returns false.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True on multisite for a caller who can manage the network.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_network' );
	}

	/**
	 * Executes the ability by querying networks and projecting flat rows.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The networks list and total, or a 400 on single-site.
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

		$args = array(
			'number' => isset( $input['number'] ) ? (int) $input['number'] : 100,
			'offset' => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
		);

		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['search'] = (string) $input['search'];
		}

		$networks = get_networks( $args );
		if ( ! is_array( $networks ) ) {
			$networks = array();
		}

		$rows = array();
		foreach ( $networks as $network ) {
			if ( ! $network instanceof WP_Network ) {
				continue;
			}

			$rows[] = array(
				'id'        => (int) $network->id,
				'domain'    => (string) $network->domain,
				'path'      => (string) $network->path,
				'site_name' => (string) $network->site_name,
			);
		}

		$total = (int) get_networks(
			array_merge(
				$args,
				array(
					'count'  => true,
					'number' => 0,
					'offset' => 0,
				)
			)
		);

		return array(
			'networks' => $rows,
			'total'    => $total,
		);
	}
}
