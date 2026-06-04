<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Content;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T1 write ability: `content/update-post`.
 *
 * Wraps `POST /wp/v2/posts/<id>` via `rest_do_request()` and returns the post's
 * id, link, status, and modified date. The `permission_callback` encodes the
 * catalog's object-level capabilities — `edit_post` on the target post,
 * `publish_posts` when the requested status would publish, and
 * `edit_others_posts` when reassigning the post to another author. The REST
 * route re-checks every capability underneath (defense in depth) and handles
 * content sanitization.
 *
 * @since 0.2.0
 */
final class UpdatePost implements Ability
{
	/**
	 * Post statuses that require the `publish_posts` capability.
	 *
	 * @var string[]
	 */
	private const PUBLISH_STATUSES = array('publish', 'future', 'private');

	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'content/update-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Update Post', 'abilities-catalog'),
			'description'         => __('Updates an existing post by ID. Only the provided fields change. Set status to "publish" to publish it (requires publish capability).', 'abilities-catalog'),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'             => array(
						'type'        => 'integer',
						'description' => __('The post ID to update.', 'abilities-catalog'),
					),
					'title'          => array(
						'type'        => 'string',
						'description' => __('The post title.', 'abilities-catalog'),
					),
					'content'        => array(
						'type'        => 'string',
						'description' => __('The post content (HTML allowed; sanitized by WordPress).', 'abilities-catalog'),
					),
					'excerpt'        => array(
						'type'        => 'string',
						'description' => __('The post excerpt.', 'abilities-catalog'),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array('draft', 'pending', 'private', 'publish', 'future'),
						'description' => __('The post status.', 'abilities-catalog'),
					),
					'author'         => array(
						'type'        => 'integer',
						'description' => __('The author user ID. Setting another user requires the edit_others_posts capability.', 'abilities-catalog'),
					),
					'slug'           => array(
						'type'        => 'string',
						'description' => __('The post slug.', 'abilities-catalog'),
					),
					'date'           => array(
						'type'        => 'string',
						'description' => __('The publish date in site time (ISO 8601).', 'abilities-catalog'),
					),
					'categories'     => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Category term IDs to assign.', 'abilities-catalog'),
					),
					'tags'           => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Tag term IDs to assign.', 'abilities-catalog'),
					),
					'featured_media' => array(
						'type'        => 'integer',
						'description' => __('Attachment ID for the featured image.', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status', 'link'),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __('The post ID.', 'abilities-catalog'),
					),
					'link'     => array(
						'type'        => 'string',
						'description' => __('The post permalink.', 'abilities-catalog'),
					),
					'status'   => array(
						'type'        => 'string',
						'description' => __('The resulting post status.', 'abilities-catalog'),
					),
					'modified' => array(
						'type'        => 'string',
						'description' => __('The last-modified date in site time.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check encoding the catalog capabilities for updating a post.
	 *
	 * Requires object-level `edit_post` on the target; additionally
	 * `publish_posts` when the requested status would publish, and
	 * `edit_others_posts` when reassigning the post to another user. Per-term
	 * assignment capabilities are enforced by the REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the requested post.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		if (!current_user_can('edit_post', $id)) {
			return false;
		}

		if (isset($input['status']) && '' !== $input['status']) {
			$status = sanitize_key((string) $input['status']);
			if (in_array($status, self::PUBLISH_STATUSES, true) && !current_user_can('publish_posts')) {
				return false;
			}
		}

		if (!empty($input['author'])) {
			$author = absint($input['author']);
			if ($author !== get_current_user_id() && !current_user_can('edit_others_posts')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post's id, link, status, modified, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$request = new WP_REST_Request('POST', '/wp/v2/posts/' . $id);

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.). Control fields are sanitized here.
		foreach (array('title', 'content', 'excerpt', 'slug', 'date') as $field) {
			if (isset($input[$field]) && '' !== $input[$field]) {
				$request->set_param($field, (string) $input[$field]);
			}
		}

		if (isset($input['status']) && '' !== $input['status']) {
			$request->set_param('status', sanitize_key((string) $input['status']));
		}

		if (!empty($input['author'])) {
			$request->set_param('author', absint($input['author']));
		}

		if (!empty($input['featured_media'])) {
			$request->set_param('featured_media', absint($input['featured_media']));
		}

		foreach (array('categories', 'tags') as $taxonomy_field) {
			if (!empty($input[$taxonomy_field]) && is_array($input[$taxonomy_field])) {
				$request->set_param($taxonomy_field, array_map('absint', $input[$taxonomy_field]));
			}
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'       => (int) ($data['id'] ?? $id),
			'link'     => (string) ($data['link'] ?? ''),
			'status'   => (string) ($data['status'] ?? ''),
			'modified' => (string) ($data['modified'] ?? ''),
		);
	}
}
