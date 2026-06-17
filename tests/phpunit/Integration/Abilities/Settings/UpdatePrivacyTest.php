<?php
/**
 * Integration tests for the settings/update-privacy ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\UpdatePrivacy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/update-privacy writes the privacy policy page ID directly via
 * update_option(). manage_privacy_options is the hard capability guard. The
 * single field is required; the stored value is cast with plain (int) to match
 * core parity (no absint sign-flip).
 */
final class UpdatePrivacyTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'settings/update-privacy' ) );
	}

	public function test_admin_writes_and_reads_back_page_id_as_typed_integer(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = wp_get_ability( 'settings/update-privacy' )->execute(
			array( 'page_for_privacy_policy' => $page_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'page_for_privacy_policy' ), array_keys( $result ) );
		$this->assertIsInt( $result['page_for_privacy_policy'] );
		$this->assertSame( $page_id, $result['page_for_privacy_policy'] );
		$this->assertSame( $page_id, (int) get_option( 'wp_page_for_privacy_policy' ) );
	}

	public function test_input_schema_requires_field_and_rejects_negatives(): void {
		$schema = ( new UpdatePrivacy() )->args()['input_schema'];

		$this->assertContains( 'page_for_privacy_policy', $schema['required'] );
		$this->assertSame( 0, $schema['properties']['page_for_privacy_policy']['minimum'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$before = get_option( 'wp_page_for_privacy_policy' );

		$result = wp_get_ability( 'settings/update-privacy' )->execute(
			array( 'page_for_privacy_policy' => 999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( $before, get_option( 'wp_page_for_privacy_policy' ) );
	}

	public function test_permission_guard_checks_manage_privacy_options(): void {
		$ability = new UpdatePrivacy();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
