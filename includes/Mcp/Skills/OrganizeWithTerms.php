<?php
/**
 * The organize-with-terms skill: how to categorize and tag content correctly.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A recipe for organizing content with categories, tags, and custom taxonomies.
 *
 * A skill is a task-oriented recipe. This one stays within the `content` tool (it
 * serves both `content/*` and `terms/*`) but crosses two record types: a term is a
 * separate record from a post, so the recipe teaches the list-or-create-then-attach
 * order that avoids duplicate terms. The recipe is *static procedural text that
 * references the live abilities*; it never embeds the actual taxonomies or terms,
 * because they differ per site (spec §10).
 *
 * {@see SkillsRegistry} registers this skill with {@see body()} as a callable, so the
 * long recipe text is not built until a `get` actually asks for it.
 *
 * @since 0.2.0
 */
final class OrganizeWithTerms {

	/**
	 * The stable skill id, used by the skills tool's `get` action.
	 */
	public const ID = 'organize-with-terms';

	/**
	 * The short human title shown by `list`.
	 *
	 * @return string The skill title.
	 */
	public static function title(): string {
		return __( 'Organize content with categories and tags', 'abilities-catalog' );
	}

	/**
	 * The one-line routing hint: when an agent should reach for this skill.
	 *
	 * @return string The when-to-use hint.
	 */
	public static function whenToUse(): string {
		return __( 'Before assigning categories or tags to a post, creating a category or tag, or reorganizing content by taxonomy.', 'abilities-catalog' );
	}

	/**
	 * The full recipe body, built only when `get` asks for it.
	 *
	 * @return string The recipe body.
	 */
	public static function body(): string {
		return __(
			'Recipe: organize content with categories, tags, and custom taxonomies.

Goal: assign the right terms to a post, reusing existing terms instead of creating duplicates. A term (a category or tag) is a separate record from the post: you list or create the term, then attach it to the post.

STEP 1 - FIND THE POST (through the "content" tool)
- You need the post\'s ID. content execute content/list-posts (narrow by search or status) or content/get-post returns it. (The find-content skill covers locating content in depth.)

STEP 2 - KNOW THE TAXONOMIES (through the "content" tool)
- content execute terms/list-taxonomies: which taxonomies exist and which post types they apply to. Categories and tags apply to posts; a custom post type may have its own taxonomies.

STEP 3 - REUSE BEFORE CREATING (through the "content" tool)
- content execute terms/list-categories or terms/list-tags (terms/list-terms for a custom taxonomy): search the existing terms first. Reuse a match — creating a second "News" category makes a duplicate.
- Only when no term matches: content execute terms/create-category, terms/create-tag, or terms/create-term.

STEP 4 - ATTACH TERMS TO THE POST (through the "content" tool)
- content execute terms/attach-post-terms with the post ID, the taxonomy slug ("category", "post_tag", or a custom one), and the terms. Terms may be given as IDs, slugs, or names, but each must already exist (Step 3). It appends by default; pass append=false to replace the post\'s terms in that taxonomy.

STEP 5 - REMOVE TERMS (through the "content" tool)
- content execute terms/detach-post-terms to unlink terms from a post without deleting the term itself.

Attaching links a term to one post; deleting a term (terms/delete-category and the like) removes that term from every post. Prefer attach/detach when you only mean to change one post.',
			'abilities-catalog'
		);
	}
}
