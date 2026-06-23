<?php
/**
 * Integration tests for the users/get-role ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the single-object read ability: one registered role by slug with
 * its display name, granted capabilities, and per-role user count, against the
 * declared closed object shape, the dedicated 404 for an unknown slug, and the
 * `list_users` capability guard enforced on execute().
 */
final class GetRoleTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'users/get-role' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'users/get-role', $ability->get_name() );
	}

	public function test_happy_path_returns_role_matching_core(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/get-role' )->execute( array( 'slug' => 'editor' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'editor', $result['slug'] );

		// Display name mirrors core's registered role name.
		$this->assertSame( wp_roles()->role_names['editor'], $result['name'] );
		$this->assertSame( 'Editor', $result['name'] );

		// Capabilities are the granted-only set read back from core.
		$role = wp_roles()->get_role( 'editor' );
		$expected = array();
		foreach ( $role->capabilities as $cap => $granted ) {
			if ( $granted ) {
				$expected[] = (string) $cap;
			}
		}
		sort( $expected );
		$this->assertSame( $expected, $result['capabilities'] );
		$this->assertContains( 'edit_posts', $result['capabilities'] );

		$this->assertIsInt( $result['user_count'] );
	}

	public function test_capabilities_are_sorted_and_granted_only(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/get-role' )->execute( array( 'slug' => 'administrator' ) );

		$sorted = $result['capabilities'];
		sort( $sorted );
		$this->assertSame( $sorted, $result['capabilities'], 'Capabilities must be sorted.' );

		// Administrator carries a core capability; the list is granted-only.
		$this->assertContains( 'manage_options', $result['capabilities'] );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/get-role' )->execute( array( 'slug' => 'subscriber' ) );

		$this->assertSame(
			array( 'slug', 'name', 'capabilities', 'user_count' ),
			array_keys( $result )
		);
		$this->assertIsString( $result['slug'] );
		$this->assertIsString( $result['name'] );
		$this->assertIsArray( $result['capabilities'] );
		$this->assertIsInt( $result['user_count'] );
	}

	public function test_user_count_reflects_assigned_users(): void {
		$this->actingAs( 'administrator' );

		$before = wp_get_ability( 'users/get-role' )->execute( array( 'slug' => 'subscriber' ) )['user_count'];

		self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$after = wp_get_ability( 'users/get-role' )->execute( array( 'slug' => 'subscriber' ) )['user_count'];

		$this->assertSame( $before + 1, $after );
	}

	public function test_zero_user_role_reports_count_zero(): void {
		$this->actingAs( 'administrator' );

		// `editor` has no users in a fresh install. count_users()['avail_roles']
		// omits zero-count roles, so the `?? 0` default is the only reason an
		// empty role still surfaces a count.
		$result = wp_get_ability( 'users/get-role' )->execute( array( 'slug' => 'editor' ) );

		$this->assertSame( 0, $result['user_count'] );
	}

	public function test_missing_role_returns_specific_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/get-role' )->execute( array( 'slug' => 'no-such-role' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_role_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		// The specific not-found error must not collapse into the generic
		// permission denial.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'users/get-role' );

		$this->assertFalse( $ability->check_permissions( array( 'slug' => 'editor' ) ) );

		$result = $ability->execute( array( 'slug' => 'editor' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'users/get-role' );

		$this->assertFalse( $ability->check_permissions( array( 'slug' => 'editor' ) ) );

		$result = $ability->execute( array( 'slug' => 'editor' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
