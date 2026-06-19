<?php
/**
 * Integration tests for the settings/update-writing ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\UpdateWriting;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/update-writing writes the Writing Settings screen via
 * POST /wp/v2/settings. manage_options is the hard capability guard. The output
 * always carries all three fields and normalizes the falsy default_post_format
 * sentinel to 'standard'.
 */
final class UpdateWritingTest extends TestCase {

	/**
	 * The full, always-present output field set.
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'default_category',
		'default_post_format',
		'use_smilies',
	);

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'settings/update-writing' ) );
	}

	public function test_admin_writes_writing_settings(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-writing' )->execute(
			array(
				'default_post_format' => 'aside',
				'use_smilies'         => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'aside', $result['default_post_format'] );
		$this->assertFalse( $result['use_smilies'] );

		$this->assertSame( 'aside', get_option( 'default_post_format' ) );
	}

	public function test_output_shape_contains_all_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-writing' )->execute(
			array( 'use_smilies' => true )
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::FIELDS, array_keys( $result ) );
		$this->assertIsInt( $result['default_category'] );
		$this->assertIsString( $result['default_post_format'] );
		$this->assertIsBool( $result['use_smilies'] );
	}

	public function test_falsy_post_format_normalizes_to_standard(): void {
		$this->actingAs( 'administrator' );

		// Default install stores 0 (int) for default_post_format.
		update_option( 'default_post_format', 0 );

		// An update that does not touch the format still reports the normalized value.
		$result = wp_get_ability( 'settings/update-writing' )->execute(
			array( 'use_smilies' => true )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'standard', $result['default_post_format'] );
	}

	public function test_invalid_post_format_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$schema = ( new UpdateWriting() )->args()['input_schema']['properties'];

		$this->assertContains( 'standard', $schema['default_post_format']['enum'] );
		$this->assertNotContains( 'not-a-format', $schema['default_post_format']['enum'] );
	}

	public function test_default_category_declares_minimum_one(): void {
		$schema = ( new UpdateWriting() )->args()['input_schema']['properties'];

		$this->assertSame( 1, $schema['default_category']['minimum'] );
	}

	public function test_no_fields_returns_error(): void {
		$ability = new UpdateWriting();
		$this->actingAs( 'administrator' );

		$result = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_no_fields', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/update-writing' )->execute(
			array( 'use_smilies' => true )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
