<?php
/**
 * Integration tests for the settings/list-rewrite-rules ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\ListRewriteRules;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use stdClass;
use WP_Error;

/**
 * settings/list-rewrite-rules is a net-new read of the stored rewrite_rules
 * option. It always returns the four fields; manage_options is the hard guard.
 * The rules map is cast to an object so an empty map serializes as {}.
 */
final class ListRewriteRulesTest extends TestCase {

	/**
	 * The full, always-present output field set, in order.
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'permalink_structure',
		'using_permalinks',
		'rules',
		'total',
	);

	/**
	 * The permalink_structure option value before a test mutated it.
	 *
	 * @var string
	 */
	private string $previous_structure = '';

	protected function setUp(): void {
		parent::setUp();
		$this->previous_structure = (string) get_option( 'permalink_structure' );
	}

	protected function tearDown(): void {
		$this->set_permalink_structure( $this->previous_structure );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'settings/list-rewrite-rules' ) );
	}

	public function test_execute_returns_rules_when_pretty_permalinks_enabled(): void {
		$this->actingAs( 'administrator' );

		$this->set_permalink_structure( '/%postname%/' );

		$result = wp_get_ability( 'settings/list-rewrite-rules' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( self::FIELDS, array_keys( $result ) );

		$this->assertSame( '/%postname%/', $result['permalink_structure'] );
		$this->assertTrue( $result['using_permalinks'] );
		$this->assertInstanceOf( stdClass::class, $result['rules'] );
		$this->assertNotEmpty( (array) $result['rules'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThan( 0, $result['total'] );
		$this->assertSame( count( (array) $result['rules'] ), $result['total'] );
	}

	public function test_empty_rules_map_serializes_as_object(): void {
		$this->actingAs( 'administrator' );

		// Plain permalinks store no rewrite rules.
		$this->set_permalink_structure( '' );

		$result = wp_get_ability( 'settings/list-rewrite-rules' )->execute();

		$this->assertSame( '', $result['permalink_structure'] );
		$this->assertFalse( $result['using_permalinks'] );
		$this->assertInstanceOf( stdClass::class, $result['rules'] );
		$this->assertSame( array(), (array) $result['rules'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/list-rewrite-rules' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'settings/list-rewrite-rules' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_manage_options(): void {
		$ability = new ListRewriteRules();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
