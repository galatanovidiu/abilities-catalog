<?php
/**
 * Integration tests for templates/get-pattern output and contract.
 *
 * Covers the non-empty title in the default "view" context (read from the
 * blocks controller's title.raw field), the additive sync_status output
 * field, and the existing object-level read permission behavior.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/get-pattern.
 */
final class GetPatternTest extends TestCase {

	/**
	 * Creates a wp_block (user pattern) post and returns its ID.
	 *
	 * @param array<string,mixed> $args Optional overrides for wp_insert_post.
	 * @return int The created pattern post ID.
	 */
	private function createPattern( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'wp_block',
					'post_title'   => 'Reusable Hero',
					'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
				),
				$args
			)
		);
	}

	public function test_view_context_returns_non_empty_title_and_content(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createPattern();

		// Default context is "view"; the title must still come back non-empty
		// because the blocks controller exposes title.raw in both contexts.
		$result = wp_get_ability( 'templates/get-pattern' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Reusable Hero', $result['title'] );
		$this->assertStringContainsString( 'wp:paragraph', $result['content'] );
	}

	public function test_sync_status_is_exposed_and_empty_for_a_fully_synced_pattern(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createPattern();

		$result = wp_get_ability( 'templates/get-pattern' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		// The field is always present; empty string means a fully synced pattern.
		$this->assertArrayHasKey( 'sync_status', $result );
		$this->assertSame( '', $result['sync_status'] );
	}

	public function test_sync_status_reflects_unsynced_meta(): void {
		$this->actingAs( 'administrator' );

		// Core registers wp_pattern_sync_status for wp_block on init and the
		// blocks controller promotes the meta to a top-level field. Across tests
		// in one process the shared REST server caches its registered meta
		// fields, so re-run the core registration and rebuild the server before
		// dispatching to guarantee the meta is exposed in the response.
		wp_create_initial_post_meta();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core global; rebuild so re-registered meta is exposed.
		rest_get_server();

		$id = $this->createPattern();
		update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );

		$result = wp_get_ability( 'templates/get-pattern' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'unsynced', $result['sync_status'] );
	}

	public function test_subscriber_is_denied_object_level_read(): void {
		$this->actingAs( 'subscriber' );

		$id = $this->createPattern();

		$ability = wp_get_ability( 'templates/get-pattern' );

		// The object-level read_post guard rejects a subscriber: wp_block maps
		// its read cap to edit_posts, which a subscriber lacks.
		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
