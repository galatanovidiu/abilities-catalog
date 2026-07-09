<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive T2 write ability: `og-users/delete-user`.
 *
 * Wraps `DELETE /wp/v2/users/<id>` via the Abilities REST Adapter, permanently
 * deleting the user. Users do not support trashing, so this is an irreversible
 * operation. The input and output schemas are OVERRIDDEN to the catalog's narrow
 * contract (an `id` + a required `reassign` in, a flat `previous_*` identity
 * snapshot out).
 *
 * {@see shapeInput()} injects the route's required `force=true` (the route refuses
 * any delete without it) and keeps the catalog's recovery-oriented multisite
 * refusal — the route also hard-fails on multisite (`rest_cannot_delete` 501), but
 * the guard here returns a 400 that points the caller at
 * `og-network/remove-user-from-site` for per-site removal. {@see shapeOutput()}
 * flattens the deleted user's `previous` snapshot.
 *
 * Permission delegates to the route's own check (no `require_permission` floor):
 * `delete_item_permissions_check` enforces the object-level `delete_user`
 * capability, surfacing the specific `rest_user_invalid_id` 404 /
 * `rest_user_cannot_delete` 401 instead of the Abilities API collapsing it into a
 * generic permission failure. The route also validates `reassign` itself
 * (`rest_user_invalid_reassign` 400 when it equals the deleted id or names no
 * existing user), so the catalog does not re-validate it. Because the annotations
 * mark this as a destructive write, the Registry registers it but the adapter
 * exposes it to the browser only when BOTH the write and destructive settings are
 * on.
 *
 * @since 0.3.0
 */
final class DeleteUser implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/delete-user';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/users/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete User', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes a user by ID and reassigns their content to another existing user. Irreversible: users do not support trashing. The reassign target is required and must be a different, existing user. Single-site only: the wrapped route hard-fails on multisite with a 501 error.', 'abilities-catalog' ),
				'category'        => 'og-core-users',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The user ID to delete permanently.', 'abilities-catalog' ),
						),
						'reassign' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Required. The ID of an existing, different user to reassign the deleted user\'s content to. Content is reassigned, never destroyed.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id', 'reassign' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'id', 'reassigned_to' ),
					'properties'           => array(
						'deleted'           => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the user was deleted.', 'abilities-catalog' ),
						),
						'id'                => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the deleted user.', 'abilities-catalog' ),
						),
						'reassigned_to'     => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the user that received the deleted user\'s content.', 'abilities-catalog' ),
						),
						'previous_username' => array(
							'type'        => 'string',
							'description' => __( 'The login username of the deleted user.', 'abilities-catalog' ),
						),
						'previous_name'     => array(
							'type'        => 'string',
							'description' => __( 'The display name of the deleted user.', 'abilities-catalog' ),
						),
						'previous_email'    => array(
							'type'        => 'string',
							'description' => __( 'The email address of the deleted user.', 'abilities-catalog' ),
						),
						'previous_slug'     => array(
							'type'        => 'string',
							'description' => __( 'The URL slug (nicename) of the deleted user.', 'abilities-catalog' ),
						),
						'previous_roles'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'The roles held by the deleted user.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'shapeInput' ),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'       => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'abilities_catalog' => array(
						'scope' => 'user',
					),
					'show_in_rest'      => true,
					'screen'            => 'users.php',
				),
			)
		);
	}

	/**
	 * Injects the route's required `force=true` and keeps the multisite refusal.
	 *
	 * Wired as the adapter's `input_callback`; runs once at dispatch, as the current
	 * user. The wrapped route requires `force=true` to delete (users do not support
	 * trashing) and a `reassign` target, both forced here from the caller's `id` and
	 * `reassign`. On multisite the route refuses every delete (`rest_cannot_delete`
	 * 501); this returns a recovery-oriented `WP_Error` first, pointing the caller at
	 * `og-network/remove-user-from-site` for per-site removal. The route validates
	 * the `reassign` value itself, so no data-loss guard is duplicated here.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed>|\WP_Error The dispatch params, or a WP_Error to reject.
	 */
	public function shapeInput( array $params ) {
		if ( is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_delete_user_multisite',
				__( 'Deleting a user is disabled on multisite. To remove a user from one site, use og-network/remove-user-from-site.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$params['force'] = true;

		return $params;
	}

	/**
	 * Flattens the deleted user's `previous` snapshot to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST delete body (`{ deleted, previous }`). The route returns the deleted
	 * user's edit-context data under `previous`; the identity fields are flattened to
	 * `previous_*`. `$response` is part of the callback signature but unused — the
	 * body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat deletion result.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data     = is_array( $data ) ? $data : array();
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();
		$roles    = is_array( $previous['roles'] ?? null ) ? array_values( array_map( 'strval', $previous['roles'] ) ) : array();

		return array(
			'deleted'           => (bool) ( $data['deleted'] ?? false ),
			'id'                => absint( $input['id'] ?? 0 ),
			'reassigned_to'     => absint( $input['reassign'] ?? 0 ),
			'previous_username' => (string) ( $previous['username'] ?? '' ),
			'previous_name'     => (string) ( $previous['name'] ?? '' ),
			'previous_email'    => (string) ( $previous['email'] ?? '' ),
			'previous_slug'     => (string) ( $previous['slug'] ?? '' ),
			'previous_roles'    => $roles,
		);
	}
}
