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
 * Read ability: `og-users/get-user`.
 *
 * Wraps `GET /wp/v2/users/<id>` via the Abilities REST Adapter and shapes the
 * response into a flat field set through {@see shapeOutput()}. The `email`,
 * `roles`, `capabilities`, and `registered_date` fields are only present when the
 * route runs the request in `edit` context with sufficient capability, so the
 * shaper copies them only when REST serves them. A password value is never output.
 * Permission delegates to the route's own check (no `require_permission` floor is
 * set): self is always readable in `view`, a public author with published posts is
 * viewable, `edit` context requires `edit_user`. Read-only.
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/users/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get User', 'abilities-catalog' ),
				'description'     => __( 'Returns a single user by ID. View context returns public fields (name, slug, url, description); edit context also returns email, roles, capabilities, and registered_date.', 'abilities-catalog' ),
				'category'        => 'og-core-users',
				'input_schema'    => array(
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
				'output_schema'   => $this->outputSchema(),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Maps the REST user body to the catalog's flat output shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST user body. Password values are never copied. The capability-gated fields
	 * (`email`, `roles`, `capabilities`, `registered_date`) are copied only when the
	 * REST response includes them — outside `edit` context, or for a caller without
	 * the capability, they are absent and stay absent. `$response` is part of the
	 * callback signature but unused here. `$input['id']` is the fallback ID when the
	 * body omits its own.
	 *
	 * @param mixed               $data     The REST user body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat user fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$id = absint( $input['id'] ?? 0 );

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
