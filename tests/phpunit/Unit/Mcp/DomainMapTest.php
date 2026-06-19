<?php
/**
 * Unit tests for the ability -> domain taxonomy.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The map is pure (it never touches the registry), so these assert the curated
 * placement rules directly: prefixes grouped into domains, the search exception,
 * and unmapped names returning null.
 */
final class DomainMapTest extends TestCase {

	/**
	 * The map under test.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap
	 */
	private DomainMap $map;

	/**
	 * Builds a fresh map for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->map = new DomainMap();
	}

	/**
	 * Drops any extensibility filter a test installed.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'abilities_catalog_mcp_domain_map' );
		parent::tear_down();
	}

	/**
	 * The 11 curated domains are exposed in tool order.
	 *
	 * @return void
	 */
	public function test_domains_lists_the_eleven_curated_slugs_in_order(): void {
		$this->assertSame(
			array( 'content', 'media', 'appearance', 'design', 'plugins', 'users', 'settings', 'tools', 'site-health', 'updates', 'dashboard' ),
			$this->map->domains()
		);
	}

	/**
	 * Each ability resolves to its curated domain; unmapped names return null.
	 *
	 * @dataProvider abilityDomainPairs
	 *
	 * @param string      $ability  The ability name.
	 * @param string|null $expected The expected domain, or null when unmapped.
	 * @return void
	 */
	public function test_domain_of( string $ability, ?string $expected ): void {
		$this->assertSame( $expected, $this->map->domainOf( $ability ) );
	}

	/**
	 * The filter places a third-party ability into an existing domain.
	 *
	 * @return void
	 */
	public function test_filter_adds_ability_to_existing_domain(): void {
		add_filter(
			'abilities_catalog_mcp_domain_map',
			static function ( array $includes ): array {
				$includes['content'][] = 'acme/special-post';

				return $includes;
			}
		);
		$map = new DomainMap();

		$this->assertSame( 'content', $map->domainOf( 'acme/special-post' ) );
		// The curated exact placement still resolves alongside the added one.
		$this->assertSame( 'content', $map->domainOf( 'search/search-content' ) );
		// Adding to an existing domain does not change the domain set.
		$this->assertSame( $this->curatedDomains(), $map->domains() );
	}

	/**
	 * The filter opens a new domain; it appears after the curated ones and resolves.
	 *
	 * @return void
	 */
	public function test_filter_opens_a_new_domain_appended_after_curated(): void {
		add_filter(
			'abilities_catalog_mcp_domain_map',
			static function ( array $includes ): array {
				$includes['commerce'] = array( 'acme/get-product', 'acme/list-orders' );

				return $includes;
			}
		);
		$map = new DomainMap();

		$this->assertSame( 'commerce', $map->domainOf( 'acme/get-product' ) );
		$this->assertSame( array_merge( $this->curatedDomains(), array( 'commerce' ) ), $map->domains() );
	}

	/**
	 * A filter that returns a non-array is ignored in favor of the curated default.
	 *
	 * @return void
	 */
	public function test_non_array_filter_return_is_ignored(): void {
		add_filter( 'abilities_catalog_mcp_domain_map', '__return_false' );
		$map = new DomainMap();

		$this->assertSame( 'content', $map->domainOf( 'search/search-content' ) );
		$this->assertSame( $this->curatedDomains(), $map->domains() );
	}

	/**
	 * A per-domain non-list value is skipped without breaking the rest of the map.
	 *
	 * @return void
	 */
	public function test_non_list_value_for_a_domain_key_is_skipped(): void {
		add_filter(
			'abilities_catalog_mcp_domain_map',
			static function ( array $includes ): array {
				$includes['commerce'] = 'acme/get-product'; // A string, not a list of names.

				return $includes;
			}
		);
		$map = new DomainMap();

		// The unusable value is skipped: the ability resolves to no domain.
		$this->assertNull( $map->domainOf( 'acme/get-product' ) );
		// The curated placement still resolves.
		$this->assertSame( 'content', $map->domainOf( 'search/search-content' ) );
		// The key still opens a domain (its tool would simply list nothing).
		$this->assertContains( 'commerce', $map->domains() );
	}

	/**
	 * Numeric or empty filter keys reach domains() as strings, not integers.
	 *
	 * @return void
	 */
	public function test_numeric_and_empty_domain_keys_are_stringified(): void {
		add_filter(
			'abilities_catalog_mcp_domain_map',
			static function ( array $includes ): array {
				$includes['0'] = array( 'acme/zero' );  // Array key coerces to int 0.
				$includes['']  = array( 'acme/empty' );

				return $includes;
			}
		);
		$map = new DomainMap();

		// assertContains is strict in PHPUnit 9, so these pass only because the keys
		// are stringified — an int 0 in domains() would fail the '0' assertion.
		$domains = $map->domains();
		$this->assertContains( '0', $domains );
		$this->assertContains( '', $domains );
		$this->assertSame( '0', $map->domainOf( 'acme/zero' ) );
		$this->assertSame( '', $map->domainOf( 'acme/empty' ) );
	}

	/**
	 * reportUnmapped fires a _doing_it_wrong notice naming the orphan abilities.
	 *
	 * @return void
	 */
	public function test_report_unmapped_warns_about_orphan_names(): void {
		$this->setExpectedIncorrectUsage( DomainMap::class . '::reportUnmapped' );

		$this->map->reportUnmapped( array( 'content/get-post', 'widgets/orphan' ) );
	}

	/**
	 * reportUnmapped is silent when every name maps to a domain.
	 *
	 * The WP test harness fails on any unexpected _doing_it_wrong, so a clean run
	 * proves silence; the explicit assertion documents the precondition.
	 *
	 * @return void
	 */
	public function test_report_unmapped_is_silent_when_all_names_map(): void {
		$names = array( 'content/get-post', 'media/list-media', 'search/search-content' );
		$this->assertSame( array(), $this->map->unmapped( $names ) );

		$this->map->reportUnmapped( $names );
	}

	/**
	 * unmapped returns only the names no domain owns, in input order.
	 *
	 * @return void
	 */
	public function test_unmapped_returns_only_orphan_names(): void {
		$names = array(
			'content/get-post',
			'widgets/list',
			'media/list-media',
			'noslash',
			'search/search-content',
		);

		$this->assertSame(
			array( 'widgets/list', 'noslash' ),
			$this->map->unmapped( $names )
		);
	}

	/**
	 * unmapped returns an empty list when every name maps to a domain.
	 *
	 * @return void
	 */
	public function test_unmapped_is_empty_when_all_names_map(): void {
		$this->assertSame(
			array(),
			$this->map->unmapped( array( 'content/get-post', 'media/list-media', 'updates/run-update' ) )
		);
	}

	/**
	 * The curated 11 domain slugs, in tool order.
	 *
	 * @return list<string>
	 */
	private function curatedDomains(): array {
		return array( 'content', 'media', 'appearance', 'design', 'plugins', 'users', 'settings', 'tools', 'site-health', 'updates', 'dashboard' );
	}

	/**
	 * Ability-name to expected-domain pairs covering every prefix and exception.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function abilityDomainPairs(): array {
		return array(
			'post -> content'           => array( 'content/get-post', 'content' ),
			'term -> content'           => array( 'terms/create-category', 'content' ),
			'comment -> content'        => array( 'comments/get-comment', 'content' ),
			'search exception->content' => array( 'search/search-content', 'content' ),
			'media -> media'            => array( 'media/list-image-sizes', 'media' ),
			'theme -> appearance'       => array( 'themes/list-themes', 'appearance' ),
			'menu -> appearance'        => array( 'menus/list-menus', 'appearance' ),
			'template -> design'        => array( 'templates/list-templates', 'design' ),
			'font -> design'            => array( 'fonts/list-font-families', 'design' ),
			'plugin -> plugins'         => array( 'plugins/list-plugins', 'plugins' ),
			'user -> users'             => array( 'users/list-users', 'users' ),
			'setting -> settings'       => array( 'settings/get-option', 'settings' ),
			'connector -> settings'     => array( 'connectors/list-connectors', 'settings' ),
			'tool -> tools'             => array( 'tools/export-content', 'tools' ),
			'privacy -> tools'          => array( 'privacy/generate-export', 'tools' ),
			'site-health -> self'       => array( 'site-health/get-status', 'site-health' ),
			'update -> updates'         => array( 'updates/run-update', 'updates' ),
			'dashboard -> self'         => array( 'dashboard/get-activity', 'dashboard' ),
			'other search -> null'      => array( 'search/something-else', null ),
			'unknown prefix -> null'    => array( 'widgets/list', null ),
			'no slash -> null'          => array( 'noslash', null ),
			'empty -> null'             => array( '', null ),
		);
	}
}
