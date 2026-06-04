<?php
/**
 * Integration tests for content/get-page output fidelity.
 *
 * Covers the additive output fields (slug, password_protected) the ability
 * returns so a caller can tell a locked page from a genuinely empty one and
 * read the page's semantic slug.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises content/get-page output.
 */
final class GetPageTest extends TestCase {

	public function test_output_returns_slug_for_published_page(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Has slug',
				'post_name'   => 'has-slug',
			)
		);

		$result = wp_get_ability( 'content/get-page' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertSame( 'has-slug', $result['slug'] );
		$this->assertArrayHasKey( 'password_protected', $result );
		$this->assertFalse( $result['password_protected'] );
	}

	public function test_password_protected_page_is_flagged_when_password_omitted(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_title'    => 'Locked page',
				'post_content'  => 'Secret body',
				'post_password' => 'hunter2',
			)
		);

		$result = wp_get_ability( 'content/get-page' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['password_protected'] );
		// Core blanks rendered content when the password is missing.
		$this->assertSame( '', $result['content'] );
	}

	public function test_password_protected_page_returns_content_with_password(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_title'    => 'Locked page',
				'post_content'  => 'Secret body',
				'post_password' => 'hunter2',
			)
		);

		$result = wp_get_ability( 'content/get-page' )->execute(
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
}
