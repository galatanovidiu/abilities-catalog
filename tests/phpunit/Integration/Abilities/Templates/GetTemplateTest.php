<?php
/**
 * Integration tests for templates/get-template output and contract.
 *
 * Covers the flat happy-path output shape for a custom template (id/slug/theme/
 * type/source/content plus the additive original_source), the template-part
 * branch exposing the additive area field, the empty-id rejection (minLength
 * stops an empty id from silently dispatching the collection route), and the
 * missing-id 404 (rest_template_not_found preserved, not collapsed to a
 * permission error).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/get-template.
 */
final class GetTemplateTest extends TestCase {

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
				'title'   => 'About ' . $slug,
				'content' => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $created );

		return (string) $created['id'];
	}

	public function test_get_custom_template_returns_flat_shape(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createCustomTemplate( 'page-about' );

		$result = wp_get_ability( 'templates/get-template' )->execute(
			array( 'id' => $id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'page-about', $result['slug'] );
		$this->assertSame( get_stylesheet(), $result['theme'] );
		$this->assertSame( 'wp_template', $result['type'] );
		// A DB-backed custom template reports source "custom".
		$this->assertSame( 'custom', $result['source'] );
		$this->assertStringContainsString( 'wp:paragraph', $result['content'] );
		// original_source is additive and distinguishes a user-created template.
		$this->assertArrayHasKey( 'original_source', $result );
		$this->assertSame( 'user', $result['original_source'] );
	}

	public function test_get_template_part_exposes_area(): void {
		$this->actingAs( 'administrator' );

		$created = wp_get_ability( 'templates/create-template' )->execute(
			array(
				'slug'      => 'my-header',
				'post_type' => 'wp_template_part',
				'title'     => 'My Header',
			)
		);
		$this->assertIsArray( $created );

		$result = wp_get_ability( 'templates/get-template' )->execute(
			array(
				'id'        => (string) $created['id'],
				'post_type' => 'wp_template_part',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'wp_template_part', $result['type'] );
		// area is additive and only present for template parts.
		$this->assertArrayHasKey( 'area', $result );
		$this->assertNotSame( '', $result['area'] );
	}

	public function test_empty_id_is_rejected_not_listed(): void {
		$this->actingAs( 'administrator' );

		// minLength:1 rejects an empty id before dispatch, so it cannot trim to
		// the collection route and return the whole template list.
		$result = wp_get_ability( 'templates/get-template' )->execute(
			array( 'id' => '' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_id_preserves_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/get-template' )->execute(
			array( 'id' => get_stylesheet() . '//does-not-exist' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_template_not_found', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
