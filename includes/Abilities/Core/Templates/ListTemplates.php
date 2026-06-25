<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/list-templates`.
 *
 * Wraps `GET /wp/v2/templates` or `GET /wp/v2/template-parts` via
 * `rest_do_request()`, selected by the `post_type` input. Returns the
 * registered/customized site-editor templates for the active theme. Read-only.
 *
 * @since 0.1.0
 */
final class ListTemplates implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/list-templates';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Templates', 'abilities-catalog' ),
			'description'         => __( 'Lists site-editor block templates or template parts for the active theme.', 'abilities-catalog' ),
			'category'            => 'og-core-templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'enum'        => array( 'wp_template', 'wp_template_part' ),
						'default'     => 'wp_template',
						'description' => __( 'Which collection to list: "wp_template" (templates) or "wp_template_part" (template parts).', 'abilities-catalog' ),
					),
					'context'   => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
					'area'      => array(
						'type'        => 'string',
						'enum'        => array( 'header', 'footer', 'sidebar', 'uncategorized' ),
						'description' => __( 'Template-part area filter ("header", "footer", "sidebar", or "uncategorized"). Applies only when post_type is "wp_template_part"; ignored otherwise.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id' ),
							'properties'           => array(
								'id'              => array(
									'type'        => 'string',
									'description' => __( 'The template id in "theme//slug" form.', 'abilities-catalog' ),
								),
								'slug'            => array(
									'type'        => 'string',
									'description' => __( 'The template slug.', 'abilities-catalog' ),
								),
								'theme'           => array(
									'type'        => 'string',
									'description' => __( 'The theme the template belongs to.', 'abilities-catalog' ),
								),
								'type'            => array(
									'type'        => 'string',
									'description' => __( 'The post type ("wp_template" or "wp_template_part").', 'abilities-catalog' ),
								),
								'source'          => array(
									'type'        => 'string',
									'description' => __( 'The source: "theme" (file-based) or "custom" (DB override).', 'abilities-catalog' ),
								),
								'title'           => array(
									'type'        => 'string',
									'description' => __( 'The rendered template title.', 'abilities-catalog' ),
								),
								'status'          => array(
									'type'        => 'string',
									'description' => __( 'The template status.', 'abilities-catalog' ),
								),
								'original_source' => array(
									'type'        => 'string',
									'description' => __( 'The original provenance: "theme", "plugin", "site", or "user". Distinguishes a user-created template from a customized one.', 'abilities-catalog' ),
								),
								'area'            => array(
									'type'        => 'string',
									'description' => __( 'For template parts only: the area ("header", "footer", or "uncategorized").', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
						'description' => __( 'The list of templates or template parts.', 'abilities-catalog' ),
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
	 * @return array<string,mixed>|\WP_Error The collection, or the REST error.
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$post_type = $input['post_type'] ?? 'wp_template';
		$is_part   = 'wp_template_part' === $post_type;
		$route     = $is_part ? '/wp/v2/template-parts' : '/wp/v2/templates';

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		// area is a template-part-only filter; ignore it for the templates route.
		if ( $is_part && isset( $input['area'] ) && '' !== $input['area'] ) {
			$request->set_param( 'area', (string) $input['area'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $row ) {
			$title = $row['title'] ?? '';
			if ( is_array( $title ) ) {
				$title = $title['rendered'] ?? '';
			}

			$item = array(
				'id'     => (string) ( $row['id'] ?? '' ),
				'slug'   => (string) ( $row['slug'] ?? '' ),
				'theme'  => (string) ( $row['theme'] ?? '' ),
				'type'   => (string) ( $row['type'] ?? $post_type ),
				'source' => (string) ( $row['source'] ?? '' ),
				'title'  => (string) $title,
				'status' => (string) ( $row['status'] ?? '' ),
			);

			// original_source distinguishes user-created from customized
			// templates; area identifies a template part.
			if ( isset( $row['original_source'] ) && '' !== $row['original_source'] ) {
				$item['original_source'] = (string) $row['original_source'];
			}
			if ( isset( $row['area'] ) && '' !== $row['area'] ) {
				$item['area'] = (string) $row['area'];
			}

			$items[] = $item;
		}

		return array(
			'items' => $items,
		);
	}
}
