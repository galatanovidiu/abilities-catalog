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
 * Read ability: `og-users/get-current-user`.
 *
 * Wraps `GET /wp/v2/users/me` via the Abilities REST Adapter. The input schema is
 * OVERRIDDEN to expose only `context` (`view`/`edit`); the output is OVERRIDDEN to
 * the catalog's flat field set through {@see shapeOutput()}, which reuses
 * {@see GetUser::mapUser()} with the current user ID. Permission delegates to the
 * route's own check — `/wp/v2/users/me` requires a logged-in user, matching the
 * catalog's old login floor, so no `require_permission` guard is set. Never outputs
 * any password value. Read-only.
 *
 * @since 0.1.0
 */
final class GetCurrentUser implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/get-current-user';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/users/me',
				'method'          => 'GET',
				'label'           => __( 'Get Current User', 'abilities-catalog' ),
				'description'     => __( 'Returns the profile of the currently logged-in user, including name and slug; email, roles, and capabilities appear only in "edit" context.', 'abilities-catalog' ),
				'category'        => 'og-core-users',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (own profile fields).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
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
							'description' => __( 'The email address of the current user (only with edit access).', 'abilities-catalog' ),
						),
						'roles'           => array(
							'type'        => array( 'array', 'null' ),
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Roles assigned to the current user (only with edit access).', 'abilities-catalog' ),
						),
						'capabilities'    => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'boolean' ),
							'description'          => __( 'Capabilities of the current user, as a map of capability name to granted (only with edit access).', 'abilities-catalog' ),
						),
						'registered_date' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'The registration date of the current user (only with edit access).', 'abilities-catalog' ),
						),
						'url'             => array(
							'type'        => 'string',
							'description' => __( 'The website URL for the current user.', 'abilities-catalog' ),
						),
						'description'     => array(
							'type'        => 'string',
							'description' => __( 'The biographical description for the current user.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'abilities_catalog' => array(
						'scope' => 'user',
					),
					'show_in_rest'      => true,
				),
			)
		);
	}

	/**
	 * Flattens the REST `/me` user body to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST user body. Delegates to {@see GetUser::mapUser()} with the current user ID
	 * as the fallback, matching the pre-conversion shape — capability-gated fields
	 * (email, roles, capabilities, registered_date) are present only when the REST
	 * response (i.e. `edit` context) includes them. `$input` and `$response` are part
	 * of the callback signature but unused here — the body carries everything this
	 * shape needs.
	 *
	 * @param mixed               $data     The REST user body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat user fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		return GetUser::mapUser( $data, get_current_user_id() );
	}
}
