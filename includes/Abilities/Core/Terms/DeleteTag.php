<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-terms/delete-tag`.
 *
 * Wraps `DELETE /wp/v2/tags/<id>` via the Abilities REST Adapter. `force=true` is
 * injected through {@see forceDelete()} (taxonomy terms have no Trash, so the
 * delete is always permanent), the input schema is a closed `{ id }`, and the
 * output is OVERRIDDEN to the catalog's flat field set through {@see shapeOutput()}.
 * Permission delegates to the route's own check (no `require_permission` floor is
 * set): for `post_tag`, `delete_term` maps to `delete_post_tags`, which is exactly
 * the route's authority — never stricter, never weaker.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteTag implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/delete-tag';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/tags/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Tag', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes a tag term by ID. Taxonomy terms have no Trash, so this cannot be undone. Deleting the tag also removes it from every object it was assigned to.', 'abilities-catalog' ),
				'category'        => 'og-core-terms',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The tag term ID to permanently delete. Find it via og-terms/list-tags or og-terms/get-tag.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'forceDelete' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'id' ),
					'properties'           => array(
						'deleted'        => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the tag term was permanently deleted.', 'abilities-catalog' ),
						),
						'id'             => array(
							'type'        => 'integer',
							'description' => __( 'The deleted tag term ID.', 'abilities-catalog' ),
						),
						'previous_name'  => array(
							'type'        => 'string',
							'description' => __( 'The deleted tag name, from the term as it existed before deletion.', 'abilities-catalog' ),
						),
						'previous_slug'  => array(
							'type'        => 'string',
							'description' => __( 'The deleted tag slug, from the term as it existed before deletion.', 'abilities-catalog' ),
						),
						'previous_link'  => array(
							'type'        => 'string',
							'description' => __( 'The deleted tag archive URL as it existed before deletion.', 'abilities-catalog' ),
						),
						'previous_count' => array(
							'type'        => 'integer',
							'description' => __( 'The number of objects assigned to the tag before deletion.', 'abilities-catalog' ),
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
					'screen'       => 'edit-tags.php?taxonomy=post_tag',
				),
			)
		);
	}

	/**
	 * Injects `force=true` so the delete is always permanent.
	 *
	 * Wired as the adapter's `input_callback`, it runs once per `execute()`, after the
	 * ability validates input against its closed `{ id }` schema and before dispatch.
	 * Taxonomy terms have no Trash, so a non-forced delete would fail; the caller never
	 * passes `force`, this ability always sets it.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with `force` forced to true.
	 */
	public function forceDelete( array $params ): array {
		$params['force'] = true;

		return $params;
	}

	/**
	 * Flattens the REST term-delete body to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST delete body (`{ deleted, previous: { … } }`). The `id` is read from the
	 * original ability input, since the delete body does not echo it back. The
	 * additive `previous_*` fields copy across only when present, so the caller knows
	 * what was removed; `post_tag` is non-hierarchical, so there is no `previous_parent`.
	 * `$response` is part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The REST delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat delete result.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$result = array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => absint( $input['id'] ?? 0 ),
		);

		$previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();
		if ( isset( $previous['name'] ) ) {
			$result['previous_name'] = (string) $previous['name'];
		}
		if ( isset( $previous['slug'] ) ) {
			$result['previous_slug'] = (string) $previous['slug'];
		}
		if ( isset( $previous['link'] ) ) {
			$result['previous_link'] = (string) $previous['link'];
		}
		if ( isset( $previous['count'] ) ) {
			$result['previous_count'] = (int) $previous['count'];
		}

		return $result;
	}
}
