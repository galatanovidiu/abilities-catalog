<?php
/**
 * Unit tests for the SourceValidator install-source guard.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Support;

use GalatanOvidiu\AbilitiesCatalog\Support\SourceValidator;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * SourceValidator restricts install sources to a wordpress.org directory slug.
 * Anything that could express a URL, ZIP, file path, or traversal must be refused.
 */
final class SourceValidatorTest extends TestCase {

	public function test_clean_slug_is_accepted(): void {
		$this->assertSame('akismet', SourceValidator::slug('akismet'));
	}

	public function test_slug_is_lowercased_and_trimmed(): void {
		$this->assertSame('wp-super-cache', SourceValidator::slug('  WP-Super-Cache  '));
	}

	/**
	 * @dataProvider rejectedSources
	 */
	public function test_unsafe_source_is_refused(string $raw): void {
		$result = SourceValidator::slug($raw);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('abilities_catalog_invalid_slug', $result->get_error_code());
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function rejectedSources(): array {
		return array(
			'empty'          => array(''),
			'whitespace'     => array('   '),
			'zip url'        => array('https://example.com/evil.zip'),
			'remote host'    => array('evil.com/package'),
			'path traversal' => array('../../wp-config'),
			'file path'      => array('/var/www/html/x'),
			'dotted'         => array('plugin.name'),
			'space inside'   => array('hello world'),
			'backslash'      => array('a\\b'),
			'colon'          => array('proto:thing'),
		);
	}
}
