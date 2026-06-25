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
		$known = array( 'og-content/get-post', 'og-content/create-post', 'og-media/list-media' );

		$result = ExposurePolicy::sanitize(
			array( 'og-content/get-post', 'plugins/forged-name', 'og-media/list-media' ),
			$known
		);

		$this->assertSame( array( 'og-content/get-post', 'og-media/list-media' ), $result );
	}

	/**
	 * sanitize collapses duplicates while preserving input order.
	 *
	 * @return void
	 */
	public function test_sanitize_deduplicates(): void {
		$known = array( 'og-content/get-post', 'og-content/create-post' );

		$result = ExposurePolicy::sanitize(
			array( 'og-content/get-post', 'og-content/get-post', 'og-content/create-post' ),
			$known
		);

		$this->assertSame( array( 'og-content/get-post', 'og-content/create-post' ), $result );
	}

	/**
	 * sanitize ignores non-string members.
	 *
	 * @return void
	 */
	public function test_sanitize_ignores_non_string_members(): void {
		$known = array( 'og-content/get-post' );

		$result = ExposurePolicy::sanitize(
			array( 'og-content/get-post', 42, array( 'nope' ), null ),
			$known
		);

		$this->assertSame( array( 'og-content/get-post' ), $result );
	}

	/**
	 * applyChanges adds the abilities a change set turns on.
	 *
	 * @return void
	 */
	public function test_apply_changes_adds_enabled(): void {
		$result = ExposurePolicy::applyChanges(
			array( 'og-content/get-post' ),
			array( 'og-media/list-media' => true )
		);

		$this->assertSame( array( 'og-content/get-post', 'og-media/list-media' ), $result );
	}

	/**
	 * applyChanges removes the abilities a change set turns off and leaves the rest.
	 *
	 * @return void
	 */
	public function test_apply_changes_removes_disabled_and_keeps_untouched(): void {
		$result = ExposurePolicy::applyChanges(
			array( 'og-content/get-post', 'og-content/create-post', 'og-media/list-media' ),
			array( 'og-content/create-post' => false )
		);

		$this->assertSame( array( 'og-content/get-post', 'og-media/list-media' ), $result );
	}

	/**
	 * applyChanges does not duplicate an ability already enabled.
	 *
	 * @return void
	 */
	public function test_apply_changes_is_idempotent_for_enabled(): void {
		$result = ExposurePolicy::applyChanges(
			array( 'og-content/get-post' ),
			array( 'og-content/get-post' => true )
		);

		$this->assertSame( array( 'og-content/get-post' ), $result );
	}

	/**
	 * Turning off an ability that is not enabled is a no-op.
	 *
	 * @return void
	 */
	public function test_apply_changes_disabling_absent_is_noop(): void {
		$result = ExposurePolicy::applyChanges(
			array( 'og-content/get-post' ),
			array( 'og-media/list-media' => false )
		);

		$this->assertSame( array( 'og-content/get-post' ), $result );
	}

	/**
	 * applyValidatedChanges enables a known ability and drops a forged enable.
	 *
	 * @return void
	 */
	public function test_apply_validated_changes_drops_unknown_enable(): void {
		$known = array( 'og-content/get-post', 'og-media/list-media' );

		$result = ExposurePolicy::applyValidatedChanges(
			array(),
			array(
				'og-content/get-post'    => true,
				'plugins/forged-name' => true,
			),
			$known
		);

		$this->assertSame( array( 'og-content/get-post' ), $result );
	}

	/**
	 * applyValidatedChanges never prunes an enabled ability the change set does not mention,
	 * even when that ability is not currently registered (its plugin is inactive).
	 *
	 * @return void
	 */
	public function test_apply_validated_changes_keeps_untouched_unregistered_ability(): void {
		$known = array( 'og-content/get-post' );

		$result = ExposurePolicy::applyValidatedChanges(
			array( 'third-party/feature' ),
			array( 'og-content/get-post' => true ),
			$known
		);

		$this->assertSame( array( 'third-party/feature', 'og-content/get-post' ), $result );
	}

	/**
	 * applyValidatedChanges honors a disable even for a name not in the registry.
	 *
	 * @return void
	 */
	public function test_apply_validated_changes_allows_disabling_unregistered(): void {
		$result = ExposurePolicy::applyValidatedChanges(
			array( 'third-party/feature', 'og-content/get-post' ),
			array( 'third-party/feature' => false ),
			array( 'og-content/get-post' )
		);

		$this->assertSame( array( 'og-content/get-post' ), $result );
	}
}
