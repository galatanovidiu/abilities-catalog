<?php
/**
 * Integration tests for the connectors/get-connector ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Connectors;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Connector_Registry;
use WP_Error;

/**
 * Exercises connectors/get-connector end-to-end: the flat field set, the
 * not-found error, the permission guard, and the no-secret-leak invariant.
 */
final class GetConnectorTest extends TestCase {

	/**
	 * Test connector id registered for the suite.
	 *
	 * @var string
	 */
	private const TEST_ID = 'catalog_test_connector';

	public function set_up(): void {
		parent::set_up();

		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry || $registry->is_registered( self::TEST_ID ) ) {
			return;
		}

		$registry->register(
			self::TEST_ID,
			array(
				'name'           => 'Catalog Test Connector',
				'description'    => 'Fixture connector for ability tests.',
				'type'           => 'spam_filtering',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'connectors/get-connector' ) );
	}

	public function test_execute_returns_flat_field_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'connectors/get-connector' )->execute(
			array( 'id' => self::TEST_ID )
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::TEST_ID, $result['id'] );
		$this->assertSame( 'Catalog Test Connector', $result['name'] );
		$this->assertSame( 'spam_filtering', $result['type'] );
		$this->assertIsBool( $result['configured'] );
		// A `none`-method connector needs no key, so it is always configured.
		$this->assertTrue( $result['configured'] );
		// Output is a strict, non-secret field set.
		$this->assertSame(
			array( 'id', 'name', 'type', 'configured' ),
			array_keys( $result )
		);
	}

	public function test_unknown_id_returns_not_found(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'connectors/get-connector' )->execute(
			array( 'id' => 'no_such_connector' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'connector_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'connectors/get-connector' )->execute(
			array( 'id' => self::TEST_ID )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
