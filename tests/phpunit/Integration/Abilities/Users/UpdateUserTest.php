<?php
/**
 * Integration tests for the og-users/update-user ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the update end-to-end: validated input in, the flat shape
 * (id/name/email/roles, never the password) out, with the edit_user guard
 * enforced by the wrapped REST route on execute(). Proves a missing object and a
 * caller who cannot edit each surface the route's specific error rather than the
 * generic permission collapse, and that the kept role-escalation guard still denies
 * a roles change by a caller who can edit but not promote.
 */
final class UpdateUserTest extends TestCase {

	/**
	 * The account that update operations target.
	 *
	 * @var int
	 */
	private int $target;

	public function set_up(): void {
		parent::set_up();

		$this->target = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_login'   => 'target_author',
				'display_name' => 'Target Author',
				'user_email'   => 'target@example.com',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-users/update-user' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-users/update-user', $ability->get_name() );
	}

	public function test_admin_updates_name_and_email_with_flat_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/update-user' )->execute(
			array(
				'id'    => $this->target,
				'name'  => 'Renamed Author',
				'email' => 'renamed@example.com',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'id', 'name', 'email', 'roles' ),
			array_keys( $result ),
			'Output must be the flat shape and must not include the password.'
		);
		$this->assertArrayNotHasKey( 'password', $result );
		$this->assertSame( $this->target, $result['id'] );
		$this->assertSame( 'Renamed Author', $result['name'] );
		$this->assertSame( 'renamed@example.com', $result['email'] );

		$user = get_userdata( $this->target );
		$this->assertSame( 'Renamed Author', $user->display_name );
		$this->assertSame( 'renamed@example.com', $user->user_email );
	}

	public function test_wrong_capability_surfaces_specific_403_and_user_unchanged(): void {
		// An author cannot edit another user. After coarsening, the logged-in floor
		// passes and the wrapped route's own guard denies with its specific 403 — the
		// user is untouched, proving the edit_user guard still holds through the route.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'og-users/update-user' )->execute(
			array(
				'id'   => $this->target,
				'name' => 'Should Not Change',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		$user = get_userdata( $this->target );
		$this->assertSame( 'Target Author', $user->display_name, 'A denied update must not change the user.' );
	}

	public function test_missing_user_returns_specific_404_not_permission_collapse(): void {
		// An admin is logged in, so the request reaches the REST route and the unknown
		// id surfaces the route's specific 404, not a permission collapse.
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/update-user' )->execute(
			array(
				'id'   => 999999,
				'name' => 'Ghost',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_roles_change_without_promote_is_denied(): void {
		// A caller who can edit the user but lacks promote_user must be denied when
		// the input carries roles. This locks the up-front role-escalation guard.
		// Grant edit_users (which maps to edit_user on the object) without
		// promote_users, so the promote branch is the only thing that can fail.
		$actor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user  = new \WP_User( $actor );
		$user->add_cap( 'edit_users' );
		wp_set_current_user( $actor );

		$this->assertTrue( current_user_can( 'edit_user', $this->target ) );
		$this->assertFalse( current_user_can( 'promote_user', $this->target ) );

		$result = wp_get_ability( 'og-users/update-user' )->execute(
			array(
				'id'    => $this->target,
				'roles' => array( 'editor' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$target = get_userdata( $this->target );
		$this->assertContains( 'author', array_values( $target->roles ), 'A denied role change must not promote the user.' );
	}
}
