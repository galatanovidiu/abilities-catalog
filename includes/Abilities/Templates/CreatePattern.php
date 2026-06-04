<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Templates;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 non-destructive write ability: `templates/create-pattern`.
 *
 * Wraps `POST /wp/v2/blocks` via `rest_do_request()`. A user pattern is a
 * `wp_block` post (a reusable block / synced pattern). The permission mirrors
 * the `WP_REST_Blocks_Controller` (which extends `WP_REST_Posts_Controller`):
 * `create_item_permissions_check()` requires the post type's `create_posts`
 * capability. For `wp_block` that capability is mapped to `publish_posts`
 * (see the `capabilities` array in the `wp_block` registration in
 * `wp-includes/post.php`), so creating ANY pattern — even a draft — requires
 * `publish_posts`. Publishing a pattern (status publish/future/private) also
 * requires `publish_posts` via `handle_status_param()`, which is already
 * subsumed by the create check.
 *
 * Write annotations (`readonly:false, destructive:false, idempotent:false`) so
 * the run controller routes the call as POST. The REST route re-checks the
 * capability and sanitizes the content (defense in depth).
 *
 * @since 0.2.0
 */
final class CreatePattern implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'templates/create-pattern';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Create Pattern', 'abilities-catalog'),
			'description'         => __('Creates a new user pattern (reusable block, post type "wp_block"). Publishes by default; set status to "draft" to keep it unpublished. Requires the publish capability.', 'abilities-catalog'),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'   => array(
						'type'        => 'string',
						'description' => __('The pattern title.', 'abilities-catalog'),
					),
					'content' => array(
						'type'        => 'string',
						'description' => __('The pattern block markup (serialized blocks; sanitized by WordPress).', 'abilities-catalog'),
					),
					'status'  => array(
						'type'        => 'string',
						'enum'        => array('draft', 'publish'),
						'default'     => 'publish',
						'description' => __('The pattern status. Defaults to "publish".', 'abilities-catalog'),
					),
				),
				'required'             => array('title', 'content'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status', 'link'),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __('The new pattern (wp_block) post ID.', 'abilities-catalog'),
					),
					'title'  => array(
						'type'        => 'string',
						'description' => __('The resulting pattern title.', 'abilities-catalog'),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __('The resulting pattern status.', 'abilities-catalog'),
					),
					'link'   => array(
						'type'        => 'string',
						'description' => __('The pattern permalink.', 'abilities-catalog'),
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
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the blocks controller's create check.
	 *
	 * Requires the `wp_block` post type's `create_posts` capability, which the
	 * post type maps to `publish_posts`. Resolved dynamically from the post type
	 * object so the gate is never weaker than the wrapped REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the requested pattern.
	 */
	public function hasPermission($input): bool
	{
		$post_type = get_post_type_object('wp_block');
		if (null === $post_type) {
			return false;
		}

		return current_user_can($post_type->cap->create_posts);
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new pattern's id, title, status, link, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$request = new WP_REST_Request('POST', '/wp/v2/blocks');

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.).
		foreach (array('title', 'content') as $field) {
			if (isset($input[$field]) && '' !== $input[$field]) {
				$request->set_param($field, (string) $input[$field]);
			}
		}

		if (isset($input['status']) && '' !== $input['status']) {
			$request->set_param('status', sanitize_key((string) $input['status']));
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		$title = $data['title'] ?? '';
		if (is_array($title)) {
			$title = $title['rendered'] ?? '';
		}

		return array(
			'id'     => (int) ($data['id'] ?? 0),
			'title'  => (string) $title,
			'status' => (string) ($data['status'] ?? ''),
			'link'   => (string) ($data['link'] ?? ''),
		);
	}
}
