<?php
/**
 * Integration tests for og-templates/list-block-types output and contract.
 *
 * Covers registration, the happy path (returns an `items` array whose rows carry
 * the four guaranteed keys and includes `core/paragraph`), the output-shape
 * guarantee (each row exposes only the declared flat keys), and the
 * wrong-capability denial. Adapter-backed: permission delegates to the wrapped
 * route, so a subscriber denial surfaces through execute() as the route's REAL
 * REST error, not the generic ability_invalid_permissions collapse.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/list-block-types.
 */
final class ListBlockTypesTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-templates/list-block-types' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-templates/list-block-types', $ability->get_name() );
	}

	public function test_returns_items_with_guaranteed_keys_including_core_paragraph(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-block-types' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );

		$names = array();
		foreach ( $result['items'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'title', $row );
			$this->assertArrayHasKey( 'category', $row );
			$this->assertArrayHasKey( 'is_dynamic', $row );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['title'] );
			$this->assertIsString( $row['category'] );
			$this->assertIsBool( $row['is_dynamic'] );
			$names[] = $row['name'];
		}

		$this->assertContains( 'core/paragraph', $names );
	}

	public function test_rows_expose_only_declared_flat_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-block-types' )->execute();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// The output schema locks each row to exactly these flat keys with
		// additionalProperties:false; the shaped result must not leak extras.
		$expected = array( 'name', 'title', 'category', 'is_dynamic' );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( $expected, array_keys( $row ) );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		// The wrapped route requires edit_posts and runs its own permission_callback
		// at dispatch, so execute() surfaces the route's REAL error — not the generic
		// collapse. This ability sets no require_permission guard, so the denial lives
		// on the execute() path.
		$result = wp_get_ability( 'og-templates/list-block-types' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_block_type_cannot_view', $result->get_error_code() );
		// 403 because the subscriber is logged in but lacks edit_posts.
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
