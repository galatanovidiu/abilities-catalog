<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw REST user items into flat summary rows for the list ability.
 *
 * `users/list-users` wraps the core `GET /wp/v2/users` route. Returning
 * `rest_get_server()->response_to_data()` verbatim leaked REST internals
 * (`_links`, `avatar_urls`, `meta`, the author `link`) and cost thousands of
 * tokens per call. This helper maps each item to a small, predictable summary and
 * exposes the matching `output_schema` fragment so the shape and the schema stay
 * in sync — the same pattern as {@see ContentListShaper} and {@see CommentListShaper}.
 * The always-present subset mirrors {@see \GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users\GetUser}'s
 * flat detail fields, so the list summary and the single-user read stay consistent;
 * the full record lives behind `users/get-user`.
 *
 * Permission-gated fields (`username`, `email`, `roles`) exist in the source row
 * only under the `edit` context, which core grants by request context and (for
 * `roles`) an extra `list_users`/`edit_user` capability check. The shaper copies
 * those keys **only when the source row carries them**, so a `view`-context row
 * never fabricates an edit-only field and the closed schema
 * (`additionalProperties:false`) stays honest.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.7.0
 */
final class UserListShaper {

	/**
	 * Flat summary row for a user REST item.
	 *
	 * The always-present keys mirror core's `view`/`embed` context fields. The
	 * edit-only keys (`username`, `email`, `roles`) are copied only when present
	 * in the source row, i.e. when core served it in `edit` context to a user with
	 * sufficient access.
	 *
	 * @param array<string,mixed> $item A single user from a REST collection response.
	 * @return array<string,mixed> The summary row. No `_links`, avatars, or `meta`.
	 */
	public static function userSummary( array $item ): array {
		$row = array(
			'id'          => (int) ( $item['id'] ?? 0 ),
			'name'        => (string) ( $item['name'] ?? '' ),
			'url'         => (string) ( $item['url'] ?? '' ),
			'description' => (string) ( $item['description'] ?? '' ),
			'slug'        => (string) ( $item['slug'] ?? '' ),
		);

		// Edit-only fields: copy only when core included them (edit context, which
		// core gates by request context plus, for roles, a list_users/edit_user check).
		if ( array_key_exists( 'username', $item ) ) {
			$row['username'] = (string) $item['username'];
		}
		if ( array_key_exists( 'email', $item ) ) {
			$row['email'] = (string) $item['email'];
		}
		if ( array_key_exists( 'roles', $item ) ) {
			$row['roles'] = array_map( 'strval', (array) $item['roles'] );
		}

		return $row;
	}

	/**
	 * The `output_schema` item definition matching {@see self::userSummary()}.
	 *
	 * The edit-only fields are declared as optional properties (absent from
	 * `required`) so a `view`-context row that omits them still validates against
	 * the closed schema.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function userItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'name', 'slug' ),
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'description' => __( 'The user ID.', 'abilities-catalog' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The display name for the user.', 'abilities-catalog' ),
				),
				'url'         => array(
					'type'        => 'string',
					'description' => __( 'The website URL for the user.', 'abilities-catalog' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'The biographical description for the user.', 'abilities-catalog' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'An alphanumeric identifier for the user.', 'abilities-catalog' ),
				),
				'username'    => array(
					'type'        => 'string',
					'description' => __( 'The login name. Present only in edit context (requires edit access).', 'abilities-catalog' ),
				),
				'email'       => array(
					'type'        => 'string',
					'description' => __( 'The email address. Present only in edit context (requires edit access).', 'abilities-catalog' ),
				),
				'roles'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Roles assigned to the user. Present only in edit context (requires "list_users" or "edit_user"). Use users/get-user for the full single user.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
