<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RegisteredMeta;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `users/get-meta`.
 *
 * Reads a user's custom fields (meta) as a key/value map, limited to the meta
 * keys the site has registered with `show_in_rest` for the user object — the
 * same set the REST API exposes. It never returns arbitrary or internal meta
 * (such as `session_tokens` or `wp_capabilities`). Wraps core `get_metadata()`;
 * the registered-key gate runs through {@see RegisteredMeta::forObject()}.
 *
 * Meta can carry data beyond a user's public profile, so this read requires
 * object-level `edit_user` — the same reason `content/get-post-meta` requires
 * `edit_post` rather than the public view context.
 *
 * @since 0.7.0
 */
final class GetUserMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/get-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get User Meta', 'abilities-catalog' ),
			'description'         => __( 'Returns a user\'s custom fields (meta) as a key/value map, restricted to the meta keys registered with show_in_rest for the user. Requires edit access to the user. Unknown requested keys are silently skipped.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The user ID to read meta from. Discover IDs with users/list-users.', 'abilities-catalog' ),
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Optional list of meta keys to return. When omitted or empty, all registered show_in_rest keys are returned. Requested keys that are not registered for the user are silently skipped.', 'abilities-catalog' ),
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
						'description' => __( 'The user ID.', 'abilities-catalog' ),
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
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: delegated to `execute()`.
	 *
	 * Meta can carry data beyond the public user fields, so reading it requires
	 * object-level `edit_user`. This ability calls core directly (no wrapped REST
	 * route), so the object-level capability is enforced in `execute()`: a missing
	 * user returns `rest_user_invalid_id` (404) and a user the caller cannot edit
	 * returns `rest_forbidden` (403), instead of masking both as a single generic
	 * permission error.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by reading registered meta for the user.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The user id and meta map, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		if ( ! get_userdata( $id ) ) {
			return new WP_Error(
				'rest_user_invalid_id',
				__( 'Invalid user ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to read this user\'s meta.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$allowed   = RegisteredMeta::forObject( 'user', 'user' );
		$requested = ! empty( $input['keys'] ) && is_array( $input['keys'] )
			? array_map( 'strval', $input['keys'] )
			: array_keys( $allowed );

		$meta = array();
		foreach ( $requested as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				continue;
			}

			$shape         = $allowed[ $name ];
			$raw           = get_metadata( 'user', $id, $shape['storage_key'], $shape['single'] );
			$meta[ $name ] = RegisteredMeta::castForResponse( $raw, $shape );
		}

		return array(
			'id'   => $id,
			'meta' => (object) $meta,
		);
	}
}
