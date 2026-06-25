<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-users/list-application-passwords`.
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
		return 'og-users/list-application-passwords';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Application Passwords', 'abilities-catalog' ),
			'description'         => __( 'Returns the application passwords for a user (metadata only, never the plaintext password).', 'abilities-catalog' ),
			'category'            => 'og-core-users',
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
						'items'       => self::itemSchema(),
						'description' => __( 'The application-password records (metadata only, never the plaintext password).', 'abilities-catalog' ),
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
					'scope' => 'user',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: a logged-in user; the route enforces the object.
	 *
	 * Application passwords are user-scoped, so a logged-in user is the
	 * object-independent floor every successful caller holds — `list_app_passwords`
	 * is never granted to a logged-out request, so this is never stricter than core.
	 * The object-level decision (own credentials are allowed, another user requires
	 * `edit_user`) is enforced by the wrapped
	 * `GET /wp/v2/users/<id>/application-passwords` route's
	 * `get_items_permissions_check`, so its specific errors (`rest_user_invalid_id`
	 * 404, `rest_cannot_list_application_passwords` 403) reach the caller instead of
	 * the generic denial the Abilities API substitutes for a non-`true` return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if a user is logged in.
	 */
	public function hasPermission( $input ): bool {
		return is_user_logged_in();
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
		$data = is_array( $data ) ? $data : array();

		$items = array_map(
			static fn ( $item ): array => self::shapeItem( is_array( $item ) ? $item : array() ),
			array_values( $data )
		);

		return array(
			'items' => $items,
		);
	}

	/**
	 * Projects a raw REST application-password item into a fixed safe metadata row.
	 *
	 * Core's list route runs each row through `response_to_data()`, which carries
	 * extra fields beyond the documented metadata (`app_id`, `last_ip`, `_links`),
	 * and the `rest_prepare_application_password` filter lets plugins add arbitrary
	 * fields. This allowlist keeps the output aligned with the documented
	 * "metadata only" contract. The plaintext password is never present in a list
	 * response, so there is nothing to strip there.
	 *
	 * @param array<string,mixed> $item A single application-password record from the REST response.
	 * @return array<string,mixed> The metadata row: uuid, name, created, last_used, last_ip.
	 */
	private static function shapeItem( array $item ): array {
		return array(
			'uuid'      => (string) ( $item['uuid'] ?? '' ),
			'name'      => (string) ( $item['name'] ?? '' ),
			'created'   => (string) ( $item['created'] ?? '' ),
			'last_used' => $item['last_used'] ?? null,
			'last_ip'   => $item['last_ip'] ?? null,
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::shapeItem()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment with a closed shape.
	 */
	private static function itemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'uuid', 'name', 'created' ),
			'properties'           => array(
				'uuid'      => array(
					'type'        => 'string',
					'description' => __( 'The UUID of the application password.', 'abilities-catalog' ),
				),
				'name'      => array(
					'type'        => 'string',
					'description' => __( 'The human-readable name of the application password.', 'abilities-catalog' ),
				),
				'created'   => array(
					'type'        => 'string',
					'description' => __( 'When the application password was created (GMT, ISO 8601).', 'abilities-catalog' ),
				),
				'last_used' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'When the application password was last used (GMT, ISO 8601), or null if never used.', 'abilities-catalog' ),
				),
				'last_ip'   => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The IP address that last used the application password, or null if never used.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
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
