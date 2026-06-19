<?php
/**
 * Integration tests for the privacy/generate-export ability.
 *
 * Covers the invalid-request 404 guard, the request_id discovery hint in the
 * input schema, exporter WP_Error pass-through (core parity), the response-shape
 * validation that requires an array `data` before processing, and the happy-path
 * regression guard that the export archive carries real grouped data (the
 * single-finalize fix for B11).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Privacy\GenerateExport;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises privacy/generate-export guards, schema, and exporter parity.
 */
final class GenerateExportTest extends TestCase {

	/**
	 * Creates an export request for the given email and returns its ID.
	 *
	 * @param string $email Subject email address.
	 * @return int The export request ID.
	 */
	private function createExportRequest( string $email ): int {
		$request_id = wp_create_user_request( $email, 'export_personal_data' );
		$this->assertIsInt( $request_id );

		return $request_id;
	}

	public function test_unknown_request_id_returns_404(): void {
		$this->actingAs( 'administrator' );

		$result = ( new GenerateExport() )->execute( array( 'request_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_invalid_export_request', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_request_id_description_points_to_list_export_requests(): void {
		$args        = ( new GenerateExport() )->args();
		$description = $args['input_schema']['properties']['request_id']['description'];

		$this->assertStringContainsString( 'privacy/list-export-requests', $description );
	}

	public function test_exporter_wp_error_is_returned_verbatim(): void {
		$this->actingAs( 'administrator' );
		$request_id = $this->createExportRequest( 'verbatim@example.com' );

		$error = new WP_Error( 'custom_exporter_problem', 'A specific exporter message.', array( 'status' => 400 ) );

		$filter = static function () use ( $error ): array {
			return array(
				'catalog-test' => array(
					'exporter_friendly_name' => 'Catalog Test',
					'callback'               => static function () use ( $error ): WP_Error {
						return $error;
					},
				),
			);
		};

		add_filter( 'wp_privacy_personal_data_exporters', $filter );
		$result = ( new GenerateExport() )->execute( array( 'request_id' => $request_id ) );
		remove_filter( 'wp_privacy_personal_data_exporters', $filter );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'custom_exporter_problem', $result->get_error_code() );
		$this->assertSame( 'A specific exporter message.', $result->get_error_message() );
	}

	public function test_exporter_response_without_array_data_is_rejected(): void {
		$this->actingAs( 'administrator' );
		$request_id = $this->createExportRequest( 'nodata@example.com' );

		$filter = static function (): array {
			return array(
				'catalog-test' => array(
					'exporter_friendly_name' => 'Catalog Test',
					'callback'               => static function (): array {
						// Missing `data` key: core would pass this through unprocessed.
						return array( 'done' => true );
					},
				),
			);
		};

		add_filter( 'wp_privacy_personal_data_exporters', $filter );
		$result = ( new GenerateExport() )->execute( array( 'request_id' => $request_id ) );
		remove_filter( 'wp_privacy_personal_data_exporters', $filter );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_export_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_export_produces_archive_with_real_grouped_data(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is required to assert the generated export archive.' );
		}

		$this->actingAs( 'administrator' );

		$email = 'subject@example.com';
		self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => $email,
			)
		);
		$request_id = $this->createExportRequest( $email );

		$result = ( new GenerateExport() )->execute( array( 'request_id' => $request_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['generated'] );
		$this->assertSame( $request_id, $result['request_id'] );
		$this->assertIsString( $result['status'] );

		// Locate the archive core wrote for this request.
		$archive_name = get_post_meta( $request_id, '_export_file_name', true );
		$this->assertIsString( $archive_name );
		$this->assertNotSame( '', $archive_name );

		$archive_path = wp_privacy_exports_dir() . $archive_name;
		$this->assertFileExists( $archive_path );
		$this->assertGreaterThan( 0, (int) filesize( $archive_path ) );

		// The archive must carry the grouped report, not the null payload the
		// double-finalize bug produced: with the grouped meta deleted, core wrote
		// `{"...":null}` and reported success anyway.
		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $archive_path ) );
		$json = $zip->getFromName( 'export.json' );
		$zip->close();

		$this->assertIsString( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );

		// export.json is `{ "Personal Data Export for <email>": { <groups> } }`.
		$payload = reset( $decoded );
		$this->assertIsArray( $payload, 'export.json payload must be a non-null group map.' );
		$this->assertArrayHasKey( 'about', $payload, 'The core "About" group is always present on a real export.' );

		wp_delete_file( $archive_path );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$allowed = wp_get_ability( 'privacy/generate-export' )
			->check_permissions( array( 'request_id' => 1 ) );

		$this->assertNotTrue( $allowed );
	}
}
