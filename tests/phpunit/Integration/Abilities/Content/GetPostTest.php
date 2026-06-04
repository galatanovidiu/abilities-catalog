<?php
/**
 * Integration tests for content/get-post output fidelity.
 *
 * Covers the additive output fields (slug, password_protected) the ability
 * returns so a caller can tell a locked post from a genuinely empty one and
 * read the post's semantic slug, plus core's invalid-id error contract for a
 * non-positive id.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content\GetPost;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises content/get-post output.
 */
final class GetPostTest extends TestCase {

	public function test_output_returns_slug_for_published_post(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Has slug',
				'post_name'   => 'has-slug',
			)
		);

		$result = wp_get_ability( 'content/get-post' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertSame( 'has-slug', $result['slug'] );
		$this->assertArrayHasKey( 'password_protected', $result );
		$this->assertFalse( $result['password_protected'] );
	}

	public function test_password_protected_post_is_flagged_when_password_omitted(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'Locked post',
				'post_content'  => 'Secret body',
				'post_password' => 'hunter2',
			)
		);

		$result = wp_get_ability( 'content/get-post' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['password_protected'] );
		// Core blanks rendered content when the password is missing.
		$this->assertSame( '', $result['content'] );
	}

	public function test_password_protected_post_returns_content_with_password(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'Locked post',
				'post_content'  => 'Secret body',
				'post_password' => 'hunter2',
			)
		);

		$result = wp_get_ability( 'content/get-post' )->execute(
			array(
				'id'       => $id,
				'password' => 'hunter2',
			)
		);

		$this->assertIsArray( $result );
		// The flag reflects that a password is set, not that content is locked,
		// so it stays true even when the correct password unlocks the content.
		$this->assertTrue( $result['password_protected'] );
		$this->assertStringContainsString( 'Secret body', $result['content'] );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		// The `minimum: 1` input guard rejects a non-positive id at the schema
		// boundary, before execute() builds a REST path.
		$result = wp_get_ability( 'content/get-post' )->execute( array( 'id' => -12 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_execute_preserves_core_invalid_id_error_for_zero_id(): void {
		$this->actingAs( 'administrator' );

		// Call execute() directly to bypass schema validation: with the
		// absolute-value coercion removed, a non-positive id preserves core's
		// invalid-id 404 path instead of resolving a different post.
		$result = ( new GetPost() )->execute( array( 'id' => 0 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
	}
}
