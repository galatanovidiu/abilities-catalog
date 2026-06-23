<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `network/count-sites`.
 *
 * Returns the multisite network's site counts grouped by status (all, public,
 * archived, mature, spam, deleted), so an agent can see the network's size and
 * moderation state at a glance.
 *
 * Wraps `wp_count_sites( $network_id )` (wp-includes/ms-blogs.php:941 →
 * `int[]`). Core builds EXACTLY six keys in that function (the `all` total plus
 * the five status counts at ms-blogs.php:958-966; the docblock at
 * ms-blogs.php:930-939 lists the same six). There is NO `empty` key, so this
 * ability does not invent one. `$network_id` defaults to the current network
 * when null/omitted (`get_current_network_id()` at ms-blogs.php:942-944).
 *
 * Multisite-only: the `wp_blogs` table the count queries read does not exist on
 * a single site, so both the permission_callback and the top of execute() guard
 * on `is_multisite()`. The fixed network capability (`manage_sites`) is the hard
 * server-side guard; it is not granted to anyone on a single site, and on
 * multisite a super admin holds it while a plain site administrator does not.
 *
 * Core-function idiom: no REST route exists for site counts, so this calls the
 * core function directly and hand-builds any error. No wp-admin-only code is
 * called, so no `AdminIncludes::load` is needed.
 *
 * @since 0.1.0
 */
final class CountSites implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/count-sites';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Count Sites', 'abilities-catalog' ),
			'description'         => __( 'Returns the multisite network\'s site counts grouped by status: all, public, archived, mature, spam, and deleted. Use network/list-sites to enumerate the sites themselves. Requires a multisite install and the manage_sites (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'network_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Count one network\'s sites (multi-network installs). Discover IDs with network/list-networks. Omit for the current network.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'all', 'public', 'archived', 'mature', 'spam', 'deleted' ),
				'properties'           => array(
					'all'      => array(
						'type'        => 'integer',
						'description' => __( 'Total number of sites in the network.', 'abilities-catalog' ),
					),
					'public'   => array(
						'type'        => 'integer',
						'description' => __( 'Number of public sites.', 'abilities-catalog' ),
					),
					'archived' => array(
						'type'        => 'integer',
						'description' => __( 'Number of archived sites.', 'abilities-catalog' ),
					),
					'mature'   => array(
						'type'        => 'integer',
						'description' => __( 'Number of sites flagged mature.', 'abilities-catalog' ),
					),
					'spam'     => array(
						'type'        => 'integer',
						'description' => __( 'Number of sites flagged as spam.', 'abilities-catalog' ),
					),
					'deleted'  => array(
						'type'        => 'integer',
						'description' => __( 'Number of sites flagged for deletion.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: multisite super admin holding `manage_sites`.
	 *
	 * `manage_sites` is a network (super-admin) capability, not the per-site
	 * `manage_options`, so a plain site administrator is denied. On a single
	 * site this returns false for everyone, which is correct: the ability is
	 * meaningless without a network.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the site is multisite and the user can manage sites.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_sites' );
	}

	/**
	 * Executes the ability by counting sites grouped by status.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,int>|\WP_Error The six status counts, or a WP_Error on single site.
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

		$network_id = isset( $input['network_id'] ) ? absint( $input['network_id'] ) : null;

		$counts = wp_count_sites( $network_id );

		return array(
			'all'      => (int) ( $counts['all'] ?? 0 ),
			'public'   => (int) ( $counts['public'] ?? 0 ),
			'archived' => (int) ( $counts['archived'] ?? 0 ),
			'mature'   => (int) ( $counts['mature'] ?? 0 ),
			'spam'     => (int) ( $counts['spam'] ?? 0 ),
			'deleted'  => (int) ( $counts['deleted'] ?? 0 ),
		);
	}
}
