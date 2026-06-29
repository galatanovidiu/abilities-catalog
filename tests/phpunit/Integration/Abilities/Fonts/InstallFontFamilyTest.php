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
 * Exercises the adapter-backed create wrap: happy path surfacing the stored CSS
 * family, default slug derivation (now performed by the input_callback), and the
 * route's real capability denial on execute().
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
		// Adapter-backed: the route's create check (create_posts -> edit_theme_options)
		// runs at dispatch, so execute() surfaces the route's REAL error, not the
		// generic collapse. The adapter's permission phase is guard-only and this
		// ability has no require_permission guard, so check_permissions() would just
		// return true; the denial lives on the execute() path.
		$this->actingAs( 'editor' );

		$result = wp_get_ability( 'og-fonts/install-font-family' )->execute(
			array(
				'name'        => 'Locked Family',
				'font_family' => 'Locked, serif',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
		// 403 because the editor is logged in but lacks edit_theme_options.
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
