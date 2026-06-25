<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw REST comment items into flat summary rows for the list ability.
 *
 * `og-comments/list-comments` wraps the core `GET /wp/v2/comments` route. Returning
 * `rest_get_server()->response_to_data()` verbatim leaked REST internals
 * (`_links`, `author_avatar_urls`, `meta`, GMT-duplicate dates, `content.raw`)
 * and cost thousands of tokens per call. This helper maps each item to a small,
 * predictable summary and exposes the matching `output_schema` fragment so the
 * shape and the schema stay in sync — the same pattern as {@see ContentListShaper}.
 *
 * Permission-gated fields (`author_email`, `author_ip`, `date_gmt`) exist in the
 * source row only under the `edit` context, which core grants solely to a user
 * with `moderate_comments`. The shaper copies those keys **only when the source
 * row carries them**, so a `view`-context row never fabricates a moderation-only
 * field and the closed schema (`additionalProperties:false`) stays honest.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.7.0
 */
final class CommentListShaper {

	/**
	 * Flat summary row for a comment REST item.
	 *
	 * The always-present keys mirror core's `view`/`embed` context fields. The
	 * moderation-only keys (`author_email`, `author_ip`, `date_gmt`) are copied
	 * only when present in the source row, i.e. when core served it in `edit`
	 * context to a moderator.
	 *
	 * @param array<string,mixed> $item A single comment from a REST collection response.
	 * @return array<string,mixed> The summary row. No `_links`, avatars, or `meta`.
	 */
	public static function commentSummary( array $item ): array {
		$row = array(
			'id'          => (int) ( $item['id'] ?? 0 ),
			'post'        => (int) ( $item['post'] ?? 0 ),
			'parent'      => (int) ( $item['parent'] ?? 0 ),
			'author'      => (int) ( $item['author'] ?? 0 ),
			'author_name' => (string) ( $item['author_name'] ?? '' ),
			'author_url'  => (string) ( $item['author_url'] ?? '' ),
			'date'        => (string) ( $item['date'] ?? '' ),
			'date_gmt'    => (string) ( $item['date_gmt'] ?? '' ),
			'link'        => (string) ( $item['link'] ?? '' ),
			'status'      => (string) ( $item['status'] ?? '' ),
			'type'        => (string) ( $item['type'] ?? '' ),
			'content'     => (string) ( $item['content']['rendered'] ?? '' ),
		);

		// Moderation-only fields: copy only when core included them (edit context,
		// which core grants solely to a user with `moderate_comments`).
		if ( array_key_exists( 'author_email', $item ) ) {
			$row['author_email'] = (string) $item['author_email'];
		}
		if ( array_key_exists( 'author_ip', $item ) ) {
			$row['author_ip'] = (string) $item['author_ip'];
		}

		return $row;
	}

	/**
	 * The `output_schema` item definition matching {@see self::commentSummary()}.
	 *
	 * The moderation-only fields are declared as optional properties (absent from
	 * `required`) so a `view`-context row that omits them still validates against
	 * the closed schema.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function commentItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'post', 'status', 'type', 'link' ),
			'properties'           => array(
				'id'           => array(
					'type'        => 'integer',
					'description' => __( 'The comment ID.', 'abilities-catalog' ),
				),
				'post'         => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post the comment is on.', 'abilities-catalog' ),
				),
				'parent'       => array(
					'type'        => 'integer',
					'description' => __( 'The parent comment ID, or 0 for a top-level comment.', 'abilities-catalog' ),
				),
				'author'       => array(
					'type'        => 'integer',
					'description' => __( 'The author user ID, or 0 for a guest comment.', 'abilities-catalog' ),
				),
				'author_name'  => array(
					'type'        => 'string',
					'description' => __( 'The display name of the comment author.', 'abilities-catalog' ),
				),
				'author_url'   => array(
					'type'        => 'string',
					'description' => __( 'The URL of the comment author.', 'abilities-catalog' ),
				),
				'author_email' => array(
					'type'        => 'string',
					'description' => __( 'The comment author email. Present only in edit context (requires "moderate_comments").', 'abilities-catalog' ),
				),
				'author_ip'    => array(
					'type'        => 'string',
					'description' => __( 'The comment author IP address. Present only in edit context (requires "moderate_comments").', 'abilities-catalog' ),
				),
				'date'         => array(
					'type'        => 'string',
					'description' => __( 'The date the comment was published, in site time.', 'abilities-catalog' ),
				),
				'date_gmt'     => array(
					'type'        => 'string',
					'description' => __( 'The date the comment was published, as GMT.', 'abilities-catalog' ),
				),
				'link'         => array(
					'type'        => 'string',
					'description' => __( 'The public URL to the comment.', 'abilities-catalog' ),
				),
				'status'       => array(
					'type'        => 'string',
					'description' => __( 'The comment status (e.g. "approved", "hold", "spam", "trash").', 'abilities-catalog' ),
				),
				'type'         => array(
					'type'        => 'string',
					'description' => __( 'The comment type (e.g. "comment").', 'abilities-catalog' ),
				),
				'content'      => array(
					'type'        => 'string',
					'description' => __( 'The rendered comment content. Use og-comments/get-comment for the full single comment.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
