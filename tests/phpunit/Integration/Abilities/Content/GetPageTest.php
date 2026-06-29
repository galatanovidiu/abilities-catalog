<?php
/**
 * Integration tests for the og-content/get-page ability.
 *
 * Covers registration through the Abilities REST Adapter seam, the additive
 * output fields (slug, password_protected, *_raw) the ability returns, and the
 * route's real permission errors surfacing through execute().
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-content/get-page output and the adapter-backed permission contract.
 */
final class GetPageTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-content/get-page' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-content/get-page', $ability->get_name() );
	}

	public function test_output_returns_slug_for_published_page(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Has slug',
				'post_name'   => 'has-slug',
			)
		);

		$result = wp_get_ability( 'og-content/get-page' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertSame( 'has-slug', $result['slug'] );
		$this->assertArrayHasKey( 'password_protected', $result );
		$this->assertFalse( $result['password_protected'] );
	}

	public function test_password_protected_page_is_flagged_when_password_omitted(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_title'    => 'Locked page',
				'post_content'  => 'Secret body',
				'post_password' => 'hunter2',
			)
		);

		$result = wp_get_ability( 'og-content/get-page' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['password_protected'] );
		// Core blanks rendered content when the password is missing.
		$this->assertSame( '', $result['content'] );
	}

	public function test_password_protected_page_returns_content_with_password(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_title'    => 'Locked page',
				'post_content'  => 'Secret body',
				'post_password' => 'hunter2',
			)
		);

		$result = wp_get_ability( 'og-content/get-page' )->execute(
			array(
				'id'       => $id,
				'password' => 'hunter2',
			)
		);

		$this->assertIsArray( $result );
		// The flag reflects that a password is set, not that content is locked,
		// so it stays true even when the correct password unlocks the content.
		$this->assertTrue( $result['password_protected'] );
		$this->assertStringContainsString( 'Secret body', $result['content'] );
	}

	public function test_edit_context_returns_raw_block_markup(): void {
		$this->actingAs( 'administrator' );

		$markup = '<!-- wp:paragraph --><p>Page block body</p><!-- /wp:paragraph -->';
		$id     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Raw page title',
				'post_content' => $markup,
				'post_excerpt' => 'Raw page excerpt',
			)
		);

		$result = wp_get_ability( 'og-content/get-page' )->execute(
			array(
				'id'      => $id,
				'context' => 'edit',
			)
		);

		$this->assertIsArray( $result );
		// In edit context core exposes the stored block markup; the ability
		// surfaces it as flat *_raw fields.
		$this->assertArrayHasKey( 'content_raw', $result );
		$this->assertSame( $markup, $result['content_raw'] );
		$this->assertSame( 'Raw page title', $result['title_raw'] );
		$this->assertSame( 'Raw page excerpt', $result['excerpt_raw'] );
	}

	public function test_view_context_omits_raw_fields(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'No raw page',
				'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		// Default (view) context: core does not return *.raw.
		$result = wp_get_ability( 'og-content/get-page' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'content_raw', $result );
		$this->assertArrayNotHasKey( 'title_raw', $result );
		$this->assertArrayNotHasKey( 'excerpt_raw', $result );
	}

	public function test_logged_out_user_can_read_published_page(): void {
		// Adapter-backed: permission delegates to the wrapped route, which allows an
		// anonymous read of a published public page in the default "view" context. The
		// catalog imposes no stricter floor; one would be added via the adapter's
		// require_permission knob.
		wp_set_current_user( 0 );

		$id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Public page',
			)
		);

		$result = wp_get_ability( 'og-content/get-page' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
	}

	public function test_logged_out_user_is_denied_edit_context(): void {
		// The route enforces edit context itself (requires edit access) and runs at
		// dispatch, so execute() surfaces the route's REAL error — not the generic
		// collapse. The adapter's permission phase is guard-only and this ability has
		// no require_permission guard, so the denial lives on the execute() path.
		wp_set_current_user( 0 );

		$id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Public page',
			)
		);

		$result = wp_get_ability( 'og-content/get-page' )->execute(
			array(
				'id'      => $id,
				'context' => 'edit',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_forbidden_context', $result->get_error_code() );
		// 401 because the user is logged out (rest_authorization_required_code()).
		$this->assertSame( 401, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
