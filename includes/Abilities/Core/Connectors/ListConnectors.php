<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Connectors;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `connectors/list-connectors`.
 *
 * Lists every connector registered through the WordPress 7.0 connectors API
 * (`wp_get_connectors()`). Core registers AI-provider connectors (`type` of
 * `ai_provider`) plus non-AI connectors such as `akismet` (`type` of
 * `spam_filtering`), and plugins may register more via `wp_connectors_init`;
 * the `type` field distinguishes them. Each connector is mapped to a strict,
 * non-secret field set: `id`, `name`, `type`, and a boolean `configured` flag.
 *
 * The API key is never read into the output. Connector records do not store the
 * key value; they only carry pointers (`setting_name`, `constant_name`,
 * `env_var_name`) to where the key lives. The `configured` flag is derived from
 * the core source resolver, which returns only a source label ('env',
 * 'constant', 'database', or 'none') and never the key itself.
 *
 * @since 0.1.0
 */
final class ListConnectors implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'connectors/list-connectors';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Connectors', 'abilities-catalog' ),
			'description'         => __( 'Lists registered connectors with non-secret metadata. The "type" field distinguishes AI providers ("ai_provider") from other kinds (e.g. "spam_filtering"). API keys are never returned; a boolean "configured" flag indicates whether a key is set.', 'abilities-catalog' ),
			'category'            => 'connectors',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The registered connectors.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id', 'name', 'type', 'configured' ),
							'additionalProperties' => false,
							'properties'           => array(
								'id'         => array(
									'type'        => 'string',
									'description' => __( 'The connector identifier.', 'abilities-catalog' ),
								),
								'name'       => array(
									'type'        => 'string',
									'description' => __( 'The connector display name.', 'abilities-catalog' ),
								),
								'type'       => array(
									'type'        => 'string',
									'description' => __( 'The connector type, e.g. "ai_provider".', 'abilities-catalog' ),
								),
								'configured' => array(
									'type'        => 'boolean',
									'description' => __( 'Whether an API key is configured for this connector. The key value itself is never returned.', 'abilities-catalog' ),
								),
							),
						),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
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
	 * Permission check: connectors handle provider API keys.
	 *
	 * No dedicated capability exists in core; the admin screen guards on
	 * `manage_options`, so this ability mirrors that.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability, returning non-secret connector metadata.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{items:array<int,array{id:string,name:string,type:string,configured:bool}>} The connector list.
	 */
	public function execute( $input = null ) {
		$items = array();

		foreach ( wp_get_connectors() as $id => $connector ) {
			$items[] = array(
				'id'         => (string) $id,
				'name'       => (string) ( $connector['name'] ?? '' ),
				'type'       => (string) ( $connector['type'] ?? '' ),
				'configured' => self::isConfigured( $connector ),
			);
		}

		return array(
			'items' => $items,
		);
	}

	/**
	 * Determines whether an API key is configured for a connector.
	 *
	 * Connector records never store the key value; they hold pointers to where
	 * the key lives. This method resolves only whether a key exists and never
	 * reads or returns the key value.
	 *
	 * Connectors using the `none` authentication method are always treated as
	 * configured (they need no key).
	 *
	 * @param array<string,mixed> $connector The connector record from `wp_get_connectors()`.
	 * @return bool True if a key is set (or none is required).
	 */
	private static function isConfigured( array $connector ): bool {
		$auth = isset( $connector['authentication'] ) && is_array( $connector['authentication'] )
			? $connector['authentication']
			: array();

		if ( ( $auth['method'] ?? '' ) !== 'api_key' ) {
			return true;
		}

		$setting_name  = (string) ( $auth['setting_name'] ?? '' );
		$env_var_name  = (string) ( $auth['env_var_name'] ?? '' );
		$constant_name = (string) ( $auth['constant_name'] ?? '' );

		if ( function_exists( '_wp_connectors_get_api_key_source' ) ) {
			return 'none' !== _wp_connectors_get_api_key_source( $setting_name, $env_var_name, $constant_name );
		}

		// Fallback: detect presence only, never read the value into output.
		if ( '' !== $env_var_name ) {
			$env_value = getenv( $env_var_name );
			if ( false !== $env_value && '' !== $env_value ) {
				return true;
			}
		}

		if ( '' !== $constant_name && defined( $constant_name ) ) {
			$const_value = constant( $constant_name );
			if ( is_string( $const_value ) && '' !== $const_value ) {
				return true;
			}
		}

		return '' !== $setting_name && '' !== (string) get_option( $setting_name, '' );
	}
}
