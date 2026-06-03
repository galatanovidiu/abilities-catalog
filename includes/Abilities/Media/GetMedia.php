<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Media;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `media/get-media`.
 *
 * Wraps `GET /wp/v2/media/<id>` via `rest_do_request()` and shapes the response
 * into a flat field set. Read-only; REST enforces per-object visibility.
 *
 * @since 0.1.0
 */
final class GetMedia implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'media/get-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'media',
			'label'       => __('Media', 'abilities-catalog'),
			'description' => __('Abilities that read media library items.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Media', 'abilities-catalog'),
			'description'         => __('Returns a single media library item by ID, including its source URL, alt text, and media details.', 'abilities-catalog'),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The attachment (media item) ID.', 'abilities-catalog'),
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
				'required'             => array('id', 'source_url'),
				'properties'           => array(
					'id'            => array(
						'type'        => 'integer',
						'description' => __('The attachment ID.', 'abilities-catalog'),
					),
					'title'         => array(
						'type'        => 'string',
						'description' => __('The rendered media title.', 'abilities-catalog'),
					),
					'alt_text'      => array(
						'type'        => 'string',
						'description' => __('Alternative text for the media item.', 'abilities-catalog'),
					),
					'caption'       => array(
						'type'        => 'string',
						'description' => __('The rendered caption.', 'abilities-catalog'),
					),
					'description'   => array(
						'type'        => 'string',
						'description' => __('The rendered description.', 'abilities-catalog'),
					),
					'source_url'    => array(
						'type'        => 'string',
						'description' => __('The direct URL of the media file.', 'abilities-catalog'),
					),
					'media_type'    => array(
						'type'        => 'string',
						'description' => __('The media type (e.g. "image", "file").', 'abilities-catalog'),
					),
					'mime_type'     => array(
						'type'        => 'string',
						'description' => __('The MIME type of the media file.', 'abilities-catalog'),
					),
					'media_details' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __('Media-specific metadata (dimensions, sizes, etc.).', 'abilities-catalog'),
					),
					'post'          => array(
						'type'        => 'integer',
						'description' => __('The ID of the post the media is attached to, if any.', 'abilities-catalog'),
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
	 * Permission check: public for view; `edit_post` on the object for edit-context.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the media item.
	 */
	public function hasPermission($input): bool
	{
		$input   = is_array($input) ? $input : array();
		$id      = isset($input['id']) ? absint($input['id']) : 0;
		$context = $input['context'] ?? 'view';

		if ($id <= 0) {
			return false;
		}

		if ('edit' === $context) {
			return current_user_can('edit_post', $id);
		}

		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat media fields, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request('GET', '/wp/v2/media/' . $id);
		$request->set_param('context', $context);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'            => (int) ($data['id'] ?? $id),
			'title'         => (string) ($data['title']['rendered'] ?? ''),
			'alt_text'      => (string) ($data['alt_text'] ?? ''),
			'caption'       => (string) ($data['caption']['rendered'] ?? ''),
			'description'   => (string) ($data['description']['rendered'] ?? ''),
			'source_url'    => (string) ($data['source_url'] ?? ''),
			'media_type'    => (string) ($data['media_type'] ?? ''),
			'mime_type'     => (string) ($data['mime_type'] ?? ''),
			'media_details' => is_array($data['media_details'] ?? null) ? $data['media_details'] : array(),
			'post'          => (int) ($data['post'] ?? 0),
		);
	}
}
