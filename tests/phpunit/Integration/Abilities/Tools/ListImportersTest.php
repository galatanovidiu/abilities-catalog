<?php
/**
 * Integration tests for the og-tools/list-importers ability.
 *
 * Covers registration, the output-shape contract (each item carries
 * id/name/description/installed/action_url), an empty-list happy path, a
 * non-empty result via a throwaway registered importer, and the `import`
 * capability gate.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Tools;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-tools/list-importers registration, output shape, and capability gate.
 */
final class ListImportersTest extends TestCase {

	/**
	 * The importer registry global as found before each test.
	 *
	 * @var array<string,array<int,mixed>>|null
	 */
	private $original_importers;

	/**
	 * Resets the importer registry between tests so each case controls it.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_importers;
		$this->original_importers = $wp_importers;
		$wp_importers             = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core global; isolate the importer registry per test, restored in tear_down().
	}

	/**
	 * Restores the importer registry captured in set_up().
	 */
	public function tear_down(): void {
		global $wp_importers;
		$wp_importers = $this->original_importers; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- core global; restore the value captured in set_up().

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-tools/list-importers' ) );
	}

	public function test_output_schema_requires_full_record(): void {
		$schema = wp_get_ability( 'og-tools/list-importers' )->get_output_schema();

		$this->assertContains( 'items', $schema['required'] );

		$item = $schema['properties']['items']['items'];
		$this->assertFalse( $item['additionalProperties'] );
		$this->assertSame(
			array( 'id', 'name', 'description', 'installed', 'action_url' ),
			$item['required']
		);
	}

	public function test_empty_registry_returns_empty_items(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-tools/list-importers' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['items'] );
	}

	public function test_registered_importer_is_listed_with_full_shape(): void {
		$this->actingAs( 'administrator' );

		register_importer(
			'demo-importer',
			'Demo Importer',
			'A throwaway importer for tests.',
			'__return_null'
		);

		$result = wp_get_ability( 'og-tools/list-importers' )->execute();

		$this->assertIsArray( $result );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$key = array_search( 'demo-importer', $ids, true );
		$this->assertNotFalse( $key, 'Registered importer should appear in the list.' );

		$item = $result['items'][ $key ];
		$this->assertSame( 'demo-importer', $item['id'] );
		$this->assertSame( 'Demo Importer', $item['name'] );
		$this->assertSame( 'A throwaway importer for tests.', $item['description'] );
		$this->assertTrue( $item['installed'] );
		$this->assertStringContainsString( 'import=demo-importer', $item['action_url'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-tools/list-importers' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-tools/list-importers' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
