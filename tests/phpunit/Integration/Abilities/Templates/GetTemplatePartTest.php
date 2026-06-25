<?php
/**
 * Integration tests for og-templates/get-template-part output and contract.
 *
 * Covers registration, the flat happy-path shape for a real template part
 * (area always surfaced), the missing-id 404 (rest_template_not_found preserved,
 * not collapsed to a permission error), and the logged-out / wrong-role denials.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/get-template-part.
 */
final class GetTemplatePartTest extends TestCase {

	/**
	 * The wp_id (underlying post ID) of a part seeded in setUp, or 0 if none.
	 *
	 * @var int
	 */
	private int $seeded_wp_id = 0;

	/**
	 * The "theme//slug" id of a template part to read in the happy path.
	 *
	 * @var string
	 */
	private string $part_id = '';

	/**
	 * Discovers an existing template part id, seeding one when the theme has none.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$theme = get_stylesheet();
		$slug  = 'abilities-catalog-test-part';

		// Seed a deterministic user-created part so the happy path never depends
		// on the active theme shipping its own parts.
		$this->seeded_wp_id = (int) wp_insert_post(
			array(
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => 'Test Part',
				'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		// The block-template id is "<theme>//<slug>"; areas are stored on the
		// wp_template_part_area / wp_theme taxonomies, so assign them explicitly.
		wp_set_object_terms( $this->seeded_wp_id, $theme, 'wp_theme' );
		wp_set_object_terms( $this->seeded_wp_id, 'header', 'wp_template_part_area' );

		$this->part_id = $theme . '//' . $slug;
	}

	/**
	 * Removes the seeded template part.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( $this->seeded_wp_id > 0 ) {
			wp_delete_post( $this->seeded_wp_id, true );
			$this->seeded_wp_id = 0;
		}

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'og-templates/get-template-part' ) );
	}

	public function test_get_template_part_returns_flat_shape_with_area(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/get-template-part' )->execute(
			array( 'id' => $this->part_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $this->part_id, $result['id'] );
		$this->assertSame( get_stylesheet(), $result['theme'] );
		// area is always surfaced for a part and resolves to a non-empty value.
		$this->assertArrayHasKey( 'area', $result );
		$this->assertIsString( $result['area'] );
		$this->assertSame( 'header', $result['area'] );
	}

	public function test_missing_id_preserves_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/get-template-part' )->execute(
			array( 'id' => get_stylesheet() . '//nope_xyz' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_template_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		// The 404 must not collapse into a generic permission error.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-templates/get-template-part' )->execute(
			array( 'id' => $this->part_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-templates/get-template-part' )->execute(
			array( 'id' => $this->part_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
