<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-settings/update-permalinks`.
 *
 * Updates the Permalink Settings screen options — `permalink_structure`,
 * `category_base`, and `tag_base` (exactly the option keys the matching read
 * ability {@see GetPermalinks} reads) — then rebuilds the rewrite rules.
 *
 * This is annotated destructive because it changes how every front-end URL is
 * generated for the whole site. After writing the options the ability MUST call
 * `flush_rewrite_rules(true)`: without it, the stored rewrite rules still match
 * the old structure and every permalink 404s. The flush is the second,
 * mandatory half of the operation, mirroring what the wp-admin Permalink
 * Settings screen does after saving (`flush_rewrite_rules()` in
 * wp-admin/options-permalink.php).
 *
 * Net-new write: there is no REST route for permalink settings, so the options
 * are written directly. Each value is sanitized with `sanitize_option()` (the
 * same callback the Settings API would run) and the input is allow-listed to the
 * three permalink keys only. A non-empty `permalink_structure` must contain at
 * least one structure tag (e.g. `%postname%`), matching the core validation in
 * `sanitize_option()`; an empty string selects plain permalinks.
 *
 * The cap mirrors the wp-admin Permalink Settings screen, which gates on
 * `manage_options` (wp-admin/options-permalink.php).
 *
 * @since 0.4.0
 */
final class UpdatePermalinks implements Ability {

	/**
	 * Option keys this ability is allowed to write.
	 *
	 * Exactly the keys {@see GetPermalinks} reads. The allow-list is the only
	 * surface; any other input key is ignored by construction.
	 *
	 * @var string[]
	 */
	private const ALLOWED_OPTIONS = array( 'permalink_structure', 'category_base', 'tag_base' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-settings/update-permalinks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Permalink Settings', 'abilities-catalog' ),
			'description'         => __( 'Updates the permalink structure and the category and tag base prefixes, then rebuilds the rewrite rules. Provide at least one field. A non-empty permalink structure must contain at least one structure tag, e.g. %postname%; an empty structure selects plain permalinks.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'permalink_structure' => array(
						'type'        => 'string',
						'description' => __( 'The permalink structure tag string (e.g. "/%postname%/"); empty string selects plain permalinks.', 'abilities-catalog' ),
					),
					'category_base'       => array(
						'type'        => 'string',
						'description' => __( 'The base prefix for category URLs; empty string restores the default.', 'abilities-catalog' ),
					),
					'tag_base'            => array(
						'type'        => 'string',
						'description' => __( 'The base prefix for tag URLs; empty string restores the default.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'permalink_structure', 'category_base', 'tag_base' ),
				'properties'           => array(
					'permalink_structure' => array(
						'type'        => 'string',
						'description' => __( 'The resulting permalink structure tag string.', 'abilities-catalog' ),
					),
					'category_base'       => array(
						'type'        => 'string',
						'description' => __( 'The resulting category base prefix.', 'abilities-catalog' ),
					),
					'tag_base'            => array(
						'type'        => 'string',
						'description' => __( 'The resulting tag base prefix.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'options-permalink.php',
			),
		);
	}

	/**
	 * Permission check: the current user may manage options.
	 *
	 * Mirrors the wp-admin Permalink Settings screen
	 * (wp-admin/options-permalink.php), which gates on `manage_options`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by writing the permalink options and flushing rules.
	 *
	 * Requires at least one allow-listed field. Sanitizes each value with
	 * `sanitize_option()`, validates a non-empty permalink structure, writes the
	 * options, then calls `flush_rewrite_rules(true)` to rebuild the rewrite
	 * rules (mandatory — without it every front-end URL 404s). Reads the stored
	 * values back and returns them.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,string>|\WP_Error The resulting permalink values, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$updates = array();
		foreach ( self::ALLOWED_OPTIONS as $option ) {
			if ( ! array_key_exists( $option, $input ) ) {
				continue;
			}

			$updates[ $option ] = sanitize_option( $option, (string) $input[ $option ] );
		}

		if ( array() === $updates ) {
			return new WP_Error(
				'no_fields',
				__( 'Provide at least one of permalink_structure, category_base, or tag_base.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		// A non-empty permalink structure must contain at least one structure tag
		// (e.g. %postname%), matching core sanitize_option() validation; an empty
		// string is plain.
		if ( isset( $updates['permalink_structure'] )
			&& '' !== $updates['permalink_structure']
			&& ! preg_match( '/%[^\/%]+%/', $updates['permalink_structure'] )
		) {
			return new WP_Error(
				'invalid_permalink_structure',
				__( 'A non-empty permalink structure must contain at least one structure tag, e.g. %postname%.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $updates as $option => $value ) {
			update_option( $option, $value );
		}

		// Mandatory: rebuild the stored rewrite rules to match the new structure.
		// The global $wp_rewrite was initialized from the OLD options earlier in
		// the request, so it must be re-initialized from the just-written options
		// before flushing — otherwise the rules regenerate from the stale structure
		// and every front-end URL would 404.
		global $wp_rewrite;
		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			$wp_rewrite->init();
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Rebuilding rewrite rules is the explicit purpose of this admin-triggered permalink update, not a per-request call.
		flush_rewrite_rules( true );

		return array(
			'permalink_structure' => (string) ( get_option( 'permalink_structure' ) ?? '' ),
			'category_base'       => (string) ( get_option( 'category_base' ) ?? '' ),
			'tag_base'            => (string) ( get_option( 'tag_base' ) ?? '' ),
		);
	}
}
