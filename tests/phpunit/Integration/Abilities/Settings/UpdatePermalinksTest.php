<?php
/**
 * Integration tests for the settings/update-permalinks ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\UpdatePermalinks;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/update-permalinks writes the three Permalink Settings option keys and
 * rebuilds the rewrite rules. manage_options is the hard capability guard; at
 * least one field is required; the output always returns all three fields.
 */
final class UpdatePermalinksTest extends TestCase {

	/**
	 * The full, always-present output field set, in order.
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'permalink_structure',
		'category_base',
		'tag_base',
	);

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'settings/update-permalinks' ) );
	}

	public function test_admin_writes_permalink_options_and_returns_all_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-permalinks' )->execute(
			array(
				'permalink_structure' => '/%postname%/',
				'category_base'       => 'sections',
				'tag_base'            => 'labels',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::FIELDS, array_keys( $result ) );
		$this->assertSame( '/%postname%/', $result['permalink_structure'] );
		$this->assertSame( 'sections', $result['category_base'] );
		$this->assertSame( 'labels', $result['tag_base'] );

		// Persisted to the underlying options.
		$this->assertSame( '/%postname%/', get_option( 'permalink_structure' ) );
		$this->assertSame( 'sections', get_option( 'category_base' ) );
		$this->assertSame( 'labels', get_option( 'tag_base' ) );
	}

	public function test_partial_update_writes_only_provided_field(): void {
		$this->actingAs( 'administrator' );

		update_option( 'category_base', 'existing-cat' );

		$result = wp_get_ability( 'settings/update-permalinks' )->execute(
			array( 'permalink_structure' => '/%postname%/' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( '/%postname%/', $result['permalink_structure'] );
		// Untouched option keeps its prior value, and is still returned.
		$this->assertSame( 'existing-cat', $result['category_base'] );
		$this->assertSame( 'existing-cat', get_option( 'category_base' ) );
	}

	public function test_output_schema_requires_all_three_fields(): void {
		$required = ( new UpdatePermalinks() )->args()['output_schema']['required'];

		$this->assertSame( self::FIELDS, $required );
	}

	public function test_execute_rejects_empty_input(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-permalinks' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_fields', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_permission_guard_checks_manage_options(): void {
		$ability = new UpdatePermalinks();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$before = get_option( 'permalink_structure' );

		$result = wp_get_ability( 'settings/update-permalinks' )->execute(
			array( 'permalink_structure' => '/%postname%/' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( $before, get_option( 'permalink_structure' ) );
	}
}
