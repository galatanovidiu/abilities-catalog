<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-themes/remove-theme-mod`.
 *
 * Removes one theme modification ("theme mod" — a customizer setting) by name
 * from the active theme, reverting that setting to the theme's built-in default.
 * Wraps core `remove_theme_mod()`. Theme mods are stored per-site in the
 * `theme_mods_<theme>` option, so this is per-site state even on multisite.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it removes a stored customizer value).
 * - `destructive` is false: it only reverts the named mod to the theme's default
 *   and is reversible — re-set the same value with `og-themes/set-theme-mod`. (It is
 *   a write, so the boolean must still be declared, hence present and set to false.)
 * - `idempotent` is true: removing an already-unset mod is a no-op; removing the
 *   same name twice leaves the same end state (the mod is not set).
 *
 * The returned `removed` flag reports whether a mod was actually present before
 * the call: true if one was removed, false if the name was already unset. A false
 * result is NOT an error — it is the honest no-op signal.
 *
 * `meta.screen` is `customize.php` (the Customizer), the wp-admin surface a human
 * uses to review and change theme mods.
 *
 * Security note: core `remove_theme_mod()` performs NO capability check of its own.
 * The `permission_callback` plus the explicit `current_user_can( 'edit_theme_options' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 * `edit_theme_options` is the per-site capability that gates customizer changes.
 *
 * @since 0.6.0
 */
final class RemoveThemeMod implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-themes/remove-theme-mod';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Remove Theme Mod', 'abilities-catalog' ),
			'description'         => __( 'Removes one theme modification (a customizer setting) by name from the active theme, reverting it to the theme\'s default. This changes front-end appearance and is reversible: re-apply the value with og-themes/set-theme-mod. A false "removed" result is not an error: it means no mod was set under that name, so nothing changed. Discover mod names with og-themes/list-theme-mods.', 'abilities-catalog' ),
			'category'            => 'og-core-themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The theme mod (customizer setting) name to remove, e.g. "background_color". Discover the set names with og-themes/list-theme-mods.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'removed' ),
				'properties'           => array(
					'name'    => array(
						'type'        => 'string',
						'description' => __( 'The theme mod name that was targeted, echoed back.', 'abilities-catalog' ),
					),
					'removed' => array(
						'type'        => 'boolean',
						'description' => __( 'True if a mod was set under this name and has been removed (reverting it to the theme default). False means nothing was set under that name, so nothing changed. False is not an error.', 'abilities-catalog' ),
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
				'screen'       => 'customize.php',
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to edit theme options.
	 *
	 * `edit_theme_options` is the per-site capability that gates customizer changes,
	 * which is what theme mods are. Core's `remove_theme_mod()` checks nothing, so
	 * this callback and the matching check in {@see self::execute()} are the only
	 * authorization. The check is object-independent (there is no per-mod capability),
	 * so nothing is deferred to a wrapped route. On multisite this stays correct:
	 * theme mods are per-site data, so the per-site capability is the right guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit theme options.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by removing the named theme mod.
	 *
	 * The explicit `current_user_can( 'edit_theme_options' )` check is repeated here,
	 * at the top and before reading or mutating state, because the wrapped core
	 * function performs no capability check of its own. The `removed` flag is computed
	 * BEFORE the removal by checking whether the name is present in the current mods,
	 * so a false result honestly reports "nothing was set" rather than an error.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The remove result, or a WP_Error.
	 */
	public function execute( $input ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_edit_theme',
				__( 'You are not allowed to edit theme options.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input = is_array( $input ) ? $input : array();
		$name  = isset( $input['name'] ) ? (string) $input['name'] : '';

		// Determine whether a mod is set under this name BEFORE removing it, so the
		// `removed` flag truthfully reports whether anything changed.
		$is_set = array_key_exists( $name, (array) get_theme_mods() );

		remove_theme_mod( $name );

		return array(
			'name'    => $name,
			'removed' => $is_set,
		);
	}
}
