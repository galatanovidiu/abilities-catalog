<?php
/**
 * Integration tests for og-templates/create-template output and contract.
 *
 * Covers a successful wp_template create (id/status/edit_link shape), the
 * wp_template_part branch creating a part record, the core slug-pattern
 * rejection (invalid slug is not silently rewritten and does not collapse to a
 * permission error), and a wrong-capability denial.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/create-template.
 */
final class CreateTemplateTest extends TestCase {

	public function test_create_wp_template_returns_id_status_and_edit_link(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/create-template' )->execute(
			array(
				'slug'    => 'page-about',
				'title'   => 'About',
				'content' => '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( get_stylesheet() . '//page-about', $result['id'] );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'About', $result['title'] );

		// edit_link must match core's canonical template edit URL form
		// (site-editor.php?p=/<post_type>/<theme//slug>&canvas=edit), built via
		// get_edit_post_link(), not the legacy postType/postId query form.
		$this->assertArrayHasKey( 'edit_link', $result );
		$this->assertNotSame( '', $result['edit_link'] );
		$this->assertStringContainsString( 'site-editor.php', $result['edit_link'] );
		$this->assertStringContainsString( 'canvas=edit', $result['edit_link'] );
		$this->assertStringNotContainsString( 'postType=', $result['edit_link'] );
	}

	public function test_create_wp_template_part_creates_part_record(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/create-template' )->execute(
			array(
				'slug'      => 'my-header',
				'post_type' => 'wp_template_part',
				'title'     => 'My Header',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( get_stylesheet() . '//my-header', $result['id'] );
		// Resolving the id as a wp_template_part proves the template-parts route
		// branch ran (a wp_template lookup of this id would not resolve).
		$part = get_block_template( $result['id'], 'wp_template_part' );
		$this->assertInstanceOf( \WP_Block_Template::class, $part );
		$this->assertSame( 'my-header', $part->slug );
	}

	public function test_invalid_slug_returns_error_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		// "@@@" contains no character in the core slug pattern [a-zA-Z0-9_%-].
		// The input schema mirrors that pattern, so the caller gets a
		// validation error instead of a silently rewritten slug.
		$result = wp_get_ability( 'og-templates/create-template' )->execute(
			array(
				'slug'  => '@@@',
				'title' => 'Bad Slug',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-templates/create-template' );

		$this->assertFalse(
			$ability->check_permissions(
				array( 'slug' => 'page-nope' )
			)
		);

		$result = $ability->execute(
			array( 'slug' => 'page-nope' )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
