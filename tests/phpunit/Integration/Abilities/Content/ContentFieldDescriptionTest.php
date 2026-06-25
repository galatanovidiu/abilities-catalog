<?php
/**
 * Integration test for D8: content-field descriptions instruct block markup.
 *
 * Locks the intent that every content-bearing write field steers an agent toward
 * Gutenberg block markup (so bare text no longer falls back to a classic block)
 * and points at og-templates/list-block-types.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Asserts the `content` field description of each write ability mentions block markup.
 */
final class ContentFieldDescriptionTest extends TestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function contentAbilities(): array {
		return array(
			'create-post'     => array( 'og-content/create-post' ),
			'create-page'     => array( 'og-content/create-page' ),
			'create-cpt-item' => array( 'og-content/create-cpt-item' ),
			'update-post'     => array( 'og-content/update-post' ),
			'update-page'     => array( 'og-content/update-page' ),
			'update-cpt-item' => array( 'og-content/update-cpt-item' ),
		);
	}

	/**
	 * @dataProvider contentAbilities
	 */
	public function test_content_field_instructs_block_markup( string $ability_name ): void {
		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability );

		$schema      = $ability->get_input_schema();
		$description = (string) ( $schema['properties']['content']['description'] ?? '' );

		$this->assertStringContainsString( 'wp:', $description, "{$ability_name} content field should show a block-markup example." );
		$this->assertStringContainsString( 'og-templates/list-block-types', $description, "{$ability_name} content field should point at block-type discovery." );
		$this->assertStringNotContainsString( 'HTML allowed; sanitized by WordPress', $description );
	}
}
