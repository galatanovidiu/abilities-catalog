---
type: Skill
title: Organize content with categories and tags
description: Before assigning categories or tags to a post, creating a category or tag, or reorganizing content by taxonomy.
---

Recipe: organize content with categories, tags, and custom taxonomies.

Goal: assign the right terms to a post, reusing existing terms instead of creating duplicates. A term (a category or tag) is a separate record from the post: you list or create the term, then attach it to the post.

STEP 1 - FIND THE POST (through the "content" tool)
- You need the post's ID. content execute og-content/list-posts (narrow by search or status) or og-content/get-post returns it. (The find-content knowledge entry covers locating content in depth.)

STEP 2 - KNOW THE TAXONOMIES (through the "content" tool)
- content execute og-terms/list-taxonomies: which taxonomies exist and which post types they apply to. Categories and tags apply to posts; a custom post type may have its own taxonomies.

STEP 3 - REUSE BEFORE CREATING (through the "content" tool)
- content execute og-terms/list-categories or og-terms/list-tags (og-terms/list-terms for a custom taxonomy): search the existing terms first. Reuse a match — creating a second "News" category makes a duplicate.
- Only when no term matches: content execute og-terms/create-category, og-terms/create-tag, or og-terms/create-term.

STEP 4 - ATTACH TERMS TO THE POST (through the "content" tool)
- content execute og-terms/attach-post-terms with the post ID, the taxonomy slug ("category", "post_tag", or a custom one), and the terms. Terms may be given as IDs, slugs, or names, but each must already exist (Step 3). It appends by default; pass append=false to replace the post's terms in that taxonomy.

STEP 5 - REMOVE TERMS (through the "content" tool)
- content execute og-terms/detach-post-terms to unlink terms from a post without deleting the term itself.

Attaching links a term to one post; deleting a term (og-terms/delete-category and the like) removes that term from every post. Prefer attach/detach when you only mean to change one post.
