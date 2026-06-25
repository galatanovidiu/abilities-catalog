<?php
/**
 * Integration tests for the og-comments/update-meta ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the comment meta write ability end-to-end: a registered show_in_rest
 * meta key is written and read back, an unregistered/internal key is rejected
 * before any write (the security property), a missing comment surfaces the
 * specific 404, and the capability guard is enforced.
 */
final class UpdateCommentMetaTest extends TestCase {

	private const TEST_KEY = 'abilities_catalog_test_key';

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
			self::TEST_KEY,
			array(
				'object_subtype' => 'comment',
				'show_in_rest'   => true,
				'single'         => true,
				'type'           => 'string',
			)
		);

		$this->post_id    = self::factory()->post->create();
		$this->comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Comment body.',
				'comment_approved' => '1',
			)
		);
	}

	public function tear_down(): void {
		delete_metadata( 'comment', $this->comment_id, self::TEST_KEY );
		unregister_meta_key( 'comment', self::TEST_KEY, 'comment' );

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-comments/update-meta' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-comments/update-meta', $ability->get_name() );
	}

	public function test_admin_writes_registered_meta_key(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/update-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'meta' => array( self::TEST_KEY => 'hello world' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $this->comment_id, $result['id'] );
		$this->assertSame( 'hello world', $result['meta']->{self::TEST_KEY} );
		$this->assertSame(
			'hello world',
			get_metadata( 'comment', $this->comment_id, self::TEST_KEY, true ),
			'The stored meta value must reflect the write.'
		);
	}

	public function test_output_shape_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/update-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'meta' => array( self::TEST_KEY => 'value' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'meta', 'edit_link' ), array_keys( $result ) );
		$this->assertIsInt( $result['id'] );
		$this->assertIsObject( $result['meta'] );
		$this->assertIsString( $result['edit_link'] );
		$this->assertStringContainsString( 'comment.php', $result['edit_link'] );
		$this->assertStringContainsString( 'action=editcomment', $result['edit_link'] );
	}

	/**
	 * SECURITY: an unregistered/internal meta key (a `_`-prefixed private key that is
	 * not registered with show_in_rest) is rejected with `rest_meta_unknown_key` (400)
	 * and NOTHING is written. This proves arbitrary/internal meta cannot be set.
	 */
	public function test_unregistered_internal_key_is_rejected_and_nothing_written(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/update-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'meta' => array( '_internal' => 'secret' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame(
			'',
			(string) get_metadata( 'comment', $this->comment_id, '_internal', true ),
			'An unknown key must never be written.'
		);
	}

	/**
	 * A registered key written alongside an unknown key must NOT be applied: the
	 * ability validates every key before writing anything.
	 */
	public function test_unknown_key_blocks_a_valid_key_in_the_same_call(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/update-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'meta' => array(
					self::TEST_KEY => 'should not persist',
					'wp_unknown'   => 'x',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame(
			'',
			(string) get_metadata( 'comment', $this->comment_id, self::TEST_KEY, true ),
			'No key may be written when any key is invalid.'
		);
	}

	/**
	 * A missing comment surfaces the specific `rest_comment_invalid_id` (404), not a
	 * generic permission collapse.
	 */
	public function test_missing_comment_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-comments/update-meta' )->execute(
			array(
				'id'   => 99999999,
				'meta' => array( self::TEST_KEY => 'value' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_comment_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied_and_meta_unchanged(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-comments/update-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'meta' => array( self::TEST_KEY => 'should be denied' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		// permission_callback returns true, so a logged-out caller reaches execute() and
		// is denied by the object-level edit_comment guard, not by the Abilities API.
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame(
			'',
			(string) get_metadata( 'comment', $this->comment_id, self::TEST_KEY, true ),
			'A denied write must not change the meta.'
		);
	}

	/**
	 * A user without `edit_comment` on the comment gets the object-level 403, not the
	 * missing-object 404.
	 */
	public function test_subscriber_is_denied_with_403_not_404(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-comments/update-meta' )->execute(
			array(
				'id'   => $this->comment_id,
				'meta' => array( self::TEST_KEY => 'value' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertNotSame( 'rest_comment_invalid_id', $result->get_error_code() );
		$this->assertSame(
			'',
			(string) get_metadata( 'comment', $this->comment_id, self::TEST_KEY, true ),
			'A denied write must not change the meta.'
		);
	}
}
