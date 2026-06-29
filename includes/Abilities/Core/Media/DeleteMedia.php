<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-media/delete-media`.
 *
 * Wraps `DELETE /wp/v2/media/<id>` via the Abilities REST Adapter, permanently
 * deleting the attachment (bypassing Trash). The input schema is OVERRIDDEN to a
 * single required `id`; `force=true` is injected post-validation by
 * {@see injectForce()}, so the caller never passes it (attachments cannot be
 * trashed, so the route needs `force` to delete at all). The output is OVERRIDDEN
 * to the catalog's flat field set through {@see shapeOutput()}, which reports the
 * removed item's identity from the route's own `previous` block.
 *
 * Permission delegates to the route's own object-level `delete_post` check (no
 * `require_permission` floor is set); this ability never calls
 * `wp_delete_attachment()` directly and surfaces the route's `WP_Error` unchanged
 * (`rest_post_invalid_id` 404, `rest_cannot_delete` 403).
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteMedia implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-media/delete-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/media/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Media', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes a media library item by ID. The deletion bypasses Trash (force), so it cannot be undone, and clears any featured-image references to the item.', 'abilities-catalog' ),
				'category'        => 'og-core-media',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The attachment (media item) ID to permanently delete.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'injectForce' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'id' ),
					'properties'           => array(
						'deleted'             => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the media item was permanently deleted.', 'abilities-catalog' ),
						),
						'id'                  => array(
							'type'        => 'integer',
							'description' => __( 'The deleted attachment ID.', 'abilities-catalog' ),
						),
						'previous_title'      => array(
							'type'        => 'string',
							'description' => __( 'The title of the deleted media item.', 'abilities-catalog' ),
						),
						'previous_source_url' => array(
							'type'        => 'string',
							'description' => __( 'The file URL of the deleted media item.', 'abilities-catalog' ),
						),
						'previous_mime_type'  => array(
							'type'        => 'string',
							'description' => __( 'The MIME type of the deleted media item.', 'abilities-catalog' ),
						),
						'previous_media_type' => array(
							'type'        => 'string',
							'description' => __( 'The media type (image or file) of the deleted media item.', 'abilities-catalog' ),
						),
						'previous_alt_text'   => array(
							'type'        => 'string',
							'description' => __( 'The alternative text of the deleted media item.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'upload.php',
				),
			)
		);
	}

	/**
	 * Injects `force=true` so the route permanently deletes the attachment.
	 *
	 * Wired as the adapter's `input_callback`, it runs once after the ability
	 * validates input against its schema and before dispatch. Attachments cannot be
	 * trashed, so without `force` the route would refuse the delete; the caller never
	 * passes it (the input schema does not expose it).
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The params with `force` forced to true.
	 */
	public function injectForce( array $params ): array {
		$params['force'] = true;

		return $params;
	}

	/**
	 * Flattens the REST delete body to the catalog's deleted-media field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST delete body (`{ deleted, previous: { ... } }`). `id` comes from the
	 * original ability input (the route's delete body does not echo it back);
	 * `previous_title` is un-nested from `previous.title.rendered`; the other
	 * `previous_*` fields copy from `previous` with a type cast and a safe default.
	 * `$response` is part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The REST delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat deleted-media fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data     = is_array( $data ) ? $data : array();
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();
		$title    = is_array( $previous['title'] ?? null ) ? $previous['title'] : array();

		return array(
			'deleted'             => (bool) ( $data['deleted'] ?? false ),
			'id'                  => (int) ( $input['id'] ?? 0 ),
			'previous_title'      => (string) ( $title['rendered'] ?? '' ),
			'previous_source_url' => (string) ( $previous['source_url'] ?? '' ),
			'previous_mime_type'  => (string) ( $previous['mime_type'] ?? '' ),
			'previous_media_type' => (string) ( $previous['media_type'] ?? '' ),
			'previous_alt_text'   => (string) ( $previous['alt_text'] ?? '' ),
		);
	}
}
