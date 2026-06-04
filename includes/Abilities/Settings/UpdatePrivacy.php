<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 non-destructive write ability: `settings/update-privacy`.
 *
 * Updates the Privacy Settings screen: the page assigned as the privacy policy
 * page (`wp_page_for_privacy_policy`). Mirrors the matching read ability
 * {@see GetPrivacy}, which exposes the value as `page_for_privacy_policy`.
 *
 * The option is not in the core REST settings registry, so it is written directly
 * with `update_option()` after the capability check. Guarded by the dedicated
 * `manage_privacy_options` capability.
 *
 * @since 0.3.0
 */
final class UpdatePrivacy implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'settings/update-privacy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Update Privacy Settings', 'abilities-catalog'),
			'description'         => __('Updates Privacy Settings: the page ID assigned as the privacy policy page. Set to 0 to clear it.', 'abilities-catalog'),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_for_privacy_policy' => array(
						'type'        => 'integer',
						'description' => __('The page ID to assign as the privacy policy page; 0 to clear it.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('page_for_privacy_policy'),
				'properties'           => array(
					'page_for_privacy_policy' => array(
						'type'        => 'integer',
						'description' => __('The resulting privacy policy page ID; 0 if none.', 'abilities-catalog'),
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
				'screen'       => 'options-privacy.php',
			),
		);
	}

	/**
	 * Permission check: the current user may manage privacy options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage privacy options.
	 */
	public function hasPermission($input = null): bool
	{
		return current_user_can('manage_privacy_options');
	}

	/**
	 * Executes the ability by writing the privacy policy page option.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The resulting privacy settings, or a WP_Error.
	 */
	public function execute($input = null)
	{
		$input = is_array($input) ? $input : array();

		// Defense in depth: update_option() does not re-check the capability.
		if (!current_user_can('manage_privacy_options')) {
			return new \WP_Error(
				'webmcp_forbidden',
				__('You are not allowed to update privacy settings.', 'abilities-catalog'),
				array('status' => 403)
			);
		}

		if (array_key_exists('page_for_privacy_policy', $input)) {
			update_option('wp_page_for_privacy_policy', absint($input['page_for_privacy_policy']));
		}

		return array(
			'page_for_privacy_policy' => absint(get_option('wp_page_for_privacy_policy')),
		);
	}
}
