<?php
/**
 * The shared OKF bundle scanner: a directory of concept files, confined and indexed.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans one OKF bundle directory into a confined, frontmatter-indexed concept set.
 *
 * This is the public extension point: any plugin scans **its own** bundle directory
 * with {@see fromDirectory()} and contributes the returned bundle through the
 * `abilities_catalog_mcp_knowledge` filter. The scanner — not the caller — owns the
 * security guard, so a third-party bundle is held to the same confinement as the core
 * one.
 *
 * v1 reads **one level** (the bundle root's direct `.md` files); a recursive walk into
 * subdirectories (progressive disclosure) is a designed-for future add. Enumeration
 * uses a lowercase-extension filter rather than `glob('*.md')`, because glob's pattern
 * stays case-sensitive even on a case-insensitive filesystem and would silently drop a
 * shipped `RECIPE.MD`. Each file's realpath must sit inside the bundle root's realpath
 * (both operands resolved, trailing separator enforced) or it is skipped — and
 * {@see Concept::body()} re-checks that at load time against a TOCTOU symlink swap.
 *
 * @since 0.4.0
 */
final class KnowledgeBundle {

	/**
	 * Cap on the head bytes read while locating the closing frontmatter delimiter.
	 */
	private const MAX_HEAD_BYTES = 16384;

	/**
	 * Reserved OKF filenames that are not concepts (lowercased for comparison).
	 *
	 * @var list<string>
	 */
	private const RESERVED = array( 'index.md', 'log.md' );

	/**
	 * The bundle slug, the first segment of every concept uri it owns.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * The scanned concepts, keyed by id.
	 *
	 * @var array<string,\GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\Concept>
	 */
	private array $concepts;

	/**
	 * Private constructor: bundles are built through {@see fromDirectory()}.
	 *
	 * Each concept already holds its own realpath'd root for the load-time confinement
	 * re-check, so the bundle keeps no root of its own.
	 *
	 * @param string                                                             $slug     The bundle slug.
	 * @param array<string,\GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\Concept> $concepts The scanned concepts, keyed by id.
	 */
	private function __construct( string $slug, array $concepts ) {
		$this->slug     = $slug;
		$this->concepts = $concepts;
	}

	/**
	 * Scans a bundle directory into a confined, indexed bundle.
	 *
	 * @param string $dir  The bundle root directory (need not be a realpath).
	 * @param string $slug The bundle slug to own the concept uris.
	 * @return self|\WP_Error The bundle, or a `WP_Error` when the directory is missing
	 *         or unreadable.
	 */
	public static function fromDirectory( string $dir, string $slug ) {
		$root = realpath( $dir );
		if ( false === $root || ! is_dir( $root ) ) {
			return new WP_Error(
				'abilities_catalog_mcp_invalid_bundle',
				sprintf(
					/* translators: %s: the bundle directory path. */
					__( 'Knowledge bundle directory "%s" does not exist.', 'abilities-catalog' ),
					$dir
				)
			);
		}

		$entries = scandir( $root );
		if ( false === $entries ) {
			return new WP_Error(
				'abilities_catalog_mcp_invalid_bundle',
				sprintf(
					/* translators: %s: the bundle directory path. */
					__( 'Knowledge bundle directory "%s" could not be read.', 'abilities-catalog' ),
					$dir
				)
			);
		}

		$concepts = array();
		foreach ( $entries as $entry ) {
			$concept = self::scanEntry( $root, $entry, $slug );
			if ( null === $concept ) {
				continue;
			}

			$concepts[ $concept->id() ] = $concept;
		}

		return new self( $slug, $concepts );
	}

	/**
	 * The bundle slug.
	 *
	 * @return string The slug.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Returns one concept by id, or `null` when none matches.
	 *
	 * @param string $id The concept id.
	 * @return \GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\Concept|null The concept, or `null`.
	 */
	public function concept( string $id ): ?Concept {
		return $this->concepts[ $id ] ?? null;
	}

	/**
	 * The bundle's first-level concepts, in scan order.
	 *
	 * @return list<\GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\Concept> The concepts.
	 */
	public function children(): array {
		return array_values( $this->concepts );
	}

	/**
	 * Scans one directory entry into a confined concept, or `null` to skip it.
	 *
	 * Skips `.`/`..`, non-`.md` (case-insensitively), reserved OKF names, anything that
	 * fails the realpath confinement, non-files (subdirectories are deferred), and any
	 * file whose leading frontmatter will not parse or carries no `type`. Each skip past
	 * the confinement guard is logged under WP_DEBUG so a failed concept is never silent.
	 *
	 * @param string $root  The realpath of the bundle root.
	 * @param string $entry The directory entry name.
	 * @param string $slug  The bundle slug.
	 * @return \GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\Concept|null The concept, or `null` to skip.
	 */
	private static function scanEntry( string $root, string $entry, string $slug ) {
		if ( '.' === $entry || '..' === $entry ) {
			return null;
		}

		if ( 'md' !== strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ) ) {
			return null;
		}

		if ( in_array( strtolower( $entry ), self::RESERVED, true ) ) {
			return null;
		}

		$path = realpath( $root . DIRECTORY_SEPARATOR . $entry );
		if ( false === $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) ) {
			self::log( sprintf( 'Knowledge concept "%s" is outside its bundle root and was skipped.', $entry ) );

			return null;
		}

		if ( ! is_file( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading the plugin's own bundled, realpath-confined markdown head on disk, not a remote URL; WP_Filesystem is for writes / credentialed hosts, not a read sandbox.
		$head = file_get_contents( $path, false, null, 0, self::MAX_HEAD_BYTES );
		if ( false === $head ) {
			self::log( sprintf( 'Knowledge concept "%s" could not be read and was skipped.', $entry ) );

			return null;
		}

		$frontmatter = FrontmatterParser::parse( $head );
		if ( null === $frontmatter ) {
			self::log( sprintf( 'Knowledge concept "%s" has no parseable frontmatter or no type and was skipped.', $entry ) );

			return null;
		}

		return new Concept( pathinfo( $entry, PATHINFO_FILENAME ), $slug, $frontmatter, $path, $root );
	}

	/**
	 * Logs a scanner diagnostic, but only under WP_DEBUG.
	 *
	 * @param string $message The diagnostic message.
	 * @return void
	 */
	private static function log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-guarded diagnostic for an off-by-default feature that has no other channel.
		error_log( 'Abilities Catalog: ' . $message );
	}
}
