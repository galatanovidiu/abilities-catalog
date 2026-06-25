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
 * Read ability: `og-users/get-user`.
 *
 * Wraps `GET /wp/v2/users/<id>` via `rest_do_request()` and shapes the response
 * into a flat field set. The `email` and `roles` fields are only present when the
 * request runs in `edit` context with sufficient capability, so they are
 * null-coalesced. Never outputs any password value. Read-only.
 *
 * @since 0.1.0
 */
final class GetUser implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/get-user';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get User', 'abilities-catalog' ),
			'description'         => __( 'Returns a single user by ID. View context returns public fields (name, slug, url, description); edit context also returns email, roles, capabilities, and registered_date.', 'abilities-catalog' ),
			'category'            => 'og-core-users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The user ID.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->outputSchema(),
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
	 * Permission check: delegated to the wrapped REST route.
	 *
	 * `og-users/get-user` reads through `GET /wp/v2/users/<id>`, whose own
	 * `get_item_permissions_check` enforces visibility on the object — self is always
	 * readable, a public author with published posts is viewable, `list_users` or
	 * `edit_user` widens access, and `edit` context requires `edit_user`. Doing the
	 * object-level check here instead would (a) narrow core by requiring `list_users`
	 * for every view (blocking the self and public-author reads core allows) and
	 * (b) collapse the route's specific errors (`rest_user_invalid_id` 404,
	 * `rest_forbidden_context` / `rest_user_cannot_view` 403) into one opaque
	 * permission error, because the Abilities API swallows a non-`true` return and
	 * replaces it with a single generic denial. Private fields (email, roles) stay
	 * gated: the route only serves them in `edit` context to a capable caller.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; the wrapped route is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat user fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/users/' . $id );
		$request->set_param( 'context', $context );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return self::mapUser( $data, $id );
	}

	/**
	 * The shared output schema for a single user record.
	 *
	 * @return array<string,mixed>
	 */
	private function outputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'name' ),
			'properties'           => array(
				'id'              => array(
					'type'        => 'integer',
					'description' => __( 'The user ID.', 'abilities-catalog' ),
				),
				'name'            => array(
					'type'        => 'string',
					'description' => __( 'The display name for the user.', 'abilities-catalog' ),
				),
				'slug'            => array(
					'type'        => 'string',
					'description' => __( 'An alphanumeric identifier for the user.', 'abilities-catalog' ),
				),
				'email'           => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The email address (only with edit access).', 'abilities-catalog' ),
				),
				'roles'           => array(
					'type'        => array( 'array', 'null' ),
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Roles assigned to the user (only with edit access).', 'abilities-catalog' ),
				),
				'capabilities'    => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => __( 'Capabilities of the user (only with edit access).', 'abilities-catalog' ),
				),
				'registered_date' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The registration date (only with edit access).', 'abilities-catalog' ),
				),
				'url'             => array(
					'type'        => 'string',
					'description' => __( 'The website URL for the user.', 'abilities-catalog' ),
				),
				'description'     => array(
					'type'        => 'string',
					'description' => __( 'The biographical description for the user.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Maps a REST user payload to the flat output shape.
	 *
	 * Password values are never copied. Capability-gated fields (email, roles,
	 * registered_date) are only present when the REST response includes them.
	 *
	 * @param mixed $data The REST response data.
	 * @param int   $id   The fallback user ID.
	 * @return array<string,mixed>
	 */
	public static function mapUser( $data, int $id ): array {
		$data = is_array( $data ) ? $data : array();

		$out = array(
			'id'          => (int) ( $data['id'] ?? $id ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'url'         => (string) ( $data['url'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
		);

		if ( isset( $data['email'] ) ) {
			$out['email'] = (string) $data['email'];
		}

		if ( isset( $data['roles'] ) ) {
			$out['roles'] = array_map( 'strval', (array) $data['roles'] );
		}

		if ( isset( $data['capabilities'] ) ) {
			$out['capabilities'] = (array) $data['capabilities'];
		}

		if ( isset( $data['registered_date'] ) ) {
			$out['registered_date'] = (string) $data['registered_date'];
		}

		return $out;
	}
}
