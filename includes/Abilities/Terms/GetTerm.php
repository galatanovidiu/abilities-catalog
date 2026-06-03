<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Terms;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Generic read ability: `terms/get-term`.
 *
 * Wraps `GET /wp/v2/<rest_base>/<id>` for any REST-exposed taxonomy keyed by the
 * `taxonomy` input, and shapes the response into a flat field set.
 *
 * @since 0.1.0
 */
final class GetTerm implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'terms/get-term';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'terms',
			'label'       => __('Terms', 'abilities-catalog'),
			'description' => __('Abilities that read taxonomy terms (categories, tags, and custom taxonomies).', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Term', 'abilities-catalog'),
			'description'         => __('Returns a single term by ID from a given taxonomy.', 'abilities-catalog'),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __('The taxonomy slug (for example "category" or "post_tag").', 'abilities-catalog'),
					),
					'id'       => array(
						'type'        => 'integer',
						'description' => __('The term ID.', 'abilities-catalog'),
					),
					'context'  => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog'),
					),
				),
				'required'             => array('taxonomy', 'id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'name', 'slug'),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __('The term ID.', 'abilities-catalog'),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __('The term name.', 'abilities-catalog'),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __('The term slug.', 'abilities-catalog'),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __('The term description.', 'abilities-catalog'),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __('The parent term ID.', 'abilities-catalog'),
					),
					'count'       => array(
						'type'        => 'integer',
						'description' => __('Number of objects assigned to the term.', 'abilities-catalog'),
					),
					'taxonomy'    => array(
						'type'        => 'string',
						'description' => __('The taxonomy the term belongs to.', 'abilities-catalog'),
					),
					'link'        => array(
						'type'        => 'string',
						'description' => __('The public term archive URL.', 'abilities-catalog'),
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
	 * Permission check: term reads require an authenticated user.
	 *
	 * Edit-context additionally requires the taxonomy's `manage_terms` capability.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the term.
	 */
	public function hasPermission($input): bool
	{
		$input    = is_array($input) ? $input : array();
		$taxonomy = isset($input['taxonomy']) ? (string) $input['taxonomy'] : '';
		$id       = isset($input['id']) ? absint($input['id']) : 0;

		if ('' === $taxonomy || $id <= 0) {
			return false;
		}

		$context = $input['context'] ?? 'view';
		if ('edit' === $context) {
			$tax = get_taxonomy($taxonomy);
			if (!$tax) {
				return false;
			}
			return current_user_can($tax->cap->manage_terms);
		}

		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat term fields, or an error.
	 */
	public function execute($input)
	{
		$input    = is_array($input) ? $input : array();
		$taxonomy = isset($input['taxonomy']) ? (string) $input['taxonomy'] : '';
		$id       = absint($input['id']);
		$context  = $input['context'] ?? 'view';

		$tax = get_taxonomy($taxonomy);
		if (!$tax || !$tax->show_in_rest) {
			return new WP_Error(
				'invalid_taxonomy',
				__('The requested taxonomy is not available in the REST API.', 'abilities-catalog'),
				array('status' => 400)
			);
		}

		$rest_base = $tax->rest_base ?: $taxonomy;

		$request = new WP_REST_Request('GET', '/wp/v2/' . $rest_base . '/' . $id);
		$request->set_param('context', $context);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'          => (int) ($data['id'] ?? $id),
			'name'        => (string) ($data['name'] ?? ''),
			'slug'        => (string) ($data['slug'] ?? ''),
			'description' => (string) ($data['description'] ?? ''),
			'parent'      => (int) ($data['parent'] ?? 0),
			'count'       => (int) ($data['count'] ?? 0),
			'taxonomy'    => (string) ($data['taxonomy'] ?? $taxonomy),
			'link'        => (string) ($data['link'] ?? ''),
		);
	}
}
