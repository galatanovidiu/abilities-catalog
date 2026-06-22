<?php
/**
 * Integration tests for the configure-reading recipe.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Skills;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\ConfigureReading;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The recipe spans the settings and content tools (spec §10). These guard against the
 * recipe rotting: every ability the body names must be registered and owned by the
 * domain whose tool the recipe tells the agent to call, so a renamed or remapped
 * ability fails here.
 */
final class ConfigureReadingTest extends TestCase {

	/**
	 * The skill exposes a stable id and short routing metadata.
	 *
	 * @return void
	 */
	public function test_metadata_is_present(): void {
		$this->assertSame( 'configure-reading', ConfigureReading::ID );
		$this->assertNotEmpty( ConfigureReading::title() );
		$this->assertNotEmpty( ConfigureReading::whenToUse() );
	}

	/**
	 * The body is non-empty procedural text.
	 *
	 * @return void
	 */
	public function test_body_is_nonempty_text(): void {
		$body = ConfigureReading::body();

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
			ConfigureReading::body(),
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
	 * The settings/content abilities the recipe names, with the domain tool that serves each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function referencedAbilities(): array {
		return array(
			'read reading'   => array( 'settings/get-reading', 'settings' ),
			'find a page'    => array( 'content/list-pages', 'content' ),
			'create a page'  => array( 'content/create-page', 'content' ),
			'confirm a page' => array( 'content/get-page', 'content' ),
			'write reading'  => array( 'settings/update-reading', 'settings' ),
		);
	}
}
