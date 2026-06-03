<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Privacy;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use Automattic\AbilitiesCatalog\Support\AdminIncludes;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T3 dangerous write ability: `privacy/generate-export`.
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
 * After all exporters finish, the export file is written by
 * `wp_privacy_generate_personal_data_export_file($request_id)`, which lands the
 * file in core's index-protected exports directory. The download URL is stored on
 * the request by core; this ability never returns the file path or download URL.
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
final class GenerateExport implements Ability
{
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
	public function name(): string
	{
		return 'privacy/generate-export';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'privacy',
			'label'       => __('Privacy', 'abilities-catalog'),
			'description' => __('Abilities that read personal-data export and erasure requests.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Generate Export', 'abilities-catalog'),
			'description'         => __('Runs an existing personal-data export request to completion and produces the export file. Does not return the download URL.', 'abilities-catalog'),
			'category'            => 'privacy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __('The ID of an existing personal-data export request.', 'abilities-catalog'),
					),
				),
				'required'             => array('request_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('request_id', 'status', 'generated'),
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'description' => __('The export request ID.', 'abilities-catalog'),
					),
					'status'     => array(
						'type'        => 'string',
						'description' => __('The resulting request status.', 'abilities-catalog'),
					),
					'generated'  => array(
						'type'        => 'boolean',
						'description' => __('True when the export file was generated.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
					'dangerous'   => true,
				),
				'show_in_rest' => true,
				'screen'       => 'export-personal-data.php',
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
	public function hasPermission($input): bool
	{
		$input      = is_array($input) ? $input : array();
		$request_id = absint($input['request_id'] ?? 0);

		if ($request_id < 1) {
			return false;
		}

		return current_user_can('export_others_personal_data');
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
	public function execute($input)
	{
		$input      = is_array($input) ? $input : array();
		$request_id = absint($input['request_id'] ?? 0);

		$request = wp_get_user_request($request_id);
		if (!$request || 'export_personal_data' !== $request->action_name) {
			return new WP_Error(
				'webmcp_invalid_export_request',
				__('No personal-data export request was found for that ID.', 'abilities-catalog'),
				array('status' => 404)
			);
		}

		AdminIncludes::load('privacy-tools', 'file');

		$email_address = $request->email;
		if (!is_email($email_address)) {
			return new WP_Error(
				'webmcp_export_failed',
				__('The export request could not be processed.', 'abilities-catalog'),
				array('status' => 500)
			);
		}

		$exporters = apply_filters('wp_privacy_personal_data_exporters', array());
		if (!is_array($exporters)) {
			return new WP_Error(
				'webmcp_export_failed',
				__('The export request could not be processed.', 'abilities-catalog'),
				array('status' => 500)
			);
		}

		$exporter_keys  = array_keys($exporters);
		$exporter_count = count($exporters);
		$pages_total    = 0;

		// Iterate exporters 1-based, mirroring wp_ajax_wp_privacy_export_personal_data().
		for ($exporter_index = 1; $exporter_index <= $exporter_count; $exporter_index++) {
			$exporter_key = $exporter_keys[$exporter_index - 1];
			$exporter     = $exporters[$exporter_key];

			if (!is_array($exporter) || !isset($exporter['callback']) || !is_callable($exporter['callback'])) {
				return new WP_Error(
					'webmcp_export_failed',
					__('The export request could not be processed.', 'abilities-catalog'),
					array('status' => 500)
				);
			}

			$callback = $exporter['callback'];
			$page     = 1;

			do {
				if (++$pages_total > self::MAX_PAGES) {
					return new WP_Error(
						'webmcp_export_capped',
						__('The export was stopped because it exceeded the maximum number of pages.', 'abilities-catalog'),
						array('status' => 500)
					);
				}

				$response = call_user_func($callback, $email_address, $page);

				if (is_wp_error($response) || !is_array($response) || !isset($response['done'])) {
					return new WP_Error(
						'webmcp_export_failed',
						__('The export request could not be processed.', 'abilities-catalog'),
						array('status' => 500)
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

				if (is_wp_error($response) || !is_array($response) || !isset($response['done'])) {
					return new WP_Error(
						'webmcp_export_failed',
						__('The export request could not be processed.', 'abilities-catalog'),
						array('status' => 500)
					);
				}

				$page++;
			} while (true !== $response['done']);
		}

		wp_privacy_generate_personal_data_export_file($request_id);

		return array(
			'request_id' => $request_id,
			'status'     => (string) get_post_status($request_id),
			'generated'  => true,
		);
	}
}
