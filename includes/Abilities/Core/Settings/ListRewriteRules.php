<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `settings/list-rewrite-rules`.
 *
 * Returns the site's stored URL rewrite (permalink) rules — the regex-to-query
 * map WordPress uses to route pretty permalinks.
 *
 * Net-new read: reads the `rewrite_rules` option directly. It deliberately does
 * NOT call `WP_Rewrite::wp_rewrite_rules()`, which regenerates and WRITES the
 * option when it is empty (class-wp-rewrite.php:1493); that side effect would
 * break the `readonly:true` classification. An empty map while permalinks are in
 * use means the rules need regenerating via `settings/flush-rewrite-rules`.
 *
 * @since 0.1.0
 */
final class ListRewriteRules implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/list-rewrite-rules';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Rewrite Rules', 'abilities-catalog' ),
			'description'         => __( 'Returns the site\'s stored URL rewrite (permalink) rules: the regex-to-query map WordPress uses to route pretty permalinks, plus the permalink structure and a flag for whether pretty permalinks are in use. An empty rules map while using_permalinks is true means the rules need regenerating with settings/flush-rewrite-rules.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'permalink_structure', 'using_permalinks', 'rules', 'total' ),
				'properties'           => array(
					'permalink_structure' => array(
						'type'        => 'string',
						'description' => __( 'The permalink structure tag string; empty means plain (query-string) permalinks.', 'abilities-catalog' ),
					),
					'using_permalinks'    => array(
						'type'        => 'boolean',
						'description' => __( 'True when a non-empty permalink structure is set (pretty permalinks are in use).', 'abilities-catalog' ),
					),
					'rules'               => array(
						'type'                 => 'object',
						'description'          => __( 'The rewrite rules as a regex-to-query map: each key is a URL-path regex and each value is the internal query string it resolves to. Empty when no rules are stored.', 'abilities-catalog' ),
						'additionalProperties' => array(
							'type' => 'string',
						),
					),
					'total'               => array(
						'type'        => 'integer',
						'description' => __( 'The number of stored rewrite rules (the size of the rules map).', 'abilities-catalog' ),
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
	 * Permission check: the current user may manage options.
	 *
	 * Permalink/rewrite configuration is an admin concern, so this mirrors
	 * `settings/get-permalinks`. Rewrite rules are stored per-site (the
	 * `rewrite_rules` option), so `manage_options` is correct even on multisite.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by reading the stored rewrite rules option.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The rewrite rules, permalink structure, and counts.
	 */
	public function execute( $input = null ) {
		$structure = (string) ( get_option( 'permalink_structure' ) ?? '' );

		$stored = get_option( 'rewrite_rules', array() );
		$rules  = is_array( $stored ) ? $stored : array();

		return array(
			'permalink_structure' => $structure,
			'using_permalinks'    => '' !== $structure,
			'rules'               => (object) $rules,
			'total'               => count( $rules ),
		);
	}
}
