<?php
/**
 * Integration tests for the exposure policy's option-backed storage.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The gate reads and writes the exposure option deny-by-default: with no option nothing
 * is allowed, a saved set is honored, and a malformed option exposes nothing.
 */
final class ExposurePolicyStorageTest extends TestCase {

	/**
	 * Clears the exposure option so each test starts from the shipped default.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION );
		parent::tear_down();
	}

	/**
	 * With no option, the gate allows nothing.
	 *
	 * @return void
	 */
	public function test_denies_everything_by_default(): void {
		delete_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION );

		$policy = new ExposurePolicy();

		$this->assertFalse( $policy->allows( 'content/get-post' ) );
		$this->assertSame( array(), $policy->enabledAbilities() );
	}

	/**
	 * save validates against the registry and a fresh policy honors the stored set.
	 *
	 * @return void
	 */
	public function test_save_validates_and_is_read_back(): void {
		$known = array_map( 'strval', array_keys( wp_get_abilities() ) );

		$stored = ExposurePolicy::save(
			array( 'content/get-post', 'plugins/forged-name' ),
			$known
		);

		$this->assertSame( array( 'content/get-post' ), $stored, 'A forged name must not be stored.' );

		$policy = new ExposurePolicy();
		$this->assertTrue( $policy->allows( 'content/get-post' ) );
		$this->assertFalse( $policy->allows( 'plugins/forged-name' ) );
		$this->assertFalse( $policy->allows( 'content/create-post' ) );
	}

	/**
	 * A malformed option (not a list of strings) exposes nothing.
	 *
	 * @return void
	 */
	public function test_malformed_option_exposes_nothing(): void {
		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, 'not-an-array' );
		$this->assertSame( array(), ( new ExposurePolicy() )->enabledAbilities() );

		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, array( 'content/get-post', 42, array( 'x' ) ) );
		$this->assertSame( array( 'content/get-post' ), ExposurePolicy::stored() );
	}
}
