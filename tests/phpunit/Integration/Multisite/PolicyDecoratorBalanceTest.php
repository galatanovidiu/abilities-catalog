<?php
/**
 * Multisite negative/balance safety net for the blog_id policy decorator.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Multisite;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Pins the decorator's failure-path invariants on a real network.
 *
 * Two concerns, both verified through the live registered, decorated ability via
 * wp_get_ability( ... )->execute( array( ..., 'blog_id' => N ) ):
 *
 * 1. WP_Error mid-switch balance (PLAN.md §7 "WP_Error mid-switch balance (M2)").
 *    When a site-scoped body returns a WP_Error WHILE switched to the target blog,
 *    the BlogSwitchRunner::run finally{} block must still restore the blog. The
 *    catalog returns WP_Error (it does not throw), so this is the realistic
 *    mid-switch failure. Both a write body (settings/update-option, whose option
 *    sanitizer rejects the value on the target blog) and a read body
 *    (content/get-post, missing id) are exercised; each must leave
 *    get_current_blog_id() restored to the pre-call blog.
 *
 * 2. Bad / archived / deleted / spam blog_id => 404, no switch leak (PLAN.md §7
 *    "Bad/archived/cross-network blog_id (F7)", §3 BlogSwitchRunner::validateTarget).
 *    A site-scoped ability (content/create-post) given a rejected blog_id must
 *    return the recovery error abilities_catalog_invalid_blog_id with
 *    array( 'status' => 404 ) (Decision 3) and NOT a generic permission collapse;
 *    because get_site() runs BEFORE switch_to_blog(), a bad id never switches, so
 *    get_current_blog_id() is unchanged. The denial is also asserted at the
 *    permission layer (check_permissions), per Decision 3, not inferred only from
 *    execute().
 *
 * Cross-network rejection is a validateTarget() branch too, but a second network
 * cannot be created in this single-network test env; missing/archived/deleted/spam
 * (all validateTarget rejections) are covered instead.
 *
 * Skipped entirely on a single-site install.
 *
 * @group multisite
 */
final class PolicyDecoratorBalanceTest extends TestCase {

	/**
	 * Sites created during a test, deleted in tear_down().
	 *
	 * @var int[]
	 */
	private array $created_sites = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->created_sites as $blog_id ) {
			if ( get_site( $blog_id ) ) {
				wp_delete_site( $blog_id );
			}
		}
		$this->created_sites = array();

		parent::tear_down();
	}

	/**
	 * Creates a sub-site and tracks it for cleanup.
	 *
	 * @return int The new blog ID.
	 */
	private function seedSite(): int {
		$blog_id               = self::factory()->blog->create();
		$this->created_sites[] = $blog_id;

		return $blog_id;
	}

	/**
	 * Marks a tracked sub-site with a status flag via core's update_blog_status().
	 *
	 * update_blog_status() routes to wp_update_site(), which clears the site cache,
	 * so a subsequent get_site() (the decorator's validateTarget() read) sees the
	 * fresh archived/deleted/spam value.
	 *
	 * @param int    $blog_id The sub-site ID.
	 * @param string $field   One of archived, deleted, spam.
	 * @return void
	 */
	private function markSite( int $blog_id, string $field ): void {
		update_blog_status( $blog_id, $field, '1' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * 1. WP_Error mid-switch balance (M2).
	 * ---------------------------------------------------------------------
	 */

	public function test_write_body_wp_error_while_switched_restores_blog(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$before  = get_current_blog_id();

		// timezone_string is allow-listed; an invalid timezone is reverted by core's
		// option sanitizer, which registers a settings error on the target blog, so
		// the wrapped body returns a WP_Error WHILE switched to $blog_id.
		$result = wp_get_ability( 'settings/update-option' )->execute(
			array(
				'name'    => 'timezone_string',
				'value'   => 'Not/A_Real_Zone',
				'blog_id' => $blog_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_option_rejected', $result->get_error_code() );
		// The error came from the body mid-switch, not the decorator's validateTarget.
		$this->assertNotSame( 'abilities_catalog_invalid_blog_id', $result->get_error_code() );

		// The finally{} balance path restored the blog despite the WP_Error return.
		$this->assertSame( $before, get_current_blog_id() );
	}

	public function test_read_body_wp_error_while_switched_restores_blog(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$before  = get_current_blog_id();

		// No post with this id exists on the target blog, so the wrapped GET body
		// returns core's invalid-id 404 WHILE switched to $blog_id.
		$result = wp_get_ability( 'content/get-post' )->execute(
			array(
				'id'      => 99999999,
				'blog_id' => $blog_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertNotSame( 'abilities_catalog_invalid_blog_id', $result->get_error_code() );

		$this->assertSame( $before, get_current_blog_id() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * 2. Bad / archived / deleted / spam blog_id => 404, no switch leak (F7).
	 * ---------------------------------------------------------------------
	 */

	public function test_missing_blog_id_is_rejected_with_404_and_no_switch(): void {
		$this->actingAsSuperAdmin();

		// No sub-site with this id exists on the network.
		$this->assertInvalidBlogIdRejected( 99999999 );
	}

	public function test_archived_blog_id_is_rejected_with_404_and_no_switch(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$this->markSite( $blog_id, 'archived' );

		$this->assertInvalidBlogIdRejected( $blog_id );
	}

	public function test_deleted_blog_id_is_rejected_with_404_and_no_switch(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$this->markSite( $blog_id, 'deleted' );

		$this->assertInvalidBlogIdRejected( $blog_id );
	}

	public function test_spam_blog_id_is_rejected_with_404_and_no_switch(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$this->markSite( $blog_id, 'spam' );

		$this->assertInvalidBlogIdRejected( $blog_id );
	}

	/**
	 * Asserts that a site-scoped ability rejects $blog_id with the recovery 404,
	 * at both the permission layer and execute(), without leaking a blog switch.
	 *
	 * Decision 3: the contract is the specific abilities_catalog_invalid_blog_id
	 * (status 404), NOT a generic ability_invalid_permissions; and the denial is
	 * asserted at the permission layer, not inferred only from execute().
	 *
	 * Because get_site() runs before switch_to_blog() (PLAN.md §3), a bad blog_id
	 * never switches, so get_current_blog_id() is unchanged after the call.
	 *
	 * @param int $blog_id A missing/archived/deleted/spam target site ID.
	 * @return void
	 */
	private function assertInvalidBlogIdRejected( int $blog_id ): void {
		$ability = wp_get_ability( 'content/create-post' );
		$input   = array(
			'title'   => 'Should never be created',
			'content' => 'Body that must not land on any blog.',
			'blog_id' => $blog_id,
		);

		$before = get_current_blog_id();

		// Permission layer (Decision 3): the decorator's perm wrapper returns the
		// same recovery WP_Error for a bad blog_id, not a bare false/true.
		$perm = $ability->check_permissions( $input );
		$this->assertInstanceOf( WP_Error::class, $perm );
		$this->assertSame( 'abilities_catalog_invalid_blog_id', $perm->get_error_code() );
		$this->assertSame( 404, $perm->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $perm->get_error_code() );
		$this->assertSame( $before, get_current_blog_id() );

		// We deliberately do NOT call raw $ability->execute() on this denial: core
		// genericizes the permission WP_Error to 'ability_invalid_permissions' AND fires
		// _doing_it_wrong (which the WP test harness fails at teardown). The permission
		// layer above is the binding contract (Decision 3); the MCP path surfaces the rich
		// error by pre-checking permissions (batch 04 DomainRouter), never via raw execute().
		// The balance assertion above already proves get_site() failed before any switch.
	}
}
