<?php
/**
 * Integration tests for the og-comments/trash-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the trash-comment write ability end-to-end: a comment is moved to
 * the trash, the capability guard is enforced on execute(), the prior status is
 * captured, the flattened identifying fields are returned, and the
 * already-trashed (410) error path is documented.
 */
final class TrashCommentTest extends TestCase {

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

		// Trashing requires a positive trash retention window.
		update_option( 'EMPTY_TRASH_DAYS', 30 );

		$this->post_id    = self::factory()->post->create();
		$this->comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'A doomed comment body.',
				'comment_author'   => 'Jane Doe',
				'comment_approved' => '1',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-comments/trash-comment' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-comments/trash-comment', $ability->get_name() );
	}

	public function test_admin_can_trash_comment(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/trash-comment' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->comment_id, $result['id'] );
		$this->assertSame( 'trash', $result['status'] );
		// previous_status is now the raw comment_approved value core records in
		// _wp_trash_meta_status (read back in shapeOutput), not the human-readable
		// status the pre-adapter execute() derived via wp_get_comment_status(). For an
		// approved comment that raw value is "1". This is what untrash-comment restores.
		$this->assertSame( '1', $result['previous_status'] );
		$this->assertSame( $this->post_id, $result['post'] );
		$this->assertSame( 0, $result['parent'] );
		$this->assertSame( 'Jane Doe', $result['author_name'] );
		$this->assertSame( 'comment', $result['type'] );

		$this->assertSame( 'trash', wp_get_comment_status( $this->comment_id ) );
	}

	public function test_output_has_expected_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/trash-comment' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'id', 'status', 'previous_status', 'post', 'parent', 'author_name', 'type' ),
			array_keys( $result )
		);
	}

	public function test_negative_id_is_rejected_before_trashing(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/trash-comment' )->execute( array( 'id' => -$this->comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( 'approved', wp_get_comment_status( $this->comment_id ) );
	}

	public function test_retrashing_already_trashed_comment_returns_410(): void {
		$this->actingAs( 'administrator' );

		wp_get_ability( 'og-comments/trash-comment' )->execute( array( 'id' => $this->comment_id ) );

		$result = wp_get_ability( 'og-comments/trash-comment' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_already_trashed', $result->get_error_code() );
	}

	/**
	 * A logged-out user now surfaces the wrapped route's specific `rest_cannot_delete`
	 * 401 (the comment exists, the edit-permission check fails for an unauthenticated
	 * caller) rather than the generic `ability_invalid_permissions` collapse — permission
	 * delegates to the route, not a coarse logged-in gate on this ability (backlog B4).
	 * The status is 401, not 403, because `rest_authorization_required_code()` returns 401
	 * when `is_user_logged_in()` is false (wp-includes/rest-api.php).
	 */
	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-comments/trash-comment' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_delete', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertSame( 'approved', wp_get_comment_status( $this->comment_id ) );
	}

	/**
	 * A logged-in non-moderator gets the wrapped route's specific `rest_cannot_delete`
	 * 403, not the generic gate failure. The object-level capability now lives in the
	 * wrapped route, not this ability's permission_callback (backlog B4).
	 */
	public function test_non_moderator_is_denied_with_403(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-comments/trash-comment' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_delete', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'approved', wp_get_comment_status( $this->comment_id ) );
	}

	/**
	 * B4 regression: a non-moderator passing a missing comment id receives the
	 * wrapped route's specific 404, not a generic permission failure.
	 */
	public function test_non_moderator_missing_id_returns_404_not_generic(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-comments/trash-comment' )->execute( array( 'id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_comment_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}
}
