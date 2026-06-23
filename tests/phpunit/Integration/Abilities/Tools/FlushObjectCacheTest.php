<?php
/**
 * Integration tests for the tools/flush-object-cache ability.
 *
 * Covers registration, the output-shape contract (flushed/persistent), a happy-path
 * flush with a wp_cache_get read-back proving the cached entry is gone, the
 * persistent-flag type, and the manage_options capability gate for logged-out and
 * subscriber callers. This is a no-input ability, so every execute() call passes NO
 * argument.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Tools;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises tools/flush-object-cache registration, flush semantics, and the gate.
 */
final class FlushObjectCacheTest extends TestCase {

	private const TEST_GROUP = 'abilities_catalog_test_grp';

	protected function tearDown(): void {
		wp_cache_delete( 'k', self::TEST_GROUP );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'tools/flush-object-cache' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'tools/flush-object-cache', $ability->get_name() );
	}

	public function test_output_schema_requires_flushed_and_persistent(): void {
		$schema = wp_get_ability( 'tools/flush-object-cache' )->get_output_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'flushed', 'persistent' ), $schema['required'] );
	}

	public function test_flushes_cache_and_reads_back_gone(): void {
		$this->actingAs( 'administrator' );

		wp_cache_set( 'k', 'v', self::TEST_GROUP );
		$this->assertSame( 'v', wp_cache_get( 'k', self::TEST_GROUP ) );

		$result = wp_get_ability( 'tools/flush-object-cache' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'flushed', 'persistent' ), array_keys( $result ) );
		$this->assertTrue( $result['flushed'] );
		$this->assertIsBool( $result['flushed'] );
		$this->assertIsBool( $result['persistent'] );

		// Side-effect read-back: the cached entry is gone after the flush. With the
		// default non-persistent cache, wp_cache_flush() clears the runtime array.
		$this->assertFalse( wp_cache_get( 'k', self::TEST_GROUP ) );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'tools/flush-object-cache' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'tools/flush-object-cache' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
