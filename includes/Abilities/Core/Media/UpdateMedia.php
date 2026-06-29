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
 * T2 non-destructive write ability: `og-media/update-media`.
 *
 * Wraps `POST /wp/v2/media/<id>` via the Abilities REST Adapter to update an
 * existing attachment's metadata fields (title, alt text, caption, description,
 * parent post). The file itself is never changed. The input schema is OVERRIDDEN
 * to the catalog's narrowed write set — `id` plus the few metadata fields — so the
 * many raw media fields the route accepts stay hidden; an absent key stays absent
 * and an explicit `''` stays `''`, matching the route's clear-on-empty semantics.
 * The output is OVERRIDDEN to the catalog's flat 7-field set through
 * {@see shapeOutput()}. Permission delegates to the route's own object-level
 * `edit_post` check (no `require_permission` floor is set), so its specific errors
 * (`rest_post_invalid_id` 404, `rest_cannot_edit` 403) reach the caller instead of
 * one generic denial. Write annotations (`readonly:false, destructive:false,
 * idempotent:false`) route the outer `/run` call as POST.
 *
 * @since 0.3.0
 */
final class UpdateMedia implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-media/update-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/media/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Media', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing media item\'s title, alt text, caption, description, or parent post by ID. Does not change the file.', 'abilities-catalog' ),
				'category'        => 'og-core-media',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The attachment (media item) ID to update.', 'abilities-catalog' ),
						),
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'The media title.', 'abilities-catalog' ),
						),
						'alt_text'    => array(
							'type'        => 'string',
							'description' => __( 'Alternative text for the media item.', 'abilities-catalog' ),
						),
						'caption'     => array(
							'type'        => 'string',
							'description' => __( 'The media caption.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The media description.', 'abilities-catalog' ),
						),
						'post'        => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'The ID of the post to attach the media to, or 0 to detach it.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The attachment ID.', 'abilities-catalog' ),
						),
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'The resulting rendered media title.', 'abilities-catalog' ),
						),
						'alt_text'    => array(
							'type'        => 'string',
							'description' => __( 'The resulting alternative text.', 'abilities-catalog' ),
						),
						'caption'     => array(
							'type'        => 'string',
							'description' => __( 'The resulting rendered caption.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The resulting rendered description.', 'abilities-catalog' ),
						),
						'source_url'  => array(
							'type'        => 'string',
							'description' => __( 'The direct URL of the media file.', 'abilities-catalog' ),
						),
						'post'        => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the post the media is attached to, if any.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'post.php?post={id}&action=edit',
				),
			)
		);
	}

	/**
	 * Flattens the REST media body to the catalog's 7-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST media body. `title`, `caption`, and `description` are un-nested from their
	 * `{ rendered: ... }` shape; `post` is cast to an int; the rest copy across with a
	 * type cast and a safe default. `$input` and `$response` are part of the callback
	 * signature but unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST media body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat media fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'          => (int) ( $data['id'] ?? 0 ),
			'title'       => (string) ( $data['title']['rendered'] ?? '' ),
			'alt_text'    => (string) ( $data['alt_text'] ?? '' ),
			'caption'     => (string) ( $data['caption']['rendered'] ?? '' ),
			'description' => (string) ( $data['description']['rendered'] ?? '' ),
			'source_url'  => (string) ( $data['source_url'] ?? '' ),
			'post'        => (int) ( $data['post'] ?? 0 ),
		);
	}
}
