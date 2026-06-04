<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Templates;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 destructive write ability: `templates/delete-template`.
 *
 * Wraps `DELETE /wp/v2/templates/<id>` (for `wp_template`) or
 * `DELETE /wp/v2/template-parts/<id>` (for `wp_template_part`) with `force=true`
 * via `rest_do_request()`. The id has the form `theme//slug`; the `//` is part of
 * the route path and is not URL-encoded.
 *
 * Behaviour depends on the template's source (the REST route enforces this):
 * - A customized THEME template (a theme file the user edited) is reverted to the
 *   theme default by deleting the database customization.
 * - A purely USER-CREATED custom template is removed entirely.
 * - A template that exists only as a theme file (never customized) cannot be
 *   deleted; the route returns "Templates based on theme files can't be removed."
 *
 * `force=true` is used because reverting/removing a template means permanently
 * deleting its `wp_template` post. Destructive: registered, exposed to the browser
 * only when both the write and destructive adapter settings are on. The
 * `permission_callback` mirrors
 * {@see \WP_REST_Templates_Controller::delete_item_permissions_check()}
 * (`edit_theme_options`); the REST route re-checks it (defense in depth).
 *
 * @since 0.5.0
 */
final class DeleteTemplate implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'templates/delete-template';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Delete Template', 'abilities-catalog'),
			'description'         => __('Deletes a site-editor template or template part by its "theme//slug" id. A customized theme template is reverted to the theme default; a user-created custom template is removed. Templates that exist only as theme files cannot be deleted. This permanently removes the database record and cannot be undone.', 'abilities-catalog'),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __('The template id in "theme//slug" form (e.g. "twentytwentyfive//single").', 'abilities-catalog'),
					),
					'post_type' => array(
						'type'        => 'string',
						'enum'        => array('wp_template', 'wp_template_part'),
						'default'     => 'wp_template',
						'description' => __('Which collection the id belongs to: "wp_template" or "wp_template_part".', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('deleted', 'id'),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __('Whether the template record was deleted (reverted/removed).', 'abilities-catalog'),
					),
					'id'      => array(
						'type'        => 'string',
						'description' => __('The deleted template id in "theme//slug" form.', 'abilities-catalog'),
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
	 * Permission check mirroring the templates controller's delete gate.
	 *
	 * {@see \WP_REST_Templates_Controller::delete_item_permissions_check()}
	 * delegates to `permissions_check()`, which requires `edit_theme_options`.
	 * Not an object-level capability for templates. The REST route re-checks it.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete site-editor templates.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Branches the route on `post_type`. Forces `force=true` so the template
	 * record is permanently deleted (reverting a customized theme template to its
	 * theme default, or removing a user-created custom template). Any REST error
	 * is returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or the REST error.
	 */
	public function execute($input)
	{
		$input     = is_array($input) ? $input : array();
		$id        = (string) ($input['id'] ?? '');
		$post_type = $input['post_type'] ?? 'wp_template';
		$base      = 'wp_template_part' === $post_type ? 'template-parts' : 'templates';

		// The "theme//slug" id is part of the route path; do not URL-encode the "//".
		$request = new WP_REST_Request('DELETE', '/wp/v2/' . $base . '/' . $id);
		$request->set_param('force', true);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'deleted' => (bool) ($data['deleted'] ?? false),
			'id'      => $id,
		);
	}
}
