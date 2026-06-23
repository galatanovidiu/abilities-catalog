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
 * T1 read ability: `terms/get-meta`.
 *
 * Reads a term's custom fields (meta) as a key/value map, limited to the meta
 * keys the site has registered with `show_in_rest` for that term's taxonomy —
 * the same set the REST API exposes. It never returns arbitrary or internal
 * meta. Wraps core `get_metadata( 'term', ... )`; the registered-key gate runs
 * through {@see RegisteredMeta::forObject()} keyed by the term's taxonomy.
 *
 * @since 0.7.0
 */
final class GetTermMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'terms/get-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Term Meta', 'abilities-catalog' ),
			'description'         => __( 'Returns a term\'s custom fields (meta) as a key/value map, restricted to the meta keys registered with show_in_rest for the term\'s taxonomy. Requires edit access to the term (meta may carry non-public data). Discover IDs with terms/list-terms.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The term ID to read meta from. Discover IDs with terms/list-terms.', 'abilities-catalog' ),
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Optional list of meta keys to return. When omitted or empty, all registered show_in_rest keys are returned. Requested keys that are not registered for the taxonomy are silently skipped.', 'abilities-catalog' ),
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
						'description' => __( 'The term ID.', 'abilities-catalog' ),
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
	 * Meta can carry data beyond the public term fields, so reading it requires
	 * object-level `edit_term`. This ability calls core directly (no wrapped
	 * REST route), so the object-level check is enforced in `execute()`:
	 * resolving the term first lets a missing term surface a specific
	 * `rest_term_invalid` (404) instead of being masked as a permission failure,
	 * and a non-editable term surfaces `rest_forbidden` (403). Doing the check
	 * here would collapse both into one generic denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by reading registered meta for the term.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The term id and meta map, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] );
		$term  = get_term( $id );

		if ( null === $term || is_wp_error( $term ) ) {
			return new WP_Error(
				'rest_term_invalid',
				__( 'Term does not exist.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_term', $id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to read this term\'s meta.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$allowed   = RegisteredMeta::forObject( 'term', $term->taxonomy );
		$requested = ! empty( $input['keys'] ) && is_array( $input['keys'] )
			? array_map( 'strval', $input['keys'] )
			: array_keys( $allowed );

		$meta = array();
		foreach ( $requested as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				continue;
			}

			$shape         = $allowed[ $name ];
			$raw           = get_metadata( 'term', $id, $shape['storage_key'], $shape['single'] );
			$meta[ $name ] = RegisteredMeta::castForResponse( $raw, $shape );
		}

		return array(
			'id'   => $id,
			'meta' => (object) $meta,
		);
	}
}
