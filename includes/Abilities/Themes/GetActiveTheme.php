<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `themes/get-active-theme`.
 *
 * Wraps `GET /wp/v2/themes` filtered to the active theme via `rest_do_request()`
 * and shapes the first item into a flat field set. Falls back to `wp_get_theme()`
 * when the active theme cannot be resolved through REST.
 *
 * @since 0.1.0
 */
final class GetActiveTheme implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'themes/get-active-theme';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Active Theme', 'abilities-catalog' ),
			'description'         => __( 'Returns the currently active theme, including its stylesheet, name, version, and author.', 'abilities-catalog' ),
			'category'            => 'themes',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'stylesheet', 'name' ),
				'properties'           => array(
					'stylesheet'     => array(
						'type'        => 'string',
						'description' => __( 'The active theme directory name (stylesheet).', 'abilities-catalog' ),
					),
					'template'       => array(
						'type'        => 'string',
						'description' => __( 'The template directory name (the parent theme for a child theme).', 'abilities-catalog' ),
					),
					'name'           => array(
						'type'        => 'string',
						'description' => __( 'The theme display name.', 'abilities-catalog' ),
					),
					'version'        => array(
						'type'        => 'string',
						'description' => __( 'The theme version.', 'abilities-catalog' ),
					),
					'status'         => array(
						'type'        => 'string',
						'description' => __( 'The theme status (e.g. "active").', 'abilities-catalog' ),
					),
					'is_block_theme' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the theme is a block theme.', 'abilities-catalog' ),
					),
					'author'         => array(
						'type'        => 'string',
						'description' => __( 'The theme author.', 'abilities-catalog' ),
					),
					'theme_uri'      => array(
						'type'        => 'string',
						'description' => __( 'The theme home page URL.', 'abilities-catalog' ),
					),
					'description'    => array(
						'type'        => 'string',
						'description' => __( 'The theme description.', 'abilities-catalog' ),
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
	 * Permission check: ability to manage themes or theme options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the active theme.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'switch_themes' ) || current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat active-theme fields, or the REST error.
	 */
	public function execute( $input = null ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/themes' );
		$request->set_param( 'status', 'active' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$items = rest_get_server()->response_to_data( $response, false );
		$item  = is_array( $items ) && isset( $items[0] ) && is_array( $items[0] ) ? $items[0] : null;

		if ( null === $item ) {
			return $this->fromCore();
		}

		return array(
			'stylesheet'     => (string) ( $item['stylesheet'] ?? '' ),
			'template'       => (string) ( $item['template'] ?? '' ),
			'name'           => $this->renderedField( $item['name'] ?? '' ),
			'version'        => (string) ( $item['version'] ?? '' ),
			'status'         => (string) ( $item['status'] ?? 'active' ),
			'is_block_theme' => (bool) ( $item['is_block_theme'] ?? false ),
			'author'         => $this->renderedField( $item['author'] ?? '' ),
			'theme_uri'      => $this->renderedField( $item['theme_uri'] ?? '' ),
			'description'    => $this->renderedField( $item['description'] ?? '' ),
		);
	}

	/**
	 * Builds the field set from core when REST does not return the active theme.
	 *
	 * @return array<string,mixed> Flat active-theme fields.
	 */
	private function fromCore(): array {
		$theme = wp_get_theme();

		return array(
			'stylesheet'     => (string) $theme->get_stylesheet(),
			'template'       => (string) $theme->get_template(),
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'status'         => 'active',
			'is_block_theme' => $theme->is_block_theme(),
			'author'         => (string) $theme->get( 'Author' ),
			'theme_uri'      => (string) $theme->get( 'ThemeURI' ),
			'description'    => (string) $theme->get( 'Description' ),
		);
	}

	/**
	 * Resolves a themes-route field that may be a `['rendered' => string]` object.
	 *
	 * @param mixed $field The raw field value from the REST item.
	 * @return string The rendered string value.
	 */
	private function renderedField( $field ): string {
		if ( is_array( $field ) ) {
			return (string) ( $field['rendered'] ?? '' );
		}

		return (string) $field;
	}
}
