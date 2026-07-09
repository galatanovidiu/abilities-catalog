<?php
/**
 * Integration tests for the og-users/delete-user ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the destructive delete end-to-end: a real user is deleted with its
 * content reassigned to another existing account, and the output carries the
 * deleted flag, ids, and the flattened `previous_*` identity snapshot.
 *
 * The ability now wraps DELETE /wp/v2/users/<id> via the Abilities REST Adapter and
 * delegates permission and reassign validation to the route. So a caller lacking
 * the capability surfaces the route's rest_user_cannot_delete 401, a missing user
 * its rest_user_invalid_id 404, and an invalid reassign target (nonexistent or
 * equal to the deleted id) its rest_user_invalid_reassign 400 — each the route's
 * own error rather than the generic permission collapse or a catalog-specific code.
 */
final class DeleteUserTest extends TestCase {

	/**
	 * The account that receives reassigned content.
	 *
	 * @var int
	 */
	private int $reassign_target;

	public function set_up(): void {
		parent::set_up();

		$this->reassign_target = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
	}

	/**
	 * Creates a distinctive user to delete so the previous_* snapshot can be asserted.
	 *
	 * @return int The created user ID.
	 */
	private function createVictim(): int {
		return self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_login'   => 'doomed_author',
				'display_name' => 'Doomed Author',
				'user_email'   => 'doomed@example.com',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-users/delete-user' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-users/delete-user', $ability->get_name() );
	}

	public function test_admin_deletes_user_with_reassign(): void {
		$this->actingAs( 'administrator' );
		$victim = $this->createVictim();

		$result = wp_get_ability( 'og-users/delete-user' )->execute(
			array(
				'id'       => $victim,
				'reassign' => $this->reassign_target,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $victim, $result['id'] );
		$this->assertSame( $this->reassign_target, $result['reassigned_to'] );
		$this->assertFalse( get_userdata( $victim ), 'The user must no longer exist.' );
	}

	public function test_output_includes_flattened_previous_identity(): void {
		$this->actingAs( 'administrator' );
		$victim = $this->createVictim();

		$result = wp_get_ability( 'og-users/delete-user' )->execute(
			array(
				'id'       => $victim,
				'reassign' => $this->reassign_target,
			)
		);

		$this->assertIsArray( $result );
		$expected = array(
			'deleted',
			'id',
			'reassigned_to',
			'previous_username',
			'previous_name',
			'previous_email',
			'previous_slug',
			'previous_roles',
		);
		sort( $expected );
		$actual = array_keys( $result );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'Output must carry exactly the documented field set.' );

		$this->assertSame( 'doomed_author', $result['previous_username'] );
		$this->assertSame( 'Doomed Author', $result['previous_name'] );
		$this->assertSame( 'doomed@example.com', $result['previous_email'] );
		$this->assertSame( 'doomed_author', $result['previous_slug'] );
		$this->assertIsArray( $result['previous_roles'] );
		$this->assertContains( 'author', $result['previous_roles'] );
	}

	public function test_missing_user_returns_specific_404(): void {
		$this->actingAs( 'administrator' );
		$missing_id = 999999;

		$result = wp_get_ability( 'og-users/delete-user' )->execute(
			array(
				'id'       => $missing_id,
				'reassign' => $this->reassign_target,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_non_privileged_user_is_denied(): void {
		$this->actingAs( 'editor' );
		$victim = $this->createVictim();

		$result = wp_get_ability( 'og-users/delete-user' )->execute(
			array(
				'id'       => $victim,
				'reassign' => $this->reassign_target,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		// The route's own permission check now surfaces, not the generic collapse.
		$this->assertSame( 'rest_user_cannot_delete', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertNotFalse( get_userdata( $victim ), 'A denied delete must not remove the user.' );
	}

	public function test_nonexistent_reassign_target_returns_specific_400_and_user_survives(): void {
		// The wrapped route validates reassign itself: a nonexistent target surfaces
		// its specific rest_user_invalid_reassign 400, not a catalog-specific code or
		// the generic permission collapse. No user is deleted.
		$this->actingAs( 'administrator' );
		$victim = $this->createVictim();

		$result = wp_get_ability( 'og-users/delete-user' )->execute(
			array(
				'id'       => $victim,
				'reassign' => 888888,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_reassign', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );

		$this->assertNotFalse( get_userdata( $victim ), 'A rejected delete must not remove the user.' );
	}

	public function test_reassign_equal_to_id_returns_specific_400_and_user_survives(): void {
		// The route rejects reassign === id with the same rest_user_invalid_reassign 400.
		$this->actingAs( 'administrator' );
		$victim = $this->createVictim();

		$result = wp_get_ability( 'og-users/delete-user' )->execute(
			array(
				'id'       => $victim,
				'reassign' => $victim,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_reassign', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );

		$this->assertNotFalse( get_userdata( $victim ), 'A rejected delete must not remove the user.' );
	}
}
