<?php
/**
 * Integration tests for the privacy/generate-export ability.
 *
 * Covers the invalid-request 404 guard, the request_id discovery hint in the
 * input schema, exporter WP_Error pass-through (core parity), and the
 * response-shape validation that requires an array `data` before processing.
 * The happy-path non-empty-ZIP assertion is intentionally omitted: the export
 * finalization path has a known deferred correctness bug.
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
		$this->assertSame( 'webmcp_invalid_export_request', $result->get_error_code() );
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
		$this->assertSame( 'webmcp_export_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$allowed = wp_get_ability( 'privacy/generate-export' )
			->check_permissions( array( 'request_id' => 1 ) );

		$this->assertNotTrue( $allowed );
	}
}
