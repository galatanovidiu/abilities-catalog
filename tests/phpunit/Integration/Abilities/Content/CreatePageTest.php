<?php
/**
 * Integration tests for the og-content/create-page ability.
 *
 * Covers registration, the curated input pass-through (signed menu_order, the
 * additive output fields slug/date/featured_media a caller uses to detect how
 * core resolved the request), and the adapter-backed denial path: a route-level
 * permission failure now surfaces through execute() as the real REST error.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-content/create-page end-to-end: curated input in, shaped field set
 * out, with the capability check enforced by the wrapped REST route on execute().
 */
final class CreatePageTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-content/create-page' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-content/create-page', $ability->get_name() );
	}

	public function test_negative_menu_order_reaches_post_unchanged(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/create-page' )->execute(
			array(
				'title'      => 'Ordered page',
				'menu_order' => -5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( -5, get_post( $result['id'] )->menu_order );
	}

	public function test_output_returns_slug_date_and_featured_media(): void {
		$this->actingAs( 'administrator' );

		// Publish so core assigns a public slug (drafts expose an empty slug).
		$result = wp_get_ability( 'og-content/create-page' )->execute(
			array(
				'title'  => 'Has slug',
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertNotEmpty( $result['slug'] );
		$this->assertArrayHasKey( 'date', $result );
		$this->assertNotEmpty( $result['date'] );
		$this->assertArrayHasKey( 'featured_media', $result );
		$this->assertSame( 0, $result['featured_media'] );
	}

	public function test_invalid_featured_media_yields_zero_in_output(): void {
		$this->actingAs( 'administrator' );

		// A non-existent attachment ID: core silently ignores it on create.
		$result = wp_get_ability( 'og-content/create-page' )->execute(
			array(
				'title'          => 'No thumbnail',
				'featured_media' => 999999,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['featured_media'] );
	}

	public function test_logged_out_user_is_denied(): void {
		// The route enforces the create capability itself and runs at dispatch, so
		// execute() surfaces the route's REAL error — not the generic collapse. The
		// adapter's permission phase is guard-only and this ability has no
		// require_permission guard, so the denial lives on the execute() path.
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-content/create-page' )->execute(
			array(
				'title' => 'Should not exist',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
		// 401 because the user is logged out (rest_authorization_required_code()).
		$this->assertSame( 401, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
