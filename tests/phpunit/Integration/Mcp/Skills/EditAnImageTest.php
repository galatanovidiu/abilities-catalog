<?php
/**
 * Integration tests for the edit-an-image recipe.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Skills;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\EditAnImage;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The recipe must point at *live* media abilities (spec §10). These guard against the
 * recipe rotting: every ability the body names must be registered and owned by the
 * domain whose tool the recipe tells the agent to call, so a renamed or remapped
 * ability fails here.
 */
final class EditAnImageTest extends TestCase {

	/**
	 * The skill exposes a stable id and short routing metadata.
	 *
	 * @return void
	 */
	public function test_metadata_is_present(): void {
		$this->assertSame( 'edit-an-image', EditAnImage::ID );
		$this->assertNotEmpty( EditAnImage::title() );
		$this->assertNotEmpty( EditAnImage::whenToUse() );
	}

	/**
	 * The body is non-empty procedural text.
	 *
	 * @return void
	 */
	public function test_body_is_nonempty_text(): void {
		$body = EditAnImage::body();

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
			EditAnImage::body(),
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
	 * The media abilities the recipe names, with the domain tool that serves each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function referencedAbilities(): array {
		return array(
			'find by list'    => array( 'media/list-media', 'media' ),
			'read one'        => array( 'media/get-media', 'media' ),
			'edit pixels'     => array( 'media/edit-media-image', 'media' ),
			'edit metadata'   => array( 'media/update-media', 'media' ),
			'rebuild sizes'   => array( 'media/regenerate-thumbnails', 'media' ),
			'list sizes'      => array( 'media/list-image-sizes', 'media' ),
			'upload new'      => array( 'media/upload-media', 'media' ),
		);
	}
}
