<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Connectors;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use Automattic\AbilitiesCatalog\Support\SecretSafeError;
use WP_Connector_Registry;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 destructive write ability: `connectors/register-connector`.
 *
 * Registers a new AI provider connector through the WordPress 7.0 connector
 * registry (`WP_Connector_Registry::register()`, reached via
 * `WP_Connector_Registry::get_instance()` — the same singleton the
 * `connectors/get-connector` and `connectors/list-connectors` reads wrap). On
 * success it also stores the supplied API key in the connector's settings
 * option so the provider becomes usable.
 *
 * SECURITY: the input carries a live API key. The key is a secret. It is never
 * logged, echoed, traced, or placed in the output. The output is strictly
 * `{id, registered}`. On any error path the raw error is replaced by a redacted
 * error via {@see SecretSafeError::redact()} so a wrapped error message cannot
 * reflect the submitted key. No `error_log`/`var_dump`/`print` of the input or
 * the key appears anywhere in this class.
 *
 * The registry has no dedicated capability; core's connectors admin screen
 * guards on `manage_options`, so this ability mirrors that (catalog open
 * decision #5). It is annotated destructive because registering a connector,
 * and persisting a provider key, changes the site's AI configuration and is not
 * idempotent (a second call with the same ID fails as already-registered).
 *
 * @since 0.3.0
 */
final class RegisterConnector implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'connectors/register-connector';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'connectors',
			'label'       => __('Connectors', 'abilities-catalog'),
			'description' => __('Abilities that read configured AI provider connectors (never exposing API keys).', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Register Connector', 'abilities-catalog'),
			'description'         => __('Registers a new AI provider connector and stores its API key. The API key is a secret: it is never returned. Returns only the connector ID and a registered flag.', 'abilities-catalog'),
			'category'            => 'connectors',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'string',
						'description' => __('The unique connector identifier. Lowercase alphanumeric, hyphens, and underscores only.', 'abilities-catalog'),
					),
					'name'    => array(
						'type'        => 'string',
						'description' => __('The connector display name.', 'abilities-catalog'),
					),
					'type'    => array(
						'type'        => 'string',
						'default'     => 'ai_provider',
						'description' => __('The connector type. Defaults to "ai_provider".', 'abilities-catalog'),
					),
					'api_key' => array(
						'type'        => 'string',
						'description' => __('The provider API key (secret). Stored in the connector setting; never returned.', 'abilities-catalog'),
					),
				),
				'required'             => array('id', 'api_key'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'registered'),
				'properties'           => array(
					'id'         => array(
						'type'        => 'string',
						'description' => __('The connector identifier.', 'abilities-catalog'),
					),
					'registered' => array(
						'type'        => 'boolean',
						'description' => __('Whether the connector was registered.', 'abilities-catalog'),
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
			),
		);
	}

	/**
	 * Permission check: connectors handle provider API keys.
	 *
	 * No dedicated capability exists in core; the registry methods are unguarded
	 * PHP and only the connectors admin screen checks `manage_options`, so this
	 * ability mirrors that.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage options.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('manage_options');
	}

	/**
	 * Executes the ability, registering the connector and storing its API key.
	 *
	 * The API key is never logged or returned. On failure a redacted error is
	 * returned so no wrapped message can reflect the submitted key.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{id:string,registered:bool}|\WP_Error The connector ID and registered flag, or a redacted error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = isset($input['id']) ? (string) $input['id'] : '';
		$name    = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';
		$type    = isset($input['type']) && '' !== $input['type'] ? sanitize_key((string) $input['type']) : 'ai_provider';
		$api_key = isset($input['api_key']) ? (string) $input['api_key'] : '';

		if ('' === $id || !preg_match('/^[a-z0-9_-]+$/', $id)) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_invalid_id',
					'Invalid connector identifier.',
					array('status' => 400)
				)
			);
		}

		if ('' === $api_key) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_missing_api_key',
					'Missing API key.',
					array('status' => 400)
				)
			);
		}

		$registry = WP_Connector_Registry::get_instance();
		if (null === $registry) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_registry_unavailable',
					'Connector registry is not available.',
					array('status' => 500)
				)
			);
		}

		if ($registry->is_registered($id)) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_already_registered',
					'Connector is already registered.',
					array('status' => 409)
				)
			);
		}

		$connector = $registry->register(
			$id,
			array(
				'name'           => '' !== $name ? $name : $id,
				'type'           => $type,
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);

		if (!is_array($connector)) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_register_failed',
					'The connector could not be registered.',
					array('status' => 400)
				)
			);
		}

		$setting_name = isset($connector['authentication']['setting_name'])
			? (string) $connector['authentication']['setting_name']
			: '';

		if ('' === $setting_name) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_register_failed',
					'The connector could not be registered.',
					array('status' => 400)
				)
			);
		}

		update_option($setting_name, $api_key);

		return array(
			'id'         => $id,
			'registered' => true,
		);
	}
}
