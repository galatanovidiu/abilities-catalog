<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `themes/get-theme-mod`.
 *
 * Reads a single theme mod (a customizer setting) on the active theme by name,
 * reporting whether the mod is actually set and, when it is, its stored value.
 * Wraps core `get_theme_mods()` for the presence check and `get_theme_mod()` for
 * the value. Discover mod names with `themes/list-theme-mods`.
 *
 * Presence vs default — why two core calls: `get_theme_mod( $name )` returns the
 * theme's DEFAULT (filtered) value when the mod is unset, so it cannot tell "set
 * to X" apart from "unset, default happens to be X". This ability reports `is_set`
 * from the RAW map (`array_key_exists( $name, get_theme_mods() )`) and only calls
 * `get_theme_mod()` when the key is present, so `value` is null for an unset mod —
 * it reports absence honestly rather than leaking the theme default. The returned
 * value is the live, filtered value (core applies the `theme_mod_{$name}` filter).
 *
 * Classification rationale:
 * - `readonly` is true: this only reads stored theme-mod state.
 * - `destructive` is false: a read changes nothing.
 * - `idempotent` is true: repeating the read leaves state unchanged.
 *
 * No `meta.screen` is set: this is a read, and a `readonly` ability must carry no
 * `abilities_catalog_screen_links` entry (RegistryTest guard).
 *
 * @since 0.5.0
 */
final class GetThemeMod implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'themes/get-theme-mod';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Theme Mod', 'abilities-catalog' ),
			'description'         => __( 'Returns a single theme mod (a customizer setting) on the active theme by name, reporting is_set (whether the mod is actually stored) and its value. When the mod is not set, is_set is false and value is null — it does NOT return the theme default, so use is_set, not value, to tell "absent" from "set to null". Discover mod names with themes/list-theme-mods.', 'abilities-catalog' ),
			'category'            => 'themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The theme mod name to read (e.g. "header_textcolor", "custom_logo"). Discover the names of the theme mods that are set with themes/list-theme-mods.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'is_set', 'value' ),
				'properties'           => array(
					'name'   => array(
						'type'        => 'string',
						'description' => __( 'The theme mod name that was requested, echoed back.', 'abilities-catalog' ),
					),
					'is_set' => array(
						'type'        => 'boolean',
						'description' => __( 'True if the mod is actually stored on the active theme. False means it is unset, in which case value is null (not the theme default).', 'abilities-catalog' ),
					),
					'value'  => array(
						'type'        => array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
						'description' => __( 'The stored mod value (the live value after the theme_mod filter), kept as-is; any JSON type. Null when is_set is false — the theme default is deliberately NOT returned here.', 'abilities-catalog' ),
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
	 * Theme mods are the active theme's customizer settings, gated in wp-admin by
	 * `edit_theme_options`, so this read mirrors that capability. The data is
	 * per-site (stored in the `theme_mods_<theme>` option), so `edit_theme_options`
	 * is correct on multisite too — no network-admin capability is needed.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit theme options.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by reading the named theme mod.
	 *
	 * Presence is read from the raw `get_theme_mods()` map so an unset mod reports
	 * `value: null` rather than the theme default that `get_theme_mod()` would
	 * substitute. The value is read with `get_theme_mod()` only when the key is
	 * present, so it carries the live, filtered value.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The theme mod read result.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$name  = isset( $input['name'] ) ? (string) $input['name'] : '';

		$mods   = get_theme_mods();
		$is_set = array_key_exists( $name, $mods );

		return array(
			'name'   => $name,
			'is_set' => $is_set,
			'value'  => $is_set ? get_theme_mod( $name ) : null,
		);
	}
}
