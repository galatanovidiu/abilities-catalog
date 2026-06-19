<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates a plugin/theme install SOURCE down to a wordpress.org directory slug.
 *
 * Installs over WebMCP accept ONLY a wordpress.org directory slug — never a ZIP
 * URL, a remote URL, a local file path, an uploaded file, or a file-editor write.
 * This is the no-arbitrary-SOURCE guard: it removes the install-from-anywhere class
 * of risk by restricting input to a clean slug that maps to a known wp.org package.
 * The slug pattern excludes slashes, dots, `..`, colons, backslashes, whitespace,
 * and URL syntax, so traversal and remote-source values cannot pass.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class SourceValidator {

	/**
	 * Normalizes and validates a wordpress.org directory slug.
	 *
	 * Lowercases and trims the input, then accepts it only when it matches
	 * `/^[a-z0-9-]+$/`. Any URL, ZIP file, file path, or empty value is refused.
	 *
	 * @param string $raw The submitted source value.
	 * @return string|\WP_Error The cleaned slug, or a 400 error when invalid.
	 */
	public static function slug( string $raw ) {
		$slug = strtolower( trim( $raw ) );

		if ( '' === $slug || 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return new \WP_Error(
				'abilities_catalog_invalid_slug',
				__( 'Only a wordpress.org directory slug is accepted (lowercase letters, numbers, hyphens). URLs, ZIP files, and file paths are not allowed.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		return $slug;
	}
}
