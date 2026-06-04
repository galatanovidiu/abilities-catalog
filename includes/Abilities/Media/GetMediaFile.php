<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Media;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if (!defined('ABSPATH')) {
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
final class GetMediaFile implements Ability
{
	/**
	 * Maximum file size eligible for inline base64 encoding (5 MB).
	 *
	 * @var int
	 */
	private const MAX_INLINE_BYTES = 5 * 1024 * 1024;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'media/get-media-file';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Media File', 'abilities-catalog'),
			'description'         => __('Returns the bytes of a media file, base64-encoded. Falls back to the source URL when the file exceeds the inline size limit.', 'abilities-catalog'),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __('The attachment (media item) ID.', 'abilities-catalog'),
					),
					'size' => array(
						'type'        => 'string',
						'default'     => 'full',
						'description' => __('Image size name (e.g. "thumbnail", "medium", "full"). Falls back to "full" if the size is unavailable.', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('data', 'mime_type'),
				'properties'           => array(
					'data'      => array(
						'type'        => 'string',
						'description' => __('The file contents, base64-encoded.', 'abilities-catalog'),
					),
					'mime_type' => array(
						'type'        => 'string',
						'description' => __('The MIME type of the file.', 'abilities-catalog'),
					),
					'filename'  => array(
						'type'        => 'string',
						'description' => __('The base file name.', 'abilities-catalog'),
					),
					'width'     => array(
						'type'        => 'integer',
						'description' => __('Image width in pixels; 0 for non-images.', 'abilities-catalog'),
					),
					'height'    => array(
						'type'        => 'integer',
						'description' => __('Image height in pixels; 0 for non-images.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
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
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		return current_user_can('read_post', $id);
	}

	/**
	 * Executes the ability by reading the attachment file from disk.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|WP_Error The encoded file payload, or an error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;
		$size  = isset($input['size']) ? (string) $input['size'] : 'full';

		if ($id <= 0 || !get_post($id) || 'attachment' !== get_post_type($id)) {
			return new WP_Error(
				'invalid_attachment',
				__('The requested attachment does not exist.', 'abilities-catalog'),
				array('status' => 404)
			);
		}

		$path   = $this->resolvePath($id, $size);
		$width  = 0;
		$height = 0;

		if ('' === $path || !is_readable($path)) {
			return new WP_Error(
				'invalid_attachment',
				__('The attachment file could not be read.', 'abilities-catalog'),
				array('status' => 404)
			);
		}

		$filesize = (int) filesize($path);

		if ($filesize > self::MAX_INLINE_BYTES) {
			return new WP_Error(
				'file_too_large',
				__('File exceeds the 5 MB inline limit; use the source URL instead.', 'abilities-catalog'),
				array('source_url' => wp_get_attachment_url($id))
			);
		}

		$contents = file_get_contents($path);
		if (false === $contents) {
			return new WP_Error(
				'invalid_attachment',
				__('The attachment file could not be read.', 'abilities-catalog'),
				array('status' => 404)
			);
		}

		$dimensions = $this->resolveDimensions($id, $path);
		$width      = $dimensions['width'];
		$height     = $dimensions['height'];

		$mime_type = (string) get_post_mime_type($id);
		if (function_exists('wp_check_filetype')) {
			$type = wp_check_filetype(basename($path));
			if (!empty($type['type'])) {
				$mime_type = (string) $type['type'];
			}
		}

		return array(
			'data'      => base64_encode($contents),
			'mime_type' => $mime_type,
			'filename'  => basename($path),
			'width'     => $width,
			'height'    => $height,
		);
	}

	/**
	 * Resolves the absolute file path for the requested attachment and size.
	 *
	 * For an intermediate size the metadata path is relative to the uploads
	 * basedir, so it is joined to that base. Falls back to the original file when
	 * the size is unavailable.
	 *
	 * @param int    $id   The attachment ID.
	 * @param string $size The requested image size name.
	 * @return string Absolute path, or empty string when unresolvable.
	 */
	private function resolvePath(int $id, string $size): string
	{
		if ('full' !== $size) {
			$intermediate = image_get_intermediate_size($id, $size);
			if (is_array($intermediate) && !empty($intermediate['path'])) {
				$uploads = wp_get_upload_dir();
				$basedir = $uploads['basedir'] ?? '';
				if ('' !== $basedir) {
					return rtrim($basedir, '/') . '/' . ltrim((string) $intermediate['path'], '/');
				}
			}
		}

		$full = get_attached_file($id);

		return is_string($full) ? $full : '';
	}

	/**
	 * Resolves image dimensions for the file, defaulting to 0 for non-images.
	 *
	 * @param int    $id   The attachment ID.
	 * @param string $path The absolute file path.
	 * @return array{width:int,height:int}
	 */
	private function resolveDimensions(int $id, string $path): array
	{
		$meta = wp_get_attachment_metadata($id);
		if (is_array($meta) && isset($meta['width'], $meta['height'])) {
			return array(
				'width'  => (int) $meta['width'],
				'height' => (int) $meta['height'],
			);
		}

		if (function_exists('getimagesize')) {
			$info = @getimagesize($path);
			if (is_array($info) && isset($info[0], $info[1])) {
				return array(
					'width'  => (int) $info[0],
					'height' => (int) $info[1],
				);
			}
		}

		return array('width' => 0, 'height' => 0);
	}
}
