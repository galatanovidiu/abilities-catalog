<?php
/**
 * Integration tests for the tools/export-content ability.
 *
 * Covers registration, the input schema (the dead `post_type` field is gone),
 * the output-schema contract (`content_type` is required), the `export`
 * capability gate, and a real end-to-end export. The happy path is now testable
 * because the ability swallows core `export_wp()`'s "headers already sent"
 * warning (the header-leak fix); previously that warning broke any execution.
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

	public function test_admin_export_returns_inline_wxr_without_leaking_headers(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Exportable Post',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'tools/export-content' )->execute( array( 'content' => 'post' ) );

		$this->assertIsArray( $result, 'export_wp() header emission must no longer break execution.' );
		$this->assertStringStartsWith( 'text/xml', $result['content_type'] );
		$this->assertStringContainsString( '<?xml', $result['data'] );
		$this->assertStringContainsString( 'Exportable Post', $result['data'] );
		$this->assertSame( strlen( $result['data'] ), $result['length'] );

		// The download headers core's export_wp() sends must not have leaked onto
		// the response (they would force an attachment download of the JSON body).
		// Under CLI/PHPUnit headers_list() is empty, so this only asserts the
		// negative — it can never have a Content-Disposition from this call.
		$this->assertNotContains( 'Content-Disposition: attachment; filename', headers_list() );

		wp_delete_post( $post_id, true );
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
