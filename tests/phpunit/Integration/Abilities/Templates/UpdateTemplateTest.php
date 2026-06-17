<?php
/**
 * Integration tests for templates/update-template output and contract.
 *
 * Covers a successful wp_template update (id/type/status/title/edit_link shape),
 * the wp_template_part branch (type/area reporting), clearing a field with an
 * empty string, a missing-id 404 that stays a specific core error (not a
 * permission collapse), and a wrong-capability denial.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/update-template.
 */
final class UpdateTemplateTest extends TestCase {

	/**
	 * Creates a template (or part) so update has an existing record to change.
	 *
	 * @param string $slug      The template slug.
	 * @param string $post_type wp_template or wp_template_part.
	 * @param string $content   Initial content.
	 * @return string The created template id in "theme//slug" form.
	 */
	private function createTemplate( string $slug, string $post_type = 'wp_template', string $content = '<!-- wp:paragraph --><p>before</p><!-- /wp:paragraph -->' ): string {
		$created = wp_get_ability( 'templates/create-template' )->execute(
			array(
				'slug'      => $slug,
				'post_type' => $post_type,
				'title'     => 'Original',
				'content'   => $content,
			)
		);

		$this->assertIsArray( $created );

		return (string) $created['id'];
	}

	public function test_update_wp_template_returns_id_type_status_and_edit_link(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createTemplate( 'page-update-me' );

		$result = wp_get_ability( 'templates/update-template' )->execute(
			array(
				'id'      => $id,
				'title'   => 'Updated',
				'content' => '<!-- wp:paragraph --><p>after</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'wp_template', $result['type'] );
		$this->assertSame( 'Updated', $result['title'] );
		$this->assertSame( 'publish', $result['status'] );

		// edit_link must be core's canonical Site Editor URL, built via
		// get_edit_post_link() (site-editor.php?p=...&canvas=edit), not the legacy
		// postType/postId query form.
		$this->assertArrayHasKey( 'edit_link', $result );
		$this->assertNotSame( '', $result['edit_link'] );
		$this->assertStringContainsString( 'site-editor.php', $result['edit_link'] );
		$this->assertStringContainsString( 'canvas=edit', $result['edit_link'] );
		$this->assertStringNotContainsString( 'postType=', $result['edit_link'] );

		// The new content reached core.
		$template = get_block_template( $id, 'wp_template' );
		$this->assertInstanceOf( \WP_Block_Template::class, $template );
		$this->assertStringContainsString( 'after', $template->content );
	}

	public function test_update_wp_template_part_reports_type_and_area(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createTemplate( 'header-update-me', 'wp_template_part' );

		$result = wp_get_ability( 'templates/update-template' )->execute(
			array(
				'id'        => $id,
				'post_type' => 'wp_template_part',
				'title'     => 'Updated Header',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'wp_template_part', $result['type'] );
		$this->assertSame( 'Updated Header', $result['title'] );
		// Parts created via the catalog land in the uncategorized area; core
		// reports `area` only for parts, so this proves the part branch ran.
		$this->assertArrayHasKey( 'area', $result );
		$this->assertSame( 'uncategorized', $result['area'] );
	}

	public function test_empty_string_clears_the_field(): void {
		$this->actingAs( 'administrator' );

		$id = $this->createTemplate(
			'page-clear-me',
			'wp_template',
			'<!-- wp:paragraph --><p>has content</p><!-- /wp:paragraph -->'
		);

		$result = wp_get_ability( 'templates/update-template' )->execute(
			array(
				'id'      => $id,
				'content' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );

		// A blank content is forwarded to core (not silently dropped), so the
		// override content is cleared.
		$template = get_block_template( $id, 'wp_template' );
		$this->assertInstanceOf( \WP_Block_Template::class, $template );
		$this->assertSame( '', $template->content );
	}

	public function test_missing_template_returns_specific_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/update-template' )->execute(
			array(
				'id'    => get_stylesheet() . '//does-not-exist',
				'title' => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_template_not_found', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'templates/update-template' );

		$this->assertFalse(
			$ability->check_permissions(
				array( 'id' => get_stylesheet() . '//page-nope' )
			)
		);

		$result = $ability->execute(
			array( 'id' => get_stylesheet() . '//page-nope' )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
