<?php
/**
 * Integration tests for og-templates/list-synced-patterns output and contract.
 *
 * Covers the flattened happy-path row shape (including the additive sync_status
 * field and the pagination totals), the fact that unsynced patterns are listed
 * (the route is not synced-only), and the coarse edit_posts permission guard.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/list-synced-patterns.
 */
final class ListSyncedPatternsTest extends TestCase {

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

	public function test_returns_flattened_rows_with_sync_status_and_totals(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createPattern();

		$result = wp_get_ability( 'og-templates/list-synced-patterns' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total_pages'] );

		$row = $this->findRow( $result['items'], $id );
		$this->assertNotNull( $row, 'Created pattern must appear in the list.' );
		$this->assertSame( $id, $row['id'] );
		$this->assertSame( 'Reusable Hero', $row['title'] );
		$this->assertSame( 'publish', $row['status'] );
		// The field is always present; empty string means a fully synced pattern.
		$this->assertArrayHasKey( 'sync_status', $row );
		$this->assertSame( '', $row['sync_status'] );
	}

	public function test_unsynced_pattern_is_listed_with_its_sync_status(): void {
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

		$result = wp_get_ability( 'og-templates/list-synced-patterns' )->execute( array() );

		$this->assertIsArray( $result );
		$row = $this->findRow( $result['items'], $id );
		// The route lists ALL user patterns, not just synced ones, so the
		// unsynced row must be present and carry its sync status.
		$this->assertNotNull( $row, 'Unsynced pattern must still be listed.' );
		$this->assertSame( 'unsynced', $row['sync_status'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-templates/list-synced-patterns' );

		// wp_block maps its edit_posts cap to edit_posts, which a subscriber lacks.
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Finds a row in the items list by pattern ID.
	 *
	 * @param array<int,array<string,mixed>> $items The shaped item rows.
	 * @param int                            $id    The pattern post ID to find.
	 * @return array<string,mixed>|null The matching row, or null.
	 */
	private function findRow( array $items, int $id ): ?array {
		foreach ( $items as $row ) {
			if ( isset( $row['id'] ) && (int) $row['id'] === $id ) {
				return $row;
			}
		}

		return null;
	}
}
