---
type: Skill
title: Export content for backup or migration
description: Before exporting site content to a WXR file for backup or migration, or checking which importers can bring content back in.
---

Recipe: export content for backup or migration.

Goal: produce a portable export of site content (a WXR file), and know how it gets imported back. Every ability here is served by the "tools" tool.

STEP 1 - EXPORT THE CONTENT (through the "tools" tool)
- tools execute og-tools/export-content: returns a WXR (WordPress eXtended RSS) XML document of the site's content. By default it exports everything (content="all"); pass a post type slug to export just posts or just pages, and narrow further by date range, author, category, or status. Call "describe" for the exact filter fields.
- Scope: this exports content — posts, pages, custom post types, terms, and comments. It does NOT include plugins, themes, uploaded files, or the database. It is a content export, not a whole-site backup.
- Size limit: the WXR is returned inline and capped at 5 MB. A larger export fails with an error (HTTP 413) instead of returning the file; when that happens, narrow by post type and/or a date range (start_date/end_date) and export in parts.

STEP 2 - KNOW HOW IT IMPORTS BACK (through the "tools" tool)
- tools execute og-tools/list-importers: the import tools registered on this site. A WXR file is read back by the WordPress importer on the destination site.

DIFFERENT TASK - personal data: to export ONE user's personal data for a privacy request (e.g. GDPR), that is tools execute og-privacy/create-export-request, not og-tools/export-content. Use this content export for backup and migration; use the privacy request for a data-subject access request.
