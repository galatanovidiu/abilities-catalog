<?php
/**
 * Multisite policy-decorator opt-out + description invariants.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Multisite;

use GalatanOvidiu\AbilitiesCatalog\Support\PolicyDecorator;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Pins the two invariants that prove the decorator only touches site-scoped abilities
 * and touches their description exactly once (PLAN.md §7 "Opt-out actually opts out (C3)"
 * and "Description pinned (H2)").
 *
 * Opt-out (Decision 9 — site is the silent default): an ability that declares a non-site
 * `scope` (network/user/global) gets NO injected `blog_id` and NO hint even on multisite.
 * Conversely `og-network/add-user-to-site` (scope=network, owns its own required `blog_id`)
 * keeps EXACTLY that one `blog_id` — the decorator does not add a second field, does not
 * clobber it, does not double-switch — and a happy-path add still works on a real network.
 *
 * Description pinned (H2): a site-scoped ability's REGISTERED description ends with the
 * multisite hint exactly once; re-running the decorator on the already-registered args
 * leaves the count at one, proving the `WRAPPED_FLAG` idempotency holds end-to-end (not
 * just in the unit flag). The hint also appears on the discover surface (`get_description()`)
 * and the injected `blog_id` lands on the registered schema, so the discover-path change is
 * intentional and pinned.
 *
 * Skipped entirely on a single-site install (Decision 10).
 *
 * @group multisite
 */
final class PolicyDecoratorOptOutTest extends TestCase {

	/**
	 * The pinned, distinctive fragment of the multisite hint the decorator appends.
	 *
	 * Matches the substring RegistryTest pins, so both guard the same phrase. A stable
	 * substring (not the full sentence) keeps the assertion robust to i18n wrapping
	 * (PLAN.md §3 `appendMultisiteHint`).
	 *
	 * @var string
	 */
	private const HINT = 'pass blog_id to target a specific site';

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
	 * Returns the registered ability's input-schema `properties` map.
	 *
	 * @param string $name The ability name.
	 * @return array<string,mixed> The properties, or an empty array when absent.
	 */
	private function registeredProperties( string $name ): array {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, $name . ' is not registered.' );

		$schema = $ability->get_input_schema();

		return isset( $schema['properties'] ) && is_array( $schema['properties'] )
			? $schema['properties']
			: array();
	}

	/**
	 * Reads a user's roles on a specific site (mirrors the exemplar's read-back).
	 *
	 * @param int $blog_id The site ID.
	 * @param int $user_id The user ID.
	 * @return string[] The user's role slugs on that site.
	 */
	private function rolesOnSite( int $blog_id, int $user_id ): array {
		switch_to_blog( $blog_id );
		$user  = get_userdata( $user_id );
		$roles = $user ? $user->roles : array();
		restore_current_blog();

		return $roles;
	}

	/*
	 * ------------------------------------------------------------------
	 * Opt-out actually opts out (C3, Decision 9)
	 * ------------------------------------------------------------------
	 */

	public function test_network_scoped_ability_gets_no_injected_blog_id(): void {
		// og-network/get-network declares scope=network: it is network-wide and takes a
		// network_id, not a blog_id, so the decorator must inject nothing.
		$props = $this->registeredProperties( 'og-network/get-network' );

		$this->assertArrayNotHasKey(
			'blog_id',
			$props,
			'og-network/get-network (scope=network) must not gain an injected blog_id on multisite.'
		);
		$this->assertStringNotContainsString(
			self::HINT,
			(string) wp_get_ability( 'og-network/get-network' )->get_description(),
			'og-network/get-network (scope=network) must not gain the multisite hint.'
		);
	}

	public function test_user_scoped_ability_gets_no_injected_blog_id(): void {
		// og-users/list-my-sites declares scope=user: a user's site membership is
		// network-global identity, not per-site state, so no blog_id and no hint.
		$props = $this->registeredProperties( 'og-users/list-my-sites' );

		$this->assertArrayNotHasKey(
			'blog_id',
			$props,
			'og-users/list-my-sites (scope=user) must not gain an injected blog_id on multisite.'
		);
		$this->assertStringNotContainsString(
			self::HINT,
			(string) wp_get_ability( 'og-users/list-my-sites' )->get_description(),
			'og-users/list-my-sites (scope=user) must not gain the multisite hint.'
		);
	}

	public function test_global_scoped_ability_gets_no_injected_blog_id(): void {
		// og-updates/list-available-updates declares scope=global: it reads install-wide
		// update data, so the decorator must inject nothing.
		$props = $this->registeredProperties( 'og-updates/list-available-updates' );

		$this->assertArrayNotHasKey(
			'blog_id',
			$props,
			'og-updates/list-available-updates (scope=global) must not gain an injected blog_id on multisite.'
		);
		$this->assertStringNotContainsString(
			self::HINT,
			(string) wp_get_ability( 'og-updates/list-available-updates' )->get_description(),
			'og-updates/list-available-updates (scope=global) must not gain the multisite hint.'
		);
	}

	public function test_add_user_to_site_keeps_exactly_its_own_single_blog_id(): void {
		// og-network/add-user-to-site (scope=network) owns a REQUIRED blog_id param. The
		// decorator must leave that schema untouched: no second blog_id, no clobber.
		$ability = wp_get_ability( 'og-network/add-user-to-site' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$props  = isset( $schema['properties'] ) && is_array( $schema['properties'] )
			? $schema['properties']
			: array();

		// Exactly one blog_id field exists, and it is the ability's own required param
		// (minimum 1), not the decorator's optional injected one.
		$this->assertArrayHasKey( 'blog_id', $props, 'og-network/add-user-to-site must keep its own blog_id.' );
		$this->assertSame( 'integer', $props['blog_id']['type'] ?? null );
		$this->assertSame( 1, $props['blog_id']['minimum'] ?? null );

		// The decorator's injected blog_id is OPTIONAL (never in `required`). The native
		// field is REQUIRED — proof the schema is the ability's own, not a decorated one.
		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$this->assertContains( 'blog_id', $required, 'add-user-to-site\'s blog_id must remain its own required param.' );

		// The injected hint sentence must NOT appear: scope=network is not decorated.
		$this->assertStringNotContainsString(
			self::HINT,
			(string) $ability->get_description(),
			'og-network/add-user-to-site (scope=network) must not gain the multisite hint.'
		);
	}

	public function test_add_user_to_site_happy_path_still_works_under_multisite(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();

		$start_blog = get_current_blog_id();

		$result = wp_get_ability( 'og-network/add-user-to-site' )->execute(
			array(
				'blog_id' => $blog_id,
				'user_id' => $user_id,
				'role'    => 'editor',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['added'] );
		$this->assertSame( $blog_id, $result['blog_id'] );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( 'editor', $result['role'] );

		// The membership landed on the target site (the ability's own blog_id worked).
		$this->assertContains( 'editor', $this->rolesOnSite( $blog_id, $user_id ) );

		// The opt-out ability is NOT wrapped, so it does not switch on its own behalf;
		// either way the current blog is balanced after the call (it must never leak).
		$this->assertSame(
			$start_blog,
			get_current_blog_id(),
			'The current blog must be restored after add-user-to-site executes.'
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Description pinned exactly once (H2)
	 * ------------------------------------------------------------------
	 */

	public function test_site_scoped_description_ends_with_the_hint_exactly_once(): void {
		// og-content/create-post has no scope line -> default site -> decorated.
		$description = (string) wp_get_ability( 'og-content/create-post' )->get_description();

		$this->assertSame(
			1,
			substr_count( $description, self::HINT ),
			'The multisite hint must appear in the registered description exactly once.'
		);

		// And the hint is appended at the END: the decorator concatenates its full
		// multisite sentence and trims, so the description ends with that sentence's
		// final fragment (the pinned HINT substring sits mid-sentence).
		$this->assertStringEndsWith(
			'act on the current site.',
			$description,
			'The multisite hint must be appended at the END of the description.'
		);
	}

	public function test_site_scoped_schema_carries_the_injected_blog_id_on_the_discover_surface(): void {
		// The discover-path schema change is intentional: a site-scoped ability gains the
		// OPTIONAL injected blog_id (minimum 1, never required). Pins it so a regression
		// that drops the injection is caught here, not only in the gates test.
		$ability = wp_get_ability( 'og-content/create-post' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$props  = isset( $schema['properties'] ) && is_array( $schema['properties'] )
			? $schema['properties']
			: array();

		$this->assertArrayHasKey( 'blog_id', $props, 'A site-scoped ability must gain an injected blog_id on multisite.' );
		$this->assertSame( 'integer', $props['blog_id']['type'] ?? null );
		$this->assertSame( 1, $props['blog_id']['minimum'] ?? null );

		// Optional, not required: the decorator never touches `required`.
		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$this->assertNotContains( 'blog_id', $required, 'The injected blog_id must be optional, never required.' );
	}

	public function test_re_decorating_the_registered_args_keeps_the_hint_exactly_once(): void {
		// End-to-end idempotency: the ability is already registered and decorated once on
		// this multisite box. Re-running decorate() on the LIVE registered args (carrying
		// the WRAPPED_FLAG meta) must short-circuit, so the hint stays at one occurrence —
		// proving the WRAPPED_FLAG guards against a second append in practice, not just in
		// a unit test.
		$ability = wp_get_ability( 'og-content/create-post' );
		$this->assertNotNull( $ability );

		// Reconstruct the registered args as the filter would see them on a re-pass.
		$registered_args = array(
			'description'  => (string) $ability->get_description(),
			'input_schema' => $ability->get_input_schema(),
			'meta'         => $ability->get_meta(),
		);

		// The live registered ability already carries the hint exactly once.
		$this->assertSame(
			1,
			substr_count( $registered_args['description'], self::HINT ),
			'Precondition: the registered description already carries the hint exactly once.'
		);

		// Re-run the real decorator on those args. The WRAPPED_FLAG in meta must make it
		// a no-op, so the hint is NOT appended a second time.
		$redecorated = ( new PolicyDecorator() )->decorate( $registered_args, 'og-content/create-post' );

		$this->assertSame(
			1,
			substr_count( (string) ( $redecorated['description'] ?? '' ), self::HINT ),
			'Re-decorating already-decorated args must keep the hint at exactly one occurrence (idempotency).'
		);
		$this->assertSame(
			$registered_args['description'],
			$redecorated['description'] ?? null,
			'Re-decorating must leave the description byte-for-byte unchanged.'
		);
	}
}
