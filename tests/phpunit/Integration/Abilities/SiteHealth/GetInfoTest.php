<?php
/**
 * Integration tests for the og-site-health/get-info ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\SiteHealth;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the Site Health Info read: the capability guard, the redaction of
 * private sections and fields, the empty-section skip, and that `info` is always
 * a JSON object.
 */
final class GetInfoTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-site-health/get-info' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-site-health/get-info', $ability->get_name() );
	}

	public function test_happy_path_returns_info_as_object(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-site-health/get-info' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'info', $result );
		// `info` must be a JSON object, not a list, even when redaction empties it.
		$this->assertIsObject( $result['info'] );

		// Core always ships a non-private `wp-core` section; spot-check the shape.
		$info = (array) $result['info'];
		$this->assertArrayHasKey( 'wp-core', $info );
		$this->assertArrayHasKey( 'label', $info['wp-core'] );
		$this->assertArrayHasKey( 'fields', $info['wp-core'] );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-site-health/get-info' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_redaction_drops_private_sections_and_fields_and_skips_empty(): void {
		$this->actingAs( 'administrator' );

		add_filter(
			'debug_information',
			static function ( $info ) {
				$info['catalog-test-public']     = array(
					'label'  => 'Public Section',
					'fields' => array(
						'keep'   => array(
							'label' => 'Keep me',
							'value' => 'visible',
							'debug' => 'visible-debug',
						),
						'secret' => array(
							'label'   => 'Secret field',
							'value'   => 'hidden',
							'private' => true,
						),
					),
				);
				$info['catalog-test-private']    = array(
					'label'   => 'Private Section',
					'private' => true,
					'fields'  => array(
						'x' => array(
							'label' => 'X',
							'value' => 'y',
						),
					),
				);
				$info['catalog-test-allprivate'] = array(
					'label'  => 'All Private Fields',
					'fields' => array(
						'only' => array(
							'label'   => 'Only field',
							'value'   => 'z',
							'private' => true,
						),
					),
				);

				return $info;
			}
		);

		$result = wp_get_ability( 'og-site-health/get-info' )->execute();
		$info   = (array) $result['info'];

		// Private section dropped entirely.
		$this->assertArrayNotHasKey( 'catalog-test-private', $info );
		// Section whose only field is private is skipped (no empty section).
		$this->assertArrayNotHasKey( 'catalog-test-allprivate', $info );

		// Public section kept, private field within it dropped.
		$this->assertArrayHasKey( 'catalog-test-public', $info );
		$fields = $info['catalog-test-public']['fields'];
		$this->assertArrayHasKey( 'keep', $fields );
		$this->assertArrayNotHasKey( 'secret', $fields );

		// Surviving field carries label, value, and the machine-stable debug copy.
		$this->assertSame( 'visible', $fields['keep']['value'] );
		$this->assertSame( 'visible-debug', $fields['keep']['debug'] );
	}
}
