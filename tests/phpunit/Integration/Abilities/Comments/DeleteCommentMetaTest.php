<?php
/**
 * Integration tests for the og-comments/delete-meta ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-comments/delete-meta end-to-end against a registered show_in_rest
 * comment-meta key, plus the registered-key security gate (an internal key like
 * wp_capabilities or a _-prefixed key is rejected), the missing-comment 404, and
 * the per-key capability guard.
 */
final class DeleteCommentMetaTest extends TestCase {

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
				'description'    => 'A test comment meta key.',
			)
		);

		$this->post_id    = self::factory()->post->create();
		$this->comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_approved' => '1',
			)
		);

		update_metadata( 'comment', $this->comment_id, 'abilities_catalog_test_key', 'to be removed' );
	}

	public function tear_down(): void {
		unregister_meta_key( 'comment', 'abilities_catalog_test_key', 'comment' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-comments/delete-meta' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-comments/delete-meta', $ability->get_name() );
	}

	public function test_admin_can_delete_registered_meta(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/delete-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'deleted', 'edit_link' ), array_keys( $result ) );
		$this->assertSame( $this->comment_id, $result['id'] );
		$this->assertSame( array( 'abilities_catalog_test_key' ), $result['deleted'] );
		$this->assertIsString( $result['edit_link'] );

		// Read back: the meta is gone.
		$this->assertSame( '', get_metadata( 'comment', $this->comment_id, 'abilities_catalog_test_key', true ) );
	}

	/**
	 * SECURITY: an unregistered / internal meta key must be rejected, and nothing
	 * is deleted. wp_capabilities is a real internal user/comment-style key that
	 * must never be reachable through this ability.
	 */
	public function test_unknown_key_is_rejected_and_nothing_deleted(): void {
		$this->actingAs( 'administrator' );

		// Seed an internal-looking key directly so we can prove it survives.
		update_metadata( 'comment', $this->comment_id, '_internal_state', 'secret' );

		$result = wp_get_ability( 'og-comments/delete-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'keys' => array( 'wp_capabilities', '_internal_state' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		// Nothing was deleted: the internal key survives, and so does the registered one.
		$this->assertSame( 'secret', get_metadata( 'comment', $this->comment_id, '_internal_state', true ) );
		$this->assertSame( 'to be removed', get_metadata( 'comment', $this->comment_id, 'abilities_catalog_test_key', true ) );
	}

	/**
	 * A key registered without show_in_rest is also rejected before any deletion,
	 * even though it is a registered key.
	 */
	public function test_non_rest_key_is_rejected(): void {
		$this->actingAs( 'administrator' );

		register_meta(
			'comment',
			'abilities_catalog_hidden_key',
			array(
				'object_subtype' => 'comment',
				'show_in_rest'   => false,
				'single'         => true,
				'type'           => 'string',
			)
		);
		update_metadata( 'comment', $this->comment_id, 'abilities_catalog_hidden_key', 'hidden' );

		$result = wp_get_ability( 'og-comments/delete-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'keys' => array( 'abilities_catalog_hidden_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame( 'hidden', get_metadata( 'comment', $this->comment_id, 'abilities_catalog_hidden_key', true ) );

		unregister_meta_key( 'comment', 'abilities_catalog_hidden_key', 'comment' );
	}

	/**
	 * A missing comment returns the specific 404, not a generic permission collapse,
	 * and is ordered before any per-key capability check.
	 */
	public function test_missing_comment_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/delete-meta' )->execute(
			array(
				'id'   => 99999999,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_comment_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied_and_value_survives(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-comments/delete-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		// A logged-out user cannot edit the comment: the object-level edit_comment
		// guard denies it with rest_forbidden (403) before any key is touched.
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		// The value survives unchanged.
		$this->assertSame( 'to be removed', get_metadata( 'comment', $this->comment_id, 'abilities_catalog_test_key', true ) );
	}

	/**
	 * A subscriber cannot edit the comment, so the object-level edit_comment guard
	 * returns a specific 403, not the 404, and the value survives.
	 */
	public function test_subscriber_without_capability_is_denied_with_403(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-comments/delete-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'rest_comment_invalid_id', $result->get_error_code() );

		$this->assertSame( 'to be removed', get_metadata( 'comment', $this->comment_id, 'abilities_catalog_test_key', true ) );
	}

	public function test_output_shape_is_exact_key_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/delete-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'deleted', 'edit_link' ), array_keys( $result ) );
		$this->assertIsInt( $result['id'] );
		$this->assertIsArray( $result['deleted'] );
		$this->assertIsString( $result['edit_link'] );
	}
}
