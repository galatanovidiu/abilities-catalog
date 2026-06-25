<?php
/**
 * Integration tests for the og-settings/get-privacy ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\GetPrivacy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * og-settings/get-privacy is a net-new read of the Privacy Settings page ID.
 * manage_privacy_options is the hard capability guard.
 */
final class GetPrivacyTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-settings/get-privacy' ) );
	}

	public function test_execute_returns_page_id_as_typed_integer(): void {
		$this->actingAs( 'administrator' );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'wp_page_for_privacy_policy', $page_id );

		$result = wp_get_ability( 'og-settings/get-privacy' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'page_for_privacy_policy' ), array_keys( $result ) );
		$this->assertIsInt( $result['page_for_privacy_policy'] );
		$this->assertSame( $page_id, $result['page_for_privacy_policy'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-settings/get-privacy' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_manage_privacy_options(): void {
		$ability = new GetPrivacy();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
