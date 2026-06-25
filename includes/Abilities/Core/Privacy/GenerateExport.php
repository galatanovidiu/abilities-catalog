<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T3 dangerous write ability: `og-privacy/generate-export`.
 *
 * Runs an existing personal-data EXPORT request to completion server-side and
 * produces the export file. Export generation only: erase-request execution is
 * deliberately NOT built here.
 *
 * The wp-admin Export Personal Data screen drives generation page-by-page from
 * the browser via `wp_ajax_wp_privacy_export_personal_data()`
 * (wp-admin/includes/ajax-actions.php). This ability replicates that loop on the
 * server: for each registered exporter (1-based index, as core keys them) it
 * calls the exporter callback per page and feeds the response through
 * `wp_privacy_process_personal_data_export_page()` (wp-admin/includes/privacy-tools.php)
 * with the exact 7-argument signature core uses on this install, accumulating
 * data until every exporter reports `done`. A hard cap of {@see self::MAX_PAGES}
 * total processed pages bounds the run; exceeding it aborts with an error.
 *
 * The export file is written exactly once, by core itself: on the last page of the
 * last exporter, `wp_privacy_process_personal_data_export_page()` fires
 * `do_action('wp_privacy_personal_data_export_file', $request_id)` while the grouped
 * data is still present, then deletes that grouped meta. Core's file generator is
 * hooked to that action only in wp-admin context
 * (wp-admin/includes/admin-filters.php), so this ability registers it for the
 * duration of the run when it is absent (REST/WP-CLI) and never calls the generator a
 * second time — a post-loop call would read the already-deleted grouped data and
 * overwrite the real archive with an empty one. The file lands in core's
 * index-protected exports directory and the download URL is stored on the request;
 * this ability never returns the file path or download URL.
 *
 * The export is run with `$send_as_email = false`, so no confirmation email is
 * sent. Generation brings exported personal data together into a file, so this
 * ability is annotated dangerous and is exposed to the browser only behind the
 * third gate plus a per-ability opt-in. The capability check mirrors the
 * wp-admin Export Personal Data screen, which gates on
 * `export_others_personal_data` (wp-admin/export-personal-data.php) and is the
 * hard authorization guard here.
 *
 * @since 0.4.0
 */
final class GenerateExport implements Ability {

	/**
	 * Hard upper bound on the total number of exporter pages processed in one run.
	 *
	 * Bounds the server-side loop so a misbehaving exporter that never reports
	 * `done` cannot run unbounded.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 1000;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-privacy/generate-export';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Generate Export', 'abilities-catalog' ),
			'description'         => __( 'Runs an existing personal-data export request to completion and produces the export file. Does not return the download URL.', 'abilities-catalog' ),
			'category'            => 'og-core-privacy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of an existing personal-data export request. Use `og-privacy/list-export-requests` to find request IDs.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'request_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'request_id', 'status', 'generated' ),
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'description' => __( 'The export request ID.', 'abilities-catalog' ),
					),
					'status'     => array(
						'type'        => 'string',
						'description' => __( 'The request status after generation. With no confirmation email this stays request-pending or request-confirmed (never request-completed), so use the `generated` flag, not this field, as the success signal.', 'abilities-catalog' ),
					),
					'generated'  => array(
						'type'        => 'boolean',
						'description' => __( 'True when this run wrote the export archive file.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
					'dangerous'   => true,
				),
				'abilities_catalog' => array(
					'scope' => 'global',
				),
				'show_in_rest'      => true,
				'screen'            => 'export-personal-data.php',
			),
		);
	}

	/**
	 * Permission check: the cap required by the Export Personal Data screen.
	 *
	 * Mirrors wp-admin/export-personal-data.php, which gates on
	 * `export_others_personal_data`, matching the AJAX export handler's own check.
	 * Returns false when the required `request_id` input is missing or not positive.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may generate the export.
	 */
	public function hasPermission( $input ): bool {
		$input      = is_array( $input ) ? $input : array();
		$request_id = absint( $input['request_id'] ?? 0 );

		if ( $request_id < 1 ) {
			return false;
		}

		return current_user_can( 'export_others_personal_data' );
	}

	/**
	 * Executes the ability by running the export request to completion.
	 *
	 * Validates that `request_id` refers to a real `export_personal_data` request,
	 * then drives every registered exporter page-by-page exactly as
	 * `wp_ajax_wp_privacy_export_personal_data()` does, bounded by
	 * {@see self::MAX_PAGES}. Finalizes by generating the export file. Returns the
	 * request ID, resulting status, and a generated flag. Never returns the export
	 * file path or download URL, and never echoes the submitted email on error.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{request_id:int,status:string,generated:bool}|\WP_Error
	 */
	public function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$request_id = absint( $input['request_id'] ?? 0 );

		$request = wp_get_user_request( $request_id );
		if ( ! $request || 'export_personal_data' !== $request->action_name ) {
			return new WP_Error(
				'abilities_catalog_invalid_export_request',
				__( 'No personal-data export request was found for that ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		AdminIncludes::load( 'privacy-tools', 'file' );

		// Pre-flight: core's file generator calls wp_send_json_error() (→ wp_die())
		// when ZipArchive is missing (wp-admin/includes/privacy-tools.php:311-313),
		// which would terminate the request instead of returning a WP_Error. Guard
		// the most common trigger here so the ability can fail cleanly.
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'abilities_catalog_export_unavailable',
				__( 'The export file cannot be generated because the ZipArchive PHP extension is not available.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		$email_address = $request->email;
		if ( ! is_email( $email_address ) ) {
			return new WP_Error(
				'abilities_catalog_export_failed',
				__( 'The export request could not be processed.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading WordPress core's own privacy-exporters filter, not defining a plugin hook.
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		if ( ! is_array( $exporters ) ) {
			return new WP_Error(
				'abilities_catalog_export_failed',
				__( 'The export request could not be processed.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		// Finalization must happen exactly once, with the grouped export data still
		// present. On the last page of the last exporter, core's
		// wp_privacy_process_personal_data_export_page() saves _export_data_grouped, fires
		// do_action( 'wp_privacy_personal_data_export_file' ), then unconditionally deletes
		// _export_data_grouped (privacy-tools.php:856-868). The core generator is hooked to
		// that action only in wp-admin context (admin-filters.php:154); register it here when
		// absent (REST/WP-CLI) so core finalizes once. A second, post-loop generate call would
		// read the already-deleted grouped meta and overwrite the real archive with an empty
		// (null-payload) one.
		$added_generate_callback = ! has_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file' );
		if ( $added_generate_callback ) {
			add_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file', 10 );
		}

		// Capture whether THIS run wrote an archive. Core fires
		// wp_privacy_personal_data_export_file_created only after the ZIP is successfully
		// written (privacy-tools.php:563), making it an honest per-run signal — unlike the
		// persisted _export_file_name meta, which would survive a prior successful run.
		$export_file_created = false;
		$capture_created     = static function () use ( &$export_file_created ): void {
			$export_file_created = true;
		};
		add_action( 'wp_privacy_personal_data_export_file_created', $capture_created );

		try {
			$result = $this->processExporters( $exporters, $email_address, $request_id );
		} finally {
			// Restore global hook state on every exit path (success, error return, or throw).
			if ( $added_generate_callback ) {
				remove_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file', 10 );
			}
			remove_action( 'wp_privacy_personal_data_export_file_created', $capture_created );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'request_id' => $request_id,
			'status'     => (string) get_post_status( $request_id ),
			'generated'  => $export_file_created,
		);
	}

	/**
	 * Drives every registered exporter page-by-page to completion.
	 *
	 * Mirrors `wp_ajax_wp_privacy_export_personal_data()`: iterates exporters 1-based,
	 * calls each callback per page, and feeds the response through
	 * `wp_privacy_process_personal_data_export_page()` until every exporter reports
	 * `done`. Core finalizes the export file inside that call on the terminal page.
	 * Bounded by {@see self::MAX_PAGES}.
	 *
	 * @param array<string,mixed> $exporters     The registered personal-data exporters.
	 * @param string              $email_address The subject email address.
	 * @param int                 $request_id    The export request ID.
	 * @return true|\WP_Error True when every exporter finished, or a `WP_Error` on failure.
	 */
	private function processExporters( array $exporters, string $email_address, int $request_id ) {
		$exporter_keys  = array_keys( $exporters );
		$exporter_count = count( $exporters );
		$pages_total    = 0;

		// Iterate exporters 1-based, mirroring wp_ajax_wp_privacy_export_personal_data().
		for ( $exporter_index = 1; $exporter_index <= $exporter_count; $exporter_index++ ) {
			$exporter_key = $exporter_keys[ $exporter_index - 1 ];
			$exporter     = $exporters[ $exporter_key ];

			if ( ! is_array( $exporter ) || ! isset( $exporter['callback'] ) || ! is_callable( $exporter['callback'] ) ) {
				return new WP_Error(
					'abilities_catalog_export_failed',
					__( 'The export request could not be processed.', 'abilities-catalog' ),
					array( 'status' => 500 )
				);
			}

			$callback = $exporter['callback'];
			$page     = 1;

			do {
				if ( ++$pages_total > self::MAX_PAGES ) {
					return new WP_Error(
						'abilities_catalog_export_capped',
						__( 'The export was stopped because it exceeded the maximum number of pages.', 'abilities-catalog' ),
						array( 'status' => 500 )
					);
				}

				$response = call_user_func( $callback, $email_address, $page );

				// Preserve a real exporter WP_Error verbatim, as core does
				// (wp-admin/includes/ajax-actions.php:5057-5059): keep its code and message.
				if ( is_wp_error( $response ) ) {
					return $response;
				}

				// Mirror core's response-shape checks before processing
				// (wp-admin/includes/privacy-tools.php:774-787): require `done` and an
				// array `data`, otherwise the page would pass through unprocessed and the
				// export could "succeed" while incomplete.
				if (
					! is_array( $response )
					|| ! isset( $response['done'] )
					|| ! isset( $response['data'] )
					|| ! is_array( $response['data'] )
				) {
					return new WP_Error(
						'abilities_catalog_export_failed',
						__( 'The export request could not be processed.', 'abilities-catalog' ),
						array( 'status' => 500 )
					);
				}

				$response = wp_privacy_process_personal_data_export_page(
					$response,
					$exporter_index,
					$email_address,
					$page,
					$request_id,
					false,
					$exporter_key
				);

				if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['done'] ) ) {
					return new WP_Error(
						'abilities_catalog_export_failed',
						__( 'The export request could not be processed.', 'abilities-catalog' ),
						array( 'status' => 500 )
					);
				}

				++$page;
				// Core treats `done` truthily (wp-admin/includes/privacy-tools.php:818):
				// `$exporter_done = $response['done']`. Match that so an exporter that
				// returns 1 or '1' for done terminates instead of paging to MAX_PAGES.
			} while ( ! $response['done'] );
		}

		return true;
	}
}
