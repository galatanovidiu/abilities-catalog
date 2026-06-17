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
}
