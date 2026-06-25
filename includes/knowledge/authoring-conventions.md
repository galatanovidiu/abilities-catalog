---
type: Guideline
title: Authoring conventions for theme-coherent blocks
description: The house rules for producing Gutenberg block content that matches the active theme, applied across every content-authoring task.
---

These conventions apply whenever you author or revise block content, on top of any specific recipe. They keep generated content valid and visually consistent with the active theme.

READ THE SITE BEFORE YOU WRITE
The block, pattern, font, and style sets differ per site and per theme. Read them first; never assume them. All reads are through the "design" tool:
- design execute templates/list-block-types: the only valid block names on this site. Never invent or misspell a block.
- design execute templates/list-patterns: ready-made block compositions to reuse instead of hand-building layout.
- design execute templates/get-global-styles and design execute templates/get-theme-styles: the theme's color, typography, and spacing presets.

USE VALID BLOCK-COMMENT MARKUP
WordPress stores content as HTML wrapped in block comments: a block opens with "<!-- wp:NAMESPACE/NAME {JSON-ATTRIBUTES} -->" and closes with "<!-- /wp:NAMESPACE/NAME -->" (core blocks omit the "core/" prefix in the comment). The inner HTML must match what the block renders, including its class names — markup that does not match is flagged as broken in the editor. Validate every block name against templates/list-block-types before saving.

PREFER THEME PRESETS OVER HARD-CODED VALUES
Reference the preset slugs from the global and theme styles for color, font size, and spacing, so content inherits the theme rather than freezing inline values that fight a later theme change. Reach for a pattern before hand-building a layout, and fill in its text.
