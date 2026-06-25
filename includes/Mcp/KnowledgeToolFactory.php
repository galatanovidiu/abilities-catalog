<?php
/**
 * Builds the cross-cutting knowledge MCP tool, shared by every server that exposes it.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeRegistry;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeTool;
use WP\MCP\Domain\Tools\McpTool;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single source for the standalone `knowledge` tool.
 *
 * The knowledge tool is not a domain — it serves OKF concepts (task recipes and authoring
 * guidelines) that span several domains. Both the curated {@see Server} and the scalable
 * {@see SearchServer} expose it as a standalone tool, so its name, description, input
 * schema and handler live here once and cannot drift between the two endpoints. Like every
 * `execute`, a concept's value still depends on the abilities it points at, each of which
 * keeps its own capability gate; this tool only shares the server's coarse permission floor.
 *
 * @since 0.4.0
 */
final class KnowledgeToolFactory {

	/**
	 * The tool name both servers register it under.
	 */
	public const TOOL_NAME = 'knowledge';

	/**
	 * Builds the knowledge tool for a server, on the given permission floor.
	 *
	 * @param callable $permission The shared coarse permission floor, `fn(array $args): bool|WP_Error`.
	 * @return \WP\MCP\Domain\Tools\McpTool|\WP_Error The knowledge tool, or a `WP_Error` when the adapter rejects the config.
	 */
	public static function create( callable $permission ) {
		return McpTool::fromArray(
			array(
				'name'        => self::TOOL_NAME,
				'description' => self::description(),
				'inputSchema' => KnowledgeTool::inputSchema(),
				'handler'     => array( new KnowledgeTool( new KnowledgeRegistry() ), 'handle' ),
				'permission'  => $permission,
			)
		);
	}

	/**
	 * The hand-written description for the knowledge tool.
	 *
	 * When to use it / what it returns / why. It carries no live concept index — that would
	 * force a full bundle scan on every request for a number the agent does not act on; the
	 * no-uri call returns the index when the agent wants it.
	 *
	 * @return string The tool description.
	 */
	public static function description(): string {
		return __( 'Curated, site-specific knowledge for this WordPress install: live facts about the site, plus recipes, guidelines, preferences, and references for working on it correctly. Before using any other tool on this site, call this once with no `uri` — that returns the live facts and an index of every knowledge document available. When one fits the task, call again with its `uri` to load it: these documents give the right abilities to run, in the right order, with the conventions that keep changes consistent with this site, so you skip guesswork and rework.', 'abilities-catalog' );
	}
}
