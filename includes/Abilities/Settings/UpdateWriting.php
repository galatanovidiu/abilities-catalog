<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Settings;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 non-destructive write ability: `settings/update-writing`.
 *
 * Updates the Writing Settings screen via `POST /wp/v2/settings`. The accepted
 * fields mirror the matching read ability {@see GetWriting}: default category,
 * default post format, and the smilies conversion flag. All three are exposed by
 * the core REST settings registry, so the write goes entirely through REST, which
 * sanitizes each value.
 *
 * @since 0.3.0
 */
final class UpdateWriting implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'settings/update-writing';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Update Writing Settings', 'abilities-catalog'),
			'description'         => __('Updates Writing Settings: default category, default post format, and the smilies conversion flag.', 'abilities-catalog'),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'default_category'    => array(
						'type'        => 'integer',
						'description' => __('The default post category term ID.', 'abilities-catalog'),
					),
					'default_post_format' => array(
						'type'        => 'string',
						'description' => __('The default post format (e.g. "standard").', 'abilities-catalog'),
					),
					'use_smilies'         => array(
						'type'        => 'boolean',
						'description' => __('Whether to convert text smileys to graphics on display.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('default_category'),
				'properties'           => array(
					'default_category'    => array(
						'type'        => 'integer',
						'description' => __('The resulting default post category term ID.', 'abilities-catalog'),
					),
					'default_post_format' => array(
						'type'        => 'string',
						'description' => __('The resulting default post format.', 'abilities-catalog'),
					),
					'use_smilies'         => array(
						'type'        => 'boolean',
						'description' => __('The resulting smilies conversion flag.', 'abilities-catalog'),
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
				'screen'       => 'options-writing.php',
			),
		);
	}

	/**
	 * Permission check: the current user may manage options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission($input = null): bool
	{
		return current_user_can('manage_options');
	}

	/**
	 * Executes the ability by writing the Writing Settings via REST.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The resulting writing settings, or a WP_Error.
	 */
	public function execute($input = null)
	{
		$input   = is_array($input) ? $input : array();
		$request = new WP_REST_Request('POST', '/wp/v2/settings');

		if (array_key_exists('default_category', $input)) {
			$request->set_param('default_category', absint($input['default_category']));
		}

		if (array_key_exists('default_post_format', $input)) {
			$request->set_param('default_post_format', (string) $input['default_post_format']);
		}

		if (array_key_exists('use_smilies', $input)) {
			$request->set_param('use_smilies', rest_sanitize_boolean($input['use_smilies']));
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'default_category'    => absint($data['default_category'] ?? 0),
			'default_post_format' => (string) ($data['default_post_format'] ?? ''),
			'use_smilies'         => (bool) ($data['use_smilies'] ?? false),
		);
	}
}
