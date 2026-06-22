<?php
/**
 * The configure-reading skill: how to set the homepage and reading options.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A recipe for configuring the homepage and the other reading settings.
 *
 * A skill is a task-oriented recipe that spans several domains — here `settings`
 * (the reading options) and `content` (the page a static homepage points at). Its
 * load-bearing job is the ordering: a static front page is an option that stores a
 * page ID, so the page must exist before the setting can reference it. The recipe is
 * *static procedural text that references the live abilities*; it embeds no settings
 * values (spec §10).
 *
 * {@see SkillsRegistry} registers this skill with {@see body()} as a callable, so the
 * long recipe text is not built until a `get` actually asks for it.
 *
 * @since 0.2.0
 */
final class ConfigureReading {

	/**
	 * The stable skill id, used by the skills tool's `get` action.
	 */
	public const ID = 'configure-reading';

	/**
	 * The short human title shown by `list`.
	 *
	 * @return string The skill title.
	 */
	public static function title(): string {
		return __( 'Configure the homepage and reading settings', 'abilities-catalog' );
	}

	/**
	 * The one-line routing hint: when an agent should reach for this skill.
	 *
	 * @return string The when-to-use hint.
	 */
	public static function whenToUse(): string {
		return __( 'Before changing what the front page shows (latest posts or a static page), how many posts a page shows, or whether search engines may index the site.', 'abilities-catalog' );
	}

	/**
	 * The full recipe body, built only when `get` asks for it.
	 *
	 * @return string The recipe body.
	 */
	public static function body(): string {
		return __(
			'Recipe: configure the homepage and reading settings.

Goal: set what the front page shows and the other reading options correctly. A static homepage depends on a real page existing, so the order matters: have the page first, then point the setting at it.

STEP 1 - READ THE CURRENT SETTINGS (through the "settings" tool)
- settings execute settings/get-reading: the current front-page display, the front and posts page IDs, posts-per-page, and the search-engine visibility flag. Read before you write so you change only what the task asks.

STEP 2 - FOR A STATIC HOMEPAGE, GET A PAGE ID FIRST (through the "content" tool)
- A static front page stores the ID of a published page. content execute content/list-pages to find an existing page, or content/create-page to make one (e.g. a "Home" page) when none fits — set status="publish" on create-page, because content/create-page defaults to a draft and a draft page does not render as a public homepage. Note the page ID it returns; content/get-page can confirm a page exists.
- For a separate blog posts page, get a second page\'s ID the same way.

STEP 3 - APPLY THE READING SETTINGS (through the "settings" tool)
- settings execute settings/update-reading. For a static homepage set show_on_front="page", page_on_front=<the Home page ID>, and page_for_posts=<the blog page ID> if you want a dedicated posts page. To go back to the latest posts, set show_on_front="posts".
- Other fields on the same ability: posts_per_page (how many posts a list shows) and blog_public (whether search engines may index the site). Call "describe" for the exact schema before writing.

This is a settings change, but the static-homepage case reaches into the "content" tool for the page IDs. Create or confirm the page before pointing the setting at it, or the homepage will reference a page that does not exist.',
			'abilities-catalog'
		);
	}
}
