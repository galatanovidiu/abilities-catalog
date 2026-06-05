<?php
/**
 * Integration tests for templates/list-templates output and contract.
 *
 * Covers the happy path (returns an `items` array whose rows carry the
 * guaranteed `id` key), the output-shape guarantee (each row exposes only the
 * declared closed fields after projection), the template-part branch (rows
 * expose the additive area field), and the wrong-capability denial (a
 * subscriber lacks edit_theme_options).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/list-templates.
 */
final class ListTemplatesTest extends TestCase {

	/**
	 * Creates a user-created custom template so the collection is non-empty.
	 *
	 * @param string $slug The template slug.
	 * @return void
	 */
	private function createCustomTemplate( string $slug ): void {
		$created = wp_get_ability( 'templates/create-template' )->execute(
			array(
				'slug'    => $slug,
				'title'   => 'About ' . $slug,
				'content' => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertIsArray( $created );
	}

	public function test_returns_items_with_guaranteed_keys(): void {
		$this->actingAs( 'administrator' );

		$this->createCustomTemplate( 'page-list-a' );

		$result = wp_get_ability( 'templates/list-templates' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );

		foreach ( $result['items'] as $row ) {
			$this->assertArrayHasKey( 'id', $row );
			$this->assertIsString( $row['id'] );
		}
	}

	public function test_rows_expose_only_declared_closed_fields(): void {
		$this->actingAs( 'administrator' );

		$this->createCustomTemplate( 'page-list-b' );

		$result = wp_get_ability( 'templates/list-templates' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// The output schema locks each row to this closed set with
		// additionalProperties:false; the projected result must not leak extras.
		$allowed = array(
			'id',
			'slug',
			'theme',
			'type',
			'source',
			'title',
			'status',
			'original_source',
			'area',
		);
		foreach ( $result['items'] as $row ) {
			foreach ( array_keys( $row ) as $key ) {
				$this->assertContains( $key, $allowed, "Unexpected row field: {$key}" );
			}
		}
	}

	public function test_template_parts_rows_expose_area(): void {
		$this->actingAs( 'administrator' );

		// Ensure at least one template part exists for the active theme.
		$created = wp_get_ability( 'templates/create-template' )->execute(
			array(
				'slug'      => 'list-header',
				'post_type' => 'wp_template_part',
				'title'     => 'List Header',
			)
		);
		$this->assertIsArray( $created );

		$result = wp_get_ability( 'templates/list-templates' )->execute(
			array( 'post_type' => 'wp_template_part' )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );

		foreach ( $result['items'] as $row ) {
			$this->assertSame( 'wp_template_part', $row['type'] );
			// area is additive and present for template parts.
			$this->assertArrayHasKey( 'area', $row );
			$this->assertNotSame( '', $row['area'] );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		// edit_theme_options is the catalog guard; a subscriber lacks it.
		$result = wp_get_ability( 'templates/list-templates' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
