<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `content/get-post-revision`.
 *
 * Wraps `GET /wp/v2/posts/<parent>/revisions/<id>` via `rest_do_request()` and
 * shapes the response into a flat field set. The capability is object-level
 * `edit_post` on the parent post.
 *
 * @since 0.1.0
 */
final class GetPostRevision implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'content/get-post-revision';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Post Revision', 'abilities-catalog'),
			'description'         => __('Returns a single post revision by parent post ID and revision ID.', 'abilities-catalog'),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'parent'  => array(
						'type'        => 'integer',
						'description' => __('The parent post ID.', 'abilities-catalog'),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The revision ID.', 'abilities-catalog'),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" or "edit".', 'abilities-catalog'),
					),
				),
				'required'             => array('parent', 'id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'parent'),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __('The revision ID.', 'abilities-catalog'),
					),
					'parent'   => array(
						'type'        => 'integer',
						'description' => __('The parent post ID.', 'abilities-catalog'),
					),
					'title'    => array(
						'type'        => 'string',
						'description' => __('The rendered revision title.', 'abilities-catalog'),
					),
					'content'  => array(
						'type'        => 'string',
						'description' => __('The rendered revision content.', 'abilities-catalog'),
					),
					'excerpt'  => array(
						'type'        => 'string',
						'description' => __('The rendered revision excerpt.', 'abilities-catalog'),
					),
					'date'     => array(
						'type'        => 'string',
						'description' => __('The revision date in site time.', 'abilities-catalog'),
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
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: `edit_post` on the parent post (object-level).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit the parent post.
	 */
	public function hasPermission($input): bool
	{
		$input  = is_array($input) ? $input : array();
		$parent = isset($input['parent']) ? absint($input['parent']) : 0;
		$id     = isset($input['id']) ? absint($input['id']) : 0;

		if ($parent <= 0 || $id <= 0) {
			return false;
		}

		return current_user_can('edit_post', $parent);
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat revision fields, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$parent  = absint($input['parent']);
		$id      = absint($input['id']);
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request('GET', '/wp/v2/posts/' . $parent . '/revisions/' . $id);
		$request->set_param('context', $context);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'       => (int) ($data['id'] ?? $id),
			'parent'   => (int) ($data['parent'] ?? $parent),
			'title'    => (string) ($data['title']['rendered'] ?? ''),
			'content'  => (string) ($data['content']['rendered'] ?? ''),
			'excerpt'  => (string) ($data['excerpt']['rendered'] ?? ''),
			'date'     => (string) ($data['date'] ?? ''),
			'modified' => (string) ($data['modified'] ?? ''),
		);
	}
}
