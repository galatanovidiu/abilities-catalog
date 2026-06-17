<?php
/**
 * Integration tests for templates/delete-template output and contract.
 *
 * Covers a successful delete of a user-created custom template (deleted:true plus
 * the flattened previous snapshot: canonical id, title, slug, type,
 * original_source), the missing-id 404 (rest_template_not_found preserved, not
 * collapsed to a permission error), the output-shape contract, and a
 * wrong-capability denial.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/delete-template.
 */
final class DeleteTemplateTest extends TestCase {

	/**
	 * Creates a user-created custom template and returns its "theme//slug" id.
	 *
	 * @param string $slug The template slug.
	 * @return string The created template id.
	 */
	private function createCustomTemplate( string $slug ): string {
		$created = wp_get_ability( 'templates/create-template' )->execute(
			array(
				'slug'    => $slug,
				'title'   => 'Doomed ' . $slug,
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $created );

		return (string) $created['id'];
	}

	public function test_delete_custom_template_returns_deleted_and_previous_snapshot(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createCustomTemplate( 'page-doomed' );

		$result = wp_get_ability( 'templates/delete-template' )->execute(
			array( 'id' => $id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		// The canonical id comes from the forced-delete `previous` snapshot, not
		// the echoed input.
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Doomed page-doomed', $result['title'] );
		$this->assertSame( 'page-doomed', $result['slug'] );
		$this->assertSame( 'wp_template', $result['type'] );
		// A user-created custom template (with an author) resolves to "user".
		$this->assertSame( 'user', $result['original_source'] );

		// The record is actually gone.
		$this->assertNull( get_block_template( $id, 'wp_template' ) );
	}

	public function test_output_shape_only_declared_keys(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createCustomTemplate( 'page-shape' );

		$result = wp_get_ability( 'templates/delete-template' )->execute(
			array( 'id' => $id )
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'deleted', 'id', 'title', 'slug', 'type', 'original_source' ),
			array_keys( $result )
		);
	}

	public function test_missing_id_preserves_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/delete-template' )->execute(
			array( 'id' => get_stylesheet() . '//does-not-exist' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_template_not_found', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'templates/delete-template' );

		$this->assertFalse(
			$ability->check_permissions(
				array( 'id' => get_stylesheet() . '//page-nope' )
			)
		);

		$result = $ability->execute(
			array( 'id' => get_stylesheet() . '//page-nope' )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
