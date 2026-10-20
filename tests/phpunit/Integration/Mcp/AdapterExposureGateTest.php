<?php
/**
 * Regression test for the exposure gate on the vendor default-server execute path.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content\GetPost;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Server;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Core\McpAdapter;

/**
 * Pins the already-working exposure gate on the vendor default-server execute path.
 *
 * The bundled mcp-adapter ships a second MCP endpoint (the "default server") whose
 * `mcp-adapter/execute-ability` ability runs any registered ability by name. Its
 * permission callback gates on `meta.mcp.public`, the flag {@see Server} stamps only
 * for an ability that is both domain-mapped AND enabled in the exposure gate. This
 * test locks that refusal so a future change to {@see Server},
 * {@see \GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy}, or the vendor adapter
 * cannot silently let a disabled ability execute through the default server.
 *
 * The check chain under test (vendor source, cited so the asserts are not re-derived):
 * - `ExecuteAbilityAbility::check_permission()` resolves the ability name, then calls
 *   `check_ability_mcp_exposure( $name )` before the per-ability capability check
 *   (`vendor/wordpress/mcp-adapter/includes/Abilities/ExecuteAbilityAbility.php:177`).
 * - `check_ability_mcp_exposure()` reads the live registered ability's
 *   `meta['mcp']['public']` and returns `WP_Error( 'ability_not_public_mcp', ... )` when
 *   it is not `true`, else `true`
 *   (`vendor/wordpress/mcp-adapter/includes/Abilities/McpAbilityHelperTrait.php:38-46`).
 * - The flag is set live, per registration pass, by the `wp_register_ability_args`
 *   filter `Server::publishToDefaultServer()` adds from a FRESH `ExposurePolicy`
 *   ({@see Server} `includes/Mcp/Server.php:141-159`) — so disabling an ability at
 *   runtime immediately closes the vendor path, with no new mechanism.
 *
 * Adapter-dependent: the vendor path lives in the `vendor/` bundle, so this skips when
 * that bundle is absent (CI and a local `composer install` have it). The exposure
 * option is deny-by-default, set in {@see set_up()} and cleared in {@see tear_down()}.
 *
 * NOTE (scope, see PLAN.md §7): this pins only the vendor EXECUTE refusal. It does NOT
 * assert "list/describe still show a disabled ability" on the vendor default server —
 * that is FALSE there (the vendor's own `DiscoverAbilitiesAbility` /
 * `GetAbilityInfoAbility` also gate on exposure). The "still visible" behavior is the
 * catalog's curated path, covered by `AbilityIndexTest` / `DomainRouterTest`.
 */
final class VendorExposureGateTest extends TestCase {

	/**
	 * A curated ability the owner has ENABLED (matches ServerTest's choice).
	 */
	private const ENABLED_ABILITY = 'og-content/get-post';

	/**
	 * A curated ability the owner has left DISABLED (matches ServerTest's choice).
	 */
	private const DISABLED_ABILITY = 'og-content/create-post';

	/**
	 * The hook tables this class mutates, snapshotted in {@see set_up()} and restored
	 * in {@see tear_down()} so the class leaves global filter state byte-for-byte.
	 *
	 * Every test calls `( new Server() )->register()`, which adds a
	 * `wp_register_ability_args` filter that is never self-removed; the re-registration
	 * helper also detaches and re-runs `wp_abilities_api_init`. Without a snapshot those
	 * additions accumulate across this class's methods and leak into the rest of the
	 * suite (a stale publish filter stamping `mcp.public` onto the live ability is what
	 * breaks `RegistryTest::test_single_site_decorator_is_a_no_op_for_every_ability`).
	 *
	 * @var array<string,\WP_Hook|null>
	 */
	private array $hook_snapshot = array();

	/**
	 * Enables only one ability, snapshots the hook tables, and loads the adapter bundle.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Deny-by-default: enable exactly one curated ability so the gate lets that one
		// through to the capability check and refuses the other.
		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, array( self::ENABLED_ABILITY ) );

		// Snapshot the two hook tables every test in this class mutates, so tear_down can
		// drop every filter any test added and restore the pre-class state verbatim.
		$this->snapshotHooks();

		if ( ! class_exists( McpAdapter::class ) ) {
			$autoload = TESTS_REPO_ROOT_DIR . '/vendor/autoload_packages.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
		}

		if ( ! class_exists( McpAdapter::class ) ) {
			$this->markTestSkipped( 'The mcp-adapter vendor bundle is not installed; run composer install.' );
		}
	}

	/**
	 * Restores global state byte-for-byte: hook tables, the live ability's clean meta,
	 * and the exposure option.
	 *
	 * Order matters. The hook tables are restored FIRST so no test-added publish filter
	 * survives, THEN the live `og-content/get-post` is re-registered from its clean
	 * `args()` — through an isolated dispatch with no Server publish filter active — so
	 * its registered meta carries NO `mcp` key again. Without this, a test that stamped
	 * the flag onto the live ability (case 2's re-registration) would leave it stamped,
	 * and `RegistryTest::test_single_site_decorator_is_a_no_op_for_every_ability` (which
	 * asserts each registered ability's meta equals its class `args()` meta) would fail.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->restoreHooks();
		$this->restoreCleanAbility( self::ENABLED_ABILITY );
		delete_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION );
		parent::tear_down();
	}

	/**
	 * Saves the current `WP_Hook` (or its absence) for the tables this class mutates.
	 *
	 * @return void
	 */
	private function snapshotHooks(): void {
		global $wp_filter;

		foreach ( array( 'wp_register_ability_args', 'wp_abilities_api_init' ) as $hook ) {
			$this->hook_snapshot[ $hook ] = isset( $wp_filter[ $hook ] )
				? clone $wp_filter[ $hook ]
				: null;
		}
	}

	/**
	 * Restores each snapshotted hook table, dropping every filter any test added.
	 *
	 * @return void
	 */
	private function restoreHooks(): void {
		global $wp_filter;

		foreach ( $this->hook_snapshot as $hook => $saved ) {
			if ( null === $saved ) {
				unset( $wp_filter[ $hook ] );
				continue;
			}

			$wp_filter[ $hook ] = clone $saved;
		}
	}

	/**
	 * A disabled ability is REFUSED on the vendor execute path (the deliverable).
	 *
	 * With only `og-content/get-post` enabled, the publish filter gives the disabled
	 * `og-content/create-post` NO `meta.mcp.public` (the same assertion `ServerTest`
	 * makes), and `ExecuteAbilityAbility::check_permission()` refuses it with the exact
	 * code `ability_not_public_mcp` — not a generic capability collapse.
	 *
	 * @return void
	 */
	public function test_disabled_ability_is_refused_on_vendor_execute_path(): void {
		( new Server() )->register();

		// The gate's source: the registration-time filter never marks the disabled
		// ability public, so the live registered ability carries no mcp.public.
		$args = apply_filters( 'wp_register_ability_args', array( 'meta' => array() ), self::DISABLED_ABILITY );
		$this->assertArrayNotHasKey(
			'mcp',
			$args['meta'],
			'A curated but exposure-disabled ability must not be marked mcp.public for the default server.'
		);

		// The exposure check runs only after the user-access check, so reach it as a
		// logged-in user (an administrator passes the coarse "read" floor cleanly).
		$this->actingAs( 'administrator' );

		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => self::DISABLED_ABILITY,
				'parameters'   => array(),
			)
		);

		$this->assertWPError( $result, 'The vendor execute path must refuse a disabled ability.' );
		$this->assertSame(
			'ability_not_public_mcp',
			$result->get_error_code(),
			'The refusal must come from the exposure gate, not a generic permission failure.'
		);
		$this->assertNotSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'The refusal must not collapse into a generic capability error.'
		);
	}

	/**
	 * An enabled ability PASSES the exposure layer on the vendor path (control).
	 *
	 * With `og-content/get-post` enabled, the publish filter stamps `meta.mcp.public ===
	 * true`, and the real `check_ability_mcp_exposure` — read off the live registered
	 * ability — returns `true` for that name instead of refusing it. This proves the
	 * test would catch a gate that wrongly refuses everything, not only one that wrongly
	 * allows everything.
	 *
	 * @return void
	 */
	public function test_enabled_ability_passes_the_exposure_layer_on_vendor_path(): void {
		( new Server() )->register();

		$args = apply_filters( 'wp_register_ability_args', array( 'meta' => array() ), self::ENABLED_ABILITY );
		$this->assertTrue(
			$args['meta']['mcp']['public'] ?? false,
			'A curated, exposure-enabled ability should be marked mcp.public for the default server.'
		);

		// Re-register the enabled ability the production way so its LIVE registered meta
		// carries the freshly-stamped flag (abilities self-register at bootstrap, before
		// this Server() filter exists). check_ability_mcp_exposure reads that live meta.
		$this->republishThroughRegistration( self::ENABLED_ABILITY );

		$exposure = $this->callExposureCheck( self::ENABLED_ABILITY );
		$this->assertTrue(
			$exposure,
			'The exposure layer must let an enabled ability through to the capability check.'
		);
	}

	/**
	 * The gate recomputes per registration pass (per request) from a fresh ExposurePolicy.
	 *
	 * `Server::publishToDefaultServer()` builds ONE `ExposurePolicy` per `register()`
	 * call and captures it in the publish closure (`includes/Mcp/Server.php:142-143`);
	 * that policy caches its enabled set on the first `allows()` call. So flipping the
	 * option and re-running the SAME already-added filter does NOT recompute — the
	 * captured policy is stale. The real "live recompute" is per registration pass: the
	 * NEXT request runs a FRESH `( new Server() )->register()`, whose new policy reads
	 * the current option.
	 *
	 * This simulates that. First half (enabled → stamped) proves the flag is present
	 * while the ability is on the enabled set. Then it flips the option, restores the
	 * hook table (dropping the first pass's stale publish filter), and registers a fresh
	 * Server — the next request — whose policy reads the now-empty option and refuses to
	 * stamp.
	 *
	 * @return void
	 */
	public function test_gate_recomputes_live_per_registration_pass(): void {
		( new Server() )->register();

		// Enabled now: the first pass's filter stamps the flag.
		$enabled = apply_filters( 'wp_register_ability_args', array( 'meta' => array() ), self::ENABLED_ABILITY );
		$this->assertTrue(
			$enabled['meta']['mcp']['public'] ?? false,
			'The ability is exposed while it is on the enabled set.'
		);

		// Flip the option: remove the ability from the enabled set.
		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, array() );

		// Simulate the next request. Drop the first pass's publish filter (its captured
		// policy is cached on the old, enabled option, so re-running it would NOT
		// recompute — see Server.php:142-143), then run a fresh register() whose new
		// ExposurePolicy reads the now-empty option.
		$this->restoreHooks();
		( new Server() )->register();

		// Fresh policy, empty option: the new pass refuses to stamp the flag.
		$disabled = apply_filters( 'wp_register_ability_args', array( 'meta' => array() ), self::ENABLED_ABILITY );
		$this->assertArrayNotHasKey(
			'mcp',
			$disabled['meta'],
			'A fresh registration pass after the option is emptied must not expose the ability.'
		);
	}

	/**
	 * Calls the vendor exposure check against the live registered ability.
	 *
	 * `check_ability_mcp_exposure()` is `protected` on the trait; `check_permission()`
	 * is its only public caller and runs `validate_user_access()` first, so this drives
	 * the public callback as a logged-in admin and reads back the exposure outcome:
	 * `true` when the ability is public (the callback then proceeds to the capability
	 * check), or the `WP_Error` when it is not.
	 *
	 * @param string $ability_name The ability to check.
	 * @return true|\WP_Error True when the exposure layer lets the ability through.
	 */
	private function callExposureCheck( string $ability_name ) {
		$this->actingAs( 'administrator' );

		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => $ability_name,
				'parameters'   => array(),
			)
		);

		// check_permission returns the exposure WP_Error verbatim; on success it falls
		// through to the per-ability capability check, whose boolean true here means the
		// exposure step was passed.
		if ( is_wp_error( $result ) && 'ability_not_public_mcp' === $result->get_error_code() ) {
			return $result;
		}

		return true;
	}

	/**
	 * Re-registers the enabled ability so its live meta picks up the Server publish flag.
	 *
	 * Abilities self-register once at the suite bootstrap, before any test's
	 * `Server()->register()` adds the publish filter, so the live registered meta never
	 * carries `mcp.public`. To exercise the real `check_ability_mcp_exposure` against a
	 * freshly-stamped live ability, this re-registers the ability while the
	 * `wp_register_ability_args` publish filter the current test's `register()` added is
	 * still active, so that filter stamps the flag onto the new live instance. tear_down
	 * restores the clean ability afterwards ({@see restoreCleanAbility()}).
	 *
	 * @param string $ability_name The ability to re-register (must be a curated content ability).
	 * @return void
	 */
	private function republishThroughRegistration( string $ability_name ): void {
		$this->reregisterInIsolation( $ability_name, ( new GetPost() )->args() );
	}

	/**
	 * Restores one ability's live registration to its clean class `args()`.
	 *
	 * Called from tear_down AFTER {@see restoreHooks()} has dropped every test-added
	 * publish filter, so this re-registration runs with no Server publish filter in
	 * `wp_register_ability_args` — the freshly registered meta therefore carries NO
	 * `mcp` key. This is what undoes case 2's {@see republishThroughRegistration()},
	 * which would otherwise leave `mcp.public` stamped on the live `og-content/get-post`
	 * and break `RegistryTest::test_single_site_decorator_is_a_no_op_for_every_ability`.
	 *
	 * @param string $ability_name The ability to restore (must be a curated content ability).
	 * @return void
	 */
	private function restoreCleanAbility( string $ability_name ): void {
		$this->reregisterInIsolation( $ability_name, ( new GetPost() )->args() );
	}

	/**
	 * Re-runs `wp_register_ability()` for one name inside an isolated dispatch.
	 *
	 * Registration is only allowed inside a `wp_abilities_api_init` dispatch, and the
	 * catalog's own bulk listener would re-register everything. So this detaches all
	 * existing `wp_abilities_api_init` listeners (keeping the `WP_Hook` to restore it
	 * verbatim), unregisters the one target, dispatches a single registration for just
	 * that name, then restores the hook table. Whether the new live meta carries
	 * `mcp.public` depends only on the `wp_register_ability_args` filters active during
	 * the dispatch — the caller controls that.
	 *
	 * @param string               $ability_name The ability to re-register.
	 * @param array<string,mixed>  $args         The ability's registration args.
	 * @return void
	 */
	private function reregisterInIsolation( string $ability_name, array $args ): void {
		global $wp_filter;

		// Detach all existing wp_abilities_api_init listeners for one isolated dispatch,
		// keeping the WP_Hook object so it is restored verbatim afterwards.
		$saved = $wp_filter['wp_abilities_api_init'] ?? null;
		unset( $wp_filter['wp_abilities_api_init'] );

		wp_unregister_ability( $ability_name );

		add_action(
			'wp_abilities_api_init',
			static function () use ( $ability_name, $args ): void {
				wp_register_ability( $ability_name, $args );
			}
		);

		do_action( 'wp_abilities_api_init' );

		if ( null !== $saved ) {
			$wp_filter['wp_abilities_api_init'] = $saved;
		} else {
			unset( $wp_filter['wp_abilities_api_init'] );
		}
	}
}
