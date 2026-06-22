<?php
/**
 * Integration tests for the back-up-content recipe.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Skills;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\BackUpContent;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The recipe must point at *live* tools abilities (spec §10). These guard against the
 * recipe rotting: every ability the body names must be registered and owned by the
 * domain whose tool the recipe tells the agent to call, so a renamed or remapped
 * ability fails here. The body also names privacy/create-export-request to disambiguate
 * a content backup from a personal-data privacy request; that reference is asserted too.
 */
final class BackUpContentTest extends TestCase {

	/**
	 * The skill exposes a stable id and short routing metadata.
	 *
	 * @return void
	 */
	public function test_metadata_is_present(): void {
		$this->assertSame( 'back-up-content', BackUpContent::ID );
		$this->assertNotEmpty( BackUpContent::title() );
		$this->assertNotEmpty( BackUpContent::whenToUse() );
	}

	/**
	 * The body is non-empty procedural text.
	 *
	 * @return void
	 */
	public function test_body_is_nonempty_text(): void {
		$body = BackUpContent::body();

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
			BackUpContent::body(),
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
	 * The tools abilities the recipe names, with the domain tool that serves each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function referencedAbilities(): array {
		return array(
			'export content'  => array( 'tools/export-content', 'tools' ),
			'list importers'  => array( 'tools/list-importers', 'tools' ),
			'privacy export'  => array( 'privacy/create-export-request', 'tools' ),
		);
	}
}
