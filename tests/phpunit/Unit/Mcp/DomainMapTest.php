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
