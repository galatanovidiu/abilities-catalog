<?php
/**
 * Integration tests for the create-content recipe.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Skills;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\CreateContent;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The recipe must point at *live* read abilities for blocks, patterns, fonts, and
 * styles (spec §10, success criterion §18). These guard against the recipe rotting:
 * every ability the body names must be registered and owned by the domain whose tool
 * the recipe tells the agent to call, so a renamed or remapped ability fails here.
 */
final class CreateContentTest extends TestCase {

	/**
	 * The skill exposes a stable id and short routing metadata.
	 *
	 * @return void
	 */
	public function test_metadata_is_present(): void {
		$this->assertSame( 'create-content', CreateContent::ID );
		$this->assertNotEmpty( CreateContent::title() );
		$this->assertNotEmpty( CreateContent::whenToUse() );
	}

	/**
	 * The body is non-empty procedural text.
	 *
	 * @return void
	 */
	public function test_body_is_nonempty_text(): void {
		$body = CreateContent::body();

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
			CreateContent::body(),
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
	 * The read/write abilities the recipe names, with the domain tool that serves each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function referencedAbilities(): array {
		return array(
			'block types'        => array( 'templates/list-block-types', 'design' ),
			'patterns'           => array( 'templates/list-patterns', 'design' ),
			'pattern categories' => array( 'templates/list-block-pattern-categories', 'design' ),
			'synced patterns'    => array( 'templates/list-synced-patterns', 'design' ),
			'global styles'      => array( 'templates/get-global-styles', 'design' ),
			'theme styles'       => array( 'templates/get-theme-styles', 'design' ),
			'font collections'   => array( 'fonts/list-font-collections', 'design' ),
			'font families'      => array( 'fonts/list-font-families', 'design' ),
			'image upload'       => array( 'media/upload-media', 'media' ),
			'create post'        => array( 'content/create-post', 'content' ),
			'create page'        => array( 'content/create-page', 'content' ),
			'create cpt item'    => array( 'content/create-cpt-item', 'content' ),
		);
	}
}
