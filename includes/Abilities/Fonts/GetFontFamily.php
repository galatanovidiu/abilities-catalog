<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Fonts;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `fonts/get-font-family`.
 *
 * Wraps `GET /wp/v2/font-families/<id>` via `rest_do_request()` and shapes the
 * response into a flat field set. Read-only; requires `edit_theme_options`.
 *
 * @since 0.1.0
 */
final class GetFontFamily implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'fonts/get-font-family';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'fonts',
			'label'       => __('Fonts', 'abilities-catalog'),
			'description' => __('Abilities that read installed font families and font collections.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Font Family', 'abilities-catalog'),
			'description'         => __('Returns a single installed font family by ID, including its settings and font faces.', 'abilities-catalog'),
			'category'            => 'fonts',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The font family post ID.', 'abilities-catalog'),
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
					'id'                 => array(
						'type'        => 'integer',
						'description' => __('The font family post ID.', 'abilities-catalog'),
					),
					'font_family_settings' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __('The font family settings (name, font family value, slug).', 'abilities-catalog'),
					),
					'font_faces'         => array(
						'type'        => 'array',
						'description' => __('The font face IDs or objects belonging to this family.', 'abilities-catalog'),
					),
					'theme_json_version' => array(
						'type'        => 'integer',
						'description' => __('The theme.json schema version of the family.', 'abilities-catalog'),
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
	 * Permission check: requires the theme-options capability (catalog).
	 *
	 * Returns false when no font family ID was supplied.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the font family.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat font-family fields, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id'] ?? 0);
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request('GET', '/wp/v2/font-families/' . $id);
		$request->set_param('context', $context);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'                   => (int) ($data['id'] ?? $id),
			'font_family_settings' => is_array($data['font_family_settings'] ?? null) ? $data['font_family_settings'] : array(),
			'font_faces'           => is_array($data['font_faces'] ?? null) ? $data['font_faces'] : array(),
			'theme_json_version'   => (int) ($data['theme_json_version'] ?? 0),
		);
	}
}
