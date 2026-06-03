<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Templates;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `templates/list-templates`.
 *
 * Wraps `GET /wp/v2/templates` or `GET /wp/v2/template-parts` via
 * `rest_do_request()`, selected by the `post_type` input. Returns the
 * registered/customized site-editor templates for the active theme. Read-only.
 *
 * @since 0.1.0
 */
final class ListTemplates implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'templates/list-templates';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'templates',
			'label'       => __('Templates', 'abilities-catalog'),
			'description' => __('Abilities that read site-editor data: templates, template parts, patterns, and global styles.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('List Templates', 'abilities-catalog'),
			'description'         => __('Lists site-editor block templates or template parts for the active theme.', 'abilities-catalog'),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'enum'        => array('wp_template', 'wp_template_part'),
						'default'     => 'wp_template',
						'description' => __('Which collection to list: "wp_template" (templates) or "wp_template_part" (template parts).', 'abilities-catalog'),
					),
					'context'   => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('items'),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'description' => __('The list of templates or template parts.', 'abilities-catalog'),
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
	 * Permission check: `edit_theme_options` (catalog capability for templates).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read site-editor templates.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection, or the REST error.
	 */
	public function execute($input)
	{
		$input     = is_array($input) ? $input : array();
		$post_type = $input['post_type'] ?? 'wp_template';
		$route     = 'wp_template_part' === $post_type ? '/wp/v2/template-parts' : '/wp/v2/templates';

		$request = new WP_REST_Request('GET', $route);
		$request->set_param('context', $input['context'] ?? 'view');

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$items = rest_get_server()->response_to_data($response, false);

		return array(
			'items' => is_array($items) ? $items : array(),
		);
	}
}
