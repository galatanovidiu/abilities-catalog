---
type: Skill
title: Author coherent Gutenberg content
description: Before creating or updating a post, page, or custom post type whose body should be blocks that match the active theme.
---

Recipe: author coherent Gutenberg block content.

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

This recipe points at live data on purpose. Re-read Step 1 for each site: the block, pattern, font, and style sets change between themes and installs.
