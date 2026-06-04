<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `users/list-application-passwords`.
 *
 * Wraps `GET /wp/v2/users/<user_id>/application-passwords` via `rest_do_request()`
 * and returns the list of application-password records. The list route returns
 * only metadata (uuid, name, created, last_used); the plaintext password is never
 * available here and is never output. Read-only.
 *
 * @since 0.1.0
 */
final class ListApplicationPasswords implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/list-application-passwords';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Application Passwords', 'abilities-catalog' ),
			'description'         => __( 'Returns the application passwords for a user (metadata only, never the plaintext password).', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'The user ID. Defaults to the current user.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'description' => __( 'The application-password records (metadata only).', 'abilities-catalog' ),
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
	 * Permission check: the current user may list the target user's app passwords.
	 *
	 * Encodes the catalog object-level capability `list_app_passwords` on the
	 * resolved user ID.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list the app passwords.
	 */
	public function hasPermission( $input ): bool {
		$user_id = $this->resolveUserId( $input );

		if ( $user_id <= 0 ) {
			return false;
		}

		return current_user_can( 'list_app_passwords', $user_id );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The app-password list, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$user_id = $this->resolveUserId( $input );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/users/' . $user_id . '/application-passwords' );
		$request->set_param( 'context', $context );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'items' => is_array( $data ) ? array_values( $data ) : array(),
		);
	}

	/**
	 * Resolves the target user ID, defaulting to the current user.
	 *
	 * @param mixed $input The validated input data.
	 * @return int The resolved user ID, or 0 when none is available.
	 */
	private function resolveUserId( $input ): int {
		$input = is_array( $input ) ? $input : array();

		if ( isset( $input['user_id'] ) ) {
			return absint( $input['user_id'] );
		}

		return get_current_user_id();
	}
}
