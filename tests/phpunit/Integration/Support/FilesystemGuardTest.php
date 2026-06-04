<?php
/**
 * Integration tests for the filesystem guard.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Tests\Integration\Support;

use Automattic\AbilitiesCatalog\Support\FilesystemGuard;
use Automattic\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * The guard requires direct filesystem access so a headless dangerous-tier call
 * never reaches a credential prompt. On a non-direct host it returns a single
 * generic error with no path or credential detail.
 */
final class FilesystemGuardTest extends TestCase {

	public function test_direct_filesystem_passes(): void {
		// The test environment sets FS_METHOD=direct.
		$this->assertTrue(FilesystemGuard::ensureDirect());
	}

	public function test_non_direct_filesystem_is_refused(): void {
		$force_ftp = static function (): string {
			return 'ftpext';
		};
		add_filter('filesystem_method', $force_ftp);

		try {
			$result = FilesystemGuard::ensureDirect();
		} finally {
			remove_filter('filesystem_method', $force_ftp);
		}

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('webmcp_fs_not_writable', $result->get_error_code());
		$this->assertSame(503, $result->get_error_data()['status']);
		// The message must not leak any path or credential detail.
		$this->assertStringNotContainsString('/', $result->get_error_message());
	}
}
