<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\TermResolver;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 write ability: `terms/detach-post-terms`.
 *
 * Removes taxonomy terms from a post's assignments without rewriting the rest of
 * the post. Terms may be given as IDs, slugs, or names. This removes only the
 * association between the post and the terms; the terms themselves are not deleted
 * (use `terms/delete-term` for that), so the change is reversible by re-attaching.
 * Wraps core `wp_remove_object_terms()`. Returns the post `id`, `taxonomy`, the
 * remaining `term_ids`, and `edit_link` (the wp-admin editor URL); surface
 * `edit_link` so a human can review the post.
 *
 * @since 0.5.0
 */
final class DetachPostTerms implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'terms/detach-post-terms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Detach Post Terms', 'abilities-catalog' ),
			'description'         => __( 'Removes terms in a taxonomy from a post\'s assignments. Terms can be IDs, slugs, or names. This only unlinks the terms from the post; it does not delete the terms. Returns the post id, taxonomy, remaining term_ids, and edit_link — surface edit_link so a human can review the post.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to remove terms from.', 'abilities-catalog' ),
					),
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy slug (e.g. "category", "post_tag", or a custom taxonomy).', 'abilities-catalog' ),
					),
					'terms'    => array(
						'type'        => 'array',
						'minItems'    => 1,
						'description' => __( 'Terms to remove, as term IDs (integers) or slugs/names (strings).', 'abilities-catalog' ),
						'items'       => array(
							'type' => array( 'integer', 'string' ),
						),
					),
				),
				'required'             => array( 'post_id', 'taxonomy', 'terms' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'taxonomy', 'term_ids', 'edit_link' ),
				'properties'           => array(
					'post_id'   => array(
						'type'        => 'integer',
						'description' => __( 'The post ID.', 'abilities-catalog' ),
					),
					'taxonomy'  => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy slug.', 'abilities-catalog' ),
					),
					'term_ids'  => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => __( 'The post\'s remaining term IDs in this taxonomy after the change.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the post. Surface this so a human can review the post.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'screen'       => 'post.php?post={post_id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: assign capability on the taxonomy plus edit on the post.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may detach the terms.
	 */
	public function hasPermission( $input ): bool {
		$input    = is_array( $input ) ? $input : array();
		$post_id  = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';

		if ( $post_id <= 0 || '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj ) {
			return false;
		}

		return current_user_can( $taxonomy_obj->cap->assign_terms ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Executes the ability by removing resolved terms from the post.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post id, taxonomy, remaining term IDs, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$post_id  = absint( $input['post_id'] );
		$taxonomy = sanitize_key( (string) $input['taxonomy'] );
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return new WP_Error( 'rest_taxonomy_invalid', __( 'The taxonomy is not registered for this post type.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$terms_in = is_array( $input['terms'] ) ? $input['terms'] : array();
		$resolved = TermResolver::resolve( $terms_in, $taxonomy );

		if ( array() !== $resolved['ids'] ) {
			$result = wp_remove_object_terms( $post_id, $resolved['ids'], $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$current  = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		$term_ids = is_wp_error( $current ) ? array() : array_map( 'intval', $current );

		return array(
			'post_id'   => $post_id,
			'taxonomy'  => $taxonomy,
			'term_ids'  => array_values( $term_ids ),
			'edit_link' => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}
}
