<?php
/**
 * Integration tests for the og-dashboard/get-drafts ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Dashboard;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the composed read ability: the current user's recent drafts out,
 * with the declared closed item shape, author scoping, and the capability
 * guard enforced on execute().
 */
final class GetDraftsTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-dashboard/get-drafts' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-dashboard/get-drafts', $ability->get_name() );
	}

	public function test_returns_own_drafts_with_closed_shape(): void {
		$this->actingAs( 'administrator' );

		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'My Draft',
				'post_author' => get_current_user_id(),
			)
		);

		$result = wp_get_ability( 'og-dashboard/get-drafts' )->execute( array( 'number' => 5 ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $draft_id, $ids );

		foreach ( $result['items'] as $item ) {
			$this->assertSame( array( 'id', 'title', 'modified', 'edit_link' ), array_keys( $item ) );
			$this->assertIsInt( $item['id'] );
			$this->assertIsString( $item['title'] );
			$this->assertIsString( $item['modified'] );
			$this->assertTrue( null === $item['edit_link'] || is_string( $item['edit_link'] ) );
		}
	}

	public function test_edit_link_is_raw_url_without_html_entities(): void {
		$this->actingAs( 'administrator' );

		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			)
		);

		$result = wp_get_ability( 'og-dashboard/get-drafts' )->execute( array( 'number' => 5 ) );

		$rows = array_filter(
			$result['items'],
			static fn( $item ) => $item['id'] === $draft_id
		);
		$row  = array_shift( $rows );

		$this->assertNotNull( $row );
		$this->assertIsString( $row['edit_link'] );
		$this->assertStringNotContainsString( '&amp;', $row['edit_link'] );
	}

	public function test_other_users_drafts_are_excluded(): void {
		$other_user  = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_draft = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $other_user,
			)
		);

		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-dashboard/get-drafts' )->execute( array( 'number' => 20 ) );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertNotContains( $other_draft, $ids );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-dashboard/get-drafts' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
