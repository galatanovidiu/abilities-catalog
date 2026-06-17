<?php
/**
 * Integration tests for the settings/update-reading ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\UpdateReading;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/update-reading writes the Reading Settings split across POST
 * /wp/v2/settings (REST-registered keys) and direct update_option() (posts_per_rss,
 * blog_public). manage_options is the hard capability guard; show_on_front is
 * validated in-execute; the -1 "show all" sentinel survives write.
 */
final class UpdateReadingTest extends TestCase {

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
		$this->assertNotNull( wp_get_ability( 'settings/update-reading' ) );
	}

	public function test_admin_writes_rest_and_non_rest_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-reading' )->execute(
			array(
				'show_on_front'  => 'posts',
				'posts_per_page' => 7,
				'posts_per_rss'  => 5,
				'blog_public'    => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'posts', $result['show_on_front'] );
		$this->assertSame( 7, $result['posts_per_page'] );
		$this->assertSame( 5, $result['posts_per_rss'] );
		$this->assertFalse( $result['blog_public'] );

		// REST key persisted, plus the two direct-write keys.
		$this->assertSame( 7, absint( get_option( 'posts_per_page' ) ) );
		$this->assertSame( 5, absint( get_option( 'posts_per_rss' ) ) );
		$this->assertSame( 0, (int) get_option( 'blog_public' ) );
	}

	public function test_output_returns_all_six_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-reading' )->execute(
			array( 'show_on_front' => 'posts' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::FIELDS, array_keys( $result ) );
	}

	public function test_output_schema_marks_all_fields_required(): void {
		$schema = ( new UpdateReading() )->args()['output_schema'];

		$this->assertSame( self::FIELDS, $schema['required'] );
	}

	public function test_execute_rejects_invalid_show_on_front(): void {
		$this->actingAs( 'administrator' );

		$before = get_option( 'show_on_front' );

		// Call the class directly to bypass the schema enum and reach the guard.
		$result = ( new UpdateReading() )->execute(
			array( 'show_on_front' => 'foo' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webmcp_invalid_show_on_front', $result->get_error_code() );
		$this->assertSame( $before, get_option( 'show_on_front' ) );
	}

	public function test_posts_per_page_preserves_show_all_sentinel(): void {
		$this->actingAs( 'administrator' );

		// Input only the non-REST-registered keys: skips the REST dispatch.
		$result = wp_get_ability( 'settings/update-reading' )->execute(
			array( 'posts_per_rss' => -1 )
		);

		$this->assertIsArray( $result );
		// Core's sanitizer keeps -1 ("show all"); absint() would have made it 1.
		$this->assertSame( -1, (int) get_option( 'posts_per_rss' ) );
	}

	public function test_non_rest_only_input_skips_rest_dispatch(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-reading' )->execute(
			array( 'blog_public' => true )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['blog_public'] );
		$this->assertSame( 1, (int) get_option( 'blog_public' ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/update-reading' )->execute(
			array( 'show_on_front' => 'page' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_direct_execute_enforces_capability(): void {
		// Reach the defense-in-depth guard before the direct update_option() path.
		$this->actingAs( 'subscriber' );

		$result = ( new UpdateReading() )->execute(
			array( 'blog_public' => false )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webmcp_forbidden', $result->get_error_code() );
	}
}
