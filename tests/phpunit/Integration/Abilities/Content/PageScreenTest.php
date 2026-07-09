<?php
/**
 * Integration tests for page abilities' meta.screen template and the
 * adapter-backed trash-page ability.
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
use WP_Error;

/**
 * Asserts page abilities report the Pages-list screen template, and exercises
 * og-content/trash-page end-to-end through the Abilities REST Adapter.
 */
final class PageScreenTest extends TestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public function pageListAbilityProvider(): array {
		return array(
			'delete-page' => array( 'og-content/delete-page' ),
			'trash-page'  => array( 'og-content/trash-page' ),
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
		$ability = wp_get_ability( 'og-content/update-page' );

		$this->assertNotNull( $ability );

		$meta = $ability->get_meta();

		$this->assertArrayHasKey( 'screen', $meta );
		$this->assertSame( 'post.php?post={id}&action=edit', $meta['screen'] );
	}

	public function test_admin_can_trash_page(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'A page to trash',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'og-content/trash-page' )->execute( array( 'id' => $page_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $page_id, $result['id'] );
		$this->assertSame( 'A page to trash', $result['title'] );
		$this->assertSame( 'trash', $result['status'] );
		$this->assertSame( 'trash', get_post_status( $page_id ) );
	}

	public function test_subscriber_is_denied_with_real_route_error(): void {
		// Adapter-backed: the route's own delete_post check runs at dispatch, so
		// execute() surfaces the REAL REST error — not the generic collapse, and not
		// via check_permissions() (guard-only now; this ability has no
		// require_permission guard).
		$this->actingAs( 'subscriber' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'og-content/trash-page' )->execute( array( 'id' => $page_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_delete', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertSame( 'publish', get_post_status( $page_id ), 'the page was not trashed' );
	}
}
