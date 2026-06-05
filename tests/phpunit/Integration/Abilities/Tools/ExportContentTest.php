<?php
/**
 * Integration tests for the tools/export-content ability.
 *
 * Covers registration, the input schema (the dead `post_type` field is gone),
 * the output-schema contract (`content_type` is required), and the `export`
 * capability gate.
 *
 * The happy-path and oversized-export assertions are intentionally omitted:
 * core `export_wp()` calls `header()` directly, which throws "headers already
 * sent" under PHPUnit. That header emission is a known deferred defect for this
 * ability (the REST/web header-leak finding); a real end-to-end execution test
 * is blocked until that is fixed.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Tools;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises tools/export-content registration, schema, and capability gate.
 */
final class ExportContentTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'tools/export-content' ) );
	}

	public function test_input_schema_omits_dead_post_type_field(): void {
		$schema     = wp_get_ability( 'tools/export-content' )->get_input_schema();
		$properties = $schema['properties'];

		// `export_wp()` has no `post_type` argument, so the field must not be advertised.
		$this->assertArrayNotHasKey( 'post_type', $properties );
		$this->assertArrayHasKey( 'content', $properties );
	}

	public function test_output_schema_requires_content_type(): void {
		$schema = wp_get_ability( 'tools/export-content' )->get_output_schema();

		// `content_type` is always returned, so the output contract must require it.
		$this->assertContains( 'content_type', $schema['required'] );
		$this->assertContains( 'data', $schema['required'] );
		$this->assertContains( 'length', $schema['required'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'tools/export-content' )->execute( array( 'content' => 'all' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'tools/export-content' )->execute( array( 'content' => 'all' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
