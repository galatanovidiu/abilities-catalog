<?php
/**
 * Integration tests for the post-meta abilities.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises get/update/delete/list post-meta end-to-end against a registered
 * show_in_rest meta key, plus the registered-key gate and the permission guard.
 */
final class PostMetaTest extends TestCase {

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		register_post_meta(
			'post',
			'subtitle',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'description'  => 'A post subtitle.',
			)
		);

		// A key that is NOT exposed to REST — must never be reachable.
		register_post_meta(
			'post',
			'internal_flag',
			array(
				'show_in_rest' => false,
				'single'       => true,
				'type'         => 'string',
			)
		);

		$this->post_id = self::factory()->post->create();
	}

	public function tear_down(): void {
		unregister_post_meta( 'post', 'subtitle' );
		unregister_post_meta( 'post', 'internal_flag' );
		parent::tear_down();
	}

	public function test_abilities_are_registered(): void {
		$this->assertNotNull( wp_get_ability( 'content/get-post-meta' ) );
		$this->assertNotNull( wp_get_ability( 'content/update-post-meta' ) );
		$this->assertNotNull( wp_get_ability( 'content/delete-post-meta' ) );
		$this->assertNotNull( wp_get_ability( 'content/list-post-meta-keys' ) );
	}

	public function test_list_returns_only_show_in_rest_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/list-post-meta-keys' )->execute( array( 'post_type' => 'post' ) );

		$this->assertIsArray( $result );
		$keys = wp_list_pluck( $result['keys'], 'key' );
		$this->assertContains( 'subtitle', $keys );
		$this->assertNotContains( 'internal_flag', $keys );
	}

	public function test_update_then_get_roundtrip(): void {
		$this->actingAs( 'administrator' );

		$updated = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'subtitle' => 'Hello world' ),
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'Hello world', ( (array) $updated['meta'] )['subtitle'] );
		$this->assertNotEmpty( $updated['edit_link'] );
		$this->assertSame( 'Hello world', get_post_meta( $this->post_id, 'subtitle', true ) );

		$got = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => $this->post_id ) );
		$this->assertSame( 'Hello world', ( (array) $got['meta'] )['subtitle'] );
	}

	public function test_update_rejects_unregistered_key(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'internal_flag' => 'x' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_meta_unknown_key', $result->get_error_code() );
		// Nothing was written.
		$this->assertSame( '', get_post_meta( $this->post_id, 'internal_flag', true ) );
	}

	public function test_delete_removes_key(): void {
		$this->actingAs( 'administrator' );
		update_post_meta( $this->post_id, 'subtitle', 'to be removed' );

		$result = wp_get_ability( 'content/delete-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'keys' => array( 'subtitle' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'subtitle' ), $result['deleted'] );
		$this->assertSame( '', get_post_meta( $this->post_id, 'subtitle', true ) );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => $this->post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
