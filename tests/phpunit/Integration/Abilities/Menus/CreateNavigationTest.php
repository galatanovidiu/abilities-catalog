<?php
/**
 * Integration tests for the menus/create-navigation ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises block navigation creation: happy path, output shape (including the
 * additive title and edit_link fields), the capability guard, and route-level
 * error preservation.
 */
final class CreateNavigationTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'menus/create-navigation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'menus/create-navigation', $ability->get_name() );
	}

	public function test_admin_creates_navigation(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'menus/create-navigation' )->execute(
			array( 'title' => 'Primary Navigation' )
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Primary Navigation', $result['title'] );

		$post = get_post( $result['id'] );
		$this->assertNotNull( $post );
		$this->assertSame( 'wp_navigation', $post->post_type );
	}

	public function test_output_contains_title_and_edit_link(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'menus/create-navigation' )->execute(
			array( 'title' => 'Footer Navigation' )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'edit_link', $result );
		$this->assertArrayHasKey( 'link', $result );
		$this->assertArrayHasKey( 'status', $result );

		// edit_link points at the site editor for this wp_navigation post.
		$this->assertStringContainsString( 'site-editor.php', $result['edit_link'] );
		$this->assertStringContainsString( (string) $result['id'], $result['edit_link'] );
	}

	public function test_omitted_status_creates_draft(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'menus/create-navigation' )->execute(
			array( 'title' => 'Draft Navigation' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'menus/create-navigation' )->execute(
			array( 'title' => 'Denied Navigation' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_route_error_is_preserved(): void {
		$this->actingAs( 'administrator' );

		// An invalid status that is not in the post type's registered statuses is
		// rejected by the REST route; the route-level error code is surfaced.
		$result = wp_get_ability( 'menus/create-navigation' )->execute(
			array(
				'title'  => 'Bad Status Navigation',
				'status' => 'bogus',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
