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
