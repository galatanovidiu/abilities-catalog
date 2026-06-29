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
 * Read ability: `og-media/get-media`.
 *
 * Wraps `GET /wp/v2/media/<id>` via the Abilities REST Adapter. The input schema
 * is DERIVED from the route — the path capture `id` plus the route's query args,
 * including `context` (`view`/`edit`), where `edit` requires edit access. The
 * output is OVERRIDDEN to the catalog's flat field set through {@see shapeOutput()}.
 * Permission delegates to the route's own check (no `require_permission` floor is
 * set), so visibility follows REST: public reads of published (or published-parent)
 * attachments, `edit_post` for `edit` context, and denial of private items.
 *
 * @since 0.1.0
 */
final class GetMedia implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-media/get-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/media/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Media', 'abilities-catalog' ),
				'description'     => __( 'Returns a single media library item by ID, including its source URL, alt text, and media details.', 'abilities-catalog' ),
				'category'        => 'og-core-media',
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'source_url' ),
					'properties'           => array(
						'id'            => array(
							'type'        => 'integer',
							'description' => __( 'The attachment ID.', 'abilities-catalog' ),
						),
						'title'         => array(
							'type'        => 'string',
							'description' => __( 'The rendered media title.', 'abilities-catalog' ),
						),
						'alt_text'      => array(
							'type'        => 'string',
							'description' => __( 'Alternative text for the media item.', 'abilities-catalog' ),
						),
						'caption'       => array(
							'type'        => 'string',
							'description' => __( 'The rendered caption.', 'abilities-catalog' ),
						),
						'description'   => array(
							'type'        => 'string',
							'description' => __( 'The rendered description.', 'abilities-catalog' ),
						),
						'source_url'    => array(
							'type'        => 'string',
							'description' => __( 'The direct URL of the media file.', 'abilities-catalog' ),
						),
						'media_type'    => array(
							'type'        => 'string',
							'description' => __( 'The media type (e.g. "image", "file").', 'abilities-catalog' ),
						),
						'mime_type'     => array(
							'type'        => 'string',
							'description' => __( 'The MIME type of the media file.', 'abilities-catalog' ),
						),
						'media_details' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'Media-specific metadata (dimensions, sizes, etc.).', 'abilities-catalog' ),
						),
						'post'          => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'The ID of the post the media is attached to, or null if unattached.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Flattens the REST media body to the catalog's 10-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST media body. `title`, `caption`, and `description` are un-nested from their
	 * `{ rendered: ... }` shape. `media_details` is cast to a `stdClass` when REST
	 * returns it empty, so it serializes as `{}` (not `[]`) under the `type: object`
	 * output schema. `post` is preserved as `null` for an unattached item rather than
	 * flattened to `0`. `$input` and `$response` are part of the callback signature
	 * but unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST media body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat media fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'            => (int) ( $data['id'] ?? 0 ),
			'title'         => (string) ( $data['title']['rendered'] ?? '' ),
			'alt_text'      => (string) ( $data['alt_text'] ?? '' ),
			'caption'       => (string) ( $data['caption']['rendered'] ?? '' ),
			'description'   => (string) ( $data['description']['rendered'] ?? '' ),
			'source_url'    => (string) ( $data['source_url'] ?? '' ),
			'media_type'    => (string) ( $data['media_type'] ?? '' ),
			'mime_type'     => (string) ( $data['mime_type'] ?? '' ),
			'media_details' => is_array( $data['media_details'] ?? null ) && array() !== $data['media_details'] ? $data['media_details'] : (object) array(),
			'post'          => isset( $data['post'] ) ? (int) $data['post'] : null,
		);
	}
}
