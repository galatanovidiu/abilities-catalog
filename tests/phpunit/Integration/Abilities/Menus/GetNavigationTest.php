<?php
/**
 * Integration tests for the menus/get-navigation ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the single block-navigation read: flat fields out, the additive
 * edit_link field, and the capability guard enforced on execute().
 */
final class GetNavigationTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'menus/get-navigation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'menus/get-navigation', $ability->get_name() );
	}

	public function test_admin_reads_navigation_by_id(): void {
		$this->actingAs( 'administrator' );

		$nav_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Primary Navigation',
				'post_content' => '<!-- wp:page-list /-->',
			)
		);

		$result = wp_get_ability( 'menus/get-navigation' )->execute( array( 'id' => $nav_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( (int) $nav_id, $result['id'] );
		$this->assertSame( 'Primary Navigation', $result['title'] );
		// content is the raw serialized block markup (the documented contract), not
		// rendered HTML: the stored block comment is present and no <ul> wrapper is.
		$this->assertStringContainsString( '<!-- wp:page-list', $result['content'] );
		$this->assertStringNotContainsString( '<ul', $result['content'] );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'date', $result );
		$this->assertArrayHasKey( 'modified', $result );
	}

	public function test_content_round_trips_into_update_navigation(): void {
		$this->actingAs( 'administrator' );

		$markup = '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->';
		$nav_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Round Trip',
				'post_content' => $markup,
			)
		);

		// Read returns the serialized markup...
		$read = wp_get_ability( 'menus/get-navigation' )->execute( array( 'id' => $nav_id ) );
		$this->assertSame( $markup, $read['content'] );

		// ...which writes back through update-navigation unchanged.
		$updated = wp_get_ability( 'menus/update-navigation' )->execute(
			array(
				'id'      => $nav_id,
				'content' => $read['content'],
			)
		);
		$this->assertIsArray( $updated );
		$this->assertStringContainsString( 'wp:navigation-link', get_post( $nav_id )->post_content );
	}

	public function test_view_context_returns_rendered_html(): void {
		$this->actingAs( 'administrator' );

		$nav_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Rendered',
				'post_content' => '<!-- wp:page-list /-->',
			)
		);

		// The view context still yields rendered HTML (no serialized block comment).
		$result = wp_get_ability( 'menus/get-navigation' )->execute(
			array(
				'id'      => $nav_id,
				'context' => 'view',
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<!-- wp:', $result['content'] );
	}

	public function test_output_contains_edit_link(): void {
		$this->actingAs( 'administrator' );

		$nav_id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'post_title'  => 'Footer Navigation',
			)
		);

		$result = wp_get_ability( 'menus/get-navigation' )->execute( array( 'id' => $nav_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'edit_link', $result );
		$this->assertStringContainsString( 'site-editor.php', $result['edit_link'] );
		$this->assertStringContainsString( (string) $nav_id, $result['edit_link'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$nav_id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'post_title'  => 'Guarded Navigation',
			)
		);

		$result = wp_get_ability( 'menus/get-navigation' )->execute( array( 'id' => $nav_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
