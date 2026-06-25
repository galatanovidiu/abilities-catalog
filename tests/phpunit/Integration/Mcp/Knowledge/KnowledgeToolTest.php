<?php
/**
 * Integration tests for the knowledge-tool MCP shim.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Knowledge;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeRegistry;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeTool;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The shim adapts the registry to the adapter's result contract, so these assert its
 * three jobs: dispatch on the presence of `uri`, shape every success result as a JSON
 * object nested under a key (`index` / `concept`) so the adapter's top-level
 * `type`/`success` switch is never triggered — even by a `type: resource` concept — and
 * fold the error code and status into the `WP_Error` message.
 */
final class KnowledgeToolTest extends TestCase {

	/**
	 * The shim under test, over the real registry.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeTool
	 */
	private KnowledgeTool $tool;

	/**
	 * Temp paths created during a test, removed in tear_down.
	 *
	 * @var list<string>
	 */
	private array $cleanup = array();

	/**
	 * Builds a knowledge tool over the real registry for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->tool = new KnowledgeTool( new KnowledgeRegistry() );
	}

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
	 * No uri returns the index nested under an `index` key (a JSON object).
	 *
	 * @return void
	 */
	public function test_no_uri_returns_the_index_object(): void {
		$result = $this->tool->handle( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'index', $result );
		$this->assertNotEmpty( $result['index'] );
		$this->assertArrayNotHasKey( 'type', $result, 'The result must not carry a top-level type the adapter would reroute.' );
	}

	/**
	 * An empty or whitespace uri is treated as no uri, returning the index.
	 *
	 * @return void
	 */
	public function test_blank_uri_returns_the_index(): void {
		$this->assertArrayHasKey( 'index', $this->tool->handle( array( 'uri' => '   ' ) ) );
	}

	/**
	 * A uri returns the concept nested under a `concept` key, body included.
	 *
	 * @return void
	 */
	public function test_uri_returns_a_concept_object(): void {
		$result = $this->tool->handle( array( 'uri' => 'core/create-content' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'concept', $result );
		$this->assertArrayNotHasKey( 'type', $result, 'A concept type must stay nested, away from the adapter top-level switch.' );
		$this->assertSame( 'core/create-content', $result['concept']['uri'] );
		$this->assertNotEmpty( $result['concept']['body'] );
	}

	/**
	 * A `type: resource` concept still returns its body, because the type stays nested.
	 *
	 * @return void
	 */
	public function test_resource_type_concept_stays_nested(): void {
		$dir = $this->makeBundle( 'res', array( 'thing.md' => 'resource' ) );
		add_filter(
			'abilities_catalog_mcp_knowledge',
			function ( array $bundles ) use ( $dir ): array {
				$bundle = KnowledgeBundle::fromDirectory( $dir, 'res' );
				if ( ! is_wp_error( $bundle ) ) {
					$bundles[] = $bundle;
				}

				return $bundles;
			}
		);
		$tool = new KnowledgeTool( new KnowledgeRegistry() );

		$result = $tool->handle( array( 'uri' => 'res/thing' ) );

		$this->assertArrayHasKey( 'concept', $result );
		$this->assertArrayNotHasKey( 'type', $result );
		$this->assertSame( 'resource', $result['concept']['type'] );
		$this->assertNotEmpty( $result['concept']['body'] );
	}

	/**
	 * An unknown uri folds the code and status into the message.
	 *
	 * @return void
	 */
	public function test_unknown_uri_is_a_folded_error(): void {
		$result = $this->tool->handle( array( 'uri' => 'core/no-such-concept' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_unknown_knowledge', $result->get_error_code() );
		$this->assertStringContainsString( 'code: abilities_catalog_mcp_unknown_knowledge', $result->get_error_message() );
		$this->assertStringContainsString( 'status: 404', $result->get_error_message() );
	}

	/**
	 * The input schema offers a single optional `uri` and requires nothing.
	 *
	 * @return void
	 */
	public function test_input_schema_has_optional_uri(): void {
		$schema = KnowledgeTool::inputSchema();

		$this->assertArrayHasKey( 'uri', $schema['properties'] );
		$this->assertArrayNotHasKey( 'required', $schema, 'The uri is optional, so the schema declares nothing required.' );
	}

	/**
	 * Creates and registers a temp bundle with the given `file => type` concepts.
	 *
	 * @param string               $label    A label for readability.
	 * @param array<string,string> $concepts File name => concept type.
	 * @return string The bundle directory realpath.
	 */
	private function makeBundle( string $label, array $concepts ): string {
		$dir = realpath( sys_get_temp_dir() ) . '/kt-' . $label . '-' . uniqid( '', true );
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
