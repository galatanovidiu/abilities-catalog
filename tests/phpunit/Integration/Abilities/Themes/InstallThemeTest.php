<?php
/**
 * Integration tests for the og-themes/install-theme ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the schema guard, output contract, and capability gate.
 *
 * A real wordpress.org install needs network access and a writable filesystem, so it
 * is not run here; that matches the sibling install/update/delete abilities, which
 * assert their capability gate and schema/contract rather than performing a live
 * install. The focus is the schema tightening (empty or malformed slug must fail at
 * input validation, not collapse into a permission error) and the output shape.
 */
final class InstallThemeTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-themes/install-theme' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-themes/install-theme', $ability->get_name() );
	}

	public function test_empty_slug_is_rejected_by_schema_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-themes/install-theme' )->execute( array( 'slug' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_malformed_slug_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-themes/install-theme' )->execute( array( 'slug' => 'Twenty TwentyFive!' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_input_schema_constrains_slug(): void {
		$schema = wp_get_ability( 'og-themes/install-theme' )->get_input_schema();

		$this->assertSame( 1, $schema['properties']['slug']['minLength'] );
		$this->assertSame( '^[a-z0-9-]+$', $schema['properties']['slug']['pattern'] );
		$this->assertSame( array( 'slug' ), $schema['required'] );
	}

	public function test_output_schema_is_the_install_contract(): void {
		$schema = wp_get_ability( 'og-themes/install-theme' )->get_output_schema();

		$this->assertSame(
			array( 'installed', 'stylesheet', 'name' ),
			$schema['required']
		);
		$this->assertSame( 'boolean', $schema['properties']['installed']['type'] );
		$this->assertSame( 'string', $schema['properties']['stylesheet']['type'] );
		$this->assertSame( 'string', $schema['properties']['name']['type'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-themes/install-theme' )->execute( array( 'slug' => 'twentytwentyfive' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
