<?php
/**
 * Integration tests for the organize-with-terms recipe.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Skills;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\OrganizeWithTerms;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The recipe must point at *live* term abilities (spec §10). These guard against the
 * recipe rotting: every ability the body names must be registered and owned by the
 * domain whose tool the recipe tells the agent to call, so a renamed or remapped
 * ability fails here.
 */
final class OrganizeWithTermsTest extends TestCase {

	/**
	 * The skill exposes a stable id and short routing metadata.
	 *
	 * @return void
	 */
	public function test_metadata_is_present(): void {
		$this->assertSame( 'organize-with-terms', OrganizeWithTerms::ID );
		$this->assertNotEmpty( OrganizeWithTerms::title() );
		$this->assertNotEmpty( OrganizeWithTerms::whenToUse() );
	}

	/**
	 * The body is non-empty procedural text.
	 *
	 * @return void
	 */
	public function test_body_is_nonempty_text(): void {
		$body = OrganizeWithTerms::body();

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
			OrganizeWithTerms::body(),
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
			'find by list'     => array( 'content/list-posts', 'content' ),
			'read one post'    => array( 'content/get-post', 'content' ),
			'list taxonomies'  => array( 'terms/list-taxonomies', 'content' ),
			'list categories'  => array( 'terms/list-categories', 'content' ),
			'list tags'        => array( 'terms/list-tags', 'content' ),
			'list terms'       => array( 'terms/list-terms', 'content' ),
			'create category'  => array( 'terms/create-category', 'content' ),
			'create tag'       => array( 'terms/create-tag', 'content' ),
			'create term'      => array( 'terms/create-term', 'content' ),
			'attach terms'     => array( 'terms/attach-post-terms', 'content' ),
			'detach terms'     => array( 'terms/detach-post-terms', 'content' ),
			'delete a term'    => array( 'terms/delete-category', 'content' ),
		);
	}
}
