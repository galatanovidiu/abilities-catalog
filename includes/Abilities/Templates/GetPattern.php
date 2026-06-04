<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `templates/get-pattern`.
 *
 * Wraps `GET /wp/v2/blocks/<id>` via `rest_do_request()`. A user pattern is a
 * `wp_block` post (a reusable block / synced pattern). The permission is the
 * object-level `read_post` capability for that post id. Read-only.
 *
 * @since 0.1.0
 */
final class GetPattern implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'templates/get-pattern';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Pattern', 'abilities-catalog'),
			'description'         => __('Returns a single user pattern (reusable block, post type "wp_block") by ID.', 'abilities-catalog'),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The pattern (wp_block) post ID.', 'abilities-catalog'),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id'),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __('The pattern post ID.', 'abilities-catalog'),
					),
					'title'    => array(
						'type'        => 'string',
						'description' => __('The pattern title.', 'abilities-catalog'),
					),
					'content'  => array(
						'type'        => 'string',
						'description' => __('The pattern block markup.', 'abilities-catalog'),
					),
					'status'   => array(
						'type'        => 'string',
						'description' => __('The pattern status.', 'abilities-catalog'),
					),
					'date'     => array(
						'type'        => 'string',
						'description' => __('The publish date in site time.', 'abilities-catalog'),
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
	 * Permission check: object-level `read_post` on the requested pattern id.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the pattern.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		return current_user_can('read_post', $id);
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped pattern, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request('GET', '/wp/v2/blocks/' . $id);
		$request->set_param('context', $context);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		$title = $data['title'] ?? '';
		if (is_array($title)) {
			$title = $title['rendered'] ?? '';
		}

		$content = $data['content'] ?? '';
		if (is_array($content)) {
			$content = $content['raw'] ?? ($content['rendered'] ?? '');
		}

		return array(
			'id'       => (int) ($data['id'] ?? $id),
			'title'    => (string) $title,
			'content'  => (string) $content,
			'status'   => (string) ($data['status'] ?? ''),
			'date'     => (string) ($data['date'] ?? ''),
			'modified' => (string) ($data['modified'] ?? ''),
		);
	}
}
