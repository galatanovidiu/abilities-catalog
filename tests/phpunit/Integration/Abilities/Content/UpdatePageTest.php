<?php
/**
 * Integration tests for the og-content/update-page ability.
 *
 * Adapter-backed: input is the catalog's curated schema normalized by an
 * input_callback, output is reshaped to the six-field set, and permission
 * delegates to the wrapped POST /wp/v2/pages/<id> route. Covers registration,
 * the pass-through rules (negative menu_order, empty title, zero parent), and a
 * route-surfaced denial via execute().
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-content/update-page.
 */
final class UpdatePageTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-content/update-page' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-content/update-page', $ability->get_name() );
	}

	public function test_admin_can_update_page_fields(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Before',
			)
		);

		$result = wp_get_ability( 'og-content/update-page' )->execute(
			array(
				'id'    => $page_id,
				'title' => 'After',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $page_id, $result['id'] );
		$this->assertSame( 'After', $result['title'] );
		$this->assertArrayHasKey( 'edit_link', $result );
		$this->assertSame( 'After', get_post( $page_id )->post_title );
	}

	public function test_negative_menu_order_reaches_post_unchanged(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Ordered page',
			)
		);

		$result = wp_get_ability( 'og-content/update-page' )->execute(
			array(
				'id'         => $page_id,
				'menu_order' => -5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( -5, get_post( $page_id )->menu_order );
	}

	public function test_empty_title_clears_the_title(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Has A Title',
			)
		);

		$result = wp_get_ability( 'og-content/update-page' )->execute(
			array(
				'id'    => $page_id,
				'title' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', get_post( $page_id )->post_title );
	}

	public function test_zero_parent_detaches_the_parent(): void {
		$this->actingAs( 'administrator' );

		$parent_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$child_id  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
			)
		);
		$this->assertSame( $parent_id, (int) get_post( $child_id )->post_parent );

		$result = wp_get_ability( 'og-content/update-page' )->execute(
			array(
				'id'     => $child_id,
				'parent' => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, (int) get_post( $child_id )->post_parent );
	}

	public function test_logged_out_user_is_denied_with_real_route_error(): void {
		// Adapter-backed: the route's own permission check runs at dispatch, so execute()
		// surfaces the REAL REST error — not the generic ability_invalid_permissions
		// collapse. The adapter's permission phase is guard-only and this ability has no
		// require_permission floor, so the denial lives on the execute() path.
		wp_set_current_user( 0 );

		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Locked',
			)
		);

		$result = wp_get_ability( 'og-content/update-page' )->execute(
			array(
				'id'    => $page_id,
				'title' => 'Hijacked',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
		// 401 because the user is logged out (rest_authorization_required_code()).
		$this->assertSame( 401, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertSame( 'Locked', get_post( $page_id )->post_title );
	}
}
