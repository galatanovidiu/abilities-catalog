<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `og-templates/init-global-styles`.
 *
 * Ensures the active theme has a user `wp_global_styles` record and returns its
 * post id, creating an empty-overrides record when none exists yet. On a block
 * theme that record is created lazily the first time the Site Editor opens, so on
 * a freshly provisioned site it does not exist — which leaves
 * {@see GetGlobalStyles} returning a 404 (it deliberately reads with
 * `$create_post = false` to stay read-only) and {@see UpdateGlobalStyles} with no
 * id to write against. This ability bootstraps that gap so "change my site colors"
 * is reachable: call it, then pass the returned id to `og-templates/update-global-styles`.
 *
 * Thin wrap of {@see \WP_Theme_JSON_Resolver::get_user_global_styles_post_id()} —
 * the canonical core create path the Site Editor itself uses — so it never
 * reimplements the insert. Idempotent: an existing record is reused and no row is
 * duplicated, so repeat calls return the same id. Annotated a non-destructive
 * idempotent write (`readonly:false`): it can insert a row, but only a benign empty
 * overrides record, and the operation has no further effect once the record exists.
 *
 * @since 0.4.0
 */
final class InitGlobalStyles implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/init-global-styles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Initialize Global Styles', 'abilities-catalog' ),
			'description'         => __( 'Ensures the active theme has a user global-styles record and returns its post ID, creating an empty record if none exists yet. Idempotent: returns the same ID on repeat calls. Call this before og-templates/update-global-styles when og-templates/get-global-styles reports no record (404) — for example to change colors or fonts on a freshly set up block theme.', 'abilities-catalog' ),
			'category'            => 'og-core-templates',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'The user global-styles post ID for the active theme (pass it to og-templates/get-global-styles or og-templates/update-global-styles).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: `edit_theme_options` (the catalog capability for global styles).
	 *
	 * For `wp_global_styles`, the create capability maps to `edit_theme_options` with no
	 * owner-vs-others split, so this coarse, object-independent check is exactly what
	 * editing global styles requires — never stricter, never weaker. The wrapped core
	 * create function does not check capabilities itself, so this gate is the guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create/read the global styles record.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability: resolve the active-theme global-styles id, creating it if absent.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{id:int}|\WP_Error The global-styles post id, or an error.
	 */
	public function execute( $input = null ) {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return new WP_Error(
				'global_styles_unavailable',
				__( 'Global styles are not available on this site.', 'abilities-catalog' ),
				array( 'status' => 501 )
			);
		}

		// Core create path: returns the existing user wp_global_styles post id for the
		// active theme, inserting an empty-overrides record when none exists. Reused, not
		// duplicated, on repeat calls (the resolver memoizes the id).
		$id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( $id <= 0 ) {
			return new WP_Error(
				'global_styles_unavailable',
				__( 'Could not create or resolve the global styles record for the active theme.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array( 'id' => $id );
	}
}
