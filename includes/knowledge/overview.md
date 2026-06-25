---
type: Reference
title: How this knowledge tool works
description: What the knowledge tool returns, how to call it, and the domains its concepts route through.
---

The knowledge tool serves OKF concepts — short, task-oriented documents — about working this WordPress site through the curated MCP domain tools. It is reference and procedure, not live data: a concept tells you which abilities to call and in what order; it never embeds the per-site data those abilities return.

HOW TO CALL IT
- Call it with no uri to get the index: live site facts (the "# Site" block) plus every available concept, grouped by type (Skills, Guidelines, Reference).
- Call it with a uri (e.g. core/create-content) to get that one concept's body. A concept uri is the slug shown in the index link.

WHAT EACH TYPE IS
- Skill — a recipe for a multi-step task that spans abilities (authoring content, organizing terms, moderating comments, editing an image, configuring the homepage, exporting content).
- Guideline — a rule of the house that applies across tasks (authoring conventions for coherent Gutenberg blocks).
- Reference — explanatory material, like this overview.

THE DOMAIN TOOLS A CONCEPT ROUTES YOU THROUGH
Each curated domain is one MCP tool with the same three actions — "list" its exact ability names, "describe" an ability's input schema, then "execute" it. Do not guess ability names or input fields; list and describe first.

- content — posts, pages and all custom post types; categories and tags; comments; post meta and revisions; full-text content search.
- media — the media library: upload, list, read, update and delete attachments; edit and crop images; regenerate thumbnails; read the registered image sizes.
- appearance — themes (install, switch, list, delete, search the directory); classic menus and block navigation, their items and locations; widgets and sidebars.
- design — block templates and template parts; block patterns and synced patterns; global and theme styles; registered block types; web fonts and font collections.
- plugins — list, read, install, activate, deactivate, update and delete plugins, and search the plugin directory.
- users — create, list, read, update and delete users; the current user; application passwords.
- settings — the general, writing, reading, discussion, media, permalink and privacy option groups; a single named option; connector metadata.
- tools — export content (WXR); list importers; send a test email; flush the object cache; transients; personal-data export and erasure; scheduled (cron) events.
- site-health — the status report, the health tests, and the debug information.
- updates — list available core, plugin, theme and translation updates, and run a plugin, theme or translation update.
- dashboard — recent activity, the At a Glance counts, and recent drafts.
- network — multisite networks and sites, network options, super admins, and per-site user membership. Inert on a single-site install.

An add-on plugin may contribute its own domain tool and its own knowledge concepts; those appear in the index alongside these.
