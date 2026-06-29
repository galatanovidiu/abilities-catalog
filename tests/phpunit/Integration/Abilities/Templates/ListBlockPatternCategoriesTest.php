<?php
/**
 * Integration tests for the og-templates/list-block-pattern-categories ability.
 *
 * Covers registration, the happy path (an `items` array whose rows carry the two
 * guaranteed keys), the output-shape guarantee (each row exposes only the declared
 * closed fields), and the wrong-capability denial. Adapter-backed: the denial now
 * surfaces through execute() as the wrapped route's REAL error, not the generic
 * ability_invalid_permissions collapse.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/list-block-pattern-categories end-to-end.
 */
final class ListBlockPatternCategoriesTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-templates/list-block-pattern-categories' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-templates/list-block-pattern-categories', $ability->get_name() );
	}

	public function test_returns_items_with_guaranteed_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-block-pattern-categories' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );

		foreach ( $result['items'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'label', $row );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['label'] );
		}
	}

	public function test_rows_expose_only_declared_closed_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-block-pattern-categories' )->execute();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// The output schema locks each row to this closed set with
		// additionalProperties:false; the shaped result must not leak extras.
		$allowed = array( 'name', 'label', 'description' );
		foreach ( $result['items'] as $row ) {
			foreach ( array_keys( $row ) as $key ) {
				$this->assertContains( $key, $allowed, "Unexpected row field: {$key}" );
			}
		}
	}

	public function test_subscriber_is_denied(): void {
		// The wrapped route enforces its own permission (edit_posts, or the edit_posts
		// cap of a public post type) and runs at dispatch, so execute() surfaces the
		// route's REAL error — not the generic ability_invalid_permissions collapse.
		// This ability sets no require_permission guard, so the denial lives on the
		// execute() path. A subscriber is logged in and lacks the cap, so the route
		// returns rest_cannot_view with the logged-in authorization code (403).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-templates/list-block-pattern-categories' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_view', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
