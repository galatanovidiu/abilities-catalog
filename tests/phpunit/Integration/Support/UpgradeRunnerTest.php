<?php
/**
 * Integration tests for the shared T3 upgrader-run path.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Support;

use GalatanOvidiu\AbilitiesCatalog\Support\UpgradeRunner;
use GalatanOvidiu\AbilitiesCatalog\Support\UpgraderLock;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use RuntimeException;
use WP_Error;

/**
 * `withLock` is the common guard+lock wrapper every dangerous install/update/delete
 * runs through. These cover its logic deterministically (no network, no real upgrader):
 * the callback runs only behind a held lock and a directly-writable filesystem, its
 * result passes through, and the lock is always released — including when the callback
 * throws or the filesystem guard refuses.
 */
final class UpgradeRunnerTest extends TestCase {

	public function tear_down(): void {
		// Never let a held lock or a forced filesystem method leak into another test.
		UpgraderLock::release();
		remove_all_filters( 'filesystem_method' );
		parent::tear_down();
	}

	public function test_withlock_runs_callback_and_returns_its_result(): void {
		$result = UpgradeRunner::withLock( WP_PLUGIN_DIR, static fn () => 'callback-ran' );

		$this->assertSame( 'callback-ran', $result );
	}

	public function test_withlock_holds_the_lock_while_the_callback_runs(): void {
		// A nested acquire from inside the callback must fail with the 409 conflict,
		// proving the run is serialized behind the lock.
		$nested = UpgradeRunner::withLock( WP_PLUGIN_DIR, static fn () => UpgraderLock::acquire() );

		$this->assertInstanceOf( WP_Error::class, $nested );
		$this->assertSame( 'abilities_catalog_update_locked', $nested->get_error_code() );
	}

	public function test_withlock_releases_the_lock_on_success(): void {
		UpgradeRunner::withLock( WP_PLUGIN_DIR, static fn () => true );

		// The lock is free again, so a fresh acquire succeeds.
		$this->assertTrue( UpgraderLock::acquire() );
	}

	public function test_withlock_releases_the_lock_when_the_callback_throws(): void {
		$threw = false;
		try {
			UpgradeRunner::withLock(
				WP_PLUGIN_DIR,
				static function (): void {
					throw new RuntimeException( 'upgrader blew up' );
				}
			);
		} catch ( RuntimeException $e ) {
			$threw = true;
		}

		$this->assertTrue( $threw, 'The callback exception must propagate out of withLock.' );
		$this->assertTrue( UpgraderLock::acquire(), 'The lock must be released even when the callback throws.' );
	}

	public function test_withlock_returns_the_guard_error_without_running_the_callback(): void {
		// Force a non-direct filesystem so the guard refuses before the lock is taken.
		add_filter( 'filesystem_method', static fn (): string => 'ssh2' );

		$ran    = false;
		$result = UpgradeRunner::withLock(
			WP_PLUGIN_DIR,
			static function () use ( &$ran ) {
				$ran = true;

				return 'should-not-run';
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_fs_not_writable', $result->get_error_code() );
		$this->assertFalse( $ran, 'The callback must not run when the filesystem guard fails.' );

		// The lock was never acquired, so it is still free.
		remove_all_filters( 'filesystem_method' );
		$this->assertTrue( UpgraderLock::acquire() );
	}

	public function test_skin_returns_a_non_echoing_upgrader_skin(): void {
		$skin = UpgradeRunner::skin();

		$this->assertInstanceOf( \Automatic_Upgrader_Skin::class, $skin );
		$this->assertInstanceOf( \WP_Upgrader_Skin::class, $skin );
	}
}
