<?php
/**
 * Integration tests for templates/list-block-binding-sources output and contract.
 *
 * Covers registration, the happy path (an editor receives the registered
 * sources including the core source registered on init, with a positive total
 * and well-shaped rows), the flat-row output guarantee, and the
 * logged-out/wrong-capability denials (edit_posts is the catalog guard).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/list-block-binding-sources.
 */
final class ListBlockBindingSourcesTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'templates/list-block-binding-sources' ) );
	}

	public function test_editor_receives_registered_sources(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/list-block-binding-sources' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'sources', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsArray( $result['sources'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertSame( count( $result['sources'] ), $result['total'] );

		$names = array();
		foreach ( $result['sources'] as $row ) {
			$names[] = $row['name'];
		}

		// Core registers this source on init; the live registry reports it.
		$this->assertContains( 'core/pattern-overrides', $names );
	}

	public function test_row_carries_name_label_and_uses_context_array(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/list-block-binding-sources' )->execute();

		$this->assertNotEmpty( $result['sources'] );

		$row = $result['sources'][0];
		$this->assertArrayHasKey( 'name', $row );
		$this->assertArrayHasKey( 'label', $row );
		$this->assertArrayHasKey( 'uses_context', $row );
		$this->assertIsString( $row['name'] );
		$this->assertIsString( $row['label'] );
		$this->assertIsArray( $row['uses_context'] );

		// The output schema locks each row to exactly these flat keys with
		// additionalProperties:false; the private get_value_callback is absent.
		$this->assertSame( array( 'name', 'label', 'uses_context' ), array_keys( $row ) );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'templates/list-block-binding-sources' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		// edit_posts is the catalog guard; a subscriber lacks it.
		$result = wp_get_ability( 'templates/list-block-binding-sources' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
