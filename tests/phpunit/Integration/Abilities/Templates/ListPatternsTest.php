<?php
/**
 * Integration tests for og-templates/list-patterns output and contract.
 *
 * Covers registration, the happy path (returns an `items` array whose rows carry
 * the two guaranteed keys), the output-shape guarantee (each row exposes only the
 * declared closed fields), and the wrong-capability denial. The ability is now
 * adapter-backed: the wrapped route's own permission check runs at dispatch, so a
 * denial surfaces through execute() as the REAL REST error, not the generic
 * ability_invalid_permissions collapse.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/list-patterns.
 */
final class ListPatternsTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-templates/list-patterns' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-templates/list-patterns', $ability->get_name() );
	}

	public function test_returns_items_with_guaranteed_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-patterns' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );

		foreach ( $result['items'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'title', $row );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['title'] );
		}
	}

	public function test_rows_expose_only_declared_closed_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-patterns' )->execute();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// The output schema locks each row to this closed set with
		// additionalProperties:false; the shaped result must not leak extras.
		$allowed = array(
			'name',
			'title',
			'description',
			'content',
			'viewport_width',
			'inserter',
			'categories',
			'keywords',
			'block_types',
			'post_types',
			'template_types',
			'source',
		);
		foreach ( $result['items'] as $row ) {
			foreach ( array_keys( $row ) as $key ) {
				$this->assertContains( $key, $allowed, "Unexpected row field: {$key}" );
			}
		}
	}

	public function test_subscriber_is_denied(): void {
		// Adapter-backed: permission delegates to the wrapped route. Core's
		// get_items_permissions_check requires edit_posts (or a show_in_rest post
		// type's edit_posts cap); a subscriber lacks it. The route check now runs at
		// dispatch, so execute() surfaces the route's REAL error, not the generic
		// collapse. The adapter's permission phase is guard-only and this ability has
		// no require_permission floor.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-templates/list-patterns' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		// 403 because the subscriber is logged in but lacks the capability.
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
