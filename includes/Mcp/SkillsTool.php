<?php
/**
 * The MCP shim for the cross-cutting skills tool.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapts the {@see SkillsRegistry} to the MCP tool handler contract.
 *
 * The skills tool takes a single `{action, id}` argument object. This shim reads
 * that envelope, dispatches to the matching registry method, and maps the result to
 * what the adapter expects: a plain array on success, a `WP_Error` on failure. The
 * adapter does not validate arguments against the input schema, so this shim
 * validates the `action` itself.
 *
 * It parallels {@see DomainToolHandler} — same envelope-then-dispatch shape, same
 * folding of the error code and HTTP status into the `WP_Error` message (the adapter
 * surfaces only the message on the error path). It carries no adapter dependency, so
 * its dispatch and error folding are unit-testable without booting a server.
 *
 * @since 0.2.0
 */
final class SkillsTool {

	/**
	 * The registry this tool delegates to.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\SkillsRegistry
	 */
	private SkillsRegistry $registry;

	/**
	 * Constructor.
	 *
	 * @param \GalatanOvidiu\AbilitiesCatalog\Mcp\SkillsRegistry $registry The skills registry.
	 */
	public function __construct( SkillsRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Handles one skills-tool call.
	 *
	 * @param array<string,mixed> $args The tool arguments: `action`, and `id` for `get`.
	 * @return mixed|\WP_Error A plain array on success; a folded `WP_Error` on failure.
	 */
	public function handle( array $args ) {
		$action = is_string( $args['action'] ?? null ) ? $args['action'] : '';

		switch ( $action ) {
			case 'list':
				// Wrap in an object: the adapter mirrors a success result verbatim into
				// MCP structuredContent, which must be a JSON object — the bare list that
				// list() returns would serialize as a JSON array and violate that.
				return array( 'skills' => $this->registry->list() );

			case 'get':
				return $this->fold( $this->registry->get( $this->skillId( $args ) ) );

			default:
				return $this->fold(
					new WP_Error(
						'abilities_catalog_mcp_invalid_action',
						sprintf(
							/* translators: %s: the action the caller sent. */
							__( 'Unknown action "%s". Use "list" or "get".', 'abilities-catalog' ),
							$action
						),
						array( 'status' => 400 )
					)
				);
		}
	}

	/**
	 * The input schema for the skills tool (spec §10).
	 *
	 * @return array<string,mixed> The JSON Schema for the `{action, id}` envelope.
	 */
	public static function inputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action' => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'get' ),
					'description' => __( 'list the available skills, or get one skill\'s full recipe body.', 'abilities-catalog' ),
				),
				'id'     => array(
					'type'        => 'string',
					'description' => __( 'Skill id, e.g. create-content. Required for get.', 'abilities-catalog' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Reads the `id` argument as a trimmed string.
	 *
	 * @param array<string,mixed> $args The tool arguments.
	 * @return string The skill id, or an empty string when absent.
	 */
	private function skillId( array $args ): string {
		return is_string( $args['id'] ?? null ) ? trim( $args['id'] ) : '';
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
