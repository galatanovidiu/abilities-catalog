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
 * Read ability: `og-users/list-application-passwords`.
 *
 * Wraps `GET /wp/v2/users/<user_id>/application-passwords` via the Abilities REST
 * Adapter. The route's callback is `get_items`, so the adapter wraps its body in the
 * collection envelope `{ items, total, total_pages }`; this ability OVERRIDES the
 * output back to the catalog's flat `{ items }` shape, each row narrowed to the
 * documented metadata allowlist (uuid, name, created, last_used, last_ip) via
 * {@see shapeOutput()}. The path capture `user_id` is required by the route, so the
 * input schema keeps it OPTIONAL and an {@see fillUserId()} `input_callback` defaults
 * it to the current user when absent. The plaintext password is never present in a
 * list response and is never output. Permission delegates to the route's own check
 * (no `require_permission` floor): the object decision (own credentials vs another
 * user's `edit_user`) and its typed errors reach the caller unchanged. Read-only.
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords',
				'method'          => 'GET',
				'label'           => __( 'List Application Passwords', 'abilities-catalog' ),
				'description'     => __( 'Returns the application passwords for a user (metadata only, never the plaintext password).', 'abilities-catalog' ),
				'category'        => 'og-core-users',
				'input_schema'    => array(
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
				'output_schema'   => array(
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
				'input_callback'  => array( $this, 'fillUserId' ),
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
	 * Defaults the required `user_id` path capture to the current user.
	 *
	 * Wired as the adapter's `input_callback`, it runs after input validation, so the
	 * `user_id` schema property stays OPTIONAL (the route's path capture would
	 * otherwise force it required and reject a call that means "my own credentials").
	 * When the caller omits `user_id`, this injects the current user's ID so the
	 * route's `/users/<user_id>/...` path can be built.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with `user_id` guaranteed present.
	 */
	public function fillUserId( array $params ): array {
		if ( ! isset( $params['user_id'] ) ) {
			$params['user_id'] = get_current_user_id();
		}

		return $params;
	}

	/**
	 * Narrows the collection envelope to the catalog's flat metadata shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter builds for this `get_items`
	 * route. It reads the envelope's `items`, projects each row through
	 * {@see shapeItem()}, and returns `{ items }` — dropping `total`/`total_pages` and
	 * any field outside the documented allowlist. `$input` and `$response` are part of
	 * the callback signature but unused here; the envelope carries everything this
	 * shape needs.
	 *
	 * @param mixed               $data     The collection envelope (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat `{ items }` shape.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array_map(
			static fn ( $item ): array => self::shapeItem( is_array( $item ) ? $item : array() ),
			array_values( $items )
		);

		return array(
			'items' => $rows,
		);
	}

	/**
	 * Projects a raw REST application-password item into a fixed safe metadata row.
	 *
	 * Core's list route runs each row through `response_to_data()`, which carries
	 * extra fields beyond the documented metadata (`app_id`, `_links`), and the
	 * `rest_prepare_application_password` filter lets plugins add arbitrary fields.
	 * This allowlist keeps the output aligned with the documented "metadata only"
	 * contract. The plaintext password is never present in a list response, so there
	 * is nothing to strip there.
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
}
