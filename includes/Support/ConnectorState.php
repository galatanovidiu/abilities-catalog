<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the non-secret usability state of a WordPress 7.0 connector record.
 *
 * Connector records never store the API key value; they only carry pointers
 * (`setting_name`, `constant_name`, `env_var_name`) to where the key lives. This
 * resolver derives four distinct, non-secret signals from a connector record and
 * never reads the key value into its output:
 *
 * - `authentication_method` — `'api_key'` or `'none'`. Core constrains the method
 *   to exactly these two values
 *   ({@see \WP_Connector_Registry::register()}, class-wp-connector-registry.php:195).
 * - `key_source` — where the credential comes from: `'env'`, `'constant'`,
 *   `'database'`, or `'none'`. Mirrors the core resolver
 *   `_wp_connectors_get_api_key_source()` (connectors.php:440-464). For `none`-method
 *   connectors there is no credential pointer, so this is always `'none'`.
 * - `configured` — exact credential presence: `true` when a `none`-method connector
 *   (needs no key) or when an `api_key` connector has a key source other than `'none'`.
 * - `connected` — actual connectivity, mirroring the core `isConnected` signal used
 *   by the connectors admin UI (connectors.php:692-700): for `ai_provider` connectors,
 *   whether the AI client registry has the provider configured; for other `api_key`
 *   connectors, whether a key source exists; for `none`-method connectors, `true`.
 *
 * The single legacy `configured` boolean overstated usability because it returned
 * `true` for every non-`api_key` connector and whenever any key source was present.
 * These four fields keep `configured` honest (credential presence only) and add the
 * distinct `authentication_method`, `key_source`, and `connected` signals.
 *
 * @since 0.1.0
 */
final class ConnectorState {

	/**
	 * Resolves the non-secret state fields for a connector record.
	 *
	 * @param array<string,mixed> $connector The connector record from
	 *                                        `wp_get_connector()` / `wp_get_connectors()`.
	 * @return array{authentication_method:string,key_source:string,configured:bool,connected:bool}
	 */
	public static function resolve( array $connector ): array {
		$auth = isset( $connector['authentication'] ) && is_array( $connector['authentication'] )
			? $connector['authentication']
			: array();

		$method = 'api_key' === ( $auth['method'] ?? '' ) ? 'api_key' : 'none';

		if ( 'none' === $method ) {
			// No credential required: configured and connected, no key source.
			return array(
				'authentication_method' => 'none',
				'key_source'            => 'none',
				'configured'            => true,
				'connected'             => true,
			);
		}

		$key_source = self::keySource( $auth );
		$has_key    = 'none' !== $key_source;

		return array(
			'authentication_method' => 'api_key',
			'key_source'            => $key_source,
			'configured'            => $has_key,
			'connected'             => self::isConnected( $connector, $key_source ),
		);
	}

	/**
	 * Resolves the credential source label for an `api_key` connector.
	 *
	 * Prefers the core resolver `_wp_connectors_get_api_key_source()`, which returns
	 * `'env'`, `'constant'`, `'database'`, or `'none'` and never the key value. Falls
	 * back to a presence-only check when the core resolver is unavailable; the value
	 * is never read into output in either path.
	 *
	 * @param array<string,mixed> $auth The connector `authentication` sub-array.
	 * @return string One of `env`, `constant`, `database`, `none`.
	 */
	private static function keySource( array $auth ): string {
		$setting_name  = (string) ( $auth['setting_name'] ?? '' );
		$env_var_name  = (string) ( $auth['env_var_name'] ?? '' );
		$constant_name = (string) ( $auth['constant_name'] ?? '' );

		if ( function_exists( '_wp_connectors_get_api_key_source' ) ) {
			return _wp_connectors_get_api_key_source( $setting_name, $env_var_name, $constant_name );
		}

		if ( '' !== $env_var_name ) {
			$env_value = getenv( $env_var_name );
			if ( false !== $env_value && '' !== $env_value ) {
				return 'env';
			}
		}

		if ( '' !== $constant_name && defined( $constant_name ) ) {
			$const_value = constant( $constant_name );
			if ( is_string( $const_value ) && '' !== $const_value ) {
				return 'constant';
			}
		}

		if ( '' !== $setting_name && '' !== (string) get_option( $setting_name, '' ) ) {
			return 'database';
		}

		return 'none';
	}

	/**
	 * Resolves actual connectivity for an `api_key` connector.
	 *
	 * Mirrors core's `isConnected` logic (connectors.php:692-700): for `ai_provider`
	 * connectors, connectivity means the AI client registry has the provider and it is
	 * configured; for other `api_key` connectors, connectivity means a key source exists.
	 * The AI-provider path is guarded so this resolver degrades to the key-source signal
	 * when the AI client is unavailable.
	 *
	 * @param array<string,mixed> $connector  The connector record.
	 * @param string              $key_source The resolved key source label.
	 * @return bool True when the connector is actually connected.
	 */
	private static function isConnected( array $connector, string $key_source ): bool {
		$type = (string) ( $connector['type'] ?? '' );

		if ( 'ai_provider' !== $type ) {
			return 'none' !== $key_source;
		}

		$id = self::providerId( $connector );

		if ( '' === $id || ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			// Cannot query the AI client registry; fall back to key presence.
			return 'none' !== $key_source;
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			return $registry->hasProvider( $id ) && $registry->isProviderConfigured( $id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Extracts the connector id used as the AI provider id.
	 *
	 * The connector record does not carry its own id; core keys connectors by id in
	 * `wp_get_connectors()` and uses that key as the provider id. This resolver accepts
	 * an optional injected `id` on the record so callers can pass it through.
	 *
	 * @param array<string,mixed> $connector The connector record.
	 * @return string The provider id, or an empty string when unknown.
	 */
	private static function providerId( array $connector ): string {
		return isset( $connector['id'] ) ? (string) $connector['id'] : '';
	}
}
