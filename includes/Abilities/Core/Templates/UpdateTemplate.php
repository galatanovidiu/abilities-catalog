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
 * T2 destructive write ability: `templates/update-template`.
 *
 * Wraps `POST /wp/v2/templates/<id>` (for `wp_template`) or
 * `POST /wp/v2/template-parts/<id>` (for `wp_template_part`) via
 * `rest_do_request()`. The template id has the form `theme//slug` (e.g.
 * `twentytwentyfive//home`); the `//` separator is part of the route path and is
 * not URL-encoded. The outer ability `/run` call is POST (an update, not a
 * delete); the internal REST verb is also POST (EDITABLE).
 *
 * This is annotated DESTRUCTIVE because it creates or replaces a database
 * override of a site-wide template or template part. The change is recoverable
 * (the original file-based template remains and the override can be deleted) but
 * has a high blast radius: it can alter the layout of every page that uses the
 * template. The browser exposes it only when both the adapter write setting and
 * destructive setting are on.
 *
 * The `permission_callback` mirrors
 * {@see \WP_REST_Templates_Controller::update_item_permissions_check()}, which
 * delegates to `permissions_check()` and requires `edit_theme_options`. The REST
 * route re-checks the capability underneath (defense in depth) and handles
 * content sanitization.
 *
 * @since 0.3.0
 */
final class UpdateTemplate implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/update-template';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Template', 'abilities-catalog' ),
			'description'         => __( 'Updates a site-editor template or template part by its "theme//slug" id. Creates or replaces a database override that changes site-wide layout. Only the provided fields change; sending a field as an empty string clears it. Returns the type and edit_link (the Site Editor URL) — surface edit_link so a human can open the result.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The template id in "theme//slug" form (e.g. "twentytwentyfive//home"). Discover ids via templates/list-templates or templates/lookup-template.', 'abilities-catalog' ),
					),
					'post_type'   => array(
						'type'        => 'string',
						'enum'        => array( 'wp_template', 'wp_template_part' ),
						'default'     => 'wp_template',
						'description' => __( 'Which collection the id belongs to: "wp_template" or "wp_template_part".', 'abilities-catalog' ),
					),
					'content'     => array(
						'type'        => 'string',
						'description' => __( 'The raw template block markup (HTML allowed; sanitized by WordPress).', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The template title.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The template description.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'type' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'string',
						'description' => __( 'The template id in "theme//slug" form.', 'abilities-catalog' ),
					),
					'type'      => array(
						'type'        => 'string',
						'description' => __( 'The post type of the updated template: "wp_template" or "wp_template_part".', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting template status.', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The resulting template title.', 'abilities-catalog' ),
					),
					'area'      => array(
						'type'        => 'string',
						'description' => __( 'The template part area (e.g. "header", "footer", "uncategorized"). Empty for a wp_template.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The Site Editor URL where a human can open and edit the template.', 'abilities-catalog' ),
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
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the templates controller's update gate.
	 *
	 * {@see \WP_REST_Templates_Controller::update_item_permissions_check()}
	 * delegates to `permissions_check()`, which requires `edit_theme_options`.
	 * The capability is not object-level for templates. The REST route re-checks
	 * it underneath (defense in depth).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update site-editor templates.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * Branches the route on `post_type`: `wp_template_part` uses
	 * `/wp/v2/template-parts/<id>`, everything else uses `/wp/v2/templates/<id>`.
	 * The "theme//slug" id is part of the route path; the "//" is not URL-encoded.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped template, or the REST error.
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$id        = (string) ( $input['id'] ?? '' );
		$post_type = 'wp_template_part' === ( $input['post_type'] ?? 'wp_template' ) ? 'wp_template_part' : 'wp_template';
		$base      = 'wp_template_part' === $post_type ? 'template-parts' : 'templates';

		// The "theme//slug" id is part of the route path; do not URL-encode the "//".
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $base . '/' . $id );

		// String fields pass through to the REST route, which sanitizes them
		// (content via the block-markup pipeline, etc.). Pass a field only when the
		// caller actually supplied the key; an empty string is a deliberate clear,
		// so it is forwarded unchanged (core applies any present field, including
		// ''). This diverges from create-template, where a blank field is skipped.
		foreach ( array( 'content', 'title', 'description' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? '';
		}

		// Build the edit link from core's canonical helper so it matches the
		// registered template `_edit_link` and runs through the `get_edit_post_link`
		// filter. The update response carries `wp_id` as an int (the underlying
		// post ID).
		$wp_id     = (int) ( $data['wp_id'] ?? 0 );
		$edit_link = 0 === $wp_id
			? ''
			: (string) get_edit_post_link( $wp_id, 'raw' );

		return array(
			'id'        => (string) ( $data['id'] ?? $id ),
			'type'      => (string) ( $data['type'] ?? $post_type ),
			'status'    => (string) ( $data['status'] ?? '' ),
			'title'     => (string) $title,
			'area'      => (string) ( $data['area'] ?? '' ),
			'edit_link' => $edit_link,
		);
	}
}
