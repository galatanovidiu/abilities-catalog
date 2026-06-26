<?php
/**
 * Plugin Name: wp-eval observability sink (DEV/EVAL ONLY)
 * Description: Captures every mcp-adapter observability event to a per-run JSONL file
 *              under uploads/eval-obs/. Installed into the wp-env container by the
 *              wp-eval harness; never shipped. Attaches via the plugin's
 *              `abilities_catalog_mcp_observability_handler` filter.
 *
 * The handler class is declared lazily on `mcp_adapter_init` (priority 1, before the
 * plugin's own createServer at priority 10) because mu-plugins load before the adapter
 * autoloader, so the interface it implements is not available at file-parse time.
 *
 * @package abilities-catalog-eval
 */

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

add_action(
	'mcp_adapter_init',
	static function (): void {
		if ( class_exists( 'Abilities_Catalog_Eval_Observability' ) ) {
			return;
		}
		if ( ! interface_exists( McpObservabilityHandlerInterface::class ) ) {
			return;
		}

		/**
		 * Append-only JSONL sink. One line per adapter event, attributed to the
		 * current eval run via the `abilities_catalog_eval_run_id` option (which the
		 * harness sets before each scenario). Best-effort: a write failure must never
		 * break the MCP request.
		 */
		class Abilities_Catalog_Eval_Observability implements McpObservabilityHandlerInterface {

			public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
				try {
					$run = (string) get_option( 'abilities_catalog_eval_run_id', '' );
					$up  = wp_upload_dir();
					$dir = trailingslashit( $up['basedir'] ) . 'eval-obs';
					if ( ! is_dir( $dir ) ) {
						wp_mkdir_p( $dir );
					}
					$name = '' !== $run ? sanitize_file_name( $run ) : '_unattributed';
					$line = wp_json_encode(
						array(
							'ts'          => microtime( true ),
							'run_id'      => $run,
							'event'       => $event,
							'duration_ms' => $duration_ms,
							'tags'        => $tags,
						)
					);
					file_put_contents( $dir . '/' . $name . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX );
				} catch ( \Throwable $e ) {
					// Observability is best-effort; never disturb the request.
					unset( $e );
				}
			}
		}
	},
	1
);

add_filter(
	'abilities_catalog_mcp_observability_handler',
	static function ( $handler ) {
		return class_exists( 'Abilities_Catalog_Eval_Observability' )
			? 'Abilities_Catalog_Eval_Observability'
			: $handler;
	}
);
