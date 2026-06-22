<?php
/**
 * Integration tests for the templates/init-global-styles ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Theme_JSON_Resolver;

/**
 * templates/init-global-styles bootstraps the active theme's user global-styles
 * record. It creates the record when none exists (so a fresh block theme can have
 * its colors/fonts changed) and is idempotent — repeat calls return the same id and
 * never duplicate the row. It is the create-side counterpart to the read-only
 * templates/get-global-styles, which 404s when no record exists yet.
 */
final class InitGlobalStylesTest extends TestCase {

	public function tear_down(): void {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'templates/init-global-styles' ) );
	}

	/**
	 * On a fresh theme with no overrides record, init creates one and returns its id —
	 * unblocking templates/update-global-styles, which requires that id.
	 *
	 * @return void
	 */
	public function test_creates_record_when_none_exists(): void {
		$this->actingAs( 'administrator' );
		$this->deleteGlobalStylesRows();

		$before = $this->globalStylesRowCount();
		$result = wp_get_ability( 'templates/init-global-styles' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( $before + 1, $this->globalStylesRowCount(), 'init must create the missing record.' );

		// The record init created is now readable through the read ability (which 404s
		// before the record exists).
		$read = wp_get_ability( 'templates/get-global-styles' )->execute();
		$this->assertIsArray( $read );
		$this->assertSame( $result['id'], $read['id'] );
	}

	/**
	 * Idempotent: a second call returns the same id and adds no second row, even after
	 * the resolver cache is cleared (so it re-finds the existing row, not creates one).
	 *
	 * @return void
	 */
	public function test_is_idempotent(): void {
		$this->actingAs( 'administrator' );
		$this->deleteGlobalStylesRows();

		$first = wp_get_ability( 'templates/init-global-styles' )->execute();
		WP_Theme_JSON_Resolver::clean_cached_data();
		$count_after_first = $this->globalStylesRowCount();

		$second = wp_get_ability( 'templates/init-global-styles' )->execute();

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first['id'], $second['id'], 'Repeat init must return the same record id.' );
		$this->assertSame( $count_after_first, $this->globalStylesRowCount(), 'Repeat init must not duplicate the row.' );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'templates/init-global-styles' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Deletes every wp_global_styles row and clears the resolver cache for a clean slate.
	 *
	 * @return void
	 */
	private function deleteGlobalStylesRows(): void {
		$existing = get_posts(
			array(
				'post_type'   => 'wp_global_styles',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $existing as $gs_id ) {
			wp_delete_post( (int) $gs_id, true );
		}
		WP_Theme_JSON_Resolver::clean_cached_data();
	}

	/**
	 * Counts published wp_global_styles rows.
	 *
	 * @return int The number of published global-styles records.
	 */
	private function globalStylesRowCount(): int {
		$counts = wp_count_posts( 'wp_global_styles' );

		return (int) ( $counts->publish ?? 0 );
	}
}
