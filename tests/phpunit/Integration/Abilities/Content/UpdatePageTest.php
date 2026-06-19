<?php
/**
 * Integration tests for content/update-page output fidelity.
 *
 * Covers the signed menu_order pass-through so a negative value survives the
 * ability instead of being clamped to a positive integer.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises content/update-page output.
 */
final class UpdatePageTest extends TestCase {

	public function test_negative_menu_order_reaches_post_unchanged(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Ordered page',
			)
		);

		$result = wp_get_ability( 'content/update-page' )->execute(
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

		$result = wp_get_ability( 'content/update-page' )->execute(
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

		$result = wp_get_ability( 'content/update-page' )->execute(
			array(
				'id'     => $child_id,
				'parent' => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, (int) get_post( $child_id )->post_parent );
	}
}
