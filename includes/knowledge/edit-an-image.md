---
type: Skill
title: Edit an image in the media library
description: Before rotating, cropping, or flipping an image, regenerating its thumbnails, or changing its alt text, title, or caption.
---

Recipe: edit an image in the media library.

Goal: transform an image's pixels (rotate, crop, flip) or change its text metadata (alt text, title, caption), without breaking the rest of the library. Every ability here is served by the "media" tool. Decide first which you mean: editing pixels and editing metadata are two different abilities.

STEP 1 - FIND THE ATTACHMENT (through the "media" tool)
- media execute media/list-media to find the image by title or filename, or media/get-media to read one attachment by ID. Both return the attachment ID and its file URL (source_url) — you need that URL in Step 2A.

STEP 2A - CHANGE THE PIXELS: ROTATE, CROP, FLIP (through the "media" tool)
- media execute media/edit-media-image. It needs BOTH the attachment "id" AND "src" — the URL of the image file to edit, read from media/get-media in Step 1. The id alone is not enough.
- This creates a NEW edited attachment and leaves the original untouched; use the new ID it returns from then on.
- The transforms are rotate, crop, and flip (there is no resize/scale transform). For a plain clockwise rotation, the "rotation" field (1-359 degrees) is the simplest input. Call "describe" for the crop and flip shapes before using them.

STEP 2B - CHANGE THE TEXT METADATA: ALT TEXT, TITLE, CAPTION (through the "media" tool)
- media execute media/update-media. This sets alt text, title, caption, or description — it does NOT alter the image pixels. Use it for accessibility alt text; use Step 2A to rotate or crop.

STEP 3 - REBUILD THE SIZES (through the "media" tool)
- media execute media/regenerate-thumbnails to rebuild an attachment's sub-sizes — useful when the registered image sizes have changed or a derivative is missing. A simple edit in Step 2A already returns a new attachment with its sizes generated, so you do not need this after it. media/list-image-sizes shows which sizes exist.

To work on a brand-new image, media execute media/upload-media first, then return to Step 2.
