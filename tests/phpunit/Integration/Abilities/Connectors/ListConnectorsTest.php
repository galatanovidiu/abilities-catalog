<?php
/**
 * Integration tests for the connectors/list-connectors ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Connectors;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Connector_Registry;
use WP_Error;

/**
 * Exercises connectors/list-connectors end-to-end: the flat item field set,
 * the permission guard, and the no-secret-leak invariant.
 */
final class ListConnectorsTest extends TestCase {

	/**
	 * Test connector id registered for the suite.
	 *
	 * @var string
	 */
	private const TEST_ID = 'catalog_test_list_connector';

	public function set_up(): void {
		parent::set_up();

		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry || $registry->is_registered( self::TEST_ID ) ) {
			return;
		}

		$registry->register(
			self::TEST_ID,
			array(
				'name'           => 'Catalog Test List Connector',
				'description'    => 'Fixture connector for ability tests.',
				'type'           => 'spam_filtering',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'connectors/list-connectors' ) );
	}

	public function test_execute_returns_items_with_flat_field_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'connectors/list-connectors' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );

		$found = null;
		foreach ( $result['items'] as $item ) {
			if ( self::TEST_ID === $item['id'] ) {
				$found = $item;
				break;
			}
		}

		$this->assertNotNull( $found, 'Registered fixture connector is listed.' );
		$this->assertSame( 'Catalog Test List Connector', $found['name'] );
		$this->assertSame( 'spam_filtering', $found['type'] );
		$this->assertIsBool( $found['configured'] );
		// A `none`-method connector needs no key, so it is always configured.
		$this->assertTrue( $found['configured'] );
		// Each item is a strict, non-secret field set in a stable key order.
		$this->assertSame(
			array( 'id', 'name', 'type', 'configured' ),
			array_keys( $found )
		);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'connectors/list-connectors' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
