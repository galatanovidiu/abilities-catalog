<?php
/**
 * Test fixture: a write ability that omits the destructive annotation.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Fixtures;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

/**
 * A write (readonly = false) that does NOT declare a boolean annotations.destructive.
 * The Registry must refuse it. Lives under tests/, so the Registry's disk scan never
 * discovers it; it is injected directly in the guard test.
 */
final class UnsafeWriteAbility implements Ability {

	public function name(): string {
		return 'catalog-test/unsafe-write';
	}

	public function args(): array {
		return array(
			'label'               => 'Unsafe Write',
			'description'         => 'A write that omits the destructive annotation.',
			'category'            => 'settings',
			'input_schema'        => array('type' => 'object'),
			'output_schema'       => array('type' => 'object'),
			'execute_callback'    => static fn() => array(),
			'permission_callback' => static fn() => true,
			'meta'                => array(
				'annotations' => array(
					'readonly' => false,
					// Intentionally NO 'destructive' key.
				),
			),
		);
	}
}
