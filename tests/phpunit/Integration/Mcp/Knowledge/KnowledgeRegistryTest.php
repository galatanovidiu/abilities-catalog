<?php
/**
 * Integration tests for the knowledge registry.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Knowledge;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeRegistry;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * The registry seeds the shipped `core` bundle, merges add-on bundles from the
 * `abilities_catalog_mcp_knowledge` filter, and answers the index and concept lookups,
 * so these assert: the root index carries live facts plus the grouped concept listing;
 * a uri resolves to a concept payload; an unknown or traversal uri returns a recovery
 * error without touching the filesystem; and a misbehaving filter (non-bundle,
 * WP_Error, duplicate slug) degrades cleanly.
 */
final class KnowledgeRegistryTest extends TestCase {

	/**
	 * Temp paths created during a test, removed in tear_down.
	 *
	 * @var list<string>
	 */
	private array $cleanup = array();

	/**
	 * Drops any filter a test installed and removes temp bundles.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'abilities_catalog_mcp_knowledge' );

		foreach ( array_reverse( $this->cleanup ) as $path ) {
			self::remove( $path );
		}
		$this->cleanup = array();

		parent::tear_down();
	}

	/**
	 * The root index opens with live site facts, then lists concepts grouped by type.
	 *
	 * @return void
	 */
	public function test_root_index_has_site_facts_and_grouped_listing(): void {
		$index = ( new KnowledgeRegistry() )->rootIndex();

		$this->assertStringContainsString( '# Site', $index );
		$this->assertStringContainsString( '- Title:', $index );
		$this->assertStringContainsString( '- Active plugins:', $index );

		$this->assertStringContainsString( '# Skills', $index );
		$this->assertStringContainsString( '# Guidelines', $index );
		$this->assertStringContainsString( '# Reference', $index );
		$this->assertStringContainsString( '(core/create-content)', $index );
	}

	/**
	 * A known uri resolves to a full concept payload.
	 *
	 * @return void
	 */
	public function test_load_returns_a_concept_payload(): void {
		$concept = ( new KnowledgeRegistry() )->load( 'core/create-content' );

		$this->assertIsArray( $concept );
		$this->assertSame( 'core/create-content', $concept['uri'] );
		$this->assertSame( 'Skill', $concept['type'] );
		$this->assertNotEmpty( $concept['title'] );
		$this->assertNotEmpty( $concept['body'] );
		$this->assertStringContainsString( 'Recipe', $concept['body'] );
	}

	/**
	 * An unknown uri returns a recoverable 404 naming the bad uri.
	 *
	 * @return void
	 */
	public function test_unknown_uri_returns_a_recovery_error(): void {
		$error = ( new KnowledgeRegistry() )->load( 'core/no-such-concept' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_unknown_knowledge', $error->get_error_code() );
		$this->assertSame( 404, $error->get_error_data()['status'] );
		$this->assertStringContainsString( 'core/no-such-concept', $error->get_error_message() );
	}

	/**
	 * A traversal or null-byte uri simply misses the index; it never reaches file I/O.
	 *
	 * @return void
	 */
	public function test_traversal_uri_misses_without_file_access(): void {
		$registry = new KnowledgeRegistry();

		$this->assertWPError( $registry->load( 'core/../../../../etc/passwd' ) );
		// An interior null byte (a path-injection shape) misses the index lookup. A
		// trailing one is irrelevant: load() never builds a path, it looks up a key.
		$this->assertWPError( $registry->load( "core/create-content\0.md" ) );
		$this->assertWPError( $registry->load( 'no-slash' ) );
	}

	/**
	 * The filter adds an add-on bundle; its concept appears in the index and resolves.
	 *
	 * @return void
	 */
	public function test_filter_adds_an_addon_bundle(): void {
		$dir = $this->makeBundle( 'addon', array( 'thing.md' => 'Skill' ) );
		add_filter(
			'abilities_catalog_mcp_knowledge',
			function ( array $bundles ) use ( $dir ): array {
				$bundle = KnowledgeBundle::fromDirectory( $dir, 'addon' );
				if ( ! is_wp_error( $bundle ) ) {
					$bundles[] = $bundle;
				}

				return $bundles;
			}
		);
		$registry = new KnowledgeRegistry();

		$this->assertStringContainsString( '(addon/thing)', $registry->rootIndex() );
		$this->assertSame( 'addon/thing', $registry->load( 'addon/thing' )['uri'] );
		// The shipped core bundle still resolves alongside the add-on.
		$this->assertSame( 'core/create-content', $registry->load( 'core/create-content' )['uri'] );
	}

	/**
	 * A non-bundle value and a WP_Error from the filter are skipped, not fatal.
	 *
	 * @return void
	 */
	public function test_non_bundle_filter_values_are_skipped(): void {
		add_filter(
			'abilities_catalog_mcp_knowledge',
			static function ( array $bundles ): array {
				$bundles[] = 'not-a-bundle';
				$bundles[] = new WP_Error( 'scan_failed', 'A scan failed.' );

				return $bundles;
			}
		);
		$registry = new KnowledgeRegistry();

		// Core still resolves; the junk entries simply did not register.
		$this->assertSame( 'core/create-content', $registry->load( 'core/create-content' )['uri'] );
	}

	/**
	 * A duplicate slug is skipped; the first bundle to claim it wins.
	 *
	 * @return void
	 */
	public function test_duplicate_slug_is_skipped(): void {
		$first  = $this->makeBundle( 'first', array( 'one.md' => 'Skill' ) );
		$second = $this->makeBundle( 'second', array( 'two.md' => 'Skill' ) );
		add_filter(
			'abilities_catalog_mcp_knowledge',
			function ( array $bundles ) use ( $first, $second ): array {
				$bundles[] = KnowledgeBundle::fromDirectory( $first, 'dup' );
				$bundles[] = KnowledgeBundle::fromDirectory( $second, 'dup' );

				return $bundles;
			}
		);
		$registry = new KnowledgeRegistry();

		$this->assertSame( 'dup/one', $registry->load( 'dup/one' )['uri'], 'The first bundle to claim a slug wins.' );
		$this->assertWPError( $registry->load( 'dup/two' ) );
	}

	/**
	 * Creates and registers a temp bundle with the given `file => type` concepts.
	 *
	 * @param string                $label    A label for readability.
	 * @param array<string,string>  $concepts File name => concept type.
	 * @return string The bundle directory realpath.
	 */
	private function makeBundle( string $label, array $concepts ): string {
		$dir = realpath( sys_get_temp_dir() ) . '/kr-' . $label . '-' . uniqid( '', true );
		mkdir( $dir );
		$this->cleanup[] = $dir;

		foreach ( $concepts as $name => $type ) {
			file_put_contents( $dir . '/' . $name, "---\ntype: {$type}\ntitle: {$label} {$name}\ndescription: A test concept.\n---\nBody for {$name}.\n" );
		}

		return realpath( $dir );
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
