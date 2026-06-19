<?php
/**
 * Unit tests for the exposure policy's pure set logic.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The exposure policy is deny-by-default and never trusts a submitted set: an unknown
 * or non-string name is dropped, duplicates collapse, and partial changes merge onto
 * the current set without disturbing the abilities they do not mention.
 */
final class ExposurePolicyTest extends TestCase {

	/**
	 * sanitize keeps only names that are registered abilities.
	 *
	 * @return void
	 */
	public function test_sanitize_drops_unknown_names(): void {
		$known = array( 'content/get-post', 'content/create-post', 'media/list-media' );

		$result = ExposurePolicy::sanitize(
			array( 'content/get-post', 'plugins/forged-name', 'media/list-media' ),
			$known
		);

		$this->assertSame( array( 'content/get-post', 'media/list-media' ), $result );
	}

	/**
	 * sanitize collapses duplicates while preserving input order.
	 *
	 * @return void
	 */
	public function test_sanitize_deduplicates(): void {
		$known = array( 'content/get-post', 'content/create-post' );

		$result = ExposurePolicy::sanitize(
			array( 'content/get-post', 'content/get-post', 'content/create-post' ),
			$known
		);

		$this->assertSame( array( 'content/get-post', 'content/create-post' ), $result );
	}

	/**
	 * sanitize ignores non-string members.
	 *
	 * @return void
	 */
	public function test_sanitize_ignores_non_string_members(): void {
		$known = array( 'content/get-post' );

		$result = ExposurePolicy::sanitize(
			array( 'content/get-post', 42, array( 'nope' ), null ),
			$known
		);

		$this->assertSame( array( 'content/get-post' ), $result );
	}

	/**
	 * applyChanges adds the abilities a change set turns on.
	 *
	 * @return void
	 */
	public function test_apply_changes_adds_enabled(): void {
		$result = ExposurePolicy::applyChanges(
			array( 'content/get-post' ),
			array( 'media/list-media' => true )
		);

		$this->assertSame( array( 'content/get-post', 'media/list-media' ), $result );
	}

	/**
	 * applyChanges removes the abilities a change set turns off and leaves the rest.
	 *
	 * @return void
	 */
	public function test_apply_changes_removes_disabled_and_keeps_untouched(): void {
		$result = ExposurePolicy::applyChanges(
			array( 'content/get-post', 'content/create-post', 'media/list-media' ),
			array( 'content/create-post' => false )
		);

		$this->assertSame( array( 'content/get-post', 'media/list-media' ), $result );
	}

	/**
	 * applyChanges does not duplicate an ability already enabled.
	 *
	 * @return void
	 */
	public function test_apply_changes_is_idempotent_for_enabled(): void {
		$result = ExposurePolicy::applyChanges(
			array( 'content/get-post' ),
			array( 'content/get-post' => true )
		);

		$this->assertSame( array( 'content/get-post' ), $result );
	}

	/**
	 * Turning off an ability that is not enabled is a no-op.
	 *
	 * @return void
	 */
	public function test_apply_changes_disabling_absent_is_noop(): void {
		$result = ExposurePolicy::applyChanges(
			array( 'content/get-post' ),
			array( 'media/list-media' => false )
		);

		$this->assertSame( array( 'content/get-post' ), $result );
	}
}
