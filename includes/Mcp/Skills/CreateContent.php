<?php
/**
 * The create-content skill: how to author coherent Gutenberg content.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The first built-in skill: a recipe for authoring block content that fits the theme.
 *
 * A skill is a task-oriented recipe that spans several domains — here `design`
 * (block types, patterns, fonts, styles), `media` (images), and `content` (the
 * create/update abilities). The recipe is *static procedural text that references
 * the read abilities for live data*; it never embeds that data, because the block,
 * pattern, font, and style sets differ per site and per theme (spec §10).
 *
 * {@see SkillsRegistry} registers this skill with {@see body()} as a callable, so the
 * long recipe text is not built until a `get` actually asks for it. The id, title,
 * and when-to-use are short and load eagerly so `list` and the tool description stay
 * cheap.
 *
 * @since 0.2.0
 */
final class CreateContent {

	/**
	 * The stable skill id, used by the skills tool's `get` action.
	 */
	public const ID = 'create-content';

	/**
	 * The short human title shown by `list`.
	 *
	 * @return string The skill title.
	 */
	public static function title(): string {
		return __( 'Author coherent Gutenberg content', 'abilities-catalog' );
	}

	/**
	 * The one-line routing hint: when an agent should reach for this skill.
	 *
	 * @return string The when-to-use hint.
	 */
	public static function whenToUse(): string {
		return __( 'Before creating or updating a post, page, or custom post type whose body should be blocks that match the active theme.', 'abilities-catalog' );
	}

	/**
	 * The full recipe body, built only when `get` asks for it.
	 *
	 * Procedural guidance plus the names of the read abilities to call for live
	 * data. It deliberately does not list the actual blocks, patterns, fonts, or
	 * styles — those are read per site through the abilities named here.
	 *
	 * @return string The recipe body.
	 */
	public static function body(): string {
		return __(
			'Recipe: author coherent Gutenberg block content.

Goal: produce post or page content as valid block markup that matches the active theme, then save it through a content ability. Do not guess which blocks, patterns, fonts, or colors exist — read them from the catalog first, because they differ per site and per theme.

BLOCK MARKUP FORMAT
WordPress stores content as block markup: HTML wrapped in block comments. A block opens with "<!-- wp:NAMESPACE/NAME {JSON-ATTRIBUTES} -->" and closes with "<!-- /wp:NAMESPACE/NAME -->". Core blocks omit the "core/" prefix in the comment. Example:

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Section title</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Body text.</p>
<!-- /wp:paragraph -->

The inner HTML must match what the block renders, including its class names. Markup that does not match is flagged as broken in the editor.

STEP 1 - LEARN WHAT IS AVAILABLE (all reads, through the "design" tool)
- design execute templates/list-block-types: the block types registered on this site. Use only these names; never invent a block.
- design execute templates/list-patterns and templates/list-block-pattern-categories: ready-made block compositions to reuse instead of hand-building layout.
- design execute templates/list-synced-patterns: reusable blocks whose content stays in sync across uses.
- design execute templates/get-global-styles and templates/get-theme-styles: the theme color palette, typography, and spacing presets. Reference these preset slugs so content inherits the theme instead of hard-coded values.
- design execute fonts/list-font-collections and fonts/list-font-families: the fonts available or installable. Use a registered font; do not assume one.

STEP 2 - FOR IMAGES, UPLOAD FIRST
Through the "media" tool: media execute media/upload-media to add the file, then reference the returned attachment id and URL in an image block. Do not paste external image URLs into content.

STEP 3 - COMPOSE THE MARKUP
Prefer a pattern from Step 1 when one fits, then fill in its text. Otherwise build from core blocks (heading, paragraph, list, image, columns, group, quote). Apply theme presets from the global styles rather than inline colors or font sizes. Validate every block name against templates/list-block-types.

STEP 4 - CREATE THE CONTENT
Through the "content" tool: content execute content/create-post for a post, content/create-page for a page, or content/create-cpt-item for a custom post type. Pass the composed block markup as the content field. To revise existing content instead, use content/update-post, content/update-page, or content/update-cpt-item.

This recipe points at live data on purpose. Re-read Step 1 for each site: the block, pattern, font, and style sets change between themes and installs.',
			'abilities-catalog'
		);
	}
}
