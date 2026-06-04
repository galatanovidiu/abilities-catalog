<?php
/**
 * Integration test for the Registry annotation guard.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Tests\Integration;

use Automattic\AbilitiesCatalog\Registry;
use Automattic\AbilitiesCatalog\Tests\Fixtures\UnsafeWriteAbility;
use Automattic\AbilitiesCatalog\Tests\TestCase;
use ReflectionProperty;

/**
 * A write that omits a boolean annotations.destructive is treated as unsafe and
 * must NOT register; the Registry flags it via _doing_it_wrong(). This is the
 * gate that keeps an un-annotated write from slipping into the catalog.
 */
final class RegistryGuardTest extends TestCase {

	public function test_write_without_destructive_annotation_is_refused(): void {
		$this->setExpectedIncorrectUsage(Registry::class . '::registerAbilities');

		$registry = new Registry();

		// Inject only the unsafe fixture so registerAbilities() processes it alone.
		$prop = new ReflectionProperty(Registry::class, 'abilities');
		$prop->setAccessible(true);
		$prop->setValue($registry, array('catalog-test/unsafe-write' => new UnsafeWriteAbility()));

		$registry->registerAbilities();

		$this->assertFalse(
			wp_has_ability('catalog-test/unsafe-write'),
			'An un-annotated write must not be registered.'
		);
	}
}
