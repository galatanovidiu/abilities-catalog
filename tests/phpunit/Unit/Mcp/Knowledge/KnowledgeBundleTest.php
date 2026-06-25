<?php
/**
 * Unit tests for the OKF bundle scanner and its filesystem confinement.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Mcp\Knowledge;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The scanner is the security boundary for third-party bundles, so these exercise both
 * enumeration (a lowercase-extension filter that catches `RECIPE.MD`, reserved names
 * skipped, malformed concepts skipped) and confinement (both operands realpath'd so a
 * symlinked directory path still resolves; a sibling-prefix symlink escape rejected at
 * scan; a symlink swapped in after the scan rejected at load — the TOCTOU case).
 */
final class KnowledgeBundleTest extends TestCase {

	/**
	 * Absolute paths created during a test, removed in tear_down.
	 *
	 * @var list<string>
	 */
	private array $cleanup = array();

	/**
	 * Removes every temp path a test created.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( array_reverse( $this->cleanup ) as $path ) {
			self::remove( $path );
		}
		$this->cleanup = array();

		parent::tear_down();
	}

	/**
	 * Enumeration uses a case-insensitive extension test, so an uppercase `.MD` counts.
	 *
	 * @return void
	 */
	public function test_enumerates_lowercase_and_uppercase_extensions(): void {
		$dir = $this->makeDir();
		$this->writeConcept( $dir, 'alpha.md' );
		$this->writeConcept( $dir, 'BRAVO.MD' );

		$bundle = KnowledgeBundle::fromDirectory( $dir, 'core' );
		$this->assertNotWPError( $bundle );

		$ids = array_map( static fn ( $c ) => $c->id(), $bundle->children() );
		$this->assertContains( 'alpha', $ids );
		$this->assertContains( 'BRAVO', $ids, 'A shipped RECIPE.MD must not be dropped by a case-sensitive glob.' );
	}

	/**
	 * Reserved OKF names and non-markdown files are not concepts.
	 *
	 * @return void
	 */
	public function test_skips_reserved_and_non_markdown(): void {
		$dir = $this->makeDir();
		$this->writeConcept( $dir, 'real.md' );
		$this->writeConcept( $dir, 'index.md' );
		$this->writeConcept( $dir, 'log.md' );
		file_put_contents( $dir . '/notes.txt', "---\ntype: Skill\n---\nNot markdown.\n" );

		$bundle = KnowledgeBundle::fromDirectory( $dir, 'core' );

		$ids = array_map( static fn ( $c ) => $c->id(), $bundle->children() );
		$this->assertSame( array( 'real' ), $ids );
	}

	/**
	 * A malformed or type-less concept is skipped, not fatal.
	 *
	 * @return void
	 */
	public function test_skips_malformed_concepts(): void {
		$dir = $this->makeDir();
		$this->writeConcept( $dir, 'good.md' );
		file_put_contents( $dir . '/no-frontmatter.md', "Just a body, no block.\n" );
		file_put_contents( $dir . '/no-type.md', "---\ntitle: Missing type\n---\nBody.\n" );

		$bundle = KnowledgeBundle::fromDirectory( $dir, 'core' );

		$ids = array_map( static fn ( $c ) => $c->id(), $bundle->children() );
		$this->assertSame( array( 'good' ), $ids );
		$this->assertNull( $bundle->concept( 'no-type' ) );
	}

	/**
	 * A bundle path that runs through a symlinked directory still scans (both realpath'd).
	 *
	 * This is the macOS/CI case the design calls out: a raw scanned path compared
	 * against a realpath'd root scans to empty unless both operands are resolved.
	 *
	 * @return void
	 */
	public function test_scans_through_a_symlinked_directory_path(): void {
		$real = $this->makeDir();
		$this->writeConcept( $real, 'concept.md' );

		$link = $this->reservePath( 'link' );
		if ( ! @symlink( $real, $link ) ) {
			$this->markTestSkipped( 'The filesystem does not support symlinks.' );
		}

		$bundle = KnowledgeBundle::fromDirectory( $link, 'core' );
		$this->assertNotWPError( $bundle );

		$this->assertNotNull( $bundle->concept( 'concept' ) );
		$this->assertIsString( $bundle->concept( 'concept' )->body() );
	}

	/**
	 * A symlinked `.md` whose target escapes a sibling-prefix directory is rejected.
	 *
	 * The sibling is named `<root>-evil`, which shares the root's name as a string
	 * prefix; only the trailing-separator confinement rejects it.
	 *
	 * @return void
	 */
	public function test_rejects_a_sibling_prefix_symlink_escape(): void {
		$root = $this->makeDir();
		$this->writeConcept( $root, 'ok.md' );

		$sibling = $this->reservePath( basename( $root ) . '-evil' );
		mkdir( $sibling );
		$this->writeConcept( $sibling, 'sneak.md' );

		if ( ! @symlink( $sibling . '/sneak.md', $root . '/escape.md' ) ) {
			$this->markTestSkipped( 'The filesystem does not support symlinks.' );
		}

		$bundle = KnowledgeBundle::fromDirectory( $root, 'core' );

		$ids = array_map( static fn ( $c ) => $c->id(), $bundle->children() );
		$this->assertSame( array( 'ok' ), $ids, 'A symlink pointing into a sibling-prefix directory must not be a concept.' );
		$this->assertNull( $bundle->concept( 'escape' ) );
	}

	/**
	 * A `.md` swapped for an escaping symlink AFTER the scan is rejected at load (TOCTOU).
	 *
	 * @return void
	 */
	public function test_rejects_a_symlink_swap_at_load(): void {
		$root = $this->makeDir();
		$this->writeConcept( $root, 'good.md' );

		$outside = $this->reservePath( 'toctou-target.md' );
		$this->writeConcept( dirname( $outside ), basename( $outside ) );

		$bundle  = KnowledgeBundle::fromDirectory( $root, 'core' );
		$concept = $bundle->concept( 'good' );
		$this->assertIsString( $concept->body(), 'The real file resolves before the swap.' );

		// Swap the real file for a symlink escaping the bundle root.
		unlink( $root . '/good.md' );
		if ( ! @symlink( $outside, $root . '/good.md' ) ) {
			$this->markTestSkipped( 'The filesystem does not support symlinks.' );
		}

		$this->assertWPError( $concept->body(), 'A symlink swapped in after the scan must be rejected at load.' );
	}

	/**
	 * A missing directory is a clean WP_Error, not a fatal.
	 *
	 * @return void
	 */
	public function test_missing_directory_is_a_wp_error(): void {
		$this->assertWPError( KnowledgeBundle::fromDirectory( '/no/such/knowledge/dir', 'core' ) );
	}

	/**
	 * Creates and registers a fresh temp bundle directory.
	 *
	 * @return string The directory realpath.
	 */
	private function makeDir(): string {
		$dir = $this->reservePath( 'bundle' );
		mkdir( $dir );

		return realpath( $dir );
	}

	/**
	 * Reserves a unique temp path (for cleanup) without creating it.
	 *
	 * @param string $label A short label for readability.
	 * @return string The reserved absolute path.
	 */
	private function reservePath( string $label ): string {
		$base = realpath( sys_get_temp_dir() ) . '/kb-' . $label . '-' . uniqid( '', true );
		$this->cleanup[] = $base;

		return $base;
	}

	/**
	 * Writes a minimal valid concept file.
	 *
	 * @param string $dir  The directory.
	 * @param string $name The file name.
	 * @return void
	 */
	private function writeConcept( string $dir, string $name ): void {
		file_put_contents( $dir . '/' . $name, "---\ntype: Skill\ntitle: Test concept\ndescription: A test.\n---\nBody text.\n" );
	}

	/**
	 * Removes a file, symlink, or directory tree.
	 *
	 * @param string $path The path.
	 * @return void
	 */
	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );

			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove( $path . '/' . $entry );
		}

		rmdir( $path );
	}
}
