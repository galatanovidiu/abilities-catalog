<?php
/**
 * The moderate-comments skill: how to triage the comment queue safely.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A recipe for moderating comments, biased toward the reversible action.
 *
 * A skill is a task-oriented recipe. This one stays within the `content` tool (it
 * serves `comments/*`) and its load-bearing job is to steer the agent away from the
 * permanent delete toward spam/trash, and to teach that the default comment list
 * hides spam and trash. The recipe is *static procedural text that references the
 * live abilities*; it embeds no comment data (spec §10).
 *
 * {@see SkillsRegistry} registers this skill with {@see body()} as a callable, so the
 * long recipe text is not built until a `get` actually asks for it.
 *
 * @since 0.2.0
 */
final class ModerateComments {

	/**
	 * The stable skill id, used by the skills tool's `get` action.
	 */
	public const ID = 'moderate-comments';

	/**
	 * The short human title shown by `list`.
	 *
	 * @return string The skill title.
	 */
	public static function title(): string {
		return __( 'Moderate the comment queue', 'abilities-catalog' );
	}

	/**
	 * The one-line routing hint: when an agent should reach for this skill.
	 *
	 * @return string The when-to-use hint.
	 */
	public static function whenToUse(): string {
		return __( 'Before approving, rejecting, marking spam, trashing, replying to, or editing comments — when working through the comment queue.', 'abilities-catalog' );
	}

	/**
	 * The full recipe body, built only when `get` asks for it.
	 *
	 * @return string The recipe body.
	 */
	public static function body(): string {
		return __(
			'Recipe: moderate the comment queue.

Goal: triage pending, spam, and published comments and act on each, choosing the reversible action over the permanent one. Every ability here is served by the "content" tool.

STEP 1 - SEE THE QUEUE (through the "content" tool)
- content execute comments/list-comments with status="hold" for comments awaiting moderation. Use status="spam" to review the spam folder, status="approve" for already-published comments, status="trash" for trashed ones. The default list (no status given) shows ONLY approved comments — it excludes pending (hold), spam, and trash — so always name the status when you want anything but approved.
- content execute comments/get-comment reads one comment in full by ID.

STEP 2 - APPROVE OR REJECT (through the "content" tool)
- Legitimate comment: content execute comments/approve-comment. To undo, comments/unapprove-comment.
- Junk: prefer content execute comments/spam-comment (it marks the comment as spam, may trigger spam plugins like Akismet, and is reversible with comments/unspam-comment) over deleting.
- To remove without marking spam: content execute comments/trash-comment (reversible with comments/untrash-comment).

STEP 3 - REPLY OR FIX (through the "content" tool)
- content execute comments/create-comment to reply: set its parent to the comment you are answering and the post it belongs to.
- content execute comments/update-comment to fix a typo in an existing comment\'s text.

SAFETY: comments/delete-comment is permanent — it skips the trash and cannot be undone. Use spam or trash for unwanted comments; reach for delete only when the task explicitly says to delete permanently.',
			'abilities-catalog'
		);
	}
}
