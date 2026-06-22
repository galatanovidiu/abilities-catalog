<?php
/**
 * Integration tests for the find-content recipe.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Skills;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\FindContent;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The recipe must point at *live* read abilities for discovering content (spec §10).
 * These guard against the recipe rotting: every ability the body names must be
 * registered and owned by the domain whose tool the recipe tells the agent to call,
 * so a renamed or remapped ability fails here.
 */
final class FindContentTest extends TestCase {

	/**
	 * The skill exposes a stable id and short routing metadata.
	 *
	 * @return void
	 */
	public function test_metadata_is_present(): void {
		$this->assertSame( 'find-content', FindContent::ID );
		$this->assertNotEmpty( FindContent::title() );
		$this->assertNotEmpty( FindContent::whenToUse() );
	}

	/**
	 * The body is non-empty procedural text.
	 *
	 * @return void
	 */
	public function test_body_is_nonempty_text(): void {
		$body = FindContent::body();

		$this->assertIsString( $body );
		$this->assertNotEmpty( $body );
	}

	/**
	 * Every ability the recipe names is referenced, registered, and in its stated domain.
	 *
	 * @dataProvider referencedAbilities
	 *
	 * @param string $ability The ability name the recipe references.
	 * @param string $domain  The domain whose tool the recipe tells the agent to call.
	 * @return void
	 */
	public function test_referenced_ability_is_live_and_in_its_domain( string $ability, string $domain ): void {
		$this->assertStringContainsString(
			$ability,
			FindContent::body(),
			sprintf( 'The recipe should reference "%s".', $ability )
		);

		$this->assertTrue(
			wp_has_ability( $ability ),
			sprintf( 'The recipe references "%s", which is not a registered ability.', $ability )
		);

		$this->assertSame(
			$domain,
			( new DomainMap() )->domainOf( $ability ),
			sprintf( 'The recipe routes "%s" through the "%s" tool, but the map owns it elsewhere.', $ability, $domain )
		);
	}

	/**
	 * The read abilities the recipe names, with the domain tool that serves each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function referencedAbilities(): array {
		return array(
			'list post types'  => array( 'content/list-post-types', 'content' ),
			'full-text search' => array( 'search/search-content', 'content' ),
			'list posts'       => array( 'content/list-posts', 'content' ),
			'list pages'       => array( 'content/list-pages', 'content' ),
			'list cpt items'   => array( 'content/list-cpt-items', 'content' ),
			'recent drafts'    => array( 'dashboard/get-drafts', 'dashboard' ),
			'recent activity'  => array( 'dashboard/get-activity', 'dashboard' ),
			'get post'         => array( 'content/get-post', 'content' ),
			'get page'         => array( 'content/get-page', 'content' ),
			'get cpt item'     => array( 'content/get-cpt-item', 'content' ),
		);
	}
}
