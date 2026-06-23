<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `themes/list-theme-mods`.
 *
 * Lists the active theme's customizer modifications ("theme mods") as a flat
 * name-to-value map. Theme mods are the per-theme settings the Customizer
 * stores in the `theme_mods_<stylesheet>` option (e.g. `custom_logo`,
 * `header_textcolor`, `background_color`, and any theme- or plugin-registered
 * setting). Wraps core `get_theme_mods()`.
 *
 * Return-shape notes:
 * - `get_theme_mods()` returns the stored array, or `false` only on very old
 *   data paths; since WP 5.9 it always returns an array. This ability normalizes
 *   a non-array result to an empty map defensively.
 * - The `mods` map is cast to an object so an empty result serializes as `{}`
 *   (a JSON object), not `[]`. A bare PHP empty array would serialize as `[]`
 *   and fail object-type output validation.
 * - Values are arbitrary (the Customizer stores scalars, arrays, and structured
 *   data), so the map's value type is the JSON-type union and each value is
 *   returned as-is.
 *
 * Classification rationale:
 * - `readonly` is true: it only reads the stored option.
 * - `destructive` is false and `idempotent` is true: a read changes nothing.
 *
 * No `meta.screen` is set: this is a read, and reads omit the deep-link screen.
 *
 * @since 0.6.0
 */
final class ListThemeMods implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'themes/list-theme-mods';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Theme Mods', 'abilities-catalog' ),
			'description'         => __( 'Returns the active theme\'s customizer modifications ("theme mods") as a name-to-value map, plus the active theme slug and a total count. Theme mods are the per-theme Customizer settings (e.g. custom_logo, header_textcolor, background_color, and any theme- or plugin-registered setting). The map is empty ({}) when the theme has no saved mods. Discover a single value with themes/get-theme-mod; change one with themes/set-theme-mod or themes/remove-theme-mod.', 'abilities-catalog' ),
			'category'            => 'themes',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'theme', 'mods', 'total' ),
				'properties'           => array(
					'theme' => array(
						'type'        => 'string',
						'description' => __( 'The active theme slug (stylesheet directory name) whose theme mods these are.', 'abilities-catalog' ),
					),
					'mods'  => array(
						'type'                 => 'object',
						'description'          => __( 'The theme mods as a name-to-value map. Keys are mod names; values are stored as-is and may be any JSON type (string, number, boolean, object, array, or null). An empty map means the theme has no saved mods.', 'abilities-catalog' ),
						'additionalProperties' => array(
							'type' => array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of theme mods in the map.', 'abilities-catalog' ),
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
	 * Permission check: the caller must be able to edit theme options.
	 *
	 * Theme mods are Customizer settings, gated in wp-admin by
	 * `edit_theme_options` (the capability that guards customize.php). This read
	 * mirrors that, so it is never weaker than the surface it reads from. The
	 * mods are stored per-site in the `theme_mods_<stylesheet>` option, so the
	 * per-site `edit_theme_options` capability is correct on multisite too — no
	 * network-admin check is needed.
	 *
	 * @param mixed $input The validated input data (none for this ability).
	 * @return bool True if the current user may edit theme options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by reading the active theme's theme mods.
	 *
	 * @param mixed $input The validated input data (none for this ability).
	 * @return array<string,mixed> The flat theme-mods result.
	 */
	public function execute( $input = null ) {
		$mods = get_theme_mods();
		if ( ! is_array( $mods ) ) {
			$mods = array();
		}

		return array(
			'theme' => get_stylesheet(),
			// Cast so an empty map serializes as {} (a JSON object), not [].
			'mods'  => (object) $mods,
			'total' => count( $mods ),
		);
	}
}
