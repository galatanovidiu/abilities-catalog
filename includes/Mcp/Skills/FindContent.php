<?php
/**
 * The find-content skill: how to locate existing content before acting on it.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A recipe for finding content that already exists, before reading or editing it.
 *
 * A skill is a task-oriented recipe that spans several domains — here `content`
 * (post types, search, lists, single-item reads) and `dashboard` (recent drafts and
 * activity). The recipe is *static procedural text that references the read abilities
 * for live data*; it never embeds that data, because the registered post types differ
 * per site (spec §10).
 *
 * {@see SkillsRegistry} registers this skill with {@see body()} as a callable, so the
 * long recipe text is not built until a `get` actually asks for it. The id, title,
 * and when-to-use are short and load eagerly so `list` and the tool description stay
 * cheap.
 *
 * @since 0.2.0
 */
final class FindContent {

	/**
	 * The stable skill id, used by the skills tool's `get` action.
	 */
	public const ID = 'find-content';

	/**
	 * The short human title shown by `list`.
	 *
	 * @return string The skill title.
	 */
	public static function title(): string {
		return __( 'Find existing content', 'abilities-catalog' );
	}

	/**
	 * The one-line routing hint: when an agent should reach for this skill.
	 *
	 * @return string The when-to-use hint.
	 */
	public static function whenToUse(): string {
		return __( 'Before locating, listing, or searching for existing posts, pages, custom post type items, or drafts — when you must find content rather than create it.', 'abilities-catalog' );
	}

	/**
	 * The full recipe body, built only when `get` asks for it.
	 *
	 * Procedural guidance plus the names of the read abilities to call for live
	 * data. It deliberately does not list the actual post types — those are read per
	 * site through the abilities named here.
	 *
	 * @return string The recipe body.
	 */
	public static function body(): string {
		return __(
			'Recipe: find existing content (posts, pages, custom post types, drafts).

Goal: locate content that already exists, then read or hand off the specific item. Do not guess IDs or assume which post types exist — discover them first, because custom post types differ per site.

STEP 1 - KNOW WHAT POST TYPES EXIST (read, through the "content" tool)
- content execute content/list-post-types: the post types registered on this site (post, page, and any custom ones). Use these slugs when listing or reading; do not assume a type exists.

STEP 2 - SEARCH BY WORDS (through the "content" tool)
- content execute search/search-content: keyword search when you have words but no ID. It returns matches with their id, title, URL, and type. Important: it searches only PUBLISHED, public content — it does NOT find drafts, pending, private, or trashed items. To find those, use the status filter in Step 3 or the drafts list in Step 4.

STEP 3 - BROWSE AND FILTER A LIST (through the "content" tool)
- content execute content/list-posts: list posts, narrowed by status (e.g. draft, publish, pending), author, or search term. content/list-pages does the same for pages. content/list-cpt-items lists a custom post type (pass its slug from Step 1) — it handles only post-like types, so use the "media" tool for attachments and the "design" tool for templates, patterns, or fonts.
- These return summary rows with IDs. Read the exact filter fields with "describe" before calling; do not guess field names.

STEP 4 - RECENT DRAFTS AND ACTIVITY (through the "dashboard" tool)
- dashboard execute dashboard/get-drafts: the current user\'s most recent drafts, when the task is about "the draft I was working on". For drafts by any author, use content/list-posts with a draft status filter instead.
- dashboard execute dashboard/get-activity: recently published posts and recent approved comments, for "what happened recently".

STEP 5 - READ THE FULL ITEM (through the "content" tool)
- Once you have an ID, content execute content/get-post (or content/get-page) returns that one item\'s full content and metadata. content/get-cpt-item reads one custom post type item, but needs both the post_type slug and the id.

This recipe finds content; it does not change it. To edit what you find, take the item\'s ID from here and use the content update abilities.',
			'abilities-catalog'
		);
	}
}
