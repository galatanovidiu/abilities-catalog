<?php
/**
 * Integration tests for the og-media/list-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-media/list-media: registration, collection output shape, and the
 * orderby enum that mirrors the sibling list abilities.
 */
final class ListMediaTest extends TestCase {

	/**
	 * The full set of keys a summary row may carry.
	 *
	 * @var string[]
	 */
	private const ROW_KEYS = array(
		'id',
		'title',
		'slug',
		'status',
		'mime_type',
		'media_type',
		'date',
		'author',
		'alt_text',
		'caption',
		'source_url',
		'link',
		'post',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-media/list-media' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-media/list-media', $ability->get_name() );
	}

	public function test_admin_lists_media_with_collection_shape(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/list-media' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total_pages'] );

		$ids = wp_list_pluck( $result['items'], 'id' );
		$this->assertContains( $attachment_id, $ids );
	}

	public function test_rows_are_flat_and_closed(): void {
		$this->actingAs( 'administrator' );
		self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$result = wp_get_ability( 'og-media/list-media' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// items must be a plain list, not a keyed map.
		$this->assertSame( array_keys( $result['items'] ), range( 0, count( $result['items'] ) - 1 ) );

		foreach ( $result['items'] as $row ) {
			// Exactly the declared flat set, in order: no _links, no media_details, no meta.
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'media_details', $row );
			$this->assertIsInt( $row['id'] );
			$this->assertIsString( $row['title'] );
			$this->assertIsString( $row['mime_type'] );
			$this->assertIsString( $row['source_url'] );
			$this->assertIsInt( $row['post'] );
		}
	}

	public function test_media_type_array_input_filters_to_images(): void {
		$this->actingAs( 'administrator' );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/list-media' )->execute(
			array( 'media_type' => array( 'image' ) )
		);

		$this->assertIsArray( $result );
		$this->assertContains( $attachment_id, wp_list_pluck( $result['items'], 'id' ) );
	}

	public function test_mime_type_array_input_is_accepted(): void {
		$this->actingAs( 'administrator' );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/list-media' )->execute(
			array( 'mime_type' => array( 'image/jpeg' ) )
		);

		$this->assertIsArray( $result );
		$this->assertContains( $attachment_id, wp_list_pluck( $result['items'], 'id' ) );
	}

	public function test_valid_orderby_is_accepted(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-media/list-media' )->execute( array( 'orderby' => 'title' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
	}

	public function test_unknown_orderby_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		// The orderby enum rejects values outside the core media collection set
		// at the schema boundary, before execute() builds a REST request.
		$result = wp_get_ability( 'og-media/list-media' )->execute( array( 'orderby' => 'menu_order' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
