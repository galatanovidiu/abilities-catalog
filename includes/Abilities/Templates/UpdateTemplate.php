<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Templates;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 destructive write ability: `templates/update-template`.
 *
 * Wraps `POST /wp/v2/templates/<id>` (for `wp_template`) or
 * `POST /wp/v2/template-parts/<id>` (for `wp_template_part`) via
 * `rest_do_request()`. The template id has the form `theme//slug` (e.g.
 * `twentytwentyfive//home`); the `//` separator is part of the route path and is
 * not URL-encoded. The outer ability `/run` call is POST (an update, not a
 * delete); the internal REST verb is also POST (EDITABLE).
 *
 * This is annotated DESTRUCTIVE because it creates or replaces a database
 * override of a site-wide template or template part. The change is recoverable
 * (the original file-based template remains and the override can be deleted) but
 * has a high blast radius: it can alter the layout of every page that uses the
 * template. The browser exposes it only when both the adapter write setting and
 * destructive setting are on.
 *
 * The `permission_callback` mirrors
 * {@see \WP_REST_Templates_Controller::update_item_permissions_check()}, which
 * delegates to `permissions_check()` and requires `edit_theme_options`. The REST
 * route re-checks the capability underneath (defense in depth) and handles
 * content sanitization.
 *
 * @since 0.3.0
 */
final class UpdateTemplate implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'templates/update-template';
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
			'label'               => __('Update Template', 'abilities-catalog'),
			'description'         => __('Updates a site-editor template or template part by its "theme//slug" id. Creates or replaces a database override that changes site-wide layout. Only the provided fields change.', 'abilities-catalog'),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array(
						'type'        => 'string',
						'description' => __('The template id in "theme//slug" form (e.g. "twentytwentyfive//home").', 'abilities-catalog'),
					),
					'post_type'   => array(
						'type'        => 'string',
						'enum'        => array('wp_template', 'wp_template_part'),
						'default'     => 'wp_template',
						'description' => __('Which collection the id belongs to: "wp_template" or "wp_template_part".', 'abilities-catalog'),
					),
					'content'     => array(
						'type'        => 'string',
						'description' => __('The raw template block markup (HTML allowed; sanitized by WordPress).', 'abilities-catalog'),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __('The template title.', 'abilities-catalog'),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __('The template description.', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id'),
				'properties'           => array(
					'id'     => array(
						'type'        => 'string',
						'description' => __('The template id in "theme//slug" form.', 'abilities-catalog'),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __('The resulting template status.', 'abilities-catalog'),
					),
					'title'  => array(
						'type'        => 'string',
						'description' => __('The resulting template title.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the templates controller's update gate.
	 *
	 * {@see \WP_REST_Templates_Controller::update_item_permissions_check()}
	 * delegates to `permissions_check()`, which requires `edit_theme_options`.
	 * The capability is not object-level for templates. The REST route re-checks
	 * it underneath (defense in depth).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update site-editor templates.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * Branches the route on `post_type`: `wp_template_part` uses
	 * `/wp/v2/template-parts/<id>`, everything else uses `/wp/v2/templates/<id>`.
	 * The "theme//slug" id is part of the route path; the "//" is not URL-encoded.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped template, or the REST error.
	 */
	public function execute($input)
	{
		$input     = is_array($input) ? $input : array();
		$id        = (string) ($input['id'] ?? '');
		$post_type = $input['post_type'] ?? 'wp_template';
		$base      = 'wp_template_part' === $post_type ? 'template-parts' : 'templates';

		// The "theme//slug" id is part of the route path; do not URL-encode the "//".
		$request = new WP_REST_Request('POST', '/wp/v2/' . $base . '/' . $id);

		// String fields pass through to the REST route, which sanitizes them
		// (content via the block-markup pipeline, etc.).
		foreach (array('content', 'title', 'description') as $field) {
			if (isset($input[$field]) && '' !== $input[$field]) {
				$request->set_param($field, (string) $input[$field]);
			}
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
			'id'     => (string) ($data['id'] ?? $id),
			'status' => (string) ($data['status'] ?? ''),
			'title'  => (string) $title,
		);
	}
}
