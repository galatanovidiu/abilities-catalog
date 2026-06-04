<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `templates/get-template`.
 *
 * Wraps `GET /wp/v2/templates/<id>` or `GET /wp/v2/template-parts/<id>` via
 * `rest_do_request()`. The template id has the form `theme//slug`; the `//`
 * separator is part of the route path and is not URL-encoded. Read-only.
 *
 * @since 0.1.0
 */
final class GetTemplate implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/get-template';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Template', 'abilities-catalog' ),
			'description'         => __( 'Returns a single site-editor template or template part by its "theme//slug" id.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'        => array(
						'type'        => 'string',
						'description' => __( 'The template id in "theme//slug" form (e.g. "twentytwentyfive//index").', 'abilities-catalog' ),
					),
					'post_type' => array(
						'type'        => 'string',
						'enum'        => array( 'wp_template', 'wp_template_part' ),
						'default'     => 'wp_template',
						'description' => __( 'Which collection the id belongs to: "wp_template" or "wp_template_part".', 'abilities-catalog' ),
					),
					'context'   => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'string',
						'description' => __( 'The template id in "theme//slug" form.', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The template slug.', 'abilities-catalog' ),
					),
					'theme'       => array(
						'type'        => 'string',
						'description' => __( 'The theme the template belongs to.', 'abilities-catalog' ),
					),
					'type'        => array(
						'type'        => 'string',
						'description' => __( 'The post type ("wp_template" or "wp_template_part").', 'abilities-catalog' ),
					),
					'source'      => array(
						'type'        => 'string',
						'description' => __( 'The source: "theme" (file-based) or "custom" (DB override).', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The rendered template title.', 'abilities-catalog' ),
					),
					'content'     => array(
						'type'        => 'string',
						'description' => __( 'The raw template block markup.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The template description.', 'abilities-catalog' ),
					),
					'status'      => array(
						'type'        => 'string',
						'description' => __( 'The template status.', 'abilities-catalog' ),
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
	 * Permission check: `edit_theme_options` (catalog capability for templates).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read site-editor templates.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped template, or the REST error.
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$id        = (string) ( $input['id'] ?? '' );
		$post_type = $input['post_type'] ?? 'wp_template';
		$base      = 'wp_template_part' === $post_type ? 'template-parts' : 'templates';

		// The "theme//slug" id is part of the route path; do not URL-encode the "//".
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . $base . '/' . $id );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? '';
		}

		$content = $data['content'] ?? '';
		if ( is_array( $content ) ) {
			$content = $content['raw'] ?? ( $content['rendered'] ?? '' );
		}

		return array(
			'id'          => (string) ( $data['id'] ?? $id ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'theme'       => (string) ( $data['theme'] ?? '' ),
			'type'        => (string) ( $data['type'] ?? $post_type ),
			'source'      => (string) ( $data['source'] ?? '' ),
			'title'       => (string) $title,
			'content'     => (string) $content,
			'description' => (string) ( $data['description'] ?? '' ),
			'status'      => (string) ( $data['status'] ?? '' ),
		);
	}
}
