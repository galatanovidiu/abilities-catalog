<?php
/**
 * Integration tests for the media/list-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises media/list-media: registration, collection output shape, and the
 * orderby enum that mirrors the sibling list abilities.
 */
final class ListMediaTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'media/list-media' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'media/list-media', $ability->get_name() );
	}

	public function test_admin_lists_media_with_collection_shape(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'media/list-media' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total_pages'] );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $attachment_id, $ids );
	}

	public function test_valid_orderby_is_accepted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'media/list-media' )->execute( array( 'orderby' => 'title' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
	}

	public function test_unknown_orderby_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		// The orderby enum rejects values outside the core media collection set
		// at the schema boundary, before execute() builds a REST request.
		$result = wp_get_ability( 'media/list-media' )->execute( array( 'orderby' => 'menu_order' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
