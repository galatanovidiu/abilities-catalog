<?php
/**
 * Integration tests for the settings/get-option ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\GetOption;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/get-option is the deny-by-default generic option reader. The allow-list
 * is the authoritative guard, repeated in execute() as defense in depth, and
 * manage_options is the hard capability guard.
 */
final class GetOptionTest extends TestCase {

	public function test_admin_reads_an_allowed_option(): void {
		$this->actingAs('administrator');
		update_option('blogname', 'Catalog Read Site');

		$result = wp_get_ability('settings/get-option')->execute(
			array(
				'name' => 'blogname',
			)
		);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('name', $result);
		$this->assertArrayHasKey('value', $result);
		$this->assertSame('blogname', $result['name']);
		$this->assertIsString($result['value']);
		$this->assertSame('Catalog Read Site', $result['value']);
	}

	public function test_execute_refuses_a_non_allowed_option(): void {
		// Call the ability method directly to reach the defense-in-depth guard,
		// bypassing the schema enum that already blocks non-allowed names upstream.
		$ability = new GetOption();

		$result = $ability->execute(
			array(
				'name' => 'active_plugins',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('abilities_catalog_option_not_readable', $result->get_error_code());
		// The rejected name must not be echoed back.
		$this->assertStringNotContainsString('active_plugins', $result->get_error_message());
	}

	public function test_hasPermission_is_false_for_non_admin(): void {
		$this->actingAs('subscriber');

		$ability = new GetOption();

		$this->assertFalse($ability->hasPermission(array( 'name' => 'blogname' )));
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('settings/get-option')->execute(
			array(
				'name' => 'blogname',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_non_scalar_option_value_is_refused(): void {
		$this->actingAs('administrator');

		$filter = static function () {
			return array( 'unexpected' => 'array' );
		};
		add_filter('option_blogname', $filter);

		try {
			$result = ( new GetOption() )->execute(
				array(
					'name' => 'blogname',
				)
			);
		} finally {
			remove_filter('option_blogname', $filter);
		}

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('abilities_catalog_option_not_scalar', $result->get_error_code());
	}
}
