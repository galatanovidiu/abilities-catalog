<?php
/**
 * Integration tests for og-templates/lookup-template output and contract.
 *
 * Covers the happy-path resolution of a known slug ("index") against the active
 * theme (the hierarchy always ends with the seeded slug; the resolved id stays
 * consistent with the resolved slug), the in-code invalid_slug guard for a slug
 * that sanitizes to empty (status 400, not collapsed to a permission error), and
 * the wrong-capability denial (a subscriber lacks edit_theme_options).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/lookup-template.
 */
final class LookupTemplateTest extends TestCase {

	public function test_lookup_known_slug_returns_hierarchy_and_resolved_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/lookup-template' )->execute(
			array( 'slug' => 'index' )
		);

		// A classic theme has no block-template resolution; the ability returns a
		// 501 there. The hierarchy/resolved contract only applies to block themes.
		if ( $result instanceof WP_Error ) {
			$this->assertSame( 'block_templates_unavailable', $result->get_error_code() );
			return;
		}

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hierarchy', $result );
		$this->assertArrayHasKey( 'resolved', $result );
		$this->assertArrayHasKey( 'resolved_id', $result );
		$this->assertArrayHasKey( 'resolved_title', $result );

		// get_template_hierarchy seeds the hierarchy with the exact slug; "index"
		// is the universal fallback and always ends the list.
		$this->assertNotEmpty( $result['hierarchy'] );
		$this->assertSame( 'index', end( $result['hierarchy'] ) );

		// resolved is the first existing hierarchy slug, or empty if none exists.
		// Whatever it is, resolved_id stays consistent with it.
		if ( '' === $result['resolved'] ) {
			$this->assertSame( '', $result['resolved_id'] );
			$this->assertSame( '', $result['resolved_title'] );
		} else {
			$this->assertContains( $result['resolved'], $result['hierarchy'] );
			$this->assertSame(
				get_stylesheet() . '//' . $result['resolved'],
				$result['resolved_id']
			);
		}
	}

	public function test_slug_that_sanitizes_to_empty_returns_invalid_slug_error(): void {
		$this->actingAs( 'administrator' );

		// "!!!" passes the schema (non-empty string) but sanitize_title() reduces
		// it to "", which the in-code guard rejects with invalid_slug.
		$result = wp_get_ability( 'og-templates/lookup-template' )->execute(
			array( 'slug' => '!!!' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_slug', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		// Not collapsed into a permission failure.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-templates/lookup-template' );

		// edit_theme_options is the catalog guard; a subscriber lacks it.
		$this->assertFalse( $ability->check_permissions( array( 'slug' => 'index' ) ) );

		$result = $ability->execute( array( 'slug' => 'index' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
