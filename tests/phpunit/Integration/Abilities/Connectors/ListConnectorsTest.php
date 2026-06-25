<?php
/**
 * Integration tests for the og-connectors/list-connectors ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Connectors;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Connector_Registry;
use WP_Error;

/**
 * Exercises og-connectors/list-connectors end-to-end: the flat item field set,
 * the distinct authentication/key-source/connected states, the permission
 * guard, and the no-secret-leak invariant.
 */
final class ListConnectorsTest extends TestCase {

	/**
	 * No-auth fixture connector id.
	 *
	 * @var string
	 */
	private const NONE_ID = 'catalog_test_list_connector';

	/**
	 * api_key fixture connector with no key configured.
	 *
	 * @var string
	 */
	private const APIKEY_EMPTY_ID = 'catalog_test_list_apikey_empty';

	/**
	 * api_key fixture connector with a database key configured.
	 *
	 * @var string
	 */
	private const APIKEY_SET_ID = 'catalog_test_list_apikey_set';

	/**
	 * Option name holding the database key for the configured fixture.
	 *
	 * @var string
	 */
	private const APIKEY_SET_OPTION = 'connectors_spam_filtering_catalog_test_list_apikey_set_api_key';

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
					'name'           => 'Catalog Test List Connector',
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
					'name'           => 'Catalog Test List API Key Empty',
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
					'name'           => 'Catalog Test List API Key Set',
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
		$this->assertNotNull( wp_get_ability( 'og-connectors/list-connectors' ) );
	}

	/**
	 * Finds a listed item by connector id.
	 *
	 * @param array<int,array<string,mixed>> $items Listed connector items.
	 * @param string                         $id    Connector id to find.
	 * @return array<string,mixed>|null The item, or null when absent.
	 */
	private function findItem( array $items, string $id ): ?array {
		foreach ( $items as $item ) {
			if ( $id === $item['id'] ) {
				return $item;
			}
		}

		return null;
	}

	public function test_none_method_item_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-connectors/list-connectors' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );

		$found = $this->findItem( $result['items'], self::NONE_ID );
		$this->assertNotNull( $found, 'Registered none-method fixture connector is listed.' );
		$this->assertSame( 'Catalog Test List Connector', $found['name'] );
		$this->assertSame( 'spam_filtering', $found['type'] );
		$this->assertSame( 'none', $found['authentication_method'] );
		$this->assertSame( 'none', $found['key_source'] );
		$this->assertTrue( $found['configured'] );
		$this->assertTrue( $found['connected'] );

		// Each item is a strict, non-secret field set in a stable key order.
		$this->assertSame(
			array( 'id', 'name', 'type', 'configured', 'authentication_method', 'key_source', 'connected' ),
			array_keys( $found )
		);
	}

	public function test_apikey_item_without_key_is_not_configured(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-connectors/list-connectors' )->execute();
		$found  = $this->findItem( $result['items'], self::APIKEY_EMPTY_ID );

		$this->assertNotNull( $found );
		$this->assertSame( 'api_key', $found['authentication_method'] );
		$this->assertSame( 'none', $found['key_source'] );
		$this->assertFalse( $found['configured'] );
		$this->assertFalse( $found['connected'] );
	}

	public function test_apikey_item_with_database_key_is_configured_and_connected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-connectors/list-connectors' )->execute();
		$found  = $this->findItem( $result['items'], self::APIKEY_SET_ID );

		$this->assertNotNull( $found );
		$this->assertSame( 'api_key', $found['authentication_method'] );
		$this->assertSame( 'database', $found['key_source'] );
		$this->assertTrue( $found['configured'] );
		$this->assertTrue( $found['connected'] );
		$this->assertNotContains( 'secret-key-value', $found );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-connectors/list-connectors' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
