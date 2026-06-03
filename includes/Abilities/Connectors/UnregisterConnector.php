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
 * T2 destructive write ability: `connectors/unregister-connector`.
 *
 * Unregisters an AI provider connector through the WordPress 7.0 connector
 * registry (`WP_Connector_Registry::unregister()`, reached via
 * `WP_Connector_Registry::get_instance()` — the same singleton the
 * `connectors/get-connector` and `connectors/list-connectors` reads wrap).
 *
 * This is destructive: removing a connector breaks any feature that relies on
 * the provider (for example AI text generation that uses its stored key). It is
 * not idempotent (a second call for the same ID fails as not-registered).
 *
 * The connector record holds no key value, only a pointer to where the key
 * lives, so unregistering does not move a secret. The output is strictly
 * `{id, unregistered}`. As a connectors ability it still routes any error
 * through {@see SecretSafeError::redact()} to keep the error shape uniform with
 * the secret-bearing register ability and to avoid echoing any wrapped detail.
 *
 * The registry has no dedicated capability; core's connectors admin screen
 * guards on `manage_options`, so this ability mirrors that (catalog open
 * decision #5).
 *
 * @since 0.3.0
 */
final class UnregisterConnector implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'connectors/unregister-connector';
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
			'label'               => __('Unregister Connector', 'abilities-catalog'),
			'description'         => __('Unregisters an AI provider connector by ID. This breaks any feature relying on the provider. Returns the connector ID and an unregistered flag.', 'abilities-catalog'),
			'category'            => 'connectors',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'string',
						'description' => __('The connector identifier to unregister.', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'unregistered'),
				'properties'           => array(
					'id'           => array(
						'type'        => 'string',
						'description' => __('The connector identifier.', 'abilities-catalog'),
					),
					'unregistered' => array(
						'type'        => 'boolean',
						'description' => __('Whether the connector was unregistered.', 'abilities-catalog'),
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
	 * Executes the ability, unregistering the connector.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{id:string,unregistered:bool}|\WP_Error The connector ID and unregistered flag, or a redacted error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? (string) $input['id'] : '';

		if ('' === $id) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_invalid_id',
					'Invalid connector identifier.',
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

		if (!$registry->is_registered($id)) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_not_found',
					'The requested connector was not found.',
					array('status' => 404)
				)
			);
		}

		$unregistered = $registry->unregister($id);

		if (!is_array($unregistered)) {
			return SecretSafeError::redact(
				new WP_Error(
					'connector_unregister_failed',
					'The connector could not be unregistered.',
					array('status' => 400)
				)
			);
		}

		return array(
			'id'           => $id,
			'unregistered' => true,
		);
	}
}
