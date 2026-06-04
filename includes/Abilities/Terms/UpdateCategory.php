<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T1 safe-write ability: `terms/update-category`.
 *
 * Wraps `POST /wp/v2/categories/<id>` via `rest_do_request()` and returns the
 * updated term's id, name, and slug. The permission check mirrors the REST
 * terms controller update path: object-level `current_user_can('edit_term', $id)`.
 * The REST route re-checks the capability and sanitizes term fields underneath
 * (defense in depth).
 *
 * @since 0.3.0
 */
final class UpdateCategory implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'terms/update-category';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Update Category', 'abilities-catalog'),
			'description'         => __('Updates an existing category term by ID.', 'abilities-catalog'),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __('The category term ID (required).', 'abilities-catalog'),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __('The category name.', 'abilities-catalog'),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __('The category slug.', 'abilities-catalog'),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __('The category description.', 'abilities-catalog'),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __('The parent category term ID.', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'name', 'slug'),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __('The category term ID.', 'abilities-catalog'),
					),
					'name' => array(
						'type'        => 'string',
						'description' => __('The category name.', 'abilities-catalog'),
					),
					'slug' => array(
						'type'        => 'string',
						'description' => __('The category slug.', 'abilities-catalog'),
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
				'screen'       => 'term.php?taxonomy=category&tag_ID={id}',
			),
		);
	}

	/**
	 * Permission check mirroring the REST terms controller update path.
	 *
	 * Object-level `edit_term` on the target term.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the category.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		return current_user_can('edit_term', $id);
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated term's id, name, slug, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$request = new WP_REST_Request('POST', '/wp/v2/categories/' . $id);

		if (isset($input['name']) && '' !== $input['name']) {
			$request->set_param('name', sanitize_text_field((string) $input['name']));
		}

		if (isset($input['slug']) && '' !== $input['slug']) {
			$request->set_param('slug', sanitize_title((string) $input['slug']));
		}

		if (isset($input['description'])) {
			$request->set_param('description', sanitize_text_field((string) $input['description']));
		}

		if (isset($input['parent'])) {
			$request->set_param('parent', absint($input['parent']));
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'   => (int) ($data['id'] ?? $id),
			'name' => (string) ($data['name'] ?? ''),
			'slug' => (string) ($data['slug'] ?? ''),
		);
	}
}
