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
 * T2 write ability: `og-terms/attach-post-terms`.
 *
 * Assigns existing taxonomy terms to a post without rewriting the rest of the
 * post. Terms may be given as IDs, slugs, or names; they must already exist
 * (use `og-terms/create-term` first) — this ability never creates terms. By default
 * it appends to the post's current terms; set `append` to false to replace them.
 * Wraps core `wp_set_object_terms()`. Returns the post `id`, `taxonomy`, the full
 * resulting `term_ids`, and `edit_link` (the wp-admin editor URL); surface
 * `edit_link` so a human can review the post.
 *
 * @since 0.5.0
 */
final class AttachPostTerms implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/attach-post-terms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Attach Post Terms', 'abilities-catalog' ),
			'description'         => __( 'Assigns existing terms in a taxonomy to a post. Terms can be IDs, slugs, or names but must already exist; create them with og-terms/create-term first. Appends by default; set append to false to replace the post\'s terms in that taxonomy. Returns the post id, taxonomy, resulting term_ids, and edit_link — surface edit_link so a human can review the post.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to assign terms to.', 'abilities-catalog' ),
					),
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy slug (e.g. "category", "post_tag", or a custom taxonomy).', 'abilities-catalog' ),
					),
					'terms'    => array(
						'type'        => 'array',
						'minItems'    => 1,
						'description' => __( 'Existing terms to attach, as term IDs (integers) or slugs/names (strings).', 'abilities-catalog' ),
						'items'       => array(
							'type' => array( 'integer', 'string' ),
						),
					),
					'append'   => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Append to existing terms (true) or replace them (false).', 'abilities-catalog' ),
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
						'description' => __( 'The post\'s full set of term IDs in this taxonomy after the change.', 'abilities-catalog' ),
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
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'post.php?post={post_id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: coarse `assign_terms` on the taxonomy; the post is guarded in execute().
	 *
	 * `assign_terms` is the object-independent taxonomy capability the REST post controller
	 * requires to set terms. This ability calls `wp_set_object_terms()` directly (no wrapped
	 * route), so the object-level `edit_post` check is enforced in {@see self::execute()} where
	 * its specific 404/403 reaches the caller instead of the generic denial the Abilities API
	 * substitutes for a non-`true` return. Coarsening without that relocation would let any
	 * `assign_terms` holder write terms onto a post they cannot edit, so the `edit_post` guard
	 * moves into execute() in the same change.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can assign terms in the taxonomy.
	 */
	public function hasPermission( $input ): bool {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj ) {
			return false;
		}

		return current_user_can( $taxonomy_obj->cap->assign_terms );
	}

	/**
	 * Executes the ability by assigning resolved terms to the post.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post id, taxonomy, resulting term IDs, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$post_id  = absint( $input['post_id'] );
		$taxonomy = sanitize_key( (string) $input['taxonomy'] );
		$append   = isset( $input['append'] ) ? (bool) $input['append'] : true;
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		// Object-level guard (relocated from permission_callback): only a user who can
		// edit this post may change its term assignments.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_cannot_assign_term',
				__( 'Sorry, you are not allowed to assign terms on this post.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return new WP_Error( 'rest_taxonomy_invalid', __( 'The taxonomy is not registered for this post type.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$terms_in = is_array( $input['terms'] ) ? $input['terms'] : array();
		$resolved = TermResolver::resolve( $terms_in, $taxonomy );

		if ( array() !== $resolved['missing'] ) {
			return new WP_Error(
				'rest_term_not_found',
				/* translators: %s: comma-separated list of term references. */
				sprintf( __( 'These terms do not exist in the taxonomy: %s. Create them with og-terms/create-term first.', 'abilities-catalog' ), implode( ', ', $resolved['missing'] ) ),
				array( 'status' => 400 )
			);
		}

		$result = wp_set_object_terms( $post_id, $resolved['ids'], $taxonomy, $append );
		if ( is_wp_error( $result ) ) {
			return $result;
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
