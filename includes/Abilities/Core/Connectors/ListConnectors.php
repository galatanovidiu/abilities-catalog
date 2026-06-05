<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Connectors;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\ConnectorState;

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
 * non-secret field set: `id`, `name`, `type`, and the distinct usability signals
 * `configured`, `authentication_method`, `key_source`, and `connected`.
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
			'description'         => __( 'Lists registered connectors with non-secret metadata. The "type" field distinguishes AI providers ("ai_provider") from other kinds (e.g. "spam_filtering"). API keys are never returned. The "configured" flag reports exact credential presence; "authentication_method" (none|api_key), "key_source" (env|constant|database|none), and "connected" expose the distinct connector states.', 'abilities-catalog' ),
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
							'required'             => array( 'id', 'name', 'type', 'configured', 'authentication_method', 'key_source', 'connected' ),
							'additionalProperties' => false,
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
	 * @return array{items:array<int,array{id:string,name:string,type:string,configured:bool,authentication_method:string,key_source:string,connected:bool}>} The connector list.
	 */
	public function execute( $input = null ) {
		$items = array();

		foreach ( wp_get_connectors() as $id => $connector ) {
			// Inject the id so ConnectorState can resolve AI-provider connectivity,
			// which core keys by connector id.
			$connector['id'] = (string) $id;
			$state           = ConnectorState::resolve( $connector );

			$items[] = array(
				'id'                    => (string) $id,
				'name'                  => (string) ( $connector['name'] ?? '' ),
				'type'                  => (string) ( $connector['type'] ?? '' ),
				'configured'            => $state['configured'],
				'authentication_method' => $state['authentication_method'],
				'key_source'            => $state['key_source'],
				'connected'             => $state['connected'],
			);
		}

		return array(
			'items' => $items,
		);
	}
}
