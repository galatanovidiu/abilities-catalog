<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive T2 write ability: `og-users/delete-application-password`.
 *
 * Wraps `DELETE /wp/v2/users/<user_id>/application-passwords/<uuid>` via the
 * Abilities REST Adapter, permanently revoking a single application password. The
 * action is irreversible: the credential stops working immediately and cannot be
 * restored. The DELETE route already revokes unconditionally, so no `force`
 * parameter is needed.
 *
 * The input schema is OVERRIDDEN so `uuid` is required but `user_id` is not; the
 * `input_callback` injects `user_id => get_current_user_id()` when the caller omits
 * it, satisfying the route's required `user_id` path capture. The output is
 * OVERRIDDEN to the catalog's flat field set through {@see shapeOutput()}, which
 * flattens the REST body's `previous` block and copies `uuid` from the input.
 *
 * Permission delegates to the wrapped route's own check (no `require_permission`
 * floor): the object-level decision (own credentials allowed, another user requires
 * `edit_user`) is the route's `delete_item_permissions_check`, so its specific
 * errors (`rest_cannot_delete_application_password` 403,
 * `rest_application_password_not_found` 404) reach the caller through `execute()`
 * instead of the generic permission collapse.
 *
 * Because the annotations mark this as a destructive write, the Registry registers
 * it but the adapter exposes it to the browser only when BOTH the write and
 * destructive settings are on.
 *
 * @since 0.3.0
 */
final class DeleteApplicationPassword implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/delete-application-password';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords/(?P<uuid>[\w\-]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Application Password', 'abilities-catalog' ),
				'description'     => __( 'Permanently revokes a single application password by its UUID for a user. Irreversible: the credential stops working immediately.', 'abilities-catalog' ),
				'category'        => 'og-core-users',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'user_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The user ID that owns the application password. Defaults to the current user.', 'abilities-catalog' ),
						),
						'uuid'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'The UUID of the application password to revoke. Use og-users/list-application-passwords to discover existing UUIDs.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'uuid' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'fillUserId' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'uuid', 'name', 'app_id' ),
					'properties'           => array(
						'deleted'   => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the application password was revoked.', 'abilities-catalog' ),
						),
						'uuid'      => array(
							'type'        => 'string',
							'description' => __( 'The UUID of the revoked application password.', 'abilities-catalog' ),
						),
						'name'      => array(
							'type'        => 'string',
							'description' => __( 'The human-readable name of the revoked application password.', 'abilities-catalog' ),
						),
						'app_id'    => array(
							'type'        => 'string',
							'description' => __( 'The application UUID of the revoked credential, if one was set.', 'abilities-catalog' ),
						),
						'created'   => array(
							'type'        => 'string',
							'description' => __( 'When the revoked application password was created (GMT, ISO 8601).', 'abilities-catalog' ),
						),
						'last_used' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'When the revoked application password was last used (GMT, ISO 8601), or null if never used.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
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
				),
			)
		);
	}

	/**
	 * Injects the current user ID as the target `user_id` when the caller omits it.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once at dispatch over the
	 * request params. The route's `user_id` path capture is required, but the
	 * overridden input schema does not require it, so this callback supplies the
	 * default before the path is built. A caller-supplied `user_id` is left untouched.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with `user_id` filled when absent.
	 */
	public function fillUserId( array $params ): array {
		if ( ! isset( $params['user_id'] ) ) {
			$params['user_id'] = get_current_user_id();
		}

		return $params;
	}

	/**
	 * Flattens the REST delete body to the catalog's closed result shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST delete body. The body's `previous` block (a snapshot of the revoked
	 * credential) is un-nested into `name`/`app_id`/`created`/`last_used`; `uuid` is
	 * copied from the original ability input (the route does not echo it), and `name`,
	 * `app_id`, and `created` fall back to `''` when absent. `$response` is part of the
	 * callback signature but unused here.
	 *
	 * @param mixed               $data     The REST delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat deletion result.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data     = is_array( $data ) ? $data : array();
		$previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();

		return array(
			'deleted'   => (bool) ( $data['deleted'] ?? false ),
			'uuid'      => (string) ( $input['uuid'] ?? '' ),
			'name'      => (string) ( $previous['name'] ?? '' ),
			'app_id'    => (string) ( $previous['app_id'] ?? '' ),
			'created'   => (string) ( $previous['created'] ?? '' ),
			'last_used' => $previous['last_used'] ?? null,
		);
	}
}
