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
 * distinct authentication/key-source/connected states, the not-found error,
 * the permission guard, and the no-secret-leak invariant.
 */
final class GetConnectorTest extends TestCase {

	/**
	 * No-auth fixture connector id.
	 *
	 * @var string
	 */
	private const NONE_ID = 'catalog_test_connector';

	/**
	 * api_key fixture connector with no key configured.
	 *
	 * @var string
	 */
	private const APIKEY_EMPTY_ID = 'catalog_test_apikey_empty';

	/**
	 * api_key fixture connector with a database key configured.
	 *
	 * @var string
	 */
	private const APIKEY_SET_ID = 'catalog_test_apikey_set';

	/**
	 * Option name holding the database key for the configured fixture.
	 *
	 * @var string
	 */
	private const APIKEY_SET_OPTION = 'connectors_spam_filtering_catalog_test_apikey_set_api_key';

	public function set_up(): void {
		parent::set_up();

		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry ) {
			return;
		}

		if ( ! $registry->is_registered( self::NONE_ID ) ) {
			$registry->register(
				self::NONE_ID,
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

		if ( ! $registry->is_registered( self::APIKEY_EMPTY_ID ) ) {
			$registry->register(
				self::APIKEY_EMPTY_ID,
				array(
					'name'           => 'Catalog Test API Key Empty',
					'description'    => 'api_key fixture with no key set.',
					'type'           => 'spam_filtering',
					'authentication' => array(
						'method' => 'api_key',
					),
				)
			);
		}

		if ( ! $registry->is_registered( self::APIKEY_SET_ID ) ) {
			$registry->register(
				self::APIKEY_SET_ID,
				array(
					'name'           => 'Catalog Test API Key Set',
					'description'    => 'api_key fixture with a database key.',
					'type'           => 'spam_filtering',
					'authentication' => array(
						'method'       => 'api_key',
						'setting_name' => self::APIKEY_SET_OPTION,
					),
				)
			);
		}

		update_option( self::APIKEY_SET_OPTION, 'secret-key-value' );
	}

	public function tear_down(): void {
		delete_option( self::APIKEY_SET_OPTION );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'connectors/get-connector' ) );
	}

	public function test_none_method_connector_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'connectors/get-connector' )->execute(
			array( 'id' => self::NONE_ID )
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::NONE_ID, $result['id'] );
		$this->assertSame( 'Catalog Test Connector', $result['name'] );
		$this->assertSame( 'spam_filtering', $result['type'] );

		// A `none`-method connector needs no key: configured + connected, no source.
		$this->assertSame( 'none', $result['authentication_method'] );
		$this->assertSame( 'none', $result['key_source'] );
		$this->assertTrue( $result['configured'] );
		$this->assertTrue( $result['connected'] );

		// Output is a strict, stable, non-secret field set.
		$this->assertSame(
			array( 'id', 'name', 'type', 'configured', 'authentication_method', 'key_source', 'connected' ),
			array_keys( $result )
		);
	}

	public function test_apikey_connector_without_key_is_not_configured(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'connectors/get-connector' )->execute(
			array( 'id' => self::APIKEY_EMPTY_ID )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'api_key', $result['authentication_method'] );
		$this->assertSame( 'none', $result['key_source'] );
		// No key present: not configured, not connected.
		$this->assertFalse( $result['configured'] );
		$this->assertFalse( $result['connected'] );
	}

	public function test_apikey_connector_with_database_key_is_configured_and_connected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'connectors/get-connector' )->execute(
			array( 'id' => self::APIKEY_SET_ID )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'api_key', $result['authentication_method'] );
		$this->assertSame( 'database', $result['key_source'] );
		$this->assertTrue( $result['configured'] );
		// Non-ai_provider api_key: connected mirrors "a key source exists".
		$this->assertTrue( $result['connected'] );
		// The key value is never echoed back.
		$this->assertArrayNotHasKey( 'api_key', $result );
		$this->assertNotContains( 'secret-key-value', $result );
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
			array( 'id' => self::NONE_ID )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
