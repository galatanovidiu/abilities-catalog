<?php
/**
 * Tests for the scalable discovery reader behind the search MCP server.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\AbilityIndex;
use GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;

/**
 * Proves the four bounded discovery answers against the really-registered catalog.
 *
 * `execute()` reuses the adapter's argument normalizer, so the suite loads the vendor
 * bundle and skips when it is absent (the catalog itself stays dependency-free).
 */
final class AbilityIndexTest extends TestCase {

	/**
	 * A real, no-input read ability enabled in the gate for these tests.
	 */
	private const ENABLED = 'dashboard/get-at-a-glance';

	/**
	 * A real ability deliberately left OUT of the enabled set (gate must refuse it).
	 */
	private const DISABLED = 'content/create-post';

	/**
	 * Enables exactly one ability and loads the adapter bundle (or skips).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, array( self::ENABLED ) );

		if ( ! class_exists( AbilityArgumentNormalizer::class ) ) {
			$autoload = TESTS_REPO_ROOT_DIR . '/vendor/autoload_packages.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
		}

		if ( ! class_exists( AbilityArgumentNormalizer::class ) ) {
			$this->markTestSkipped( 'The mcp-adapter vendor bundle is not installed; run composer install.' );
		}
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION );
		parent::tear_down();
	}

	/**
	 * Builds a reader over the live registry and the current exposure option.
	 *
	 * @return \GalatanOvidiu\AbilitiesCatalog\Mcp\AbilityIndex
	 */
	private function index(): AbilityIndex {
		return new AbilityIndex( new ExposurePolicy() );
	}

	/**
	 * overview() is a per-category map, biggest-first, with totals.
	 *
	 * @return void
	 */
	public function test_overview_is_a_sorted_category_map_with_totals(): void {
		$overview = $this->index()->overview();

		$this->assertGreaterThan( 0, $overview['total_abilities'] );
		$this->assertGreaterThanOrEqual( 1, $overview['total_enabled'], 'The one enabled ability should be counted.' );
		$this->assertNotEmpty( $overview['categories'] );

		$counts = array_column( $overview['categories'], 'abilities' );
		$sorted = $counts;
		rsort( $sorted );
		$this->assertSame( $sorted, $counts, 'Categories must be sorted by ability count, biggest first.' );

		foreach ( $overview['categories'] as $row ) {
			$this->assertArrayHasKey( 'label', $row );
			$this->assertArrayHasKey( 'description', $row );
			$this->assertGreaterThan( 0, $row['abilities'] );
		}
	}

	/**
	 * overview() seeds search vocabulary: each category lists a few example abilities.
	 *
	 * @return void
	 */
	public function test_overview_lists_bounded_example_abilities_per_category(): void {
		$overview = $this->index()->overview();

		foreach ( $overview['categories'] as $row ) {
			$this->assertArrayHasKey( 'examples', $row );
			$this->assertNotEmpty( $row['examples'], 'A non-empty category must show at least one example.' );
			$this->assertLessThanOrEqual( 5, count( $row['examples'] ), 'Examples stay bounded so overview is O(categories).' );

			foreach ( $row['examples'] as $example ) {
				$this->assertArrayHasKey( 'name', $example );
				$this->assertArrayHasKey( 'label', $example );
			}
		}
	}

	/**
	 * search() ranks the matching ability in, honors the limit, and flags enabled state.
	 *
	 * @return void
	 */
	public function test_search_finds_ranks_and_bounds(): void {
		$result = $this->index()->search( 'create post', null, 5 );

		$names = array_column( $result['abilities'], 'name' );
		$this->assertContains( self::DISABLED, $names, 'A "create post" query should surface content/create-post.' );

		$this->assertLessThanOrEqual( 5, $result['returned'] );
		$this->assertSame( $result['returned'], count( $result['abilities'] ) );
		$this->assertGreaterThanOrEqual( $result['returned'], $result['total_matched'] );

		$this->assertArrayNotHasKey( 'no_match', $result, 'A matching search must not carry the no-match fallback.' );

		foreach ( $result['abilities'] as $hit ) {
			$this->assertArrayHasKey( 'enabled', $hit );
			$this->assertArrayNotHasKey( '_score', $hit, 'The internal score must not leak to the client.' );
		}
	}

	/**
	 * A query that matches nothing returns the category map so the agent can re-orient.
	 *
	 * @return void
	 */
	public function test_search_with_no_match_returns_the_category_map(): void {
		$result = $this->index()->search( 'zzqqwx qphwlmx vbxnkt', null, 5 );

		$this->assertSame( 0, $result['total_matched'], 'The nonsense query must match nothing.' );
		$this->assertSame( array(), $result['abilities'] );
		$this->assertTrue( $result['no_match'] );
		$this->assertNotEmpty( $result['categories'], 'A dead-end search hands back the category map to re-orient.' );
		$this->assertArrayHasKey( 'next_step', $result );
	}

	/**
	 * search() stopword/min-length filtering keeps the matched set meaningful.
	 *
	 * A query of only stopwords/short tokens must not match the whole catalog.
	 *
	 * @return void
	 */
	public function test_search_filters_noise(): void {
		$all     = $this->index()->search( 'create post', null, 5 )['total_matched'];
		$catalog = count( wp_get_abilities() );

		$this->assertLessThan( $catalog, $all, 'A real query must not match every ability in the catalog.' );
	}

	/**
	 * describe() returns the schema even for a disabled ability, flagged, and errors on unknown.
	 *
	 * @return void
	 */
	public function test_describe_shows_disabled_and_errors_on_unknown(): void {
		$detail = $this->index()->describe( self::DISABLED );

		$this->assertIsArray( $detail );
		$this->assertSame( self::DISABLED, $detail['name'] );
		$this->assertArrayHasKey( 'input_schema', $detail );
		$this->assertFalse( $detail['enabled'], 'A non-enabled ability is still describable, flagged disabled.' );
		$this->assertNotNull( $detail['enabled_note'] );

		$this->assertWPError( $this->index()->describe( 'nope/does-not-exist' ) );
	}

	/**
	 * execute() refuses unknown and gate-disabled abilities, and runs an enabled one.
	 *
	 * @return void
	 */
	public function test_execute_enforces_gate_then_runs(): void {
		$this->actingAs( 'administrator' );

		$unknown = $this->index()->execute( 'nope/does-not-exist', array() );
		$this->assertWPError( $unknown );
		$this->assertSame( 'unknown_ability', $unknown->get_error_code() );

		$disabled = $this->index()->execute( self::DISABLED, array( 'title' => 'x', 'status' => 'draft' ) );
		$this->assertWPError( $disabled );
		$this->assertSame( 'ability_disabled', $disabled->get_error_code(), 'The gate must refuse a disabled ability before running it.' );

		$result = $this->index()->execute( self::ENABLED, array() );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result, 'The enabled dashboard read should return its summary.' );
	}
}
