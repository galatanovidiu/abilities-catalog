<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RegisteredMeta;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-terms/delete-meta`.
 *
 * Removes one or more custom fields (meta) from a term, deleting all stored
 * values for each named key. It operates only on meta keys registered with
 * `show_in_rest` for the term's taxonomy and rejects unknown keys, so it can
 * never touch arbitrary or internal term meta. Wraps core `delete_metadata()`
 * (with the `term` object type) after a per-key `delete_term_meta` capability
 * check. This is a data deletion and cannot be undone through this ability; it
 * does not change the term's other fields. Returns the term `id`, the `deleted`
 * keys, and `edit_link` (the wp-admin term editor URL); surface `edit_link` so a
 * human can review the term.
 *
 * @since 0.7.0
 */
final class DeleteTermMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/delete-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Term Meta', 'abilities-catalog' ),
			'description'         => __( 'Permanently removes custom fields (meta) from a term by key, deleting all values for each key. Only meta keys registered with show_in_rest for the term\'s taxonomy can be deleted; unknown keys are rejected. This cannot be undone. Returns the term id, the deleted keys, and edit_link — surface edit_link so a human can review the term.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The term ID to delete meta from. Discover IDs with og-terms/list-terms.', 'abilities-catalog' ),
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'minItems'    => 1,
						'description' => __( 'The meta keys to remove. Each must be a registered show_in_rest meta key for the term.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id', 'keys' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'deleted', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The term ID.', 'abilities-catalog' ),
					),
					'deleted'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The meta keys that were removed.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the term, or an empty string when the term is not editable in wp-admin. Surface this so a human can review the term.', 'abilities-catalog' ),
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
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'screen'       => 'term.php?taxonomy={taxonomy}&tag_ID={id}',
			),
		);
	}

	/**
	 * Permission check: delegated to `execute()`.
	 *
	 * This ability calls core directly (no wrapped REST route), so the
	 * object-level `edit_term` decision and the missing-term 404 are made in
	 * `execute()`: a missing term returns `rest_term_invalid` (404) and an
	 * unauthorized caller returns `rest_forbidden` (403), instead of the
	 * Abilities API masking both as one generic permission error. The per-key
	 * `delete_term_meta` capability is also enforced in `execute()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by deleting registered meta from the term.
	 *
	 * Resolves the term (404 if missing), then validates every key up front
	 * (registered + per-key capability) and deletes nothing unless all keys pass.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The term id, deleted keys, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] );
		$term  = get_term( $id );

		if ( null === $term || is_wp_error( $term ) ) {
			return new WP_Error( 'rest_term_invalid', __( 'Term does not exist.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_term', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this term.', 'abilities-catalog' ), array( 'status' => 403 ) );
		}

		$keys = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_values( array_unique( array_map( 'strval', $input['keys'] ) ) ) : array();
		if ( array() === $keys ) {
			return new WP_Error( 'rest_meta_empty', __( 'No meta keys provided.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$allowed = RegisteredMeta::forObject( 'term', $term->taxonomy );

		foreach ( $keys as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				return new WP_Error(
					'rest_meta_unknown_key',
					/* translators: %s: meta key. */
					sprintf( __( 'The meta key "%s" is not registered with show_in_rest for this taxonomy and cannot be deleted.', 'abilities-catalog' ), $name ),
					array( 'status' => 400 )
				);
			}

			// The per-key capability is checked against the storage key, matching
			// core (class-wp-rest-meta-fields.php:235).
			if ( ! current_user_can( 'delete_term_meta', $id, $allowed[ $name ]['storage_key'] ) ) {
				return new WP_Error(
					'rest_cannot_delete_meta',
					/* translators: %s: meta key. */
					sprintf( __( 'You are not allowed to delete the meta key "%s".', 'abilities-catalog' ), $name ),
					array( 'status' => 403 )
				);
			}
		}

		foreach ( $keys as $name ) {
			delete_metadata( 'term', $id, $allowed[ $name ]['storage_key'] );
		}

		return array(
			'id'        => $id,
			'deleted'   => $keys,
			'edit_link' => (string) get_edit_term_link( $id, $term->taxonomy ),
		);
	}
}
