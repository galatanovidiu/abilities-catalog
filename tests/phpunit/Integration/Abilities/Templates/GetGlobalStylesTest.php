<?php
/**
 * Integration tests for the og-templates/get-global-styles ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Theme_JSON_Resolver;

/**
 * og-templates/get-global-styles is a read of the active theme's user global-style
 * overrides. It must NOT create a wp_global_styles row (the readonly annotation),
 * and it 404s honestly when no overrides record exists.
 */
final class GetGlobalStylesTest extends TestCase {

	public function tear_down(): void {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-templates/get-global-styles' ) );
	}

	/**
	 * The core of S4: a read must not insert a wp_global_styles row. When none
	 * exists, the ability returns a 404 instead of fabricating (and persisting) one.
	 */
	public function test_read_does_not_create_a_global_styles_row(): void {
		$this->actingAs( 'administrator' );

		// Clean slate: remove any existing user global-styles record + cache.
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

		$before = $this->globalStylesRowCount();

		$result = wp_get_ability( 'og-templates/get-global-styles' )->execute();

		$this->assertSame( $before, $this->globalStylesRowCount(), 'A read must not create a wp_global_styles row.' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'global_styles_unavailable', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * When an overrides record exists, the read returns it, shaping the empty
	 * settings/styles sections as JSON objects ({}), not arrays.
	 */
	public function test_returns_existing_record_with_object_sections(): void {
		$this->actingAs( 'administrator' );

		// Create the record via core's create path, then read it back.
		$id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$this->assertGreaterThan( 0, $id );

		$result = wp_get_ability( 'og-templates/get-global-styles' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		// Empty overrides must serialize as objects to satisfy the type:object schema.
		$this->assertIsObject( $result['settings'] );
		$this->assertIsObject( $result['styles'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-templates/get-global-styles' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
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
