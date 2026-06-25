---
type: Skill
title: Find existing content
description: Before locating, listing, or searching for existing posts, pages, custom post type items, or drafts — when you must find content rather than create it.
---

Recipe: find existing content (posts, pages, custom post types, drafts).

Goal: locate content that already exists, then read or hand off the specific item. Do not guess IDs or assume which post types exist — discover them first, because custom post types differ per site.

STEP 1 - KNOW WHAT POST TYPES EXIST (read, through the "content" tool)
- content execute og-content/list-post-types: the post types registered on this site (post, page, and any custom ones). Use these slugs when listing or reading; do not assume a type exists.

STEP 2 - SEARCH BY WORDS (through the "content" tool)
- content execute og-search/search-content: keyword search when you have words but no ID. It returns matches with their id, title, URL, and type. Important: it searches only PUBLISHED, public content — it does NOT find drafts, pending, private, or trashed items. To find those, use the status filter in Step 3 or the drafts list in Step 4.

STEP 3 - BROWSE AND FILTER A LIST (through the "content" tool)
- content execute og-content/list-posts: list posts, narrowed by status (e.g. draft, publish, pending), author, or search term. og-content/list-pages does the same for pages. og-content/list-cpt-items lists a custom post type (pass its slug from Step 1) — it handles only post-like types, so use the "media" tool for attachments and the "design" tool for templates, patterns, or fonts.
- These return summary rows with IDs. Read the exact filter fields with "describe" before calling; do not guess field names.

STEP 4 - RECENT DRAFTS AND ACTIVITY (through the "dashboard" tool)
- dashboard execute og-dashboard/get-drafts: the current user's most recent drafts, when the task is about "the draft I was working on". For drafts by any author, use og-content/list-posts with a draft status filter instead.
- dashboard execute og-dashboard/get-activity: recently published posts and recent approved comments, for "what happened recently".

STEP 5 - READ THE FULL ITEM (through the "content" tool)
- Once you have an ID, content execute og-content/get-post (or og-content/get-page) returns that one item's full content and metadata. og-content/get-cpt-item reads one custom post type item, but needs both the post_type slug and the id.

This recipe finds content; it does not change it. To edit what you find, take the item's ID from here and use the content update abilities.
