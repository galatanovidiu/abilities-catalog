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
 * T2 destructive write ability: `og-terms/delete-category`.
 *
 * Wraps `DELETE /wp/v2/categories/<id>` via the Abilities REST Adapter, injecting
 * `force=true` so the term is permanently deleted (taxonomy terms have no Trash).
 * Permission delegates to the route's own check ({@see Rest_Route_Ability} permission
 * model): the terms controller's object-level `delete_term`, which for `category`
 * maps to `delete_categories`. No `require_permission` floor is set, so the route is
 * the authority and its real `WP_Error` (e.g. `rest_term_invalid` 404, the
 * default-category refusal) surfaces through `execute()` unchanged.
 *
 * The input schema is OVERRIDDEN to a closed `{ id }`-only shape so the raw REST
 * `force` field is hidden from callers — {@see addForce()} injects it after
 * validation. The output is OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}; the `previous` object is in the DELETE response body (the
 * route returns the prior term on force-delete), so the callback covers it.
 *
 * Category-specific side effects (from `wp-includes/taxonomy.php`): the site's
 * default category cannot be deleted; child categories are reparented to the
 * deleted term's parent; and posts left with no category are reassigned to the
 * default category.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteCategory implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/delete-category';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/categories/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Category', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes a category term by ID. Taxonomy terms have no Trash, so this cannot be undone. The default category cannot be deleted; child categories are reparented to the deleted term\'s parent, and posts left with no category are reassigned to the default category.', 'abilities-catalog' ),
				'category'        => 'og-core-terms',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The category term ID to permanently delete. Find it via og-terms/list-categories or og-terms/get-category.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'addForce' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'id' ),
					'properties'           => array(
						'deleted'         => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the category term was permanently deleted.', 'abilities-catalog' ),
						),
						'id'              => array(
							'type'        => 'integer',
							'description' => __( 'The deleted category term ID.', 'abilities-catalog' ),
						),
						'previous_name'   => array(
							'type'        => 'string',
							'description' => __( 'The deleted category name, from the term as it existed before deletion.', 'abilities-catalog' ),
						),
						'previous_slug'   => array(
							'type'        => 'string',
							'description' => __( 'The deleted category slug, from the term as it existed before deletion.', 'abilities-catalog' ),
						),
						'previous_parent' => array(
							'type'        => 'integer',
							'description' => __( 'The deleted category parent term ID (0 if top-level), before deletion.', 'abilities-catalog' ),
						),
						'previous_link'   => array(
							'type'        => 'string',
							'description' => __( 'The deleted category archive URL as it existed before deletion.', 'abilities-catalog' ),
						),
						'previous_count'  => array(
							'type'        => 'integer',
							'description' => __( 'The number of objects assigned to the category before deletion.', 'abilities-catalog' ),
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
					'screen'       => 'edit-tags.php?taxonomy=category',
				),
			)
		);
	}

	/**
	 * Injects `force=true` so the route permanently deletes the term.
	 *
	 * Wired as the adapter's `input_callback`, it runs after input validation, over
	 * the request params. The caller never passes `force`; the closed `id`-only
	 * input schema hides it, and this adds it before dispatch. Taxonomy terms have
	 * no Trash, so `force` is the only deletion mode the route supports.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The params with `force` set to true.
	 */
	public function addForce( array $params ): array {
		$params['force'] = true;
		return $params;
	}

	/**
	 * Flattens the REST delete body to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST delete body `{ deleted, previous: { name, slug, parent, link, count } }`.
	 * The flat `id` is read from `$input` (the body's `previous` term carries no
	 * top-level id). Each `previous_*` field is added only when the source key is
	 * present, matching the original additive shape. `$response` is part of the
	 * callback signature but unused — the body carries everything this shape needs.
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
		if ( isset( $previous['parent'] ) ) {
			$result['previous_parent'] = (int) $previous['parent'];
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
