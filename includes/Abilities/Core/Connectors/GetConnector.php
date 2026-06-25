<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Connectors;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\ConnectorState;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-connectors/get-connector`.
 *
 * Returns a single connector by ID (`wp_get_connector()`) as a strict,
 * non-secret field set: `id`, `name`, `type`, and the distinct usability signals
 * `configured`, `authentication_method`, `key_source`, and `connected`. Core
 * registers AI-provider connectors plus non-AI connectors such as `akismet`, and
 * plugins may register more via `wp_connectors_init`; this ability returns any
 * registered connector type.
 *
 * The API key is never read into the output. Connector records do not store the
 * key value; they only carry pointers (`setting_name`, `constant_name`,
 * `env_var_name`) to where the key lives. The state fields are derived by
 * {@see ConnectorState}, which returns only labels and booleans, never the key
 * itself:
 *
 * - `authentication_method` — `none` or `api_key`.
 * - `key_source` — `env`, `constant`, `database`, or `none` (credential location).
 * - `configured` — exact credential presence (a key source exists, or no key is
 *   required). This replaces the previous single overstated boolean.
 * - `connected` — actual connectivity: AI-provider registry status for
 *   `ai_provider` connectors, key presence otherwise, `true` for no-auth connectors.
 *
 * @since 0.1.0
 */
final class GetConnector implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-connectors/get-connector';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Connector', 'abilities-catalog' ),
			'description'         => __( 'Returns a single connector by ID with non-secret metadata. The API key is never returned. The "configured" flag reports exact credential presence; "authentication_method" (none|api_key), "key_source" (env|constant|database|none), and "connected" expose the distinct connector states.', 'abilities-catalog' ),
			'category'            => 'connectors',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'string',
						'description' => __( 'The connector identifier. Discover IDs via the og-connectors/list-connectors ability.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name', 'type', 'configured', 'authentication_method', 'key_source', 'connected' ),
				'properties'           => array(
					'id'                    => array(
						'type'        => 'string',
						'description' => __( 'The connector identifier.', 'abilities-catalog' ),
					),
					'name'                  => array(
						'type'        => 'string',
						'description' => __( 'The connector display name.', 'abilities-catalog' ),
					),
					'type'                  => array(
						'type'        => 'string',
						'description' => __( 'The connector type, e.g. "ai_provider".', 'abilities-catalog' ),
					),
					'configured'            => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a credential is present: a key source exists, or no key is required. The key value itself is never returned.', 'abilities-catalog' ),
					),
					'authentication_method' => array(
						'type'        => 'string',
						'enum'        => array( 'none', 'api_key' ),
						'description' => __( 'The authentication method: "none" (no key required) or "api_key".', 'abilities-catalog' ),
					),
					'key_source'            => array(
						'type'        => 'string',
						'enum'        => array( 'env', 'constant', 'database', 'none' ),
						'description' => __( 'Where the API key comes from: "env", "constant", "database", or "none" when no key is set or required. The key value itself is never returned.', 'abilities-catalog' ),
					),
					'connected'             => array(
						'type'        => 'boolean',
						'description' => __( 'Actual connectivity: for AI providers, whether the AI client has the provider configured; for other api_key connectors, whether a key source exists; always true for no-auth connectors.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'global',
				),
				'show_in_rest'      => true,
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
	 * @return array{id:string,name:string,type:string,configured:bool,authentication_method:string,key_source:string,connected:bool}|\WP_Error The connector, or a 404 error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? (string) $input['id'] : '';

		// Pre-check registration: `wp_get_connector()` calls `_doing_it_wrong()`
		// for any unregistered id, which emits a developer notice under WP_DEBUG
		// on the normal not-found path. Guard with the registration check first.
		$connector = wp_is_connector_registered( $id ) ? wp_get_connector( $id ) : null;

		if ( null === $connector ) {
			return new WP_Error(
				'connector_not_found',
				__( 'The requested connector was not found.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		// Inject the id so ConnectorState can resolve AI-provider connectivity,
		// which core keys by connector id.
		$connector['id'] = $id;
		$state           = ConnectorState::resolve( $connector );

		return array(
			'id'                    => $id,
			'name'                  => (string) ( $connector['name'] ?? '' ),
			'type'                  => (string) ( $connector['type'] ?? '' ),
			'configured'            => $state['configured'],
			'authentication_method' => $state['authentication_method'],
			'key_source'            => $state['key_source'],
			'connected'             => $state['connected'],
		);
	}
}
