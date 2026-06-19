<?php
/**
 * The MCP shim for one domain tool.
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
 * Adapts one domain's {@see DomainRouter} to the MCP tool handler contract.
 *
 * A domain tool takes a single `{action, ability, input}` argument object. This
 * shim reads that envelope, dispatches to the matching router method, and maps the
 * result to what the adapter expects: a plain array on success, a `WP_Error` on
 * failure. The adapter does not validate arguments against the input schema, so
 * this shim validates the `action` itself.
 *
 * It also folds the error code and HTTP status into the `WP_Error` message,
 * because the adapter surfaces only the message on the error path — the code and
 * status would otherwise be lost to the agent.
 *
 * The shim carries no adapter dependency, so its dispatch and error folding are
 * unit-testable without booting a server.
 *
 * @since 0.2.0
 */
final class DomainToolHandler {

	/**
	 * The router this tool delegates to.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter
	 */
	private DomainRouter $router;

	/**
	 * The domain slug this tool owns.
	 *
	 * @var string
	 */
	private string $domain;

	/**
	 * Constructor.
	 *
	 * @param \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter $router The router.
	 * @param string                                           $domain The domain slug.
	 */
	public function __construct( DomainRouter $router, string $domain ) {
		$this->router = $router;
		$this->domain = $domain;
	}

	/**
	 * Handles one domain-tool call.
	 *
	 * @param array<string,mixed> $args The tool arguments: `action`, optional `ability`, optional `input`.
	 * @return mixed|\WP_Error A plain array (or ability result) on success; a folded `WP_Error` on failure.
	 */
	public function handle( array $args ) {
		$action = is_string( $args['action'] ?? null ) ? $args['action'] : '';

		switch ( $action ) {
			case 'list':
				return array( 'abilities' => $this->router->list( $this->domain ) );

			case 'describe':
				return $this->fold( $this->router->describe( $this->domain, $this->abilityName( $args ) ) );

			case 'execute':
				return $this->fold( $this->router->execute( $this->domain, $this->abilityName( $args ), $this->input( $args ) ) );

			default:
				return $this->fold(
					new WP_Error(
						'abilities_catalog_mcp_invalid_action',
						sprintf(
							/* translators: %s: the action the caller sent. */
							__( 'Unknown action "%s". Use "list", "describe" or "execute".', 'abilities-catalog' ),
							$action
						),
						array( 'status' => 400 )
					)
				);
		}
	}

	/**
	 * Reads the `ability` argument as a trimmed string.
	 *
	 * @param array<string,mixed> $args The tool arguments.
	 * @return string The ability name, or an empty string when absent.
	 */
	private function abilityName( array $args ): string {
		return is_string( $args['ability'] ?? null ) ? trim( $args['ability'] ) : '';
	}

	/**
	 * Reads the `input` argument as an array.
	 *
	 * @param array<string,mixed> $args The tool arguments.
	 * @return array<string,mixed> The ability input, or an empty array when absent.
	 */
	private function input( array $args ): array {
		return is_array( $args['input'] ?? null ) ? $args['input'] : array();
	}

	/**
	 * Passes a success result through; folds a `WP_Error` so the agent keeps the code and status.
	 *
	 * @param mixed|\WP_Error $result A router result.
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
