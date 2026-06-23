<?php
/**
 * Integration tests for templates/update-template-part output and contract.
 *
 * Covers a successful part update (title + area change reported as the resulting
 * area and confirmed via read-back), a missing-id 404 that stays a specific core
 * error (not a permission collapse), a subscriber denial that leaves the part
 * unchanged, and a logged-out denial.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/update-template-part.
 */
final class UpdateTemplatePartTest extends TestCase {

	/**
	 * Underlying post IDs of parts seeded by a test, deleted in tearDown.
	 *
	 * @var array<int,int>
	 */
	private array $seeded_wp_ids = array();

	/**
	 * Removes any part seeded during a test.
	 */
	public function tear_down(): void {
		foreach ( $this->seeded_wp_ids as $wp_id ) {
			wp_delete_post( $wp_id, true );
		}

		$this->seeded_wp_ids = array();

		parent::tear_down();
	}

	/**
	 * Seeds a user-created template part and returns its "theme//slug" id.
	 *
	 * @param string $slug The part slug.
	 * @param string $area The initial area (use a valid area to avoid a fallback warning).
	 * @return string The seeded part id in "theme//slug" form.
	 */
	private function seedPart( string $slug, string $area = 'header' ): string {
		$wp_id = wp_insert_post(
			array(
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => 'Original Part',
				'post_content' => '<!-- wp:paragraph --><p>before</p><!-- /wp:paragraph -->',
				'tax_input'    => array(
					'wp_theme'              => array( get_stylesheet() ),
					'wp_template_part_area' => array( $area ),
				),
			),
			true
		);

		$this->assertIsInt( $wp_id );
		$this->assertGreaterThan( 0, $wp_id );

		$this->seeded_wp_ids[] = $wp_id;

		return get_stylesheet() . '//' . $slug;
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'templates/update-template-part' ) );
	}

	public function test_update_changes_title_and_area_and_reports_resulting_area(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedPart( 'header-update-me', 'header' );

		$result = wp_get_ability( 'templates/update-template-part' )->execute(
			array(
				'id'    => $id,
				'title' => 'Updated Footer Region',
				'area'  => 'footer',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'footer', $result['area'] );
		$this->assertSame( 'Updated Footer Region', $result['title'] );

		// Output is the exact flat, closed key set.
		$keys = array_keys( $result );
		sort( $keys );
		$expected = array( 'area', 'edit_link', 'id', 'status', 'title' );
		sort( $expected );
		$this->assertSame( $expected, $keys );

		$this->assertArrayHasKey( 'edit_link', $result );
		$this->assertIsString( $result['edit_link'] );
		$this->assertNotSame( '', $result['edit_link'] );
		$this->assertStringContainsString( 'site-editor.php', $result['edit_link'] );

		// The new area and title reached core (read-back).
		$part = get_block_template( $id, 'wp_template_part' );
		$this->assertInstanceOf( \WP_Block_Template::class, $part );
		$this->assertSame( 'footer', $part->area );
		$this->assertSame( 'Updated Footer Region', $part->title );
	}

	public function test_missing_part_returns_specific_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/update-template-part' )->execute(
			array(
				'id'    => get_stylesheet() . '//does-not-exist',
				'title' => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_template_not_found', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_subscriber_is_denied_and_part_unchanged(): void {
		$this->actingAs( 'administrator' );
		$id = $this->seedPart( 'header-subscriber-guard', 'header' );

		$before = get_block_template( $id, 'wp_template_part' );
		$this->assertInstanceOf( \WP_Block_Template::class, $before );

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'templates/update-template-part' );

		$this->assertFalse(
			$ability->check_permissions( array( 'id' => $id ) )
		);

		$result = $ability->execute(
			array(
				'id'    => $id,
				'title' => 'Hijacked',
				'area'  => 'footer',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );

		// The part survived unchanged.
		$after = get_block_template( $id, 'wp_template_part' );
		$this->assertInstanceOf( \WP_Block_Template::class, $after );
		$this->assertSame( $before->title, $after->title );
		$this->assertSame( $before->area, $after->area );
		$this->assertSame( $before->content, $after->content );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'templates/update-template-part' );

		$this->assertFalse(
			$ability->check_permissions(
				array( 'id' => get_stylesheet() . '//header-nope' )
			)
		);

		$result = $ability->execute(
			array( 'id' => get_stylesheet() . '//header-nope' )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
