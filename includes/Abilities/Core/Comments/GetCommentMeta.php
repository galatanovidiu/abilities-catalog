<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RegisteredMeta;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-comments/get-meta`.
 *
 * Reads a comment's custom fields (meta), limited to the meta keys the site has
 * registered with `show_in_rest` for comments — the same set the REST API
 * exposes. It never returns arbitrary or internal meta. Wraps core
 * `get_metadata( 'comment', ... )`; the registered-key gate runs through
 * {@see RegisteredMeta::forObject()}.
 *
 * @since 0.7.0
 */
final class GetCommentMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/get-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Comment Meta', 'abilities-catalog' ),
			'description'         => __( 'Returns a comment\'s custom fields (meta) as a key/value map, restricted to the meta keys registered with show_in_rest for comments. Requires edit access to the comment, because meta can carry non-public data. Unknown requested keys are silently skipped.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to read meta from. Discover IDs with og-comments/list-comments.', 'abilities-catalog' ),
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Optional list of meta keys to return. When omitted or empty, all registered show_in_rest keys are returned. Requested keys that are not registered for comments are silently skipped.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'meta' ),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The comment ID.', 'abilities-catalog' ),
					),
					'meta' => array(
						'type'        => 'object',
						'description' => __( 'Map of meta key to value. Single-value keys return one value (a scalar, array, or object, depending on the registered meta type); multi-value keys return an array of values.', 'abilities-catalog' ),
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
	 * Permission check: delegated to `execute()`.
	 *
	 * Comment meta can carry data beyond the public comment fields, so reading it
	 * requires object-level `edit_comment`. This ability calls core directly (no
	 * wrapped REST route), so the object-level decision is enforced in `execute()`:
	 * a missing comment surfaces `rest_comment_invalid_id` (404) and a caller who
	 * may not edit the comment gets `rest_forbidden` (403), instead of masking both
	 * as a single generic permission error.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by reading registered meta for the comment.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment id and meta map, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$comment = get_comment( $id );
		if ( ! $comment ) {
			return new WP_Error(
				'rest_comment_invalid_id',
				__( 'Invalid comment ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to read meta for this comment.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$allowed   = RegisteredMeta::forObject( 'comment', 'comment' );
		$requested = ! empty( $input['keys'] ) && is_array( $input['keys'] )
			? array_map( 'strval', $input['keys'] )
			: array_keys( $allowed );

		$meta = array();
		foreach ( $requested as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				continue;
			}

			$shape         = $allowed[ $name ];
			$raw           = get_metadata( 'comment', $id, $shape['storage_key'], $shape['single'] );
			$meta[ $name ] = RegisteredMeta::castForResponse( $raw, $shape );
		}

		return array(
			'id'   => $id,
			'meta' => (object) $meta,
		);
	}
}
