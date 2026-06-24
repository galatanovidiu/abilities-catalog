<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Tools;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `tools/export-content`.
 *
 * Produces a WXR (WordPress eXtended RSS) export of site content via the core
 * `export_wp()` function. This is the classic content export, not the Site Editor
 * ZIP export. `export_wp()` echoes the XML directly and also sends file-download
 * headers (`Content-Disposition: attachment`, `Content-Type: text/xml`), so the
 * ability captures the body with output buffering and strips those leaked headers
 * — returning the XML inline in the response instead of forcing a download.
 *
 * Inline transport has a hard 5 MB size cap: a larger export returns a
 * `WP_Error` with HTTP 413 rather than a giant payload. The XML is not mutated;
 * this ability only reads and serializes existing content.
 *
 * @since 0.1.0
 */
final class ExportContent implements Ability {

	/**
	 * Maximum inline export size in bytes (5 MB).
	 *
	 * @var int
	 */
	private const MAX_BYTES = 5242880;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'tools/export-content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Export Content', 'abilities-catalog' ),
			'description'         => __( 'Exports site content as a WXR (WordPress eXtended RSS) XML document, filtered by type, date range, author, category, or status.', 'abilities-catalog' ),
			'category'            => 'tools',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'content'    => array(
						'type'        => 'string',
						'default'     => 'all',
						'description' => __( 'What to export: a post type slug, or "all" for everything. The remaining filters apply only for specific content types; an unrecognized slug exports all content.', 'abilities-catalog' ),
					),
					'start_date' => array(
						'type'        => 'string',
						'description' => __( 'Earliest publish date to include (e.g. "2024-01"). Applied only when "content" is "post", "page", or "attachment".', 'abilities-catalog' ),
					),
					'end_date'   => array(
						'type'        => 'string',
						'description' => __( 'Latest publish date to include (e.g. "2024-12"). Applied only when "content" is "post", "page", or "attachment".', 'abilities-catalog' ),
					),
					'author'     => array(
						'type'        => 'integer',
						'description' => __( 'Restrict to a single author user ID. Applied only when "content" is "post", "page", or "attachment".', 'abilities-catalog' ),
					),
					'category'   => array(
						'type'        => 'integer',
						'description' => __( 'Restrict to a single category term ID. Applied only when "content" is "post" and the term exists; otherwise core ignores it.', 'abilities-catalog' ),
					),
					'status'     => array(
						'type'        => 'string',
						'description' => __( 'Restrict to a single post status. Applied only when "content" is "post" or "page".', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'content_type', 'data', 'length' ),
				'properties'           => array(
					'content_type' => array(
						'type'        => 'string',
						'description' => __( 'The MIME type of the export payload.', 'abilities-catalog' ),
					),
					'data'         => array(
						'type'        => 'string',
						'description' => __( 'The WXR XML document.', 'abilities-catalog' ),
					),
					'length'       => array(
						'type'        => 'integer',
						'description' => __( 'The byte length of the export payload.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: the current user may export content.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user has the `export` capability.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'export' );
	}

	/**
	 * Executes the ability by capturing the WXR export.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The inline export, or an error if too large.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		AdminIncludes::load( 'export' );

		if ( ! function_exists( 'export_wp' ) ) {
			return new WP_Error(
				'export_unavailable',
				__( 'The export function is not available.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		$args = array(
			'content'    => isset( $input['content'] ) ? (string) $input['content'] : 'all',
			'start_date' => isset( $input['start_date'] ) ? (string) $input['start_date'] : '',
			'end_date'   => isset( $input['end_date'] ) ? (string) $input['end_date'] : '',
			'author'     => isset( $input['author'] ) ? (int) $input['author'] : 0,
			'category'   => isset( $input['category'] ) ? (int) $input['category'] : 0,
			'status'     => isset( $input['status'] ) ? (string) $input['status'] : '',
		);

		// Drop empty keys so export_wp() applies its own defaults.
		foreach ( $args as $key => $value ) {
			if ( '' !== $value && 0 !== $value ) {
				continue;
			}

			unset( $args[ $key ] );
		}

		// export_wp() unconditionally sends file-download headers via header() and
		// echoes the WXR. Two problems for a consumer-agnostic ability:
		//   1. Over a real REST request the header() calls (Content-Disposition:
		//      attachment, Content-Type: text/xml) are NOT captured by output
		//      buffering, so they leak onto the JSON response and force a download.
		//   2. Under a non-HTTP SAPI (WP-CLI, PHPUnit) the headers are already
		//      "sent", so each header() call raises a warning.
		// Swallow only that header warning for the duration of the call, capture the
		// body with output buffering, then strip the leaked download headers while
		// they can still be removed (an HTTP request, before the body is flushed).
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped, restored immediately below; the only safe way to tolerate export_wp()'s unconditional header() call under a non-HTTP SAPI.
		set_error_handler(
			static function ( $errno, $errstr ) {
				return false !== stripos( (string) $errstr, 'headers already sent' )
					|| false !== stripos( (string) $errstr, 'Cannot modify header information' );
			},
			E_WARNING
		);

		ob_start();
		export_wp( $args );
		$xml = (string) ob_get_clean();

		restore_error_handler();

		if ( ! headers_sent() ) {
			header_remove( 'Content-Description' );
			header_remove( 'Content-Disposition' );
			header_remove( 'Content-Type' );
		}

		$length = strlen( $xml );

		if ( $length > self::MAX_BYTES ) {
			return new WP_Error(
				'export_too_large',
				__( 'Export exceeds the 5 MB inline limit.', 'abilities-catalog' ),
				array(
					'status' => 413,
					'length' => $length,
				)
			);
		}

		return array(
			'content_type' => 'text/xml; charset=' . get_option( 'blog_charset' ),
			'data'         => $xml,
			'length'       => $length,
		);
	}
}
