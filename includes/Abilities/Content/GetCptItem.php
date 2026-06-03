<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Content;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `content/get-cpt-item`.
 *
 * Generic single-item reader keyed by `post_type`. Resolves the type's REST base
 * and wraps `GET /wp/v2/<rest_base>/<id>` via `rest_do_request()`. The capability
 * is object-level `read_post`.
 *
 * @since 0.1.0
 */
final class GetCptItem implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'content/get-cpt-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'content',
			'label'       => __('Content', 'abilities-catalog'),
			'description' => __('Abilities that read posts, pages, and other content.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Custom Post Type Item', 'abilities-catalog'),
			'description'         => __('Returns a single item of any REST-enabled post type by ID.', 'abilities-catalog'),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __('The post type slug.', 'abilities-catalog'),
					),
					'id'        => array(
						'type'        => 'integer',
						'description' => __('The item ID.', 'abilities-catalog'),
					),
					'context'   => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" or "edit".', 'abilities-catalog'),
					),
				),
				'required'             => array('post_type', 'id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'type'),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __('The item ID.', 'abilities-catalog'),
					),
					'title'    => array(
						'type'        => 'string',
						'description' => __('The rendered title.', 'abilities-catalog'),
					),
					'content'  => array(
						'type'        => 'string',
						'description' => __('The rendered content.', 'abilities-catalog'),
					),
					'excerpt'  => array(
						'type'        => 'string',
						'description' => __('The rendered excerpt.', 'abilities-catalog'),
					),
					'status'   => array(
						'type'        => 'string',
						'description' => __('The item status.', 'abilities-catalog'),
					),
					'author'   => array(
						'type'        => 'integer',
						'description' => __('The author user ID.', 'abilities-catalog'),
					),
					'link'     => array(
						'type'        => 'string',
						'description' => __('The public permalink.', 'abilities-catalog'),
					),
					'date'     => array(
						'type'        => 'string',
						'description' => __('The publish date in site time.', 'abilities-catalog'),
					),
					'modified' => array(
						'type'        => 'string',
						'description' => __('The last-modified date in site time.', 'abilities-catalog'),
					),
					'type'     => array(
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
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: object-level `read_post` on the requested item. Rejects
	 * unknown or non-REST post types and missing IDs.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the item.
	 */
	public function hasPermission($input): bool
	{
		$input     = is_array($input) ? $input : array();
		$post_type = isset($input['post_type']) ? (string) $input['post_type'] : '';
		$id        = isset($input['id']) ? absint($input['id']) : 0;

		if ('' === $post_type || $id <= 0) {
			return false;
		}

		$obj = get_post_type_object($post_type);
		if (!$obj || empty($obj->show_in_rest)) {
			return false;
		}

		return current_user_can('read_post', $id);
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat item fields, or an error.
	 */
	public function execute($input)
	{
		$input     = is_array($input) ? $input : array();
		$post_type = isset($input['post_type']) ? (string) $input['post_type'] : '';
		$id        = absint($input['id']);
		$context   = $input['context'] ?? 'view';

		$obj = get_post_type_object($post_type);
		if (!$obj || empty($obj->show_in_rest)) {
			return new WP_Error(
				'invalid_post_type',
				__('The requested post type does not exist or is not available in REST.', 'abilities-catalog'),
				array('status' => 400)
			);
		}

		$rest_base = $obj->rest_base ?: $post_type;

		$request = new WP_REST_Request('GET', '/wp/v2/' . $rest_base . '/' . $id);
		$request->set_param('context', $context);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'       => (int) ($data['id'] ?? $id),
			'title'    => (string) ($data['title']['rendered'] ?? ''),
			'content'  => (string) ($data['content']['rendered'] ?? ''),
			'excerpt'  => (string) ($data['excerpt']['rendered'] ?? ''),
			'status'   => (string) ($data['status'] ?? get_post_status($id)),
			'author'   => (int) ($data['author'] ?? 0),
			'link'     => (string) ($data['link'] ?? ''),
			'date'     => (string) ($data['date'] ?? ''),
			'modified' => (string) ($data['modified'] ?? ''),
			'type'     => (string) ($data['type'] ?? $post_type),
		);
	}
}
