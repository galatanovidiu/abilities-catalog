<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `media/get-media-file`.
 *
 * Net-new (no core REST route returns file bytes). Reads an attachment file from
 * disk and returns it base64-encoded, with a hard size ceiling. Above the ceiling
 * the file is not encoded; the caller gets the source URL to fetch directly.
 *
 * @since 0.1.0
 */
final class GetMediaFile implements Ability {

	/**
	 * Maximum file size eligible for inline base64 encoding (5 MB).
	 *
	 * @var int
	 */
	private const MAX_INLINE_BYTES = 5 * 1024 * 1024;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'media/get-media-file';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Media File', 'abilities-catalog' ),
			'description'         => __( 'Returns the bytes of a media file, base64-encoded. When the file exceeds the inline size limit, returns a "file_too_large" error whose data carries the source URL to fetch directly.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The attachment (media item) ID. Discover IDs via media/list-media or search/search-content.', 'abilities-catalog' ),
					),
					'size' => array(
						'type'        => 'string',
						'default'     => 'full',
						'description' => __( 'Image size name (e.g. "thumbnail", "medium", "full"). Discover valid size names via media/list-image-sizes. Falls back to "full" if the size is unavailable.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'data', 'mime_type' ),
				'properties'           => array(
					'data'      => array(
						'type'        => 'string',
						'description' => __( 'The file contents, base64-encoded.', 'abilities-catalog' ),
					),
					'mime_type' => array(
						'type'        => 'string',
						'description' => __( 'The MIME type of the file.', 'abilities-catalog' ),
					),
					'filename'  => array(
						'type'        => 'string',
						'description' => __( 'The base file name.', 'abilities-catalog' ),
					),
					'width'     => array(
						'type'        => 'integer',
						'description' => __( 'Image width in pixels; 0 for non-images.', 'abilities-catalog' ),
					),
					'height'    => array(
						'type'        => 'integer',
						'description' => __( 'Image height in pixels; 0 for non-images.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: read access to the attachment (object-level).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the attachment.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'read_post', $id );
	}

	/**
	 * Executes the ability by reading the attachment file from disk.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The encoded file payload, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$size  = isset( $input['size'] ) ? (string) $input['size'] : 'full';

		if ( $id <= 0 || ! get_post( $id ) || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'The requested attachment does not exist.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$resolved = $this->resolve( $id, $size );
		$path     = $resolved['path'];

		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'The attachment file could not be read.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$filesize = (int) filesize( $path );

		if ( $filesize > self::MAX_INLINE_BYTES ) {
			$source_url = '' !== $resolved['url'] ? $resolved['url'] : (string) wp_get_attachment_url( $id );

			return new WP_Error(
				'file_too_large',
				__( 'File exceeds the 5 MB inline limit; use the source URL instead.', 'abilities-catalog' ),
				array(
					'status'     => 413,
					'source_url' => $source_url,
				)
			);
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- $path is a local attachment file on disk, not a remote URL; caching does not apply.
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'The attachment file could not be read.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$dimensions = $this->resolveDimensions( $id, $path, $resolved );
		$width      = $dimensions['width'];
		$height     = $dimensions['height'];

		$mime_type = (string) get_post_mime_type( $id );
		if ( function_exists( 'wp_check_filetype' ) ) {
			$type = wp_check_filetype( basename( $path ) );
			if ( ! empty( $type['type'] ) ) {
				$mime_type = (string) $type['type'];
			}
		}

		return array(
			'data'      => base64_encode( $contents ),
			'mime_type' => $mime_type,
			'filename'  => basename( $path ),
			'width'     => $width,
			'height'    => $height,
		);
	}

	/**
	 * Resolves the requested attachment and size to a file path, URL, and the
	 * selected file's dimensions.
	 *
	 * For an intermediate size the metadata path is relative to the uploads
	 * basedir, so it is joined to that base; the intermediate also carries its
	 * own width/height and URL, which describe the selected file (not the
	 * original). Falls back to the original file when the size is unavailable.
	 *
	 * @param int    $id   The attachment ID.
	 * @param string $size The requested image size name.
	 * @return array{path:string,url:string,width:int,height:int} The selected
	 *         file's absolute path (empty when unresolvable), URL (empty when the
	 *         original file was selected), and dimensions (0 when not an
	 *         intermediate).
	 */
	private function resolve( int $id, string $size ): array {
		if ( 'full' !== $size ) {
			$intermediate = image_get_intermediate_size( $id, $size );
			if ( is_array( $intermediate ) && ! empty( $intermediate['path'] ) ) {
				$uploads = wp_get_upload_dir();
				$basedir = $uploads['basedir'] ?? '';
				if ( '' !== $basedir ) {
					return array(
						'path'   => rtrim( $basedir, '/' ) . '/' . ltrim( (string) $intermediate['path'], '/' ),
						'url'    => isset( $intermediate['url'] ) ? (string) $intermediate['url'] : '',
						'width'  => isset( $intermediate['width'] ) ? (int) $intermediate['width'] : 0,
						'height' => isset( $intermediate['height'] ) ? (int) $intermediate['height'] : 0,
					);
				}
			}
		}

		$full = get_attached_file( $id );

		return array(
			'path'   => is_string( $full ) ? $full : '',
			'url'    => '',
			'width'  => 0,
			'height' => 0,
		);
	}

	/**
	 * Resolves image dimensions for the selected file, defaulting to 0 for
	 * non-images.
	 *
	 * When an intermediate size was selected, its own dimensions describe the
	 * returned bytes and are used directly. Otherwise the original file's
	 * dimensions come from attachment metadata, falling back to reading the file.
	 *
	 * @param int                                                  $id       The attachment ID.
	 * @param string                                               $path     The absolute file path.
	 * @param array{path:string,url:string,width:int,height:int} $resolved The resolved file descriptor.
	 * @return array{width:int,height:int}
	 */
	private function resolveDimensions( int $id, string $path, array $resolved ): array {
		if ( $resolved['width'] > 0 && $resolved['height'] > 0 ) {
			return array(
				'width'  => $resolved['width'],
				'height' => $resolved['height'],
			);
		}

		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
			return array(
				'width'  => (int) $meta['width'],
				'height' => (int) $meta['height'],
			);
		}

		if ( function_exists( 'getimagesize' ) ) {
			// wp_getimagesize() wraps getimagesize() and suppresses its warnings
			// safely, so no error-silencing operator is needed here.
			$info = wp_getimagesize( $path );
			if ( is_array( $info ) && isset( $info[0], $info[1] ) ) {
				return array(
					'width'  => (int) $info[0],
					'height' => (int) $info[1],
				);
			}
		}

		return array(
			'width'  => 0,
			'height' => 0,
		);
	}
}
