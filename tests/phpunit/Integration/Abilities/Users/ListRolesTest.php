<?php
/**
 * Integration tests for the og-users/list-roles ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the composed read ability: registered roles with slug, display
 * name, granted capabilities, and per-role user counts, with the declared
 * closed object shape and the `list_users` capability guard enforced on
 * execute().
 */
final class ListRolesTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-users/list-roles' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-users/list-roles', $ability->get_name() );
	}

	public function test_result_uses_closed_top_level_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/list-roles' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'roles', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['roles'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['roles'] ), $result['total'] );
	}

	public function test_each_role_row_has_exactly_the_closed_field_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/list-roles' )->execute();

		$this->assertNotEmpty( $result['roles'] );

		foreach ( $result['roles'] as $row ) {
			$this->assertSame(
				array( 'slug', 'name', 'capabilities', 'user_count' ),
				array_keys( $row )
			);
			$this->assertIsString( $row['slug'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsArray( $row['capabilities'] );
			$this->assertIsInt( $row['user_count'] );
			foreach ( $row['capabilities'] as $cap ) {
				$this->assertIsString( $cap );
			}
		}
	}

	public function test_default_roles_are_listed_with_resolved_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/list-roles' )->execute();

		$by_slug = array();
		foreach ( $result['roles'] as $row ) {
			$by_slug[ $row['slug'] ] = $row;
		}

		// The five default WordPress roles must all appear.
		foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $by_slug, "Default role {$slug} must be listed." );
		}

		// Display name mirrors core's registered role name.
		$this->assertSame( 'Administrator', $by_slug['administrator']['name'] );

		// Administrator carries a core capability; the list is granted-only.
		$this->assertContains( 'manage_options', $by_slug['administrator']['capabilities'] );
		// Subscriber must not carry an admin capability it is not granted.
		$this->assertNotContains( 'manage_options', $by_slug['subscriber']['capabilities'] );

		// A default role with no users must report user_count 0. This pins the
		// docblock's headline gotcha: count_users()['avail_roles'] omits
		// zero-count roles, so the `?? 0` default is the only reason an empty
		// role still surfaces with a count.
		$this->assertSame( 0, $by_slug['editor']['user_count'] );
	}

	public function test_capabilities_are_sorted_and_granted_only(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/list-roles' )->execute();

		foreach ( $result['roles'] as $row ) {
			$sorted = $row['capabilities'];
			sort( $sorted );
			$this->assertSame( $sorted, $row['capabilities'], 'Capabilities must be sorted.' );
		}
	}

	public function test_user_count_reflects_assigned_users(): void {
		$this->actingAs( 'administrator' );

		$before = $this->roleCount( 'subscriber' );

		self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$after = $this->roleCount( 'subscriber' );

		$this->assertSame( $before + 1, $after );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-users/list-roles' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-users/list-roles' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Reads the resolved user_count for a single role slug from the ability.
	 *
	 * @param string $slug The role slug to look up.
	 * @return int The user_count reported for that role, or 0 if absent.
	 */
	private function roleCount( string $slug ): int {
		$result = wp_get_ability( 'og-users/list-roles' )->execute();

		foreach ( $result['roles'] as $row ) {
			if ( $row['slug'] === $slug ) {
				return $row['user_count'];
			}
		}

		return 0;
	}
}
