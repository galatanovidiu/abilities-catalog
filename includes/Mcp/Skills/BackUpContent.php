<?php
/**
 * The back-up-content skill: how to export site content for backup or migration.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A recipe for exporting site content as a portable WXR file.
 *
 * A skill is a task-oriented recipe. This one stays within the `tools` tool and its
 * load-bearing job is to set expectations: a WXR export carries content (posts,
 * pages, terms, comments), not the whole site, and a personal-data privacy export is
 * a different task. The recipe is *static procedural text that references the live
 * abilities*; it embeds no exported data (spec §10).
 *
 * {@see SkillsRegistry} registers this skill with {@see body()} as a callable, so the
 * long recipe text is not built until a `get` actually asks for it.
 *
 * @since 0.2.0
 */
final class BackUpContent {

	/**
	 * The stable skill id, used by the skills tool's `get` action.
	 */
	public const ID = 'back-up-content';

	/**
	 * The short human title shown by `list`.
	 *
	 * @return string The skill title.
	 */
	public static function title(): string {
		return __( 'Export content for backup or migration', 'abilities-catalog' );
	}

	/**
	 * The one-line routing hint: when an agent should reach for this skill.
	 *
	 * @return string The when-to-use hint.
	 */
	public static function whenToUse(): string {
		return __( 'Before exporting site content to a WXR file for backup or migration, or checking which importers can bring content back in.', 'abilities-catalog' );
	}

	/**
	 * The full recipe body, built only when `get` asks for it.
	 *
	 * @return string The recipe body.
	 */
	public static function body(): string {
		return __(
			'Recipe: export content for backup or migration.

Goal: produce a portable export of site content (a WXR file), and know how it gets imported back. Every ability here is served by the "tools" tool.

STEP 1 - EXPORT THE CONTENT (through the "tools" tool)
- tools execute tools/export-content: returns a WXR (WordPress eXtended RSS) XML document of the site\'s content. By default it exports everything (content="all"); pass a post type slug to export just posts or just pages, and narrow further by date range, author, category, or status. Call "describe" for the exact filter fields.
- Scope: this exports content — posts, pages, custom post types, terms, and comments. It does NOT include plugins, themes, uploaded files, or the database. It is a content export, not a whole-site backup.
- Size limit: the WXR is returned inline and capped at 5 MB. A larger export fails with an error (HTTP 413) instead of returning the file; when that happens, narrow by post type and/or a date range (start_date/end_date) and export in parts.

STEP 2 - KNOW HOW IT IMPORTS BACK (through the "tools" tool)
- tools execute tools/list-importers: the import tools registered on this site. A WXR file is read back by the WordPress importer on the destination site.

DIFFERENT TASK - personal data: to export ONE user\'s personal data for a privacy request (e.g. GDPR), that is tools execute privacy/create-export-request, not tools/export-content. Use this content export for backup and migration; use the privacy request for a data-subject access request.',
			'abilities-catalog'
		);
	}
}
