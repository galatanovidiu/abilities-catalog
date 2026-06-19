<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `media/upload-media`.
 *
 * Wraps `POST /wp/v2/media` via `rest_do_request()` to create a new attachment
 * from base64-encoded file bytes, setting any provided metadata fields (title,
 * alt text, caption, description, parent post) on the same create request. File
 * transport is base64 inline only: the decoded bytes
 * are sent as the request body with `Content-Type` and
 * `Content-Disposition: attachment; filename="..."` headers, so the attachments
 * controller's `upload_from_data()` path handles the upload and keeps the
 * `upload_files` capability. A remote `source_url` is never accepted or fetched
 * (SSRF guard). The decoded size is capped at `min(wp_max_upload_size(), 8 MiB)`;
 * an over-limit upload returns a 413 `WP_Error` before any REST dispatch.
 *
 * The `permission_callback` mirrors the controller's `create_item_permissions_check`
 * exactly: `upload_files`, plus object-level `edit_post` on the parent when a `post`
 * is supplied. Write annotations (`readonly:false, destructive:false,
 * idempotent:false`) route the outer `/run` call as POST.
 *
 * @since 0.3.0
 */
final class UploadMedia implements Ability {

	/**
	 * Hard upper bound on the decoded file size, in bytes (8 MiB).
	 *
	 * The effective cap is the smaller of this value and `wp_max_upload_size()`.
	 *
	 * @var int
	 */
	private const MAX_UPLOAD_BYTES = 8388608;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'media/upload-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Upload Media', 'abilities-catalog' ),
			'description'         => __( 'Uploads a new media item from a base64-encoded file and optionally sets its title, alt text, caption, description, and parent post.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'file'        => array(
						'type'        => 'string',
						'description' => __( 'The file contents, base64-encoded. Maximum decoded size is 8 MiB (or the server upload limit, whichever is smaller).', 'abilities-catalog' ),
					),
					'filename'    => array(
						'type'        => 'string',
						'description' => __( 'The file name, including extension (e.g. "photo.png"). Sanitized by WordPress.', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The media title.', 'abilities-catalog' ),
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => __( 'Alternative text for the media item.', 'abilities-catalog' ),
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => __( 'The media caption.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The media description.', 'abilities-catalog' ),
					),
					'post'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the post to attach the media to. Requires edit access to that post.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'file', 'filename' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'source_url' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The new attachment ID.', 'abilities-catalog' ),
					),
					'source_url'  => array(
						'type'        => 'string',
						'description' => __( 'The direct URL of the uploaded file.', 'abilities-catalog' ),
					),
					'media_type'  => array(
						'type'        => 'string',
						'description' => __( 'The media type (e.g. "image", "file").', 'abilities-catalog' ),
					),
					'mime_type'   => array(
						'type'        => 'string',
						'description' => __( 'The MIME type of the uploaded file.', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The resulting rendered media title.', 'abilities-catalog' ),
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting alternative text.', 'abilities-catalog' ),
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => __( 'The resulting rendered caption.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The resulting rendered description.', 'abilities-catalog' ),
					),
					'post'        => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the post the media is attached to, if any.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'upload.php',
			),
		);
	}

	/**
	 * Permission check: coarse `upload_files` capability; the route enforces the parent.
	 *
	 * `upload_files` is the object-independent capability the controller's
	 * `create_item_permissions_check` requires. When a `post` parent is supplied, the
	 * same wrapped `POST /wp/v2/media` route re-checks object-level `edit_post` on that
	 * parent, so the conditional parent check here is redundant — and doing it here
	 * collapses the route's specific `rest_cannot_edit` 403 / invalid-parent error into
	 * one generic denial (the Abilities API swallows a non-`true` return). Leaving it to
	 * the route surfaces the specific error.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can upload files at all.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Executes the ability: upload the file with all metadata on one create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The uploaded media fields, or a REST/validation error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$filename = sanitize_file_name( (string) ( $input['filename'] ?? '' ) );

		if ( '' === $filename ) {
			return new WP_Error(
				'abilities_catalog_invalid_filename',
				__( 'A valid filename is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$file = $input['file'] ?? '';
		if ( ! is_string( $file ) || '' === $file ) {
			return new WP_Error(
				'abilities_catalog_missing_file',
				__( 'A base64-encoded file is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$max_bytes = min( self::MAX_UPLOAD_BYTES, (int) wp_max_upload_size() );

		// Reject by the estimated decoded size before allocating the decoded
		// bytes, so an oversized payload never reaches base64_decode().
		if ( (int) ( strlen( $file ) * 3 / 4 ) > $max_bytes ) {
			return $this->tooLargeError( $max_bytes );
		}

		$bytes = $this->decodeFile( $file );
		if ( $bytes instanceof WP_Error ) {
			return $bytes;
		}

		if ( strlen( $bytes ) > $max_bytes ) {
			return $this->tooLargeError( $max_bytes );
		}

		$mime = $this->detectMime( $bytes, $filename );

		$upload = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$upload->set_header( 'Content-Type', $mime );
		$upload->set_header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		$upload->set_body( $bytes );

		// Set metadata whenever the key is present, so an explicit empty value
		// (e.g. alt_text: "") blanks the field rather than being ignored. This
		// also overrides any EXIF-derived metadata core would apply on insert.
		foreach ( array( 'title', 'caption', 'description', 'alt_text' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$upload->set_param( $field, (string) $input[ $field ] );
		}

		if ( ! empty( $input['post'] ) ) {
			$upload->set_param( 'post', absint( $input['post'] ) );
		}

		$response = rest_do_request( $upload );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		return array(
			'id'          => (int) ( $data['id'] ?? 0 ),
			'source_url'  => (string) ( $data['source_url'] ?? '' ),
			'media_type'  => (string) ( $data['media_type'] ?? '' ),
			'mime_type'   => (string) ( $data['mime_type'] ?? '' ),
			'title'       => (string) ( $data['title']['rendered'] ?? '' ),
			'alt_text'    => (string) ( $data['alt_text'] ?? '' ),
			'caption'     => (string) ( $data['caption']['rendered'] ?? '' ),
			'description' => (string) ( $data['description']['rendered'] ?? '' ),
			'post'        => (int) ( $data['post'] ?? 0 ),
		);
	}

	/**
	 * Builds the 413 over-limit error.
	 *
	 * @param int $max_bytes The effective maximum size in bytes.
	 * @return \WP_Error
	 */
	private function tooLargeError( int $max_bytes ): WP_Error {
		return new WP_Error(
			'abilities_catalog_file_too_large',
			sprintf(
				/* translators: %s: maximum allowed size in bytes. */
				__( 'The uploaded file exceeds the maximum allowed size of %s bytes.', 'abilities-catalog' ),
				number_format_i18n( $max_bytes )
			),
			array( 'status' => 413 )
		);
	}

	/**
	 * Decodes the base64 `file` input into raw bytes.
	 *
	 * @param string $file The base64-encoded file input.
	 * @return string|\WP_Error The decoded bytes, or an error when input is invalid.
	 */
	private function decodeFile( string $file ) {
		$decoded = base64_decode( $file, true );
		if ( false === $decoded || '' === $decoded ) {
			return new WP_Error(
				'abilities_catalog_invalid_file',
				__( 'The file could not be decoded. It must be valid base64.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		return $decoded;
	}

	/**
	 * Detects the MIME type from the file bytes, falling back to the filename.
	 *
	 * @param string $bytes    The decoded file bytes.
	 * @param string $filename The sanitized file name.
	 * @return string The detected MIME type, or `application/octet-stream`.
	 */
	private function detectMime( string $bytes, string $filename ): string {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				// No finfo_close(): deprecated since PHP 8.5; the finfo object is
				// freed automatically when it goes out of scope (PHP 8.1+).
				$detected = finfo_buffer( $finfo, $bytes );
				if ( is_string( $detected ) && '' !== $detected ) {
					return $detected;
				}
			}
		}

		$check = wp_check_filetype( $filename );
		if ( ! empty( $check['type'] ) ) {
			return (string) $check['type'];
		}

		return 'application/octet-stream';
	}
}
