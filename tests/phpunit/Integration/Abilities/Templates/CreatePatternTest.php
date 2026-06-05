<?php
/**
 * Integration tests for templates/create-pattern output and contract.
 *
 * Covers the publish-by-default contract (status injected when omitted), the
 * non-empty title read from the blocks controller's raw field, the additive
 * edit_link (Site Editor URL), a wrong-capability denial, and error
 * preservation on a failed create.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/create-pattern.
 */
final class CreatePatternTest extends TestCase {

	public function test_create_returns_title_publish_status_and_edit_link(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/create-pattern' )->execute(
			array(
				'title'   => 'My Pattern',
				'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $result );
		// Title must come back non-empty (read from title.raw).
		$this->assertSame( 'My Pattern', $result['title'] );
		// Status defaults to publish when omitted.
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'publish', get_post( $result['id'] )->post_status );
		// edit_link points at the Site Editor for the new wp_block.
		$this->assertArrayHasKey( 'edit_link', $result );
		$this->assertStringContainsString( 'site-editor.php', $result['edit_link'] );
		$this->assertStringContainsString( 'postType=wp_block', $result['edit_link'] );
		$this->assertStringContainsString( 'postId=' . $result['id'], $result['edit_link'] );
	}

	public function test_explicit_draft_status_is_honored(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/create-pattern' )->execute(
			array(
				'title'   => 'Draft Pattern',
				'content' => '<!-- wp:paragraph --><p>Draft</p><!-- /wp:paragraph -->',
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'draft', get_post( $result['id'] )->post_status );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'templates/create-pattern' );

		// The capability gate must reject a subscriber.
		$this->assertFalse(
			$ability->check_permissions(
				array(
					'title'   => 'Nope',
					'content' => 'x',
				)
			)
		);

		$result = $ability->execute(
			array(
				'title'   => 'Nope',
				'content' => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_missing_required_content_returns_error_without_creating(): void {
		$this->actingAs( 'administrator' );

		$before = wp_count_posts( 'wp_block' )->publish;

		// Content is required by the input schema; omitting it must fail validation.
		$result = wp_get_ability( 'templates/create-pattern' )->execute(
			array( 'title' => 'No content' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, wp_count_posts( 'wp_block' )->publish );
	}
}
