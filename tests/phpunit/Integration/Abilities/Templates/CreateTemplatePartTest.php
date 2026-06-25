<?php
/**
 * Integration tests for og-templates/create-template-part output and contract.
 *
 * Covers a successful part create placed in a valid area (id/status/area/
 * edit_link shape) with a read-back confirming the applied area, a
 * wrong-capability denial that creates no part, and a logged-out denial. Uses
 * only valid areas (header) so no core area-fallback warning fires.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/create-template-part.
 */
final class CreateTemplatePartTest extends TestCase {

	/**
	 * Underlying post ID of a created part, for cleanup.
	 *
	 * @var int
	 */
	private int $created_wp_id = 0;

	public function tearDown(): void {
		if ( $this->created_wp_id > 0 ) {
			wp_delete_post( $this->created_wp_id, true );
			$this->created_wp_id = 0;
		}

		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'og-templates/create-template-part' ) );
	}

	public function test_create_places_part_in_requested_area_and_returns_edit_link(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/create-template-part' )->execute(
			array(
				'slug'    => 'abilities-catalog-test-part',
				'area'    => 'header',
				'title'   => 'Test Part',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'id', 'status', 'title', 'area', 'edit_link' ),
			array_keys( $result )
		);

		$this->assertStringContainsString( '//', $result['id'] );
		$this->assertNotSame( '', $result['status'] );
		// "header" is a default allowed area, so core applies it verbatim — no
		// fallback to uncategorized and no core warning.
		$this->assertSame( 'header', $result['area'] );
		$this->assertNotSame( '', $result['edit_link'] );

		// Read the part back to confirm core stored it in the requested area.
		$part = get_block_template( $result['id'], 'wp_template_part' );
		$this->assertInstanceOf( \WP_Block_Template::class, $part );
		$this->assertSame( 'header', $part->area );

		$this->created_wp_id = (int) $part->wp_id;
		$this->assertGreaterThan( 0, $this->created_wp_id );
	}

	public function test_subscriber_is_denied_and_creates_no_part(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-templates/create-template-part' );

		$this->assertFalse(
			$ability->check_permissions(
				array( 'slug' => 'abilities-catalog-denied-part' )
			)
		);

		$result = $ability->execute(
			array(
				'slug' => 'abilities-catalog-denied-part',
				'area' => 'header',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );

		// The denied write created nothing.
		$part = get_block_template( get_stylesheet() . '//abilities-catalog-denied-part', 'wp_template_part' );
		$this->assertNull( $part );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-templates/create-template-part' );

		$this->assertFalse(
			$ability->check_permissions(
				array( 'slug' => 'abilities-catalog-loggedout-part' )
			)
		);

		$result = $ability->execute(
			array(
				'slug' => 'abilities-catalog-loggedout-part',
				'area' => 'header',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
