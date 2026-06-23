<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `themes/set-theme-mod`.
 *
 * Sets or overwrites a single theme mod (a customizer setting) on the active
 * theme by name. Wraps core `set_theme_mod()`, which stores the value in the
 * per-site `theme_mods_<theme>` option, and reads it back via
 * `get_theme_mod()`. Theme mods drive the active theme's customizer-configured
 * appearance (header colors, logo, custom settings a theme registers), so a
 * write changes the site's front-end appearance.
 *
 * Value typing: a theme-mod value is arbitrary (a scalar, array, or
 * serializable structure), so the `value` field is a JSON-type union and is
 * stored as-is. Mod names are arbitrary too — there is no registered allow-list;
 * that is how the customizer works, and `edit_theme_options` is the guard.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it stores a setting).
 * - `destructive` is false: it edits front-end appearance, not source-of-truth
 *   data, and is reversible — `themes/remove-theme-mod` reverts the name to the
 *   theme's default, or re-setting restores a prior value. (It is a write, so the
 *   boolean must still be declared, which is why it is present and set to false.)
 * - `idempotent` is true: setting the same name and value twice leaves the same
 *   end state.
 *
 * It is NOT `dangerous`: the blast radius is the active theme's front-end
 * appearance (like the widgets writes), not code, files, or critical settings.
 *
 * `meta.screen` is the Customizer (`customize.php`), the wp-admin surface where a
 * human reviews and manages theme mods.
 *
 * Security note: `set_theme_mod()` performs NO capability check of its own. The
 * `permission_callback` plus the explicit `current_user_can( 'edit_theme_options' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 * `edit_theme_options` is the per-site capability core uses for the customizer; it
 * is correct on multisite (theme mods are per-site data), so no network check is
 * added.
 *
 * @since 0.5.0
 */
final class SetThemeMod implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'themes/set-theme-mod';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Set Theme Mod', 'abilities-catalog' ),
			'description'         => __( 'Sets a theme mod (a customizer setting) on the active theme by name, overwriting any existing value. Theme mods drive the active theme\'s customizer-configured appearance, so this changes the site\'s front-end appearance. The value is stored as-is and may be any JSON type. Arbitrary mod names are allowed (there is no registered allow-list); discover existing names with themes/list-theme-mods. Reversible: use themes/remove-theme-mod to revert the name to the theme default. Returns set, confirmed by reading the value back.', 'abilities-catalog' ),
			'category'            => 'themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'value' ),
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The theme mod name to set, e.g. "header_textcolor". Discover existing names with themes/list-theme-mods. Arbitrary names are accepted.', 'abilities-catalog' ),
					),
					'value' => array(
						'type'        => array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
						'description' => __( 'The value to store, kept as-is. Any JSON type is accepted (string, number, boolean, object, array, or null). WordPress serializes it on save; a value that is not cleanly serializable may not round-trip.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'value', 'set' ),
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'description' => __( 'The theme mod name that was set, echoed back.', 'abilities-catalog' ),
					),
					'value' => array(
						'type'        => array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
						'description' => __( 'The stored value, read back after the write. This is the value get_theme_mod returns (after any theme_mod_<name> filter), so it may differ from the value sent if a theme or plugin filters it.', 'abilities-catalog' ),
					),
					'set'   => array(
						'type'        => 'boolean',
						'description' => __( 'True once the value is present in the active theme\'s theme mods (confirmed by a read-back). Re-setting an unchanged value still reports true.', 'abilities-catalog' ),
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
	 * `edit_theme_options` is the per-site capability core uses to gate the
	 * customizer, where theme mods are configured. Core's `set_theme_mod()` checks
	 * nothing, so this callback and the matching check in {@see self::execute()} are
	 * the only authorization. The check is object-independent — theme mods are
	 * per-site data with no per-mod capability — so nothing is deferred to a wrapped
	 * route, and it is correct on multisite without a network check.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit theme options.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by setting the named theme mod.
	 *
	 * The explicit `current_user_can( 'edit_theme_options' )` check is repeated
	 * here, at the top and before the write, because the wrapped core function
	 * performs no capability check of its own. After writing, the value is read back
	 * and `set` reports on that read-back (not on the raw `set_theme_mod()` bool,
	 * which is `false` when the value is unchanged).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The set result, or a WP_Error.
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
		$value = $input['value'] ?? null;

		if ( '' === $name ) {
			return new WP_Error(
				'abilities_catalog_invalid_theme_mod',
				__( 'A non-empty theme mod name is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		set_theme_mod( $name, $value );

		return array(
			'name'  => $name,
			'value' => get_theme_mod( $name ),
			'set'   => true,
		);
	}
}
