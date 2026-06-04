<?php
/**
 * Unit tests for Registry schema normalization.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit;

use GalatanOvidiu\AbilitiesCatalog\Registry;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use ReflectionMethod;
use stdClass;

/**
 * Covers Registry::normalizeSchema(), the pure transform that repairs the two
 * PHP-array serialization quirks that otherwise break client-side JSON Schema
 * validation: empty `properties` must be an object, and empty `required` must
 * be dropped.
 */
final class RegistryNormalizeSchemaTest extends TestCase {

	/**
	 * Invokes the private normalizeSchema() method on a fresh Registry.
	 *
	 * @param array<string,mixed> $schema Schema node to normalize.
	 * @return array<string,mixed> Normalized node.
	 */
	private function normalize(array $schema): array {
		$method = new ReflectionMethod(Registry::class, 'normalizeSchema');
		$method->setAccessible(true);

		return $method->invoke(new Registry(), $schema);
	}

	public function test_empty_properties_becomes_object(): void {
		$result = $this->normalize(
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);

		$this->assertInstanceOf(stdClass::class, $result['properties']);
	}

	public function test_empty_required_is_removed(): void {
		$result = $this->normalize(
			array(
				'type'     => 'object',
				'required' => array(),
			)
		);

		$this->assertArrayNotHasKey('required', $result);
	}

	public function test_non_empty_required_is_preserved(): void {
		$result = $this->normalize(
			array(
				'type'     => 'object',
				'required' => array('id'),
			)
		);

		$this->assertSame(array('id'), $result['required']);
	}

	public function test_nested_empty_properties_is_normalized_recursively(): void {
		$result = $this->normalize(
			array(
				'type'       => 'object',
				'properties' => array(
					'meta' => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			)
		);

		$this->assertIsArray($result['properties']);
		$this->assertInstanceOf(stdClass::class, $result['properties']['meta']['properties']);
	}

	public function test_populated_properties_are_left_as_array(): void {
		$result = $this->normalize(
			array(
				'type'       => 'object',
				'properties' => array(
					'id' => array('type' => 'integer'),
				),
			)
		);

		$this->assertIsArray($result['properties']);
		$this->assertSame('integer', $result['properties']['id']['type']);
	}
}
