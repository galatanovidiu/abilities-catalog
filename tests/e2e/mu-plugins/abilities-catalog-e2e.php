<?php
/**
 * E2E-only direct MCP tools for exact-revision projection and header checks.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a throwaway write used to exercise the execute-ability consent gate.
 *
 * The gate applies to any ability not annotated `readonly`, and every ability the E2E
 * exposes is a read. This one is annotated as a write but only echoes its input, so the
 * confirmation paths can be driven over the wire without changing the site.
 */
add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! get_option( 'abilities_catalog_mcp_e2e_enabled', false ) ) {
			return;
		}

		wp_register_ability(
			'e2e-consent/echo-note',
			array(
				'label'               => 'Echo a note (E2E consent fixture)',
				'description'         => 'Returns the note it was given. Annotated as a write so the consent gate applies; it changes nothing.',
				'category'            => 'og-core-tools',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'note' => array( 'type' => 'string' ),
					),
					'required'             => array( 'note' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'note' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static fn ( $input ): array => array( 'note' => (string) ( $input['note'] ?? '' ) ),
				'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}
);

add_filter(
	'abilities_catalog_mcp_tools',
	static function ( array $tools ): array {
		if ( ! get_option( 'abilities_catalog_mcp_e2e_enabled', false ) ) {
			return $tools;
		}

		$header_tool = \WP\MCP\Domain\Tools\McpTool::fromArray(
			array(
				'name'        => 'header-echo',
				'description' => 'Echo a string whose value must be mirrored into an MCP request header.',
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'value' => array(
							'type'         => 'string',
							'description'  => 'Value returned by the tool.',
							'x-mcp-header' => 'Echo-Value',
						),
					),
					'required'             => array( 'value' ),
					'additionalProperties' => false,
				),
				'execution'   => array( 'taskSupport' => 'forbidden' ),
				'handler'     => static fn ( array $args ): array => array( 'value' => (string) ( $args['value'] ?? '' ) ),
				'permission'  => static fn (): bool => is_user_logged_in(),
			)
		);
		if ( ! is_wp_error( $header_tool ) ) {
			$tools[] = $header_tool;
		}

		$legacy_only = \WP\MCP\Domain\Tools\McpTool::fromArray(
			array(
				'name'        => 'legacy-only-header-tool',
				'description' => 'Projection fixture whose numeric header annotation is invalid only under MCP 2026.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'value' => array(
							'type'         => 'number',
							'x-mcp-header' => 'Legacy-Number',
						),
					),
				),
				'handler'     => static fn ( array $args ): array => array( 'value' => $args['value'] ?? null ),
				'permission'  => static fn (): bool => is_user_logged_in(),
			)
		);
		if ( ! is_wp_error( $legacy_only ) ) {
			$tools[] = $legacy_only;
		}

		return $tools;
	}
);
