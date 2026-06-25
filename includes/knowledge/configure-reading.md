---
type: Skill
title: Configure the homepage and reading settings
description: Before changing what the front page shows (latest posts or a static page), how many posts a page shows, or whether search engines may index the site.
---

Recipe: configure the homepage and reading settings.

Goal: set what the front page shows and the other reading options correctly. A static homepage depends on a real page existing, so the order matters: have the page first, then point the setting at it.

STEP 1 - READ THE CURRENT SETTINGS (through the "settings" tool)
- settings execute og-settings/get-reading: the current front-page display, the front and posts page IDs, posts-per-page, and the search-engine visibility flag. Read before you write so you change only what the task asks.

STEP 2 - FOR A STATIC HOMEPAGE, GET A PAGE ID FIRST (through the "content" tool)
- A static front page stores the ID of a published page. content execute og-content/list-pages to find an existing page, or og-content/create-page to make one (e.g. a "Home" page) when none fits — set status="publish" on create-page, because og-content/create-page defaults to a draft and a draft page does not render as a public homepage. Note the page ID it returns; og-content/get-page can confirm a page exists.
- For a separate blog posts page, get a second page's ID the same way.

STEP 3 - APPLY THE READING SETTINGS (through the "settings" tool)
- settings execute og-settings/update-reading. For a static homepage set show_on_front="page", page_on_front=<the Home page ID>, and page_for_posts=<the blog page ID> if you want a dedicated posts page. To go back to the latest posts, set show_on_front="posts".
- Other fields on the same ability: posts_per_page (how many posts a list shows) and blog_public (whether search engines may index the site). Call "describe" for the exact schema before writing.

This is a settings change, but the static-homepage case reaches into the "content" tool for the page IDs. Create or confirm the page before pointing the setting at it, or the homepage will reference a page that does not exist.
