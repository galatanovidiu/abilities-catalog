<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T1 write ability: `content/create-page`.
 *
 * Wraps `POST /wp/v2/pages` via `rest_do_request()` and returns the new page's
 * id, link, and status. The `permission_callback` encodes the catalog's page
 * capabilities — `edit_pages` to author a draft, `publish_pages` when the
 * requested status would publish, and `edit_others_pages` when authoring as
 * another user. The REST route re-checks every capability underneath (defense
 * in depth) and handles content sanitization.
 *
 * @since 0.2.0
 */
final class CreatePage implements Ability
{
	/**
	 * Page statuses that require the `publish_pages` capability.
	 *
	 * @var string[]
	 */
	private const PUBLISH_STATUSES = array('publish', 'future', 'private');

	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'content/create-page';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Create Page', 'abilities-catalog'),
			'description'         => __('Creates a new page. Defaults to a draft; set status to "publish" to publish it (requires publish capability).', 'abilities-catalog'),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'          => array(
						'type'        => 'string',
						'description' => __('The page title.', 'abilities-catalog'),
					),
					'content'        => array(
						'type'        => 'string',
						'description' => __('The page content (HTML allowed; sanitized by WordPress).', 'abilities-catalog'),
					),
					'excerpt'        => array(
						'type'        => 'string',
						'description' => __('The page excerpt.', 'abilities-catalog'),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array('draft', 'pending', 'private', 'publish', 'future'),
						'default'     => 'draft',
						'description' => __('The page status. Defaults to "draft".', 'abilities-catalog'),
					),
					'author'         => array(
						'type'        => 'integer',
						'description' => __('The author user ID. Setting another user requires the edit_others_pages capability.', 'abilities-catalog'),
					),
					'slug'           => array(
						'type'        => 'string',
						'description' => __('The page slug.', 'abilities-catalog'),
					),
					'date'           => array(
						'type'        => 'string',
						'description' => __('The publish date in site time (ISO 8601).', 'abilities-catalog'),
					),
					'parent'         => array(
						'type'        => 'integer',
						'description' => __('The parent page ID.', 'abilities-catalog'),
					),
					'menu_order'     => array(
						'type'        => 'integer',
						'description' => __('The page order value.', 'abilities-catalog'),
					),
					'template'       => array(
						'type'        => 'string',
						'description' => __('The page template file name.', 'abilities-catalog'),
					),
					'featured_media' => array(
						'type'        => 'integer',
						'description' => __('Attachment ID for the featured image.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status', 'link'),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __('The new page ID.', 'abilities-catalog'),
					),
					'link'   => array(
						'type'        => 'string',
						'description' => __('The page permalink.', 'abilities-catalog'),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __('The resulting page status.', 'abilities-catalog'),
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
	 * Permission check encoding the catalog capabilities for creating a page.
	 *
	 * Requires `edit_pages`; additionally `publish_pages` when the requested
	 * status would publish, and `edit_others_pages` when authoring as another
	 * user.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the requested page.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();

		if (!current_user_can('edit_pages')) {
			return false;
		}

		$status = isset($input['status']) ? sanitize_key((string) $input['status']) : 'draft';
		if (in_array($status, self::PUBLISH_STATUSES, true) && !current_user_can('publish_pages')) {
			return false;
		}

		if (!empty($input['author'])) {
			$author = absint($input['author']);
			if ($author !== get_current_user_id() && !current_user_can('edit_others_pages')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new page's id, link, status, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$request = new WP_REST_Request('POST', '/wp/v2/pages');

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.). Control fields are sanitized here.
		foreach (array('title', 'content', 'excerpt', 'slug', 'date', 'template') as $field) {
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

		if (!empty($input['parent'])) {
			$request->set_param('parent', absint($input['parent']));
		}

		if (isset($input['menu_order'])) {
			$request->set_param('menu_order', absint($input['menu_order']));
		}

		if (!empty($input['featured_media'])) {
			$request->set_param('featured_media', absint($input['featured_media']));
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
		);
	}
}
