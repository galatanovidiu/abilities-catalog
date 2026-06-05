<?php
/**
 * Integration tests for page abilities' meta.screen template.
 *
 * The Pages list in wp-admin is edit.php?post_type=page, not the bare
 * edit.php (which defaults to the all-posts list). delete-page and trash-page
 * must point at the Pages list.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Asserts page abilities report the Pages-list screen template.
 */
final class PageScreenTest extends TestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public function pageListAbilityProvider(): array {
		return array(
			'delete-page' => array( 'content/delete-page' ),
			'trash-page'  => array( 'content/trash-page' ),
		);
	}

	/**
	 * @dataProvider pageListAbilityProvider
	 */
	public function test_page_list_abilities_point_at_pages_list_screen( string $ability_name ): void {
		$ability = wp_get_ability( $ability_name );

		$this->assertNotNull( $ability, "Ability {$ability_name} is registered." );

		$meta = $ability->get_meta();

		$this->assertArrayHasKey( 'screen', $meta );
		$this->assertSame( 'edit.php?post_type=page', $meta['screen'] );
	}

	public function test_update_page_points_at_page_editor_screen(): void {
		$ability = wp_get_ability( 'content/update-page' );

		$this->assertNotNull( $ability );

		$meta = $ability->get_meta();

		$this->assertArrayHasKey( 'screen', $meta );
		$this->assertSame( 'post.php?post={id}&action=edit', $meta['screen'] );
	}
}
