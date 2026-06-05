<?php
/**
 * Integration tests for the dangerous settings/update-option ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\UpdateOption;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/update-option is the deny-by-default generic option writer. The
 * allow-list is the authoritative guard, repeated in execute() as defense in
 * depth, and manage_options is the hard capability guard.
 */
final class UpdateOptionTest extends TestCase {

	public function test_admin_writes_an_allowed_option(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('settings/update-option')->execute(
			array(
				'name'  => 'blogname',
				'value' => 'Catalog Test Site',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('blogname', $result['name']);
		$this->assertSame('Catalog Test Site', $result['value']);
		$this->assertTrue($result['updated']);
		$this->assertSame('Catalog Test Site', get_option('blogname'));
	}

	public function test_execute_refuses_a_non_allowed_option(): void {
		// Call the ability method directly to reach the defense-in-depth guard,
		// bypassing the schema enum that already blocks non-allowed names upstream.
		$ability = new UpdateOption();

		$result = $ability->execute(
			array(
				'name'  => 'active_plugins',
				'value' => 'anything',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('webmcp_option_not_allowed', $result->get_error_code());
		// The rejected name and value must not be echoed back.
		$this->assertStringNotContainsString('active_plugins', $result->get_error_message());
	}

	public function test_silently_rejected_value_returns_error_not_success(): void {
		$this->actingAs('administrator');

		$before = get_option('timezone_string');

		// An invalid timezone is reverted by sanitize_option, which registers a
		// settings error and writes nothing. The ability must surface that as an
		// error, not report updated => true with the unchanged read-back.
		$result = wp_get_ability('settings/update-option')->execute(
			array(
				'name'  => 'timezone_string',
				'value' => 'Not/A_Real_Zone',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('webmcp_option_rejected', $result->get_error_code());
		$this->assertSame(400, $result->get_error_data()['status']);
		// The stored value is unchanged.
		$this->assertSame($before, get_option('timezone_string'));
		// The rejected value must not be echoed back.
		$this->assertStringNotContainsString('Not/A_Real_Zone', $result->get_error_message());
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('settings/update-option')->execute(
			array(
				'name'  => 'blogname',
				'value' => 'Should Not Apply',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
		$this->assertNotSame('Should Not Apply', get_option('blogname'));
	}
}
