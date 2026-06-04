<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Connectors;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `connectors/get-connector`.
 *
 * Returns a single AI provider connector by ID (`wp_get_connector()`) as a
 * strict, non-secret field set: `id`, `name`, `type`, and a boolean
 * `configured` flag.
 *
 * The API key is never read into the output. Connector records do not store the
 * key value; they only carry pointers (`setting_name`, `constant_name`,
 * `env_var_name`) to where the key lives. The `configured` flag is derived from
 * the core source resolver, which returns only a source label ('env',
 * 'constant', 'database', or 'none') and never the key itself.
 *
 * @since 0.1.0
 */
final class GetConnector implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'connectors/get-connector';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Connector', 'abilities-catalog' ),
			'description'         => __( 'Returns a single AI provider connector by ID with non-secret metadata. The API key is never returned; a boolean "configured" flag indicates whether a key is set.', 'abilities-catalog' ),
			'category'            => 'connectors',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'string',
						'description' => __( 'The connector identifier.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
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
	public function hasPermission( $input ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability, returning non-secret connector metadata.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{id:string,name:string,type:string,configured:bool}|\WP_Error The connector, or a 404 error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? (string) $input['id'] : '';

		$connector = wp_get_connector( $id );

		if ( null === $connector ) {
			return new WP_Error(
				'connector_not_found',
				__( 'The requested connector was not found.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'id'         => $id,
			'name'       => (string) ( $connector['name'] ?? '' ),
			'type'       => (string) ( $connector['type'] ?? '' ),
			'configured' => self::isConfigured( $connector ),
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
	 * @param array<string,mixed> $connector The connector record from `wp_get_connector()`.
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
