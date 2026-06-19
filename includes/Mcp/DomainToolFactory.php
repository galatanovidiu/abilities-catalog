<?php
/**
 * Builds one MCP tool per curated domain.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use WP\MCP\Domain\Tools\McpTool;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles the adapter `McpTool` for a domain from the shared parts.
 *
 * This is the only Phase-2 class that touches the adapter. Every domain tool has
 * the same shape — the `{action, ability, input}` input schema, a
 * {@see DomainToolHandler} bound to the domain, and a shared permission floor — so
 * the factory holds the router and floor once and stamps out a tool per call.
 *
 * @since 0.2.0
 */
final class DomainToolFactory {

	/**
	 * The router every tool this factory builds delegates to.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter
	 */
	private DomainRouter $router;

	/**
	 * The coarse permission floor applied to every tool ("may this user reach the server").
	 *
	 * @var callable
	 */
	private $permission;

	/**
	 * Constructor.
	 *
	 * @param \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter $router     The shared router.
	 * @param callable                                         $permission The shared permission floor.
	 */
	public function __construct( DomainRouter $router, callable $permission ) {
		$this->router     = $router;
		$this->permission = $permission;
	}

	/**
	 * Builds the MCP tool for one domain.
	 *
	 * @param string $domain      The domain slug; also the tool name.
	 * @param string $description The hand-written capability blurb for the tool.
	 * @return \WP\MCP\Domain\Tools\McpTool|\WP_Error The tool, or a `WP_Error` when the adapter rejects the config.
	 */
	public function forDomain( string $domain, string $description ) {
		$handler = new DomainToolHandler( $this->router, $domain );

		return McpTool::fromArray(
			array(
				'name'        => $domain,
				'description' => $description,
				'inputSchema' => self::inputSchema(),
				'handler'     => array( $handler, 'handle' ),
				'permission'  => $this->permission,
			)
		);
	}

	/**
	 * The input schema shared by every domain tool (spec §6).
	 *
	 * @return array<string,mixed> The JSON Schema for the `{action, ability, input}` envelope.
	 */
	private static function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'  => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'describe', 'execute' ),
					'description' => __( 'list the domain\'s abilities, describe one ability\'s schema, or execute one ability.', 'abilities-catalog' ),
				),
				'ability' => array(
					'type'        => 'string',
					'description' => __( 'Full ability name, e.g. content/get-post. Required for describe and execute.', 'abilities-catalog' ),
				),
				'input'   => array(
					'type'        => 'object',
					'description' => __( 'Arguments for the ability. Used by execute.', 'abilities-catalog' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}
}
