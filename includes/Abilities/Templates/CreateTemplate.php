<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 non-destructive write ability: `templates/create-template`.
 *
 * Wraps `POST /wp/v2/templates` (for `wp_template`) or `POST /wp/v2/template-parts`
 * (for `wp_template_part`) via `rest_do_request()`. Creates a new site-editor
 * template or template part as a database record, identified afterwards by its
 * `theme//slug` id. It does NOT modify any theme file and does NOT overwrite an
 * existing customization — use `templates/update-template` to change an existing
 * one. The new template is editable in the Site Editor.
 *
 * Annotated as a non-destructive write (`destructive:false`): it only adds a new
 * record. The `permission_callback` mirrors
 * {@see \WP_REST_Templates_Controller::create_item_permissions_check()}, which
 * requires `edit_theme_options`. The REST route re-checks the capability and
 * sanitizes content (defense in depth).
 *
 * @since 0.5.0
 */
final class CreateTemplate implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'templates/create-template';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Create Template', 'abilities-catalog'),
			'description'         => __('Creates a new site-editor template or template part (post type wp_template / wp_template_part). Returns the new "theme//slug" id, status, and edit_link (the Site Editor URL) — surface edit_link so a human can open and finish the template. Does not change theme files and does not overwrite an existing template — use update-template for that. Send the content field as Gutenberg block markup (e.g. <!-- wp:template-part {"slug":"header"} /-->), not bare HTML.', 'abilities-catalog'),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __('The template slug (e.g. "page-about" or, for a part, "header"). Combined with the active theme to form the "theme//slug" id.', 'abilities-catalog'),
					),
					'post_type'   => array(
						'type'        => 'string',
						'enum'        => array('wp_template', 'wp_template_part'),
						'default'     => 'wp_template',
						'description' => __('Which collection to create in: "wp_template" or "wp_template_part".', 'abilities-catalog'),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __('The template title.', 'abilities-catalog'),
					),
					'content'     => array(
						'type'        => 'string',
						'description' => __('The template content as Gutenberg block markup (e.g. <!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->). Bare HTML saves but degrades to a single Classic block; compose blocks for an editable result.', 'abilities-catalog'),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __('The template description.', 'abilities-catalog'),
					),
				),
				'required'             => array('slug'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status', 'edit_link'),
				'properties'           => array(
					'id'        => array(
						'type'        => 'string',
						'description' => __('The new template id in "theme//slug" form.', 'abilities-catalog'),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __('The resulting template status.', 'abilities-catalog'),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __('The resulting template title.', 'abilities-catalog'),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __('The Site Editor URL where a human can open and edit the new template.', 'abilities-catalog'),
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
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the templates controller's create gate.
	 *
	 * {@see \WP_REST_Templates_Controller::create_item_permissions_check()}
	 * delegates to `permissions_check()`, which requires `edit_theme_options`.
	 * Not an object-level capability for templates. The REST route re-checks it.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create site-editor templates.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * Branches the route on `post_type`: `wp_template_part` uses
	 * `/wp/v2/template-parts`, everything else uses `/wp/v2/templates`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped new template, or the REST error.
	 */
	public function execute($input)
	{
		$input     = is_array($input) ? $input : array();
		$post_type = 'wp_template_part' === ($input['post_type'] ?? 'wp_template') ? 'wp_template_part' : 'wp_template';
		$base      = 'wp_template_part' === $post_type ? 'template-parts' : 'templates';

		$request = new WP_REST_Request('POST', '/wp/v2/' . $base);
		$request->set_param('slug', sanitize_title((string) ($input['slug'] ?? '')));

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

		$id        = (string) ($data['id'] ?? '');
		$edit_link = '' === $id
			? ''
			: admin_url('site-editor.php?postType=' . $post_type . '&postId=' . rawurlencode($id) . '&canvas=edit');

		return array(
			'id'        => $id,
			'status'    => (string) ($data['status'] ?? ''),
			'title'     => (string) $title,
			'edit_link' => $edit_link,
		);
	}
}
