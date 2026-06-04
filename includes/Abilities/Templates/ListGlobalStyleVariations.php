<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `templates/list-global-style-variations`.
 *
 * Wraps `GET /wp/v2/global-styles/themes/<stylesheet>/variations` via
 * `rest_do_request()`. Returns the style variations a theme ships (the alternate
 * palettes and type sets a user can switch between in the Site Editor's Styles
 * panel), each with its title and the theme.json-shaped settings and styles it
 * would apply. The `stylesheet` defaults to the active theme. Read-only.
 *
 * @since 0.5.0
 */
final class ListGlobalStyleVariations implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'templates/list-global-style-variations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('List Global Style Variations', 'abilities-catalog'),
			'description'         => __('Lists the style variations a theme provides (alternate palettes and typography sets selectable in the Site Editor Styles panel). Each variation includes its title and the theme.json settings and styles it applies. Defaults to the active theme.', 'abilities-catalog'),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'stylesheet' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => __('The theme stylesheet (directory name). Leave empty to use the active theme.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('stylesheet', 'items'),
				'properties'           => array(
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __('The theme stylesheet these variations belong to.', 'abilities-catalog'),
					),
					'items'      => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'properties'           => array(
								'title'    => array(
									'type'        => 'string',
									'description' => __('The variation title.', 'abilities-catalog'),
								),
								'settings' => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'description'          => __('The theme.json-shaped settings the variation applies.', 'abilities-catalog'),
								),
								'styles'   => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'description'          => __('The theme.json-shaped styles the variation applies.', 'abilities-catalog'),
								),
							),
							'additionalProperties' => true,
						),
						'description' => __('The list of style variations.', 'abilities-catalog'),
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
	 * Permission check: `edit_theme_options` (catalog capability for global styles).
	 *
	 * The theme global-styles route accepts `edit_posts` or `edit_theme_options`;
	 * this guard uses `edit_theme_options` and is never weaker than that route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read theme style variations.
	 */
	public function hasPermission($input = null): bool
	{
		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST request and shaping the result.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped variations, or the REST error.
	 */
	public function execute($input = null)
	{
		$input      = is_array($input) ? $input : array();
		$stylesheet = isset($input['stylesheet']) ? trim((string) $input['stylesheet']) : '';
		if ('' === $stylesheet) {
			$stylesheet = get_stylesheet();
		}

		$request = new WP_REST_Request('GET', '/wp/v2/global-styles/themes/' . $stylesheet . '/variations');

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data  = rest_get_server()->response_to_data($response, false);
		$items = array();

		foreach (is_array($data) ? $data : array() as $variation) {
			$items[] = array(
				'title'    => (string) ($variation['title'] ?? ''),
				'settings' => (object) (is_array($variation['settings'] ?? null) ? $variation['settings'] : array()),
				'styles'   => (object) (is_array($variation['styles'] ?? null) ? $variation['styles'] : array()),
			);
		}

		return array(
			'stylesheet' => $stylesheet,
			'items'      => $items,
		);
	}
}
