<?php
/**
 * Integration tests for the og-menus/update-navigation ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises block navigation updates: happy path, the additive title and
 * edit_link output, the empty-string clear of title and content, the
 * negative-id schema guard, and the capability guard on execute().
 */
final class UpdateNavigationTest extends TestCase {

	/**
	 * Creates a block navigation post and returns its ID.
	 *
	 * @param string $title   The navigation title.
	 * @param string $content The serialized block content.
	 * @return int The new wp_navigation post ID.
	 */
	private function makeNavigation( string $title = 'Primary Navigation', string $content = '<!-- wp:page-list /-->' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/update-navigation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/update-navigation', $ability->get_name() );
	}

	/**
	 * 'future' was non-functional: the ability accepts no date input, so a future
	 * status degrades to publish. It is dropped from the enum (B7).
	 */
	public function test_status_enum_excludes_future(): void {
		$enum = wp_get_ability( 'og-menus/update-navigation' )->get_input_schema()['properties']['status']['enum'];

		$this->assertNotContains( 'future', $enum );
		$this->assertSame( array( 'draft', 'pending', 'private', 'publish' ), $enum );
	}

	public function test_admin_updates_title(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->makeNavigation();

		$result = wp_get_ability( 'og-menus/update-navigation' )->execute(
			array(
				'id'    => $nav_id,
				'title' => 'Renamed Navigation',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $nav_id, $result['id'] );
		$this->assertSame( 'Renamed Navigation', $result['title'] );
	}

	public function test_output_contains_title_and_edit_link(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->makeNavigation();

		$result = wp_get_ability( 'og-menus/update-navigation' )->execute(
			array(
				'id'    => $nav_id,
				'title' => 'Shaped',
			)
		);

		$this->assertIsArray( $result );
		foreach ( array( 'id', 'title', 'status', 'edit_link' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}

		$this->assertStringContainsString( 'site-editor.php', $result['edit_link'] );
		$this->assertStringContainsString( (string) $nav_id, $result['edit_link'] );
	}

	public function test_empty_title_clears_the_title(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->makeNavigation( 'Has A Title' );

		$result = wp_get_ability( 'og-menus/update-navigation' )->execute(
			array(
				'id'    => $nav_id,
				'title' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['title'] );
		$this->assertSame( '', get_post( $nav_id )->post_title );
	}

	public function test_empty_content_clears_the_block_markup(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->makeNavigation( 'Navigation', '<!-- wp:page-list /-->' );

		$result = wp_get_ability( 'og-menus/update-navigation' )->execute(
			array(
				'id'      => $nav_id,
				'content' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', get_post( $nav_id )->post_content );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-menus/update-navigation' )->execute(
			array(
				'id'    => -7,
				'title' => 'Bad',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->makeNavigation();

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-menus/update-navigation' )->execute(
			array(
				'id'    => $nav_id,
				'title' => 'Denied',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_route_error_is_preserved(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->makeNavigation();

		$result = wp_get_ability( 'og-menus/update-navigation' )->execute(
			array(
				'id'     => $nav_id,
				'status' => 'bogus',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_navigation_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// An admin holds edit_theme_options (the coarse guard), so a non-existent id
		// reaches the route and surfaces its specific 404 instead of the opaque
		// ability_invalid_permissions the object-level pre-check produced.
		$result = wp_get_ability( 'og-menus/update-navigation' )->execute(
			array(
				'id'    => 999999,
				'title' => 'Renamed',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}
}
