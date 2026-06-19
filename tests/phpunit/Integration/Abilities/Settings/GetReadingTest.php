<?php
/**
 * Integration tests for the settings/get-reading ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\GetReading;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/get-reading is a net-new read of the Reading Settings option values.
 * It always returns all 6 fields; manage_options is the hard capability guard.
 */
final class GetReadingTest extends TestCase {

	/**
	 * The full, always-present output field set.
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'posts_per_rss',
		'blog_public',
	);

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'settings/get-reading' ) );
	}

	public function test_execute_returns_all_fields_typed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/get-reading' )->execute();

		$this->assertIsArray( $result );
		// All 6 fields are always present.
		$this->assertSame( self::FIELDS, array_keys( $result ) );

		// Default front-page display is the latest posts.
		$this->assertSame( 'posts', $result['show_on_front'] );

		// Page IDs are non-negative (via absint); counts are plain ints (the -1
		// "show all" sentinel must survive, so they are cast with (int), not absint).
		$this->assertIsInt( $result['page_on_front'] );
		$this->assertIsInt( $result['page_for_posts'] );
		$this->assertIsInt( $result['posts_per_page'] );
		$this->assertIsInt( $result['posts_per_rss'] );

		// Search-engine visibility is a boolean.
		$this->assertIsBool( $result['blog_public'] );
	}

	public function test_show_all_sentinel_is_not_collapsed(): void {
		$this->actingAs( 'administrator' );

		update_option( 'posts_per_rss', -1 );

		$result = wp_get_ability( 'settings/get-reading' )->execute();

		// absint() would report 1 here; (int) keeps the stored -1 ("show all").
		$this->assertSame( -1, $result['posts_per_rss'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/get-reading' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_manage_options(): void {
		$ability = new GetReading();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
