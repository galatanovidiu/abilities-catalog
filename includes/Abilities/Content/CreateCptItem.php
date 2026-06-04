<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 write ability: `content/create-cpt-item` (generic, keyed by `post_type`).
 *
 * Resolves the type's REST base and wraps `POST /wp/v2/<rest_base>` via
 * `rest_do_request()` to create an item of any registered `show_in_rest` post
 * type. Mirrors the create fan-out of `content/create-post`, but resolves every
 * capability per-type from `get_post_type_object()`: `create_posts` to author a
 * draft, `publish_posts` to publish, and `edit_others_posts` to set another user
 * as author. Defaults to a draft. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 * The REST route re-checks every capability underneath (defense in depth) and
 * handles content sanitization.
 *
 * @since 0.4.0
 */
final class CreateCptItem implements Ability
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
		return 'content/create-cpt-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Create Custom Post Type Item', 'abilities-catalog'),
			'description'         => __('Creates an item of any REST-enabled post type. Defaults to a draft; set status to "publish" to publish it (requires publish capability).', 'abilities-catalog'),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __('The post type slug (required).', 'abilities-catalog'),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __('The item title.', 'abilities-catalog'),
					),
					'content'   => array(
						'type'        => 'string',
						'description' => __('The item content (HTML allowed; sanitized by WordPress).', 'abilities-catalog'),
					),
					'excerpt'   => array(
						'type'        => 'string',
						'description' => __('The item excerpt.', 'abilities-catalog'),
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array('draft', 'pending', 'private', 'publish', 'future'),
						'default'     => 'draft',
						'description' => __('The item status. Defaults to "draft".', 'abilities-catalog'),
					),
					'slug'      => array(
						'type'        => 'string',
						'description' => __('The item slug.', 'abilities-catalog'),
					),
					'date'      => array(
						'type'        => 'string',
						'description' => __('The publish date in site time (ISO 8601).', 'abilities-catalog'),
					),
					'author'    => array(
						'type'        => 'integer',
						'description' => __('The author user ID. Setting another user requires the edit_others_posts capability.', 'abilities-catalog'),
					),
				),
				'required'             => array('post_type'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status'),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __('The new item ID.', 'abilities-catalog'),
					),
					'link'   => array(
						'type'        => 'string',
						'description' => __('The item permalink.', 'abilities-catalog'),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __('The resulting item status.', 'abilities-catalog'),
					),
					'type'   => array(
						'type'        => 'string',
						'description' => __('The post type slug.', 'abilities-catalog'),
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
			),
		);
	}

	/**
	 * Permission check encoding the per-type capabilities for creating an item.
	 *
	 * Validates the type is registered and `show_in_rest`, then requires the type's
	 * `create_posts` capability; additionally `publish_posts` when the requested
	 * status would publish, and `edit_others_posts` when authoring as another user.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the requested item.
	 */
	public function hasPermission($input): bool
	{
		$input     = is_array($input) ? $input : array();
		$post_type = isset($input['post_type']) ? (string) $input['post_type'] : '';

		if ('' === $post_type) {
			return false;
		}

		$obj = get_post_type_object($post_type);
		if (!$obj || empty($obj->show_in_rest)) {
			return false;
		}

		if (!current_user_can($obj->cap->create_posts)) {
			return false;
		}

		$status = isset($input['status']) ? sanitize_key((string) $input['status']) : 'draft';
		if (in_array($status, self::PUBLISH_STATUSES, true) && !current_user_can($obj->cap->publish_posts)) {
			return false;
		}

		if (!empty($input['author'])) {
			$author = absint($input['author']);
			if ($author !== get_current_user_id() && !current_user_can($obj->cap->edit_others_posts)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new item's id, link, status, type, or an error.
	 */
	public function execute($input)
	{
		$input     = is_array($input) ? $input : array();
		$post_type = isset($input['post_type']) ? (string) $input['post_type'] : '';

		$obj = get_post_type_object($post_type);
		if (!$obj || empty($obj->show_in_rest)) {
			return new WP_Error(
				'invalid_post_type',
				__('The requested post type does not exist or is not available in REST.', 'abilities-catalog'),
				array('status' => 400)
			);
		}

		$rest_base = $obj->rest_base ?: $post_type;

		$request = new WP_REST_Request('POST', '/wp/v2/' . $rest_base);

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

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'     => (int) ($data['id'] ?? 0),
			'link'   => (string) ($data['link'] ?? ''),
			'status' => (string) ($data['status'] ?? ''),
			'type'   => (string) ($data['type'] ?? $post_type),
		);
	}
}
