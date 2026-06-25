<?php
/**
 * Integration tests for the og-widgets/list-widget-types ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the widget-types read end-to-end: the registered widget types in,
 * shaped rows plus a body-derived total out, with the capability guard enforced
 * by the Abilities API on execute(). The collection route returns a bare array
 * (no X-WP-Total header), so the test confirms `total === count(items)`.
 */
final class ListWidgetTypesTest extends TestCase {

	/**
	 * Finds a shaped row by widget type id in the result items.
	 *
	 * @param array<int,array<string,mixed>> $items The shaped result rows.
	 * @param string                         $id    The widget type id to find.
	 * @return array<string,mixed>|null The matching row or null.
	 */
	private function rowById( array $items, string $id ): ?array {
		foreach ( $items as $row ) {
			if ( isset( $row['id'] ) && $id === $row['id'] ) {
				return $row;
			}
		}

		return null;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-widgets/list-widget-types' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-widgets/list-widget-types', $ability->get_name() );
	}

	public function test_admin_lists_widget_types_including_block(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/list-widget-types' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertNotEmpty( $result['items'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		$block = $this->rowById( $result['items'], 'block' );
		$this->assertNotNull( $block, 'The core "block" widget type must be listed.' );
		$this->assertSame( 'block', $block['id'] );
	}

	public function test_row_has_exactly_the_closed_field_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/list-widget-types' )->execute();
		$block  = $this->rowById( $result['items'], 'block' );

		$this->assertNotNull( $block );

		$expected = array( 'id', 'name', 'description', 'is_multi' );
		sort( $expected );
		$actual = array_keys( $block );
		sort( $actual );

		$this->assertSame( $expected, $actual, 'A widget type row must carry exactly the closed field set.' );
		$this->assertIsString( $block['id'] );
		$this->assertIsString( $block['name'] );
		$this->assertIsString( $block['description'] );
		$this->assertIsBool( $block['is_multi'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-widgets/list-widget-types' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-widgets/list-widget-types' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
