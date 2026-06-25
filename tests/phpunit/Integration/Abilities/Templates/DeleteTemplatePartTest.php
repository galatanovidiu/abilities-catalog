<?php
/**
 * Integration tests for og-templates/delete-template-part output and contract.
 *
 * Covers a successful delete of a user-created custom template part (deleted:true
 * plus the flattened previous snapshot: canonical id, title, slug, area,
 * original_source 'user', and a read-back 404 proving the record is gone), the
 * theme-file-only/unknown-id error preserved (not collapsed to a permission
 * error), a subscriber denial that leaves the part intact, and a logged-out
 * denial.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Exercises og-templates/delete-template-part.
 */
final class DeleteTemplatePartTest extends TestCase {

	/**
	 * Underlying post ids of parts seeded during a test, for tearDown cleanup.
	 *
	 * @var array<int,int>
	 */
	private array $seeded_wp_ids = array();

	/**
	 * Best-effort removal of any seeded part that a test left behind.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->seeded_wp_ids as $wp_id ) {
			if ( $wp_id <= 0 || ! get_post( $wp_id ) ) {
				continue;
			}

			wp_delete_post( $wp_id, true );
		}
		$this->seeded_wp_ids = array();

		parent::tear_down();
	}

	/**
	 * Seeds a user-created (source=custom) template part via the REST route and
	 * returns its "theme//slug" id. The route wires the wp_theme term and sets the
	 * current user as author, so the part resolves to original_source 'user' and is
	 * deletable. Uses the valid "header" area so no core area-fallback warning fires.
	 *
	 * @param string $slug The part slug.
	 * @return string The created part id.
	 */
	private function seedCustomPart( string $slug ): string {
		$request = new WP_REST_Request( 'POST', '/wp/v2/template-parts' );
		$request->set_param( 'slug', $slug );
		$request->set_param( 'area', 'header' );
		$request->set_param( 'title', 'Doomed ' . $slug );
		$request->set_param( 'content', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );

		$response = rest_do_request( $request );
		$this->assertFalse( $response->is_error(), 'Seeding the template part failed.' );

		$data = rest_get_server()->response_to_data( $response, false );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'id', $data );

		$wp_id = (int) ( $data['wp_id'] ?? 0 );
		if ( $wp_id > 0 ) {
			$this->seeded_wp_ids[] = $wp_id;
		}

		return (string) $data['id'];
	}

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'og-templates/delete-template-part' ) );
	}

	public function test_delete_user_part_returns_deleted_and_flattened_previous(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCustomPart( 'abilities-catalog-doomed-part' );

		$result = wp_get_ability( 'og-templates/delete-template-part' )->execute(
			array( 'id' => $id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		// The canonical id comes from the forced-delete `previous` snapshot.
		$this->assertSame( $id, $result['id'] );
		$this->assertStringContainsString( '//', $result['id'] );
		$this->assertSame( 'Doomed abilities-catalog-doomed-part', $result['title'] );
		$this->assertSame( 'abilities-catalog-doomed-part', $result['slug'] );
		$this->assertSame( 'header', $result['area'] );
		// A user-created part (with an author, no theme file) resolves to "user".
		$this->assertSame( 'user', $result['original_source'] );

		// The record is actually gone: a read-back 404s.
		$this->assertNull( get_block_template( $id, 'wp_template_part' ) );

		$get          = new WP_REST_Request( 'GET', '/wp/v2/template-parts/' . $id );
		$get_response = rest_do_request( $get );
		$this->assertTrue( $get_response->is_error() );
		$this->assertSame( 404, $get_response->as_error()->get_error_data()['status'] );
	}

	public function test_output_shape_only_declared_keys(): void {
		$this->actingAs( 'administrator' );

		$id = $this->seedCustomPart( 'abilities-catalog-shape-part' );

		$result = wp_get_ability( 'og-templates/delete-template-part' )->execute(
			array( 'id' => $id )
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'deleted', 'id', 'title', 'slug', 'area', 'original_source' ),
			array_keys( $result )
		);
	}

	public function test_unknown_id_preserves_specific_error_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/delete-template-part' )->execute(
			array( 'id' => get_stylesheet() . '//abilities-catalog-nope-xyz' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_template_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_part_survives(): void {
		$this->actingAs( 'administrator' );
		$id = $this->seedCustomPart( 'abilities-catalog-survives-part' );

		// Switch to a subscriber and attempt the delete.
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-templates/delete-template-part' );
		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );

		$result = $ability->execute( array( 'id' => $id ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The part survives the denied attempt.
		$this->assertNotNull( get_block_template( $id, 'wp_template_part' ) );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-templates/delete-template-part' );
		$this->assertFalse(
			$ability->check_permissions(
				array( 'id' => get_stylesheet() . '//abilities-catalog-loggedout-part' )
			)
		);

		$result = $ability->execute(
			array( 'id' => get_stylesheet() . '//abilities-catalog-loggedout-part' )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
