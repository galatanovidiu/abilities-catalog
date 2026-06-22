<?php
/**
 * The edit-an-image skill: how to transform an image or change its metadata.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Skills;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A recipe for editing an image in the media library.
 *
 * A skill is a task-oriented recipe. This one stays within the `media` tool and its
 * load-bearing job is to separate two abilities agents conflate — editing pixels
 * (`media/edit-media-image`) versus editing text metadata (`media/update-media`) —
 * and to surface that the pixel edit needs the file URL (`src`), not just the
 * attachment id. The recipe is *static procedural text that references the live
 * abilities*; it embeds no media data (spec §10).
 *
 * {@see SkillsRegistry} registers this skill with {@see body()} as a callable, so the
 * long recipe text is not built until a `get` actually asks for it.
 *
 * @since 0.2.0
 */
final class EditAnImage {

	/**
	 * The stable skill id, used by the skills tool's `get` action.
	 */
	public const ID = 'edit-an-image';

	/**
	 * The short human title shown by `list`.
	 *
	 * @return string The skill title.
	 */
	public static function title(): string {
		return __( 'Edit an image in the media library', 'abilities-catalog' );
	}

	/**
	 * The one-line routing hint: when an agent should reach for this skill.
	 *
	 * @return string The when-to-use hint.
	 */
	public static function whenToUse(): string {
		return __( 'Before rotating, cropping, or flipping an image, regenerating its thumbnails, or changing its alt text, title, or caption.', 'abilities-catalog' );
	}

	/**
	 * The full recipe body, built only when `get` asks for it.
	 *
	 * @return string The recipe body.
	 */
	public static function body(): string {
		return __(
			'Recipe: edit an image in the media library.

Goal: transform an image\'s pixels (rotate, crop, flip) or change its text metadata (alt text, title, caption), without breaking the rest of the library. Every ability here is served by the "media" tool. Decide first which you mean: editing pixels and editing metadata are two different abilities.

STEP 1 - FIND THE ATTACHMENT (through the "media" tool)
- media execute media/list-media to find the image by title or filename, or media/get-media to read one attachment by ID. Both return the attachment ID and its file URL (source_url) — you need that URL in Step 2A.

STEP 2A - CHANGE THE PIXELS: ROTATE, CROP, FLIP (through the "media" tool)
- media execute media/edit-media-image. It needs BOTH the attachment "id" AND "src" — the URL of the image file to edit, read from media/get-media in Step 1. The id alone is not enough.
- This creates a NEW edited attachment and leaves the original untouched; use the new ID it returns from then on.
- The transforms are rotate, crop, and flip (there is no resize/scale transform). For a plain clockwise rotation, the "rotation" field (1-359 degrees) is the simplest input. Call "describe" for the crop and flip shapes before using them.

STEP 2B - CHANGE THE TEXT METADATA: ALT TEXT, TITLE, CAPTION (through the "media" tool)
- media execute media/update-media. This sets alt text, title, caption, or description — it does NOT alter the image pixels. Use it for accessibility alt text; use Step 2A to rotate or crop.

STEP 3 - REBUILD THE SIZES (through the "media" tool)
- media execute media/regenerate-thumbnails to rebuild an attachment\'s sub-sizes — useful when the registered image sizes have changed or a derivative is missing. A simple edit in Step 2A already returns a new attachment with its sizes generated, so you do not need this after it. media/list-image-sizes shows which sizes exist.

To work on a brand-new image, media execute media/upload-media first, then return to Step 2.',
			'abilities-catalog'
		);
	}
}
