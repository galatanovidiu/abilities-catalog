<?php
/**
 * Integration tests for the plugins/list-plugins ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the happy-path read, the flat numeric-array output shape, and the
 * capability gate for the plugins/list-plugins ability.
 */
final class ListPluginsTest extends TestCase {

	/**
	 * The full set of keys a summary row may carry.
	 *
	 * @var string[]
	 */
	private const ROW_KEYS = array(
		'plugin',
		'status',
		'name',
		'version',
		'network_only',
	);

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'plugins/list-plugins' ) );
	}

	public function test_returns_items_array_for_admin(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/list-plugins' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
	}

	public function test_items_is_a_flat_numeric_array(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/list-plugins' )->execute( array() );

		$this->assertIsArray( $result['items'] );
		$this->assertSame(
			array_keys( $result['items'] ),
			range( 0, count( $result['items'] ) - 1 ),
			'items must be a flat numeric array, not keyed by plugin file.'
		);
	}

	public function test_rows_are_flat_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/list-plugins' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		foreach ( $result['items'] as $row ) {
			// Exactly the declared flat set, in order: no _links, no rendered description object.
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertIsString( $row['plugin'] );
			$this->assertIsString( $row['status'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsBool( $row['network_only'] );
		}
	}

	public function test_status_array_input_filters_to_active(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/list-plugins' )->execute(
			array( 'status' => array( 'active' ) )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( 'active', $row['status'] );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'plugins/list-plugins' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
