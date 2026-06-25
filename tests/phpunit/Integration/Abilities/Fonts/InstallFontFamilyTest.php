<?php
/**
 * Integration tests for the og-fonts/install-font-family ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the create wrap: happy path surfacing the stored CSS family,
 * default slug derivation, and capability denial.
 */
final class InstallFontFamilyTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-fonts/install-font-family' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-fonts/install-font-family', $ability->get_name() );
	}

	public function test_admin_installs_family_and_receives_stored_font_family(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-fonts/install-font-family' )->execute(
			array(
				'name'        => 'Catalog Sans',
				'font_family' => '"Catalog Sans", sans-serif',
				'slug'        => 'catalog-sans',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'catalog-sans', $result['slug'] );
		$this->assertSame( 'Catalog Sans', $result['name'] );
		$this->assertSame( '"Catalog Sans", sans-serif', $result['font_family'] );

		$post = get_post( $result['id'] );
		$this->assertNotNull( $post );
		$this->assertSame( 'wp_font_family', $post->post_type );
	}

	public function test_slug_defaults_from_name_when_omitted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-fonts/install-font-family' )->execute(
			array(
				'name'        => 'Inter Display',
				'font_family' => 'Inter, sans-serif',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'inter-display', $result['slug'] );
		$this->assertSame( 'Inter, sans-serif', $result['font_family'] );
	}

	public function test_non_admin_is_denied(): void {
		$this->actingAs( 'editor' );

		$result = wp_get_ability( 'og-fonts/install-font-family' )->execute(
			array(
				'name'        => 'Locked Family',
				'font_family' => 'Locked, serif',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
