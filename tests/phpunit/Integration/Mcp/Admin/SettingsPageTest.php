<?php
/**
 * Integration tests for the MCP settings page glue.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Admin;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Admin\SettingsPage;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The page enqueues its app on its own screen and nowhere else — a wrong hook suffix
 * would either fail to load the app or leak the bundle onto every admin page.
 */
final class SettingsPageTest extends TestCase {

	/**
	 * Script handle the page enqueues.
	 *
	 * @var string
	 */
	private const HANDLE = 'abilities-catalog-mcp-settings';

	/**
	 * Clears any prior registration so each test starts clean.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_dequeue_script( self::HANDLE );
		wp_deregister_script( self::HANDLE );
	}

	/**
	 * Drops the script so it cannot leak into another test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_dequeue_script( self::HANDLE );
		wp_deregister_script( self::HANDLE );
		parent::tear_down();
	}

	/**
	 * The app enqueues on the settings page screen.
	 *
	 * @return void
	 */
	public function test_enqueues_on_its_own_screen(): void {
		SettingsPage::enqueue( 'settings_page_' . ABILITIES_CATALOG_MCP_SETTINGS_SLUG );

		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	/**
	 * The app does not enqueue on any other admin screen.
	 *
	 * @return void
	 */
	public function test_does_not_enqueue_elsewhere(): void {
		SettingsPage::enqueue( 'index.php' );

		$this->assertFalse( wp_script_is( self::HANDLE, 'enqueued' ) );
	}
}
