<?php
/**
 * The MCP shim for the knowledge tool.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapts the {@see KnowledgeRegistry} to the MCP tool handler contract.
 *
 * The knowledge tool takes a single optional `uri`. This shim reads it and dispatches:
 * no `uri` returns the index, a `uri` returns one concept. Both results are nested
 * under a key — `['index' => …]` or `['concept' => …]` — never spread at the top level,
 * because the adapter mirrors a success result verbatim into `structuredContent`
 * (which must be a JSON object) and reroutes any result whose top-level `type` is
 * `resource`/`image` or that carries `success:false`. Nesting keeps a concept's
 * frontmatter `type` away from that switch.
 *
 * It parallels {@see \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainToolHandler}: same
 * read-then-dispatch shape, and the same folding of the error code and HTTP status
 * into the `WP_Error` message, since the adapter surfaces only the message on the error
 * path. It carries no adapter dependency, so its dispatch and folding are unit-testable
 * without booting a server.
 *
 * @since 0.4.0
 */
final class KnowledgeTool {

	/**
	 * The registry this tool delegates to.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeRegistry
	 */
	private KnowledgeRegistry $registry;

	/**
	 * Constructor.
	 *
	 * @param \GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeRegistry $registry The knowledge registry.
	 */
	public function __construct( KnowledgeRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Handles one knowledge-tool call.
	 *
	 * @param array<string,mixed> $args The tool arguments: an optional `uri`.
	 * @return mixed|\WP_Error `['index' => …]` or `['concept' => …]` on success; a
	 *         folded `WP_Error` when the uri is unknown or unreadable.
	 */
	public function handle( array $args ) {
		$uri = is_string( $args['uri'] ?? null ) ? trim( $args['uri'] ) : '';

		if ( '' === $uri ) {
			return array( 'index' => $this->registry->rootIndex() );
		}

		$concept = $this->registry->load( $uri );
		if ( is_wp_error( $concept ) ) {
			return $this->fold( $concept );
		}

		return array( 'concept' => $concept );
	}

	/**
	 * The input schema for the knowledge tool: a single optional `uri`.
	 *
	 * No `outputSchema` is declared — the result is polymorphic (index vs concept) by
	 * design, which the adapter allows.
	 *
	 * @return array<string,mixed> The JSON Schema for the `{uri?}` envelope.
	 */
	public static function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'uri' => array(
					'type'        => 'string',
					'description' => __( 'A knowledge concept uri, e.g. core/create-content. Omit it to get the index: live site facts plus every available concept grouped by type.', 'abilities-catalog' ),
				),
			),
		);
	}

	/**
	 * Passes a success result through; folds a `WP_Error` so the agent keeps the code and status.
	 *
	 * @param mixed|\WP_Error $result A registry result.
	 * @return mixed|\WP_Error The result, or a `WP_Error` whose message carries the code and status.
	 */
	private function fold( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		$data   = $result->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403;
		$code   = $result->get_error_code();

		return new WP_Error(
			$code,
			sprintf( '%s (code: %s, status: %d)', $result->get_error_message(), $code, $status )
		);
	}
}
