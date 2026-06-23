<?php
/**
 * Integration tests for the comments/get-meta ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises comments/get-meta end-to-end: a registered show_in_rest comment-meta
 * key is returned, the registered-key gate keeps internal/unregistered meta out,
 * and the object-level capability guard surfaces a specific 404/403 rather than a
 * generic permission collapse.
 */
final class GetCommentMetaTest extends TestCase {

	/**
	 * Post the comment is attached to.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Comment under test.
	 *
	 * @var int
	 */
	private int $comment_id;

	public function set_up(): void {
		parent::set_up();

		register_meta(
			'comment',
			'abilities_catalog_test_key',
			array(
				'object_subtype' => 'comment',
				'show_in_rest'   => true,
				'single'         => true,
				'type'           => 'string',
				'description'    => 'A test comment-meta key.',
			)
		);

		$this->post_id    = self::factory()->post->create();
		$this->comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'A test comment body.',
				'comment_approved' => '1',
			)
		);
	}

	public function tear_down(): void {
		unregister_meta_key( 'comment', 'abilities_catalog_test_key', 'comment' );
		wp_delete_comment( $this->comment_id, true );
		wp_delete_post( $this->post_id, true );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'comments/get-meta' ) );
	}

	public function test_get_returns_registered_meta_value(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'comment', $this->comment_id, 'abilities_catalog_test_key', 'hello world' );

		$result = wp_get_ability( 'comments/get-meta' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->comment_id, $result['id'] );
		$this->assertIsObject( $result['meta'] );
		$meta = (array) $result['meta'];
		$this->assertArrayHasKey( 'abilities_catalog_test_key', $meta );
		$this->assertSame( 'hello world', $meta['abilities_catalog_test_key'] );
	}

	public function test_get_keys_filter_returns_subset(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'comment', $this->comment_id, 'abilities_catalog_test_key', 'only me' );

		$result = wp_get_ability( 'comments/get-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'abilities_catalog_test_key' ), array_keys( (array) $result['meta'] ) );
	}

	/**
	 * Security property: an unregistered/internal key (here a synthetic internal
	 * key set directly in the DB) is never reachable through the ability — the
	 * registered-key gate silently drops it from the result.
	 */
	public function test_get_drops_unregistered_key(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'comment', $this->comment_id, '_internal_secret', 'do-not-leak' );

		$result = wp_get_ability( 'comments/get-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'keys' => array( 'abilities_catalog_test_key', '_internal_secret', 'does_not_exist' ),
			)
		);

		$this->assertIsArray( $result );
		$meta = (array) $result['meta'];
		$this->assertArrayNotHasKey( '_internal_secret', $meta );
		$this->assertArrayNotHasKey( 'does_not_exist', $meta );
	}

	public function test_get_missing_comment_returns_invalid_id_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'comments/get-meta' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_comment_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'comments/get-meta' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	/**
	 * A user without `edit_comment` gets a specific 403, never the 404 — the
	 * object exists, the caller just may not read its meta.
	 */
	public function test_user_without_edit_comment_is_forbidden_not_missing(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'comments/get-meta' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'rest_comment_invalid_id', $result->get_error_code() );
	}

	public function test_output_shape_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'comments/get-meta' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'meta' ), array_keys( $result ) );
		$this->assertIsInt( $result['id'] );
		$this->assertIsObject( $result['meta'] );
	}
}
