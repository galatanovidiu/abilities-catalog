<?php
/**
 * Integration tests for the T3 upgrader lock.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Tests\Integration\Support;

use Automattic\AbilitiesCatalog\Support\UpgraderLock;
use Automattic\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * The lock serializes dangerous upgrader runs: a second concurrent acquire must
 * fail fast with a 409 rather than overlap an in-progress operation.
 */
final class UpgraderLockTest extends TestCase {

	public function tear_down(): void {
		// Ensure the lock never leaks into another test.
		UpgraderLock::release();
		parent::tear_down();
	}

	public function test_acquire_succeeds_when_free(): void {
		$this->assertTrue(UpgraderLock::acquire());
	}

	public function test_second_acquire_is_rejected_with_conflict(): void {
		$this->assertTrue(UpgraderLock::acquire());

		$second = UpgraderLock::acquire();

		$this->assertInstanceOf(WP_Error::class, $second);
		$this->assertSame('webmcp_update_locked', $second->get_error_code());
		$this->assertSame(409, $second->get_error_data()['status']);
	}

	public function test_release_allows_reacquire(): void {
		$this->assertTrue(UpgraderLock::acquire());
		UpgraderLock::release();

		$this->assertTrue(UpgraderLock::acquire());
	}
}
