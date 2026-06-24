<?php
/**
 * Unit tests for the BlogSwitchRunner switch-balance discipline.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Support;

use GalatanOvidiu\AbilitiesCatalog\Support\BlogSwitcher;
use GalatanOvidiu\AbilitiesCatalog\Support\BlogSwitchRunner;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use RuntimeException;
use stdClass;
use WP_Error;

/**
 * A counting fake `BlogSwitcher` proves the switch is always balanced — one switch is
 * matched by one restore on a value return, a `WP_Error` return, and an exception — and
 * that no switch happens for a missing or invalid `blog_id`. The site-lookup and
 * current-network seams keep the test pure-unit: no DB, no real network.
 *
 * The fake site is a plain `stdClass` carrying the fields `validateTarget()` reads
 * (`archived` / `deleted` / `spam` / `network_id`), NOT a real `WP_Site`: core only
 * loads `WP_Site` on multisite (`wp-settings.php` guards it behind `is_multisite()`),
 * so referencing it would break this pure unit test under the single-site test env.
 */
final class BlogSwitchRunnerBalanceTest extends TestCase {

	public function test_value_return_switches_once_and_restores_once(): void {
		$switcher = new CountingBlogSwitcher();
		$runner   = $this->runner($switcher);

		$result = $runner->run(
			array( 'blog_id' => 2, 'title' => 'hello' ),
			static function ($input) {
				return $input;
			}
		);

		$this->assertSame(1, $switcher->switches);
		$this->assertSame(1, $switcher->restores);
		$this->assertSame(2, $switcher->last_blog_id);
		$this->assertSame(array( 'title' => 'hello' ), $result); // blog_id stripped.
	}

	public function test_wp_error_return_still_switches_once_and_restores_once(): void {
		$switcher = new CountingBlogSwitcher();
		$runner   = $this->runner($switcher);

		$error  = new WP_Error('boom', 'mid-switch failure');
		$result = $runner->run(
			array( 'blog_id' => 2 ),
			static function () use ($error) {
				return $error;
			}
		);

		$this->assertSame(1, $switcher->switches);
		$this->assertSame(1, $switcher->restores);
		$this->assertSame($error, $result);
	}

	public function test_thrown_exception_restores_via_finally_and_repropagates(): void {
		$switcher = new CountingBlogSwitcher();
		$runner   = $this->runner($switcher);

		try {
			$runner->run(
				array( 'blog_id' => 2 ),
				static function (): void {
					throw new RuntimeException('callback blew up');
				}
			);
			$this->fail('Expected RuntimeException to propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('callback blew up', $e->getMessage());
		}

		$this->assertSame(1, $switcher->switches);
		$this->assertSame(1, $switcher->restores);
	}

	public function test_input_without_blog_id_never_switches(): void {
		$switcher = new CountingBlogSwitcher();
		$runner   = $this->runner($switcher);

		$calls  = 0;
		$result = $runner->run(
			array( 'title' => 'no target' ),
			static function ($input) use (&$calls) {
				++$calls;
				return $input;
			}
		);

		$this->assertSame(0, $switcher->switches);
		$this->assertSame(0, $switcher->restores);
		$this->assertSame(1, $calls);
		$this->assertSame(array( 'title' => 'no target' ), $result);
	}

	public function test_non_array_input_never_switches(): void {
		$switcher = new CountingBlogSwitcher();
		$runner   = $this->runner($switcher);

		$result = $runner->run(
			null,
			static function ($input) {
				return $input;
			}
		);

		$this->assertSame(0, $switcher->switches);
		$this->assertSame(array(), $result);
	}

	/**
	 * @dataProvider invalidTargets
	 *
	 * @param int            $blog_id The candidate site (blog) ID.
	 * @param stdClass|null  $site    The fake site the lookup returns.
	 */
	public function test_invalid_blog_id_returns_wp_error_without_switching(int $blog_id, ?stdClass $site): void {
		$switcher = new CountingBlogSwitcher();
		$runner   = new BlogSwitchRunner(
			$switcher,
			static function () use ($site): ?stdClass {
				return $site;
			},
			static function (): int {
				return 1; // current network.
			}
		);

		$called = false;
		$result = $runner->run(
			array( 'blog_id' => $blog_id ),
			static function () use (&$called) {
				$called = true;
				return 'should not run';
			}
		);

		$this->assertSame(0, $switcher->switches, 'No switch on an invalid target.');
		$this->assertSame(0, $switcher->restores);
		$this->assertFalse($called, 'The callback must not run on an invalid target.');
		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('abilities_catalog_invalid_blog_id', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status']);
	}

	/**
	 * @return array<string,array{0:int,1:stdClass|null}>
	 */
	public static function invalidTargets(): array {
		return array(
			'zero'             => array(0, self::site(2, 1)),
			'negative'        => array(-5, self::site(2, 1)),
			'missing site'    => array(99, null),
			'archived'        => array(2, self::site(2, 1, array( 'archived' => '1' ))),
			'deleted'         => array(2, self::site(2, 1, array( 'deleted' => '1' ))),
			'spam'            => array(2, self::site(2, 1, array( 'spam' => '1' ))),
			'cross-network'   => array(2, self::site(2, 7)),
		);
	}

	/**
	 * Builds a `BlogSwitchRunner` whose seams describe a single valid site (blog 2 on
	 * network 1) so the happy-path switch cases run pure-unit.
	 */
	private function runner(BlogSwitcher $switcher): BlogSwitchRunner {
		return new BlogSwitchRunner(
			$switcher,
			static function (int $blog_id): ?stdClass {
				return 2 === $blog_id ? self::site(2, 1) : null;
			},
			static function (): int {
				return 1;
			}
		);
	}

	/**
	 * Builds a fake site object without touching the database or loading `WP_Site`.
	 *
	 * `validateTarget()` reads the site via the injected lookup seam and only touches
	 * `archived` / `deleted` / `spam` / `network_id`, so a plain `stdClass` carrying
	 * those fields is a faithful stand-in for a real `WP_Site` (whose `network_id`
	 * magic-getter resolves from `site_id`) — and keeps this test pure-unit on
	 * single-site, where core never loads `WP_Site`.
	 *
	 * @param int                  $blog_id    The site (blog) ID.
	 * @param int                  $network_id The owning network ID.
	 * @param array<string,string> $overrides  archived/deleted/spam string flags.
	 * @return stdClass
	 */
	private static function site(int $blog_id, int $network_id, array $overrides = array()): stdClass {
		$raw             = new stdClass();
		$raw->blog_id    = (string) $blog_id;
		$raw->network_id = $network_id;
		$raw->archived   = $overrides['archived'] ?? '0';
		$raw->deleted    = $overrides['deleted'] ?? '0';
		$raw->spam       = $overrides['spam'] ?? '0';

		return $raw;
	}
}

/**
 * A `BlogSwitcher` that counts switches/restores instead of touching core.
 */
final class CountingBlogSwitcher implements BlogSwitcher {

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

	public function switchTo(int $blog_id): void {
		++$this->switches;
		$this->last_blog_id = $blog_id;
	}

	public function restore(): void {
		++$this->restores;
	}
}
