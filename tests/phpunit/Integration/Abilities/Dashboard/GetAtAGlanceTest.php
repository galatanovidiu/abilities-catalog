<?php
/**
 * Integration tests for the og-dashboard/get-at-a-glance ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Dashboard;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the composed read ability: post/page counts, comment counts,
 * active theme, and WordPress version, with the declared closed object shape
 * and the capability guard enforced on execute().
 */
final class GetAtAGlanceTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-dashboard/get-at-a-glance' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-dashboard/get-at-a-glance', $ability->get_name() );
	}

	public function test_result_uses_closed_shape_with_all_six_fields(): void {
		$this->actingAs( 'editor' );

		$result = wp_get_ability( 'og-dashboard/get-at-a-glance' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'posts', 'pages', 'comments_approved', 'comments_pending', 'theme', 'wp_version' ),
			array_keys( $result )
		);
		$this->assertIsInt( $result['posts'] );
		$this->assertIsInt( $result['pages'] );
		$this->assertIsInt( $result['comments_approved'] );
		$this->assertIsInt( $result['comments_pending'] );
		$this->assertIsString( $result['theme'] );
		$this->assertIsString( $result['wp_version'] );
		$this->assertNotSame( '', $result['theme'] );
	}

	public function test_counts_reflect_published_content_and_comment_states(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '0',
			)
		);

		$result = wp_get_ability( 'og-dashboard/get-at-a-glance' )->execute();

		$this->assertGreaterThanOrEqual( 1, $result['posts'] );
		$this->assertGreaterThanOrEqual( 1, $result['pages'] );
		$this->assertGreaterThanOrEqual( 1, $result['comments_approved'] );
		$this->assertGreaterThanOrEqual( 1, $result['comments_pending'] );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-dashboard/get-at-a-glance' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
