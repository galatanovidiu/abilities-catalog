<?php
/**
 * Integration tests for the moderate-comments recipe.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Skills;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\ModerateComments;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The recipe must point at *live* comment abilities (spec §10). These guard against
 * the recipe rotting: every ability the body names must be registered and owned by
 * the domain whose tool the recipe tells the agent to call, so a renamed or remapped
 * ability fails here. The body also names the permanent comments/delete-comment so it
 * can warn against it; the data set asserts that reference stays accurate too.
 */
final class ModerateCommentsTest extends TestCase {

	/**
	 * The skill exposes a stable id and short routing metadata.
	 *
	 * @return void
	 */
	public function test_metadata_is_present(): void {
		$this->assertSame( 'moderate-comments', ModerateComments::ID );
		$this->assertNotEmpty( ModerateComments::title() );
		$this->assertNotEmpty( ModerateComments::whenToUse() );
	}

	/**
	 * The body is non-empty procedural text.
	 *
	 * @return void
	 */
	public function test_body_is_nonempty_text(): void {
		$body = ModerateComments::body();

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
			ModerateComments::body(),
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
	 * The comment abilities the recipe names, with the domain tool that serves each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function referencedAbilities(): array {
		return array(
			'list comments'  => array( 'comments/list-comments', 'content' ),
			'get one'        => array( 'comments/get-comment', 'content' ),
			'approve'        => array( 'comments/approve-comment', 'content' ),
			'unapprove'      => array( 'comments/unapprove-comment', 'content' ),
			'spam'           => array( 'comments/spam-comment', 'content' ),
			'unspam'         => array( 'comments/unspam-comment', 'content' ),
			'trash'          => array( 'comments/trash-comment', 'content' ),
			'untrash'        => array( 'comments/untrash-comment', 'content' ),
			'reply'          => array( 'comments/create-comment', 'content' ),
			'fix typo'       => array( 'comments/update-comment', 'content' ),
			'delete forever' => array( 'comments/delete-comment', 'content' ),
		);
	}
}
