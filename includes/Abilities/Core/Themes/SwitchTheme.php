<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `themes/switch-theme`.
 *
 * Net-new (no themes REST update route): validates the requested stylesheet with
 * `wp_get_theme()->exists()`, rejects a present-but-broken theme via
 * `WP_Theme::errors()`, preflights `validate_theme_requirements()` to avoid core's
 * `wp_die()` on incompatible WP/PHP versions, then activates it with `switch_theme()`.
 * Switching the theme changes the whole front end, so this ability is annotated
 * destructive and is exposed to the browser only when the adapter's write AND
 * destructive settings are both on. All guards run before any mutation; `switch_theme()`
 * is never called for a missing, broken, or incompatible theme. The outer `/run` call
 * is POST.
 *
 * @since 0.3.0
 */
final class SwitchTheme implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'themes/switch-theme';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Switch Theme', 'abilities-catalog' ),
			'description'         => __( 'Activates an installed theme by its stylesheet (directory name). Switching the theme changes the whole front end.', 'abilities-catalog' ),
			'category'            => 'themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'stylesheet' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The theme directory name (stylesheet) to activate, for example "twentytwentyfive".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'stylesheet' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'success', 'active_theme', 'previous_stylesheet', 'name' ),
				'properties'           => array(
					'success'             => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the theme was switched.', 'abilities-catalog' ),
					),
					'active_theme'        => array(
						'type'        => 'string',
						'description' => __( 'The active theme stylesheet after the switch.', 'abilities-catalog' ),
					),
					'previous_stylesheet' => array(
						'type'        => 'string',
						'description' => __( 'The stylesheet that was active before the switch, so the change can be explained or undone.', 'abilities-catalog' ),
					),
					'name'                => array(
						'type'        => 'string',
						'description' => __( 'The display name of the newly active theme.', 'abilities-catalog' ),
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
				'screen'       => 'themes.php',
			),
		);
	}

	/**
	 * Permission check: the current user may switch themes.
	 *
	 * Encodes the catalog capability for `themes/switch-theme` (`switch_themes`).
	 * Input shape is enforced by the schema (`required` + `minLength`), so this
	 * callback only checks the capability.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may switch themes.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'switch_themes' );
	}

	/**
	 * Executes the ability by validating the theme then switching to it.
	 *
	 * All guards run before any mutation: a missing theme (404), a present-but-broken
	 * theme (422), or a theme that fails `validate_theme_requirements()` (incompatible
	 * WP/PHP, 409) returns a `WP_Error` and `switch_theme()` is never called. The last
	 * check is a preflight because core's `switch_theme()` calls `wp_die()` on the same
	 * failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Switch result, or an error.
	 */
	public function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$stylesheet = isset( $input['stylesheet'] ) ? (string) $input['stylesheet'] : '';

		if ( '' === $stylesheet ) {
			return new WP_Error(
				'webmcp_missing_stylesheet',
				__( 'A theme stylesheet is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error(
				'webmcp_theme_not_found',
				/* translators: %s: theme stylesheet. */
				sprintf( __( 'No installed theme found for stylesheet "%s".', 'abilities-catalog' ), $stylesheet ),
				array( 'status' => 404 )
			);
		}

		// `exists()` is true for a broken theme (only `theme_not_found` makes it false).
		// Refuse a present-but-broken theme so the site is never switched into errors.
		$errors = $theme->errors();
		if ( $errors instanceof WP_Error ) {
			$data = $errors->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$errors->add_data( array( 'status' => 422 ) );
			}

			return $errors;
		}

		// Preflight the WP/PHP requirement check core runs inside `switch_theme()`;
		// core calls `wp_die()` on failure, which would terminate the request.
		$requirements = validate_theme_requirements( $theme->get_stylesheet() );
		if ( is_wp_error( $requirements ) ) {
			$data = $requirements->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$requirements->add_data( array( 'status' => 409 ) );
			}

			return $requirements;
		}

		$previous_stylesheet = (string) get_stylesheet();

		switch_theme( $stylesheet );

		return array(
			'success'             => true,
			'active_theme'        => (string) get_stylesheet(),
			'previous_stylesheet' => $previous_stylesheet,
			'name'                => (string) $theme->get( 'Name' ),
		);
	}
}
