<?php
/**
 * Integration tests for content/delete-page cascade-effect reporting.
 *
 * Permanently deleting a page has secondary mutations that are otherwise
 * invisible to the caller: deleting the front page or posts page resets the
 * reading settings, and deleting a parent reparents its child pages. The
 * ability snapshots this state before dispatch and reports it as optional
 * output fields.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises content/delete-page cascade reporting.
 */
final class DeletePageCascadeTest extends TestCase {

	public function test_deleting_front_page_reports_was_front_page(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Home',
				'post_status' => 'publish',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$result = wp_get_ability( 'content/delete-page' )->execute( array( 'id' => $page_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertTrue( $result['was_front_page'] );
		$this->assertFalse( $result['was_posts_page'] );
		// Core reset the reading settings as a side effect.
		$this->assertSame( 'posts', get_option( 'show_on_front' ) );
		$this->assertSame( 0, (int) get_option( 'page_on_front' ) );
	}

	public function test_deleting_posts_page_reports_was_posts_page(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Blog',
				'post_status' => 'publish',
			)
		);
		update_option( 'page_for_posts', $page_id );

		$result = wp_get_ability( 'content/delete-page' )->execute( array( 'id' => $page_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_posts_page'] );
		$this->assertFalse( $result['was_front_page'] );
		$this->assertSame( 0, (int) get_option( 'page_for_posts' ) );
	}

	public function test_deleting_parent_reports_previous_parent_and_reparented_count(): void {
		$this->actingAs( 'administrator' );

		$grandparent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $grandparent_id,
				'post_status' => 'publish',
			)
		);
		$child_one = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);
		$child_two = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'content/delete-page' )->execute( array( 'id' => $parent_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $grandparent_id, $result['previous_parent'] );
		$this->assertSame( 2, $result['reparented_child_count'] );
		// Core reparented the children to the grandparent.
		$this->assertSame( $grandparent_id, get_post( $child_one )->post_parent );
		$this->assertSame( $grandparent_id, get_post( $child_two )->post_parent );
	}

	public function test_deleting_plain_page_reports_zero_cascade(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'content/delete-page' )->execute( array( 'id' => $page_id ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['was_front_page'] );
		$this->assertFalse( $result['was_posts_page'] );
		$this->assertSame( 0, $result['previous_parent'] );
		$this->assertSame( 0, $result['reparented_child_count'] );
	}
}
