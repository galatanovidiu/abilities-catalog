<?php
/**
 * Single-site wrap-path coverage for the multisite PolicyDecorator (C1).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Support;

use GalatanOvidiu\AbilitiesCatalog\Support\BlogSwitcher;
use GalatanOvidiu\AbilitiesCatalog\Support\PolicyDecorator;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use stdClass;
use WP_Abilities_Registry;

/**
 * Exercises the decorator's multisite WRAP path end-to-end on a single-site box.
 *
 * The multisite branch (inject `blog_id`, append the hint, wrap the callbacks in a
 * balanced `switch_to_blog()`) is the load-bearing code. On single-site CI the real
 * `is_multisite()` is false, so the branch would never run — and if the multisite
 * integration suite is not executed, it would ship unverified (PLAN.md §7 "Wrap-path
 * single-site coverage (C1)").
 *
 * This test forces the branch through the decorator's seams instead of a real network:
 *
 * - `is_multisite` seam forced `true` so `decorate()` takes the multisite branch.
 * - `site_lookup` seam returns a fake `stdClass` site for blog 2 (and `null` otherwise),
 *   so `BlogSwitchRunner::validateTarget()` accepts `blog_id=2` without a second site.
 * - `current_network_id` seam returns 1, matching the fake site's `network_id`.
 * - a counting fake `BlogSwitcher` records the switch so balance is asserted without
 *   touching core's `switch_to_blog()`.
 *
 * The ability is registered through the REAL `wp_register_ability_args` filter path
 * (the seam the whole design hinges on), then driven via `wp_get_ability(...)->execute()`,
 * proving the wrapped `execute_callback` strips `blog_id` and the switch is balanced.
 *
 * NO `@group multisite`: it forces the multisite branch via the seam, so it runs on the
 * normal single-site env.
 */
final class PolicyDecoratorWrapPathTest extends TestCase {

	/**
	 * The throwaway ability name. Not excluded (does NOT start with `mcp-adapter/` or
	 * `abilities-catalog/`), so the decorator wraps it.
	 *
	 * @var string
	 */
	private const ABILITY = 'mstest/throwaway';

	/**
	 * The counting fake injected into the decorator, asserted for switch balance.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Support\WrapPathCountingBlogSwitcher
	 */
	private WrapPathCountingBlogSwitcher $switcher;

	/**
	 * The test decorator added on the filter, removed in tear_down().
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Support\PolicyDecorator
	 */
	private PolicyDecorator $decorator;

	public function set_up(): void {
		parent::set_up();

		$this->switcher = new WrapPathCountingBlogSwitcher();

		// A forced-multisite decorator whose seams describe one valid site (blog 2 on
		// network 1), so the wrap path runs and validateTarget() accepts blog_id 2 on a
		// single-site box, with no real network.
		$this->decorator = new PolicyDecorator(
			$this->switcher,
			static function (): bool {
				return true; // Force the multisite branch.
			},
			static function (int $blog_id): ?stdClass {
				return 2 === $blog_id ? self::fakeSite( 2, 1 ) : null;
			},
			static function (): int {
				return 1; // Current network; matches the fake site's network_id.
			}
		);

		// Drive the REAL filter. The global single-site decorator from the bootstrap is
		// ALSO on this filter at priority 20, but on single-site it returns the args
		// unchanged and writes no idempotency flag, so it cannot interfere: it runs first
		// (registered earlier) as a no-op, then this forced-multisite decorator wraps. Two
		// callbacks at the same priority run in insertion order, so this read order is
		// deterministic.
		$this->decorator->register();
	}

	public function tear_down(): void {
		remove_filter( 'wp_register_ability_args', array( $this->decorator, 'decorate' ), 20 );

		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		parent::tear_down();
	}

	public function test_wrap_path_strips_blog_id_and_balances_the_switch(): void {
		$received = null;

		$this->registerThrowaway(
			static function ( $input ) use ( &$received ) {
				$received = $input; // Capture exactly what the body sees.

				return $input;      // Return the callback's own value.
			}
		);

		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability, 'The throwaway ability should register through the real filter path.' );

		$result = $ability->execute(
			array(
				'blog_id' => 2,
				'note'    => 'x',
			)
		);

		// (a) The body received the input with blog_id STRIPPED.
		$this->assertSame( array( 'note' => 'x' ), $received, 'The wrapped body must not see blog_id.' );

		// (b) The return value is the callback's own return.
		$this->assertSame( array( 'note' => 'x' ), $result );

		// (c) The fake recorded a BALANCED switch. WP_Ability::execute() runs the wrapped
		// permission_callback (check_permissions) AND the wrapped execute_callback
		// (do_execute) — both wrapped — so one execute() drives the switch twice, each
		// balanced by its own finally. The contract is balance (switches === restores),
		// not a single switch (PLAN.md §3 F4: "asserts the switch is balanced, not
		// single-switch"). Both ran and targeted blog 2.
		$this->assertGreaterThanOrEqual( 1, $this->switcher->switches, 'The wrap path must switch at least once.' );
		$this->assertSame(
			$this->switcher->switches,
			$this->switcher->restores,
			'Every switchTo() must be matched by a restore() (balanced switch).'
		);
		$this->assertSame( 2, $this->switcher->last_blog_id, 'Switched to the targeted blog.' );
	}

	/**
	 * Registers the throwaway ability with the given execute callback.
	 *
	 * Registration goes through `WP_Abilities_Registry::register()` directly rather than
	 * the `wp_register_ability()` wrapper: the wrapper `_doing_it_wrong()`s unless called
	 * inside `wp_abilities_api_init` (which fired once during the bootstrap and cannot be
	 * re-fired without re-registering every catalog ability). `register()` applies the
	 * same `wp_register_ability_args` filter (the design's seam), so the decorator still
	 * runs, while only this one ability is added.
	 *
	 * Default scope = site (no scope meta), object input schema, permission_callback that
	 * returns true. The decorator injects an optional `blog_id` into the schema, so
	 * `execute(['blog_id'=>2,'note'=>'x'])` validates and the wrapper strips it.
	 *
	 * @param callable $execute The execute callback (returns the input it receives).
	 * @return void
	 */
	private function registerThrowaway( callable $execute ): void {
		$registry = WP_Abilities_Registry::get_instance();
		$this->assertNotNull( $registry, 'The abilities registry must be available at test time.' );

		$registry->register(
			self::ABILITY,
			array(
				'label'               => 'Throwaway',
				'description'         => 'A throwaway site-scoped ability for wrap-path coverage.',
				'category'            => 'og-core-tools', // Reuse a registered category; avoids registering a new one.
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'note' => array(
							'type' => 'string',
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => $execute,
				'permission_callback' => static function (): bool {
					return true;
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Builds a fake site object carrying only the fields `validateTarget()` reads.
	 *
	 * `BlogSwitchRunner::validateTarget()` reads the looked-up site structurally
	 * (`archived` / `deleted` / `spam` / `network_id`), so a plain `stdClass` is a
	 * faithful stand-in for a `WP_Site` and keeps this test off the multisite-only
	 * `WP_Site` class on the single-site env. Mirrors the builder in
	 * BlogSwitchRunnerBalanceTest.
	 *
	 * @param int $blog_id    The site (blog) ID.
	 * @param int $network_id The owning network ID.
	 * @return stdClass
	 */
	private static function fakeSite( int $blog_id, int $network_id ): stdClass {
		$raw             = new stdClass();
		$raw->blog_id    = (string) $blog_id;
		$raw->network_id = $network_id;
		$raw->archived   = '0';
		$raw->deleted    = '0';
		$raw->spam       = '0';

		return $raw;
	}
}

/**
 * A `BlogSwitcher` that counts switches/restores instead of touching core.
 *
 * Mirrors the counting fake in BlogSwitchRunnerBalanceTest; redeclared here so this
 * integration test does not depend on a unit-test class.
 */
final class WrapPathCountingBlogSwitcher implements BlogSwitcher {

	/**
	 * Number of switchTo() calls.
	 *
	 * @var int
	 */
	public int $switches = 0;

	/**
	 * Number of restore() calls.
	 *
	 * @var int
	 */
	public int $restores = 0;

	/**
	 * The last blog ID switched to.
	 *
	 * @var int|null
	 */
	public ?int $last_blog_id = null;

	public function switchTo( int $blog_id ): void {
		++$this->switches;
		$this->last_blog_id = $blog_id;
	}

	public function restore(): void {
		++$this->restores;
	}
}
