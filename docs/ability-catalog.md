# wp-admin Ability Catalog

A coherent, complete-enough catalog of WordPress **abilities** for wp-admin. Scope is **core
WordPress 7.0 wp-admin only** (no plugin/theme surfaces). This document is the build plan for
later implementation loops; it does **not** implement any ability.

The catalog is consumer-agnostic: it defines abilities and their risk classification. How those
abilities are surfaced to any particular consumer (a browser agent, a server-side MCP client, or
none) is the consumer's concern. One such consumer is the
[webmcp-adapter](https://github.com/galatanovidiu/webmcp-adapter) plugin, which exposes these
abilities to an in-browser AI agent; its
[architecture.md](https://github.com/galatanovidiu/webmcp-adapter/blob/trunk/docs/architecture.md)
documents that surface, including the write-gating mechanism.

## How an ability is registered and consumed

1. Register the ability in PHP on `wp_abilities_api_init` via `wp_register_ability( 'namespace/name', [...] )`.
2. Set `meta.show_in_rest = true` and a `permission_callback` that passes for the user — or the
   ability is not exposed over REST and consumers that read the client store never see it.
3. Any consumer reads the registered abilities and acts on them. The ability is the contract;
   no per-consumer change is needed per ability. Server-side, an ability is run directly with
   `wp_get_ability( 'namespace/name' )->execute( $input )`, which runs the full path (input
   validation → permission check → callback → output validation).

## Conventions

- **Granularity:** one ability per CRUD operation per resource, verb-first
  (`content/create-post`). Custom post types and custom taxonomies use ONE generic family
  keyed by a `post_type` / `taxonomy` param.
- **Naming:** `namespace/verb-resource`, kebab-case. Namespace = the domain / ability
  category. New domains need one entry in their group's `CategoryProvider` (the Core group's is
  `includes/Abilities/Core/CategoryCatalog.php`).
- **Capability** is always the hard server-side guard (`permission_callback`), regardless of
  client-side gating. Object-level checks (`edit_post`, `edit_term`, `edit_user`) are noted.
- **REST tag:** `wrapper` (delegates to a `/wp/v2/...` route or core function — preferred:
  wrap, don't reimplement), `net-new` (no REST equivalent), or `deferred` (no clean shape).
- **Schema sketch:** input/output list field names + types only. Full JSON Schema is
  implementation-time work.

## Write-gating model (the classification, and the consumer's duty)

Every ability carries a risk classification in `meta.annotations` — `readonly`, `destructive`,
and `idempotent` — plus a `dangerous` marker for the T3 tier. The catalog defines that
classification; it does not define how a consumer enforces it. The **principle** the catalog
commits to is:

- **Capability is the hard guard.** `permission_callback` enforces the catalog capability
  server-side on every call, regardless of any consumer-side gating. This is non-negotiable and
  lives in the ability itself.
- **Reads** are always safe to expose (mapped from `readonly`).
- **Writes** (create/update/delete and other mutations) must be gated by the consumer — they
  are not surfaced by default. A consumer must require an explicit opt-in before acting on them.
- **Destructive** writes (deletes, plugin activate/deactivate, theme switch, connectors,
  permalink and site-editor changes) carry a higher bar: the consumer must require an additional
  opt-in and a human confirmation before each call.
- **Dangerous** tier (T3) carries the highest bar: irreversible or code-on-disk operations that a
  consumer must gate behind an explicit, separate, per-ability opt-in on top of the write and
  destructive gates, with a human confirmation per call.

The classification (`access`, `destructive`, `idempotent`, `dangerous`) is what every consumer
keys its gating on. The concrete enforcement mechanism for the in-browser consumer — settings,
store filtering, the name-gate, and the confirmation modal — lives in the
[webmcp-adapter architecture.md](https://github.com/galatanovidiu/webmcp-adapter/blob/trunk/docs/architecture.md).

## Build tiers

| Tier | What | Consumer gating |
|---|---|---|
| **T1** | All reads (list/get) across every domain + low-risk writes (content/page create·update·trash, comment moderation, term create·update) | Reads always safe; T1 writes require the consumer's write opt-in |
| **T2** | Standard opt-in writes: media/users/terms-delete/menus/templates/fonts/settings updates, plugin activate·deactivate, theme switch, connectors register·unregister | Require the consumer's write opt-in (destructive ones also need the destructive opt-in + confirmation) |
| **T3** | **Dangerous tier (built)**: plugin/theme install·update·delete, `updates/run-update` (plugin/theme/translation only — NOT core), `settings/update-option` (deny-by-default allow-list), `privacy/generate-export` | Require a separate per-ability dangerous opt-in on top of write + destructive, plus a per-call human confirmation (see Write-gating model) |

T3 (8 abilities) is **built** behind a dangerous-tier safety pipeline. Two T3 items are
**excluded by decision**: CORE update is not in `updates/run-update` (timeout/brick risk), and
`privacy/run-erase` execution is not built (irreversible + batched) — it stays a deliberate
GAP, human-only in wp-admin. Plugin/theme **file editors** are excluded entirely (arbitrary
code edit). Links, multisite, Customizer, and classic header/background are out of scope (see
Coverage).

Build order followed: **T1 reads** (validate the whole read surface end-to-end) → **T1 safe
writes** → **T2 opt-in writes** → **T3** behind the dangerous-tier safety pipeline.

---

# Catalog by domain

Legend per entry: **R**=read **W**=write · **D!**=destructive · REST tag · Tier.
All write entries must be consumer-gated; capability is the hard guard.

## Content — namespace `content`  (post type `post` and `page`, plus generic CPT)

### Posts
- **content/list-posts** — R · wrapper `/wp/v2/posts` GET · T1. Cap: public for published; `edit_posts` for edit-context/non-public (`read_private_posts` for private). In: search, status, author, categories[], tags[], per_page, page, orderby, order, context. Out: items[], id, title, status, link, date, total, total_pages.
- **content/get-post** — R · wrapper `/wp/v2/posts/<id>` GET · T1. Cap: public for published; `read_post`/`edit_post` (object) otherwise. In: id, context, password. Out: id, title, content, excerpt, status, author, link, date, modified; plus `title_raw`, `content_raw`, `excerpt_raw` (stored block markup, only when `context=edit`).
- **content/create-post** — W · wrapper `/wp/v2/posts` POST · T1. Cap: `edit_posts`; `edit_others_posts` if author set; `publish_posts` to publish; `assign_term` per term. Not idempotent. In: title, content, excerpt, status, author, categories[], tags[], slug, date, featured_media. Out: id, link, status.
- **content/update-post** — W · wrapper `/wp/v2/posts/<id>` POST/PATCH · T1. Cap: `edit_post` (object); `edit_others_posts` if author change; `publish_posts` to publish. Covers set-status (status is a field). In: id + post fields. Out: id, link, status, modified.
- **content/trash-post** — W · wrapper `/wp/v2/posts/<id>` DELETE force=false · T1. Cap: `delete_post` (object). Not destructive (recoverable). Requires `EMPTY_TRASH_DAYS>0`. In: id. Out: id, status=trash.
- **content/delete-post** — W · D! · wrapper `/wp/v2/posts/<id>` DELETE force=true · T2 (high-sensitivity). Cap: `delete_post` (object). In: id, force. Out: deleted, previous.
- **content/list-post-revisions** — R · wrapper `/wp/v2/posts/<parent>/revisions` GET · T1. Cap: `edit_post` on parent (object). In: parent, context. Out: items[], id, parent, author, date, title, modified.
- **content/get-post-revision** — R · wrapper `/wp/v2/posts/<parent>/revisions/<id>` GET · T1. Cap: `edit_post` on parent. In: parent, id, context. Out: id, parent, content, title, excerpt, date; plus `title_raw`, `content_raw`, `excerpt_raw` (stored block markup, only when `context=edit`).
- **content/restore-post-revision** — W · **net-new** (wraps `wp_restore_post_revision()`; core fn does no cap check — integration must enforce) · T2. Cap: `edit_post` on parent. In: parent, revision_id. Out: restored, post_id, revision_id.

### Post meta  (L8 — registered `show_in_rest` keys only, via `Support/PostMetaKeys`)
- **content/list-post-meta-keys** (L8) — R · **net-new** (`get_registered_meta_keys('post', <post_type>)`) · T1. Cap: the post type's `edit_posts`. Lists the registered `show_in_rest` meta keys for a post type (key, type, single, description) so an agent knows what the post-meta abilities can touch. In: post_type (default `post`). Out: post_type, keys[].
- **content/get-post-meta** (L8) — R · **net-new** (`get_post_meta`, gated to registered `show_in_rest` keys) · T1. Cap: `edit_post` (object). In: id, keys[] (optional). Out: id, meta{}.
- **content/update-post-meta** (L8) — W · **net-new** (`update_post_meta`) · T2. Cap: `edit_post` (object) + per-key `edit_post_meta`. Writes only registered `show_in_rest` keys; rejects unknown keys; validates all keys before writing. In: id, meta{}. Out: id, meta{} (applied), edit_link.
- **content/delete-post-meta** (L8) — W · D! · **net-new** (`delete_post_meta`) · T2. Cap: `edit_post` (object) + per-key `edit_post_meta`. Removes all values for each named registered key; rejects unknown keys. In: id, keys[]. Out: id, deleted[], edit_link.

### Pages  (post type `page`, hierarchical)
- **content/list-pages**, **get-page**, **create-page**, **update-page**, **trash-page**, **delete-page** — same shapes as posts; wrappers of `/wp/v2/pages[/ <id>]`; extra fields `parent`, `menu_order`, `template`. Caps resolve to page caps (`edit_pages`, `edit_others_pages`, `publish_pages`, `delete_pages`) via `map_meta_cap`. Tiers: list/get/create/update/trash = T1, delete-page = T2 (D!, high-sensitivity).

### Custom post types  (generic family, `post_type` param)
- **content/list-post-types** — R · wrapper `/wp/v2/types` GET · T1. Discovery of `show_in_rest` post types. Cap: `edit_posts` for edit-context. Out: items[], slug, name, hierarchical, rest_base, supports, taxonomies.
- **content/list-cpt-items**, **get-cpt-item**, **create-cpt-item**, **update-cpt-item**, **delete-cpt-item** — generic CRUD keyed by `post_type`; wrappers of `/wp/v2/<rest_base>[/ <id>]`. Caps resolved per type object (`$type->cap->create_posts`/`publish_posts`/`edit_others_posts`, object-level `edit_post`/`delete_post`). create/update forward only type-agnostic fields (title, content, excerpt, status, slug, date, author). **create-cpt-item**, **update-cpt-item**, and **list-cpt-items** restrict `post_type` to **post-like** types — controller is exactly `WP_REST_Posts_Controller` (not a subclass) *and* the collection route exposes the matching handler (create/update require `POST`; list uses the readable `GET` variant) — rejecting global-styles, attachment, font-family/face, templates, blocks, menu-items, and navigation up-front with `unsupported_post_type` (400); `show_in_rest` alone is not proof a post-like route exists (L18, B5). delete collapses trash/permanent into a `force` param (D! when force=true). Tiers: reads + create/update = T1, delete = T2. create/update-cpt-item **built (L6)**.

## Taxonomies — namespace `terms`
- **terms/list-categories**, **get-category**, **create-category**, **update-category**, **delete-category** — taxonomy `category` (hierarchical). Caps: `manage_categories`/`edit_categories`/`delete_categories`/`assign_categories`; object-level `edit_term`/`delete_term`. Wrappers of `/wp/v2/categories[/ <id>]`. Terms have NO trash → delete force=true, always D! (high-sensitivity). Fields: name, slug, description, parent. Tiers: reads + create/update = T1, delete = T2.
- **terms/list-tags**, **get-tag**, **create-tag**, **update-tag**, **delete-tag** — taxonomy `post_tag` (non-hierarchical, no parent). Caps: `manage_post_tags` etc. NOTE: non-hierarchical create checks `assign_terms`, not `edit_terms`. Wrappers of `/wp/v2/tags[/ <id>]`. Tiers as categories.
- **terms/list-taxonomies** — R · wrapper `/wp/v2/taxonomies` GET · T1. Discovery of `show_in_rest` taxonomies.
- **terms/list-terms**, **get-term**, **create-term**, **update-term**, **delete-term** — generic family keyed by `taxonomy` (covers any `show_in_rest` taxonomy incl. `link_category`). Caps per taxonomy object. `parent` only for hierarchical. Wrappers of `/wp/v2/<rest_base>[/ <id>]`. Tiers: reads + create/update = T1, delete = T2.
- **terms/attach-post-terms** (L8) — W · **net-new** (`wp_set_object_terms`, append by default) · T2. Cap: taxonomy `assign_terms` + `edit_post` (object). Assigns existing terms (IDs, slugs, or names) to a post; resolves via `Support/TermResolver` and errors on a missing term — never creates terms. In: post_id, taxonomy, terms[], append (default true). Out: post_id, taxonomy, term_ids[] (resulting), edit_link.
- **terms/detach-post-terms** (L8) — W · **net-new** (`wp_remove_object_terms`) · T2. Cap: taxonomy `assign_terms` + `edit_post` (object). Removes the named terms from a post's assignments (terms themselves are not deleted; reversible). In: post_id, taxonomy, terms[]. Out: post_id, taxonomy, term_ids[] (remaining), edit_link.

## Media — namespace `media`
- **media/list-media** — R · wrapper `/wp/v2/media` GET · T1. Cap: view public; `edit_post` per attachment for edit-context. In: search, media_type, mime_type, parent[], author[], status, page, per_page, orderby, order. Out: items[], total, total_pages.
- **media/get-media** — R · wrapper `/wp/v2/media/<id>` GET · T1. Cap: view public; `edit_post` for edit-context. Out: id, title, alt_text, caption, description, source_url, media_type, mime_type, media_details, post.
- **media/get-media-file** — R · **net-new** (no core route returns bytes) · T1. Cap: as get-media. In: id, size. Out: data(base64), mime_type, filename, width, height. Note: base64 size ceiling → cap or URL fallback (impl decision).
- **media/upload-media** — W · wrapper `/wp/v2/media` POST · T2. Cap: `upload_files`; `edit_post` on parent if `post` set. Not idempotent. In: file(base64), filename, source_url, title, alt_text, caption, description, post. Out: id, source_url, media_type, mime_type. **Open: file transport** (base64 vs source_url).
- **media/update-media** — W · wrapper `/wp/v2/media/<id>` POST/PATCH · T2. Cap: `edit_post` on attachment. In: id, title, alt_text, caption, description, post. Out: id, title, alt_text, caption, description.
- **media/delete-media** — W · D! · wrapper `/wp/v2/media/<id>` DELETE · T2 (high-sensitivity). Cap: `delete_post` on attachment. Core requires force=true (no trash for media). In: id, force. Out: deleted, previous.
- **media/edit-media-image** — W · wrapper `/wp/v2/media/<id>/edit` POST · T2 (low priority). Cap: `upload_files` + `edit_post`. Image MIME only; creates a NEW attachment. In: id, src, modifiers[], rotation, x, y, width, height. Out: id, source_url.
- **media/list-image-sizes** (L8) — R · **net-new** (`wp_get_registered_image_subsizes()`) · T1. Cap: `upload_files`. Lists the configured image sub-sizes (core + theme/plugin) with width, height, crop. Reports configured sizes, not files present for an attachment. No input. Out: sizes[].
- **media/regenerate-thumbnails** (L8) — W · **net-new** (`wp_generate_attachment_metadata` + `wp_update_attachment_metadata`; loads admin includes) · T2. Cap: `upload_files` + `edit_post` (object). Rebuilds an image attachment's sub-size files from the original; non-destructive (original preserved); image MIME only. In: id. Out: id, sizes[] (regenerated), edit_link.

## Comments — namespace `comments`
- **comments/list-comments** — R · wrapper `/wp/v2/comments` GET · T1. Cap: `moderate_comments` (unrestricted/non-default status/edit-context), `edit_post` (by post), `edit_posts` (baseline). In: post[], status, type, author[], author_email, search, parent[], page, per_page, orderby, order. Out: items[], total, total_pages.
- **comments/get-comment** — R · wrapper `/wp/v2/comments/<id>` GET · T1. Cap: view → approved+readable-post else `edit_comment`/`moderate_comments`; edit-context → `moderate_comments`. Out: id, post, parent, author_name, author_email, content, status, type, date, link.
- **comments/create-comment** — W · wrapper `/wp/v2/comments` POST · T1. Cap: logged-in may comment; `moderate_comments` to set author/ip/non-default status; must read target post. Reply = same ability with `parent`. Not idempotent. In: post, parent, content, author, author_name, author_email, status. Out: id, status, link.
- **comments/update-comment** — W · wrapper `/wp/v2/comments/<id>` POST/PATCH · T1. Cap: `moderate_comments` OR `edit_comment` (object). In: id, content, author_name, author_email, date. Out: id, content, status.
- **comments/approve-comment**, **unapprove-comment**, **spam-comment**, **unspam-comment** — W · net-new framing over `/wp/v2/comments/<id>` PATCH status · T1. Cap: `moderate_comments` OR `edit_comment` (object). Not destructive. In: id. Out: id, status. (unspam/unapprove set approve/hold — no distinct core status.)
- **comments/trash-comment** — W · wrapper `/wp/v2/comments/<id>` DELETE force=false · T1. Cap: `edit_comment` (object). Recoverable (not D!). In: id. Out: trashed, previous.
- **comments/delete-comment** — W · D! · wrapper `/wp/v2/comments/<id>` DELETE force=true · T2 (high-sensitivity). Cap: `edit_comment` (object). In: id, force. Out: deleted, previous.

## Users — namespace `users`
- **users/list-users** — R · wrapper `/wp/v2/users` GET · T1. Cap: `list_users`. In: page, per_page, search, roles, capabilities, orderby, order, context. Out: users[], id, name, slug, email, roles.
- **users/get-user** — R · wrapper `/wp/v2/users/<id>` GET · T1. Cap: `edit_user(id)` for edit-context (object). Out: id, name, slug, email, roles, capabilities, registered_date.
- **users/create-user** — W · wrapper `/wp/v2/users` POST · T2. Cap: `create_users`. Password input credential-sensitive (never log). In: username, email, password, name, first_name, last_name, roles, url, locale. Out: id, username, email, roles.
- **users/update-user** — W · wrapper `/wp/v2/users/<id>` POST/PATCH · T2. Cap: `edit_user(id)`; `promote_user(id)` when `roles` sent (role folded into update, not a separate verb). In: id + profile fields + roles + password. Out: id, name, email, roles.
- **users/delete-user** — W · D! · wrapper `/wp/v2/users/<id>` DELETE force=true · T2 (high-sensitivity). Cap: `delete_user(id)`. `reassign` must be a valid different user or content is removed (data-loss). In: id, reassign, force. Out: deleted, previous.
- **users/get-current-user**, **update-current-user** — self-scoped `/wp/v2/users/me`. get T1; update T2 (`edit_user(self)`; role change still `promote_user`). Password credential-sensitive.
- **users/list-application-passwords**, **create-application-password**, **delete-application-password** — wrappers of `/wp/v2/users/<id>/application-passwords[/ <uuid>]`. Object-level caps `list_app_passwords`/`create_app_password`/`delete_app_password`. create returns plaintext password ONCE (one-time secret, never log); delete D!. Tiers: list T1, create/delete T2.

## Settings — namespace `settings`  (cap `manage_options` unless noted; Privacy = `manage_privacy_options`)
- **settings/get-general**, **update-general** — title, description, url, email, timezone, date_format, time_format, start_of_week, language. get-general reads options directly (net-new read, no REST dispatch); update-general wraps `/wp/v2/settings` for its registered subset. Changing url/email can lock out admin. Tiers: get T1, update T2.
- **settings/get-writing**, **update-writing** — default_category, default_post_format, use_smilies. get-writing reads options directly (net-new read); update-writing wraps the `/wp/v2/settings` subset. get T1, update T2.
- **settings/get-reading**, **update-reading** — show_on_front, page_on_front, page_for_posts, posts_per_page, posts_per_rss, blog_public. get-reading reads options directly (net-new read); update-reading wraps `/wp/v2/settings` for its registered keys and writes blog_public/posts_per_rss directly (blog_public affects indexing). blog_public is surfaced as a boolean (0/1); a third-party `blog_privacy_selector` storing non-binary values is not represented. get T1, update T2.
- **settings/get-discussion**, **update-discussion** — comment/notification/avatar options. **net-new** (mostly unregistered). get T1, update T2.
- **settings/get-media**, **update-media** — image size dims, thumbnail_crop, uploads_use_yearmonth_folders. **net-new**. get T1, update T2.
- **settings/get-permalinks**, **update-permalinks** — permalink_structure, category_base, tag_base. **net-new**. update MUST `flush_rewrite_rules()`. get T1, update T2.
- **settings/get-privacy**, **update-privacy** — page_for_privacy_policy. Cap `manage_privacy_options`. **net-new**. get T1, update T2.
- **settings/get-option** — R · **net-new** (`get_option`) · T1, **built (L6)**. Gated by a deny-by-default read allow-list (`Support/ReadableOptionAllowList`, scalar safe-to-read names only; secret-bearing options such as `mailserver_pass` are excluded and the `name` enum refuses anything off-list at input validation). In: name (allow-listed). Out: name, value.
- **settings/update-option** — W · D! · **net-new** (`update_option`) · **T3 (dangerous)**. Deny-by-default allow-list (`OptionAllowList`): blogname, blogdescription, timezone_string, gmt_offset, date_format, time_format, start_of_week, blog_public, posts_per_page. Refuses everything else — siteurl/home/active_plugins/template/stylesheet/role maps/db_version/etc. — so it cannot break or hijack the site. In: option, value. Out: option, value, updated.

## Connectors — namespace `connectors`  (WP 7.0 AI providers; cap `manage_options` — no dedicated cap; see Open decisions)
- **connectors/list-connectors**, **get-connector** — R · net-new (wrap `WP_Connector_Registry`) · T1. Output must NOT include API keys — only non-secret state: `configured` (exact credential presence), `authentication_method` (`none`|`api_key`), `key_source` (`env`|`constant`|`database`|`none`), and `connected` (actual connectivity: AI-provider registry status for `ai_provider`, key presence otherwise, `true` for no-auth). Derived in `Support/ConnectorState` from core (`class-wp-connector-registry.php:195`, `connectors.php:440-464`, `692-700`).
- **connectors/register-connector**, **unregister-connector** — **dropped.** `WP_Connector_Registry` is an in-memory singleton, rebuilt every request on `init` (`_wp_connectors_init` → `do_action( 'wp_connectors_init', $registry )`). A `register()`/`unregister()` call persists only for the current request; the intended registration path is a plugin re-registering on the `wp_connectors_init` action (code-only). An ability executing in one REST request cannot durably register or unregister a connector, so these were removed. Durable connector key management belongs to the setting option (e.g. `connectors_ai_anthropic_api_key`) — a `settings/update-option` concern, not a registry write.

## Appearance: Themes — namespace `themes`
- **themes/list-themes**, **get-active-theme** — R · wrapper `/wp/v2/themes` GET · T1. Cap: `switch_themes` (read also via `edit_theme_options`).
- **themes/switch-theme** — W · D! · net-new (`switch_theme()`) · T2. Cap: `switch_themes`. Changes whole front-end; flag confirmation. In: stylesheet. Out: success, active_theme.
- **themes/install-theme**, **delete-theme** — W · D! · net-new · **T3 (dangerous)**. Caps `install_themes` / `delete_themes`. install takes a wp.org directory slug only (`SourceValidator::slug()`, `^[a-z0-9-]+$` — no ZIP/URL/path); writes code to disk via `FilesystemGuard::ensureDirect()` + `UpgraderLock`. delete removes the theme directory.
- **themes/search-directory** (L7) — R · net-new (`themes_api()` `query_themes`) · **outbound WordPress.org HTTP call** · T1. Cap: `install_themes` (gated on the capability needed to act on a result; pairs with install-theme). Returns shaped matches (slug, name, version, rating, preview_url, author). Reads remote data; changes nothing.

## Appearance: Site editor data — namespace `templates`  (cap `edit_theme_options`, object-level via post-type cap maps)
- **templates/list-templates**, **get-template**, **update-template** — `wp_template` + `wp_template_part` (`post_type` param). Wrappers of `/wp/v2/templates` + `/wp/v2/template-parts`. update creates a DB override (D! — changes layout, high blast radius; flag confirmation). Tiers: reads T1, update T2.
- **templates/create-template** (L7) — W · wrapper `/wp/v2/templates` + `/wp/v2/template-parts` POST (`post_type` param) · T2. Cap: `edit_theme_options`. Non-destructive (adds a new record); returns the `theme//slug` id, status, and the Site Editor `edit_link`. Content field expects Gutenberg block markup.
- **templates/delete-template** (L7) — W · D! · wrapper DELETE (`force=true`, `post_type` param) · T2. Cap: `edit_theme_options`. Reverts a customized theme template to its theme default, or removes a user-created custom template; templates that exist only as theme files cannot be deleted (REST `source==='custom'` rule). Permanent.
- **templates/lookup-template** (L7) — R · **pure core** (`get_template_hierarchy()` + `get_block_templates()`) · T1. Cap: `edit_theme_options`. Resolves which template renders a given slug: returns the template hierarchy and the first existing template's `theme//slug` id + title. No network.
- **templates/list-patterns** — R · wrapper `/wp/v2/block-patterns/patterns` GET · T1. Cap: `edit_posts`. Registered patterns (read-only registry).
- **templates/list-block-pattern-categories** (L7) — R · wrapper `/wp/v2/block-patterns/categories` GET · T1. Cap: `edit_posts`. The categories that group block patterns.
- **templates/list-block-types** (L7) — R · wrapper `/wp/v2/block-types` GET · T1. Cap: `edit_posts`. Registered block types, shaped to name/title/category/is_dynamic — the blocks an agent can compose into block markup.
- **templates/list-synced-patterns** (L7) — R · wrapper `/wp/v2/blocks` GET (shaped + paginated) · T1. Cap: `wp_block` `edit_posts`. The user synced-pattern library (`wp_block` posts), distinct from the read-only `list-patterns` registry.
- **templates/get-pattern**, **create-pattern** — user patterns = `wp_block` (`/wp/v2/blocks`). get T1 (`read_post`). create T2 (`edit_posts` + `wp_block` publish cap — exact cap NEEDS VERIFICATION). Lower risk (creates a post).
- **templates/get-global-styles**, **update-global-styles** — `wp_global_styles` for active theme (user overrides). Wrapper `/wp/v2/global-styles/<id>`. get T1 (`read_post`); update T2 (`edit_theme_options`, plus `edit_css` if custom CSS). D! (site-wide appearance). In: id, settings, styles, title.
- **templates/get-theme-styles** (L7) — R · wrapper `/wp/v2/global-styles/themes/<stylesheet>` GET · T1. Cap: `edit_theme_options`. The theme's baseline global styles from `theme.json` (design tokens), distinct from `get-global-styles` (user overrides). `stylesheet` defaults to the active theme.
- **templates/list-global-style-variations** (L7) — R · wrapper `/wp/v2/global-styles/themes/<stylesheet>/variations` GET · T1. Cap: `edit_theme_options`. The theme's style variations (alternate palettes/type sets). `stylesheet` defaults to the active theme.

## Appearance: Navigation menus — namespace `menus`  (cap `edit_theme_options`)
- **Block** (`wp_navigation`): **list-navigation**, **get-navigation**, **create-navigation**, **update-navigation** — wrappers of `/wp/v2/navigation[/ <id>]`; items are serialized blocks inside `content`. Reads T1, create/update T2.
- **Classic** (`nav_menu` terms): **list-classic-menus**, **get-classic-menu**, **create-classic-menu**, **update-classic-menu**, **assign-menu-location** — wrappers of `/wp/v2/menus[/ <id>]` (`/wp/v2/menu-locations` GET-only). Reads T1, writes T2.
- **Classic items**: **list-menu-items**, **create-menu-item**, **update-menu-item**, **delete-menu-item** — wrappers of `/wp/v2/menu-items[/ <id>]`. Fields: title, url, object, object_id, type, parent, menu_order. Reads T1, writes T2. **delete-menu-item is D! (destructive):** menu items have **no Trash** — the REST controller returns HTTP 501 on `force=false`, so delete is always permanent (`force=true`). Cap: object-level `delete_post`.
- **menus/delete-classic-menu** (L7) — W · D! · wrapper `/wp/v2/menus/<id>` DELETE (`force=true`) · T2. Cap: object-level `delete_term` (→ `edit_theme_options` for `nav_menu`). Deletes a whole classic menu (the `nav_menu` term) and all its items; classic menus have **no Trash**, so permanent.
- **menus/delete-navigation** (L7) — W · D! · wrapper `/wp/v2/navigation/<id>` DELETE · T2. Cap: object-level `delete_post` (→ `edit_theme_options` for `wp_navigation`). Deletes a block navigation menu; `wp_navigation` **supports Trash**, so `force` is optional (default = trash, recoverable). Site-wide blast radius.

## Appearance: Fonts — namespace `fonts`  (cap `edit_theme_options`)
- **fonts/list-font-families**, **get-font-family** — R · wrappers of `/wp/v2/font-families[/ <id>]` GET · T1.
- **fonts/list-font-collections** — R · wrapper `/wp/v2/font-collections[/ <slug>]` GET · T1. Remote installable-font catalogs.
- **fonts/install-font-family** — W · wrapper `/wp/v2/font-families` POST · T2. Creates a `wp_font_family` post (+ font-face files; same file-transport open question as media). Lower risk (not code).
- **fonts/delete-font-family** — W · D! · wrapper `/wp/v2/font-families/<id>` DELETE (`force=true`) · T2. Cap `edit_theme_options`. Deletes the `wp_font_family` post + its font-face asset files; may break typography that references it. (Named `delete-` to match the catalog's other delete abilities, not `remove-`.)

## Plugins — namespace `plugins`
- **plugins/list-plugins**, **get-plugin** — R · wrappers of `/wp/v2/plugins[/ <plugin>]` GET · T1. Cap: `activate_plugins`. `plugin` = relative file path without `.php`.
- **plugins/activate-plugin**, **deactivate-plugin** — W · D! · wrappers of `/wp/v2/plugins/<plugin>` POST status · T2. Cap: `activate_plugins` + object-level `activate_plugin`/`deactivate_plugin`. Activate runs plugin code (flag confirmation).
- **plugins/install-plugin**, **update-plugin**, **delete-plugin** — W · D! · **T3 (dangerous)**. Caps `install_plugins`/`update_plugins`/`delete_plugins`. install takes a wp.org directory slug only (`SourceValidator::slug()` — no ZIP/URL/path); update wraps Plugin_Upgrader; delete removes the plugin (must be inactive). All write/run code on disk via `FilesystemGuard::ensureDirect()` + `UpgraderLock`/`UpgradeRunner`. Updates still run plugin-author upgrade code (DB migrations etc.) by design.
- **plugins/search-directory** (L7) — R · net-new (`plugins_api()` `query_plugins`) · **outbound WordPress.org HTTP call** · T1. Cap: `install_plugins` (gated on the capability needed to act on a result; pairs with install-plugin). Returns shaped matches (slug, name, version, rating, active_installs, short_description, author). Reads remote data; changes nothing.

## Updates — namespace `updates`
- **updates/list-available-updates** — R · **net-new** (`get_core_updates()`, `get_plugin_updates()`, `get_theme_updates()`, `wp_get_translation_updates()`) · T1. Cap: `update_core` (union with `update_plugins`/`update_themes`). In: type[core|plugins|themes|translations|all]. Out: core/plugins/themes/translations arrays.
- **updates/run-update** — W · D! · net-new (Plugin/Theme/Language_Pack Upgrader) · **T3 (dangerous)**. Cap per type: `update_plugins`/`update_themes` (translations follow). **Plugin/theme/translation only — CORE update is excluded** (timeout/brick risk). Runs via `UpgradeRunner::withLock()` (FS guard → `UpgraderLock` → quiet `Automatic_Upgrader_Skin` → release). In: type, target. Out: type, target, success, new_version.

## Tools: Import/Export — namespace `tools`
- **tools/list-importers** — R · net-new (`get_importers()`) · T1. Cap: `import`. Out: importers[]{id,name,description,installed,action_url}.
- **tools/run-import** — W · D! · **deferred (UI-only)**. Multi-step interactive flow; WP importer is a separate plugin, not core. Only list-importers is ability-shaped today.
- **tools/export-content** — R (produces a file, no mutation) · net-new (`export_wp()`; NOT the Site-Editor ZIP export) · T1. Cap: `export`. Output transport (inline vs file URL) is an impl decision. In: content, post_type, start_date, end_date, author, category, status.

## Tools: Site Health — namespace `site-health`  (cap `view_site_health_checks`)
- **site-health/get-status** — R · wrapper `/wp-site-health/v1/` · T1. Overall good/recommended/critical (may need a helper wrap).
- **site-health/run-tests** — R · wrapper `/wp-site-health/v1/tests/<test>` GET · T1. Per-test results (per-test filter `site_health_test_rest_capability_{$check}`). Some tests do live loopback/HTTP/cron checks (no data mutation).
- **site-health/get-info** — R · net-new (`WP_Debug_Data::debug_data()`) · T1. Redact core-flagged `private` fields before returning.

## Tools: Personal data — namespace `privacy`
- **privacy/create-export-request** — W · net-new (`wp_create_user_request()`) · T2. Cap: `export_others_personal_data`. Creation not yet destructive. In: email, send_confirmation_email. Out: request_id, status, action_name.
- **privacy/create-erase-request** — W · net-new · T2 (high-sensitivity). Cap: `erase_others_personal_data`. Creation not destructive; the erase EXECUTION is.
- **privacy/list-export-requests**, **list-erase-requests** — R · net-new (query `user_request` posts) · T1.
- **privacy/confirm-request**, **cancel-request** — W · net-new · T2. Cap `manage_options` (NEEDS VERIFICATION — no dedicated cap). cancel deletes the request record (D!), not user data.
- **privacy/generate-export** — W · D! · net-new · **T3 (dangerous)**. Cap: `export_others_personal_data`. Builds the export archive for a confirmed request (finalized once by core; never returns the file path or URL). In: request_id. Out: request_id, status, generated.
- **GAP — erase execution (`privacy/run-erase`)** still NOT built (deliberate). Erase execution is irreversible and batched (AJAX-paginated); unsafe for a single agent-driven call. It stays human-only in wp-admin.

## Dashboard — namespace `dashboard`  (read-only, composed)
- **dashboard/get-at-a-glance** — R · net-new (`wp_count_posts()`, `wp_count_comments()`) · T1. Cap: `edit_posts`. Composed — implement via other abilities/counters.
- **dashboard/get-activity** — R · net-new · T1. Cap: `edit_posts` (+ moderation for pending). In: number. Composed of content + comments.
- **dashboard/get-drafts** — R · net-new · T1. Cap: `edit_posts`. ≈ `content/list-posts?status=draft&author=current`.

## Search — namespace `search`  (L7)
- **search/search-content** (L7) — R · wrapper `/wp/v2/search` GET (shaped) · T1. Cap: `edit_posts` (the core route is public; this catalog ability hardens it to an authenticated authoring tool). WordPress's unified search across object types (posts/pages, terms, post formats); returns shaped matches (id, title, url, type, subtype). Use it to find content when the id is unknown.

---

# Coverage & deferrals

**Covered (13 domains):** Content (posts/pages/CPT/revisions), Taxonomies, Media, Comments,
Users (+ app passwords), Settings (all 7 screens + generic option), Connectors, Appearance
(themes, site-editor data, menus, fonts), Plugins, Updates, Tools (import/export, site
health, privacy), Dashboard, Search (L7).

**Authoring-context + completeness gaps closed (loop L7, 13 abilities):** six authoring-context
reads (`templates/list-block-types`, `list-block-pattern-categories`, `list-synced-patterns`,
`get-theme-styles`, `list-global-style-variations`, and the new `search/search-content`),
four write/destructive completeness gaps (`templates/create-template`, `templates/delete-template`,
`menus/delete-classic-menu`, `menus/delete-navigation`), and three discovery abilities
(`templates/lookup-template` plus `plugins/search-directory` and `themes/search-directory`, which
make an outbound WordPress.org call and are gated on `install_plugins`/`install_themes`).

**Per-object completeness gaps closed (loop L8, 9 abilities):** post-meta CRUD
(`content/get-post-meta`, `content/update-post-meta`, `content/delete-post-meta`,
`content/list-post-meta-keys`) — all gated to registered `show_in_rest` keys via
`Support/PostMetaKeys`; post↔term assignment (`terms/attach-post-terms`,
`terms/detach-post-terms`) — resolve existing terms via `Support/TermResolver`, never create; the
`menus/list-menu-locations` read (companion to `assign-menu-location`); and the image-size
abilities (`media/list-image-sizes`, `media/regenerate-thumbnails`).

**Built behind the dangerous-tier pipeline (loop L5):** the T3 dangerous tier — plugin/theme
install·update·delete, `updates/run-update` (plugin/theme/translation only), `settings/update-option`
(allow-list), `privacy/generate-export`. **Still deferred (deliberate):** CORE update
(excluded from `updates/run-update`) and privacy erase-execution (`privacy/run-erase`,
human-only). `tools/run-import` deferred as UI-only.

**Out of scope (no entry):**
- **Links / link manager** — legacy, disabled by default since 3.5.
- **Multisite / network** — `network.php`, `ms-delete-site.php`, My Sites.
- **Customizer, classic custom header/background** — UI-only live preview, no request/response shape.
- **Plugin/theme file editors** — excluded entirely (arbitrary code edit = security risk).
- **Block editor canvas** — interaction surface, not an ability (the underlying post/template data is cataloged instead).

**Beyond `wordpress-mcp`:** the Automattic `wordpress-mcp` plugin (now superseded by
`WordPress/mcp-adapter`) covers posts/pages/media/users/general-settings/CPT/site-info only.
This catalog additionally covers comments, taxonomies, plugins, themes, updates, menus,
site-editor data, fonts, connectors, site health, privacy, dashboard, and per-screen
settings.

# Open decisions (resolve in implementation loops)

1. **Dangerous tier (T3) safety pipeline** — RESOLVED (loop L5). The catalog ships the server-side
   guards in `includes/Support/`: `FilesystemGuard` (direct-or-fail), `SourceValidator`
   (wp.org-slug-only source), `OptionAllowList` (deny-by-default for `settings/update-option`),
   `UpgraderLock`, and `UpgradeRunner`. Every T3 ability carries `dangerous: true` in
   `meta.annotations` so a consumer can detect it. How a consumer enforces the extra opt-in and
   per-call confirmation is the consumer's concern (for the in-browser adapter, see its docs).
2. **File-upload transport** (cross-cutting: media upload, font faces, run-import) — base64 inline vs `source_url` sideload vs attachment-id. Decide once.
3. **Large-output transport** — `media/get-media-file` (base64) and `tools/export-content` (WXR) can be large; need a size cap or file-URL convention.
4. **Generic option abilities** — RESOLVED (L6). `settings/get-option` exposure gating uses a deny-by-default read allow-list (`Support/ReadableOptionAllowList`, scalar safe-to-read names only); `settings/update-option` uses the write allow-list (`Support/OptionAllowList`) + the strictest consumer gate (dangerous tier).
5. **Connectors capability** — no dedicated cap exists; registry methods are unguarded PHP; only the admin screen checks `manage_options`. Decide: keep `manage_options` or register `manage_connectors` (these handle provider API keys).
6. **Privacy confirm/cancel caps** — no dedicated cap; verify against the requests list-table handler before locking `manage_options`.
7. **`wp_block` (pattern) caps** — `capability_type => 'block'`; confirm resolved create/publish cap names at implementation.
8. **Pattern namespace** — keep user-pattern CRUD under `templates` or split a `patterns` namespace.
9. **Comment status verbs** — confirm explicit approve/unapprove/spam/unspam verbs (vs a single set-status); unspam/unapprove map to approve/hold.
10. **CPT / custom-term trash vs delete** — generic families collapse trash+delete into a `force` param; decide if symmetry (split) is wanted.
11. **Discovery abilities** (`list-post-types`, `list-taxonomies`) — added so generic families know valid type values; confirm the small scope addition.
12. **Application passwords** — included list/create/delete; single-get and update-name omitted as lower value (exist if needed).

# Build order (summary)

1. **T1 reads** — every `list`/`get`, site-health, dashboard, settings reads, updates list-available, discovery abilities. Validates the full read surface end-to-end.
2. **T1 safe writes** — content/page create·update·trash, comment moderation, term create·update.
3. **T2 opt-in writes** — gated by the consumer's write opt-in.
4. **T3** — built (loop L5) behind the dangerous-tier safety pipeline (decision 1): the catalog's server-side guards plus the `dangerous` annotation; the consumer adds the per-ability opt-in and per-call confirmation. Core update and privacy erase-execution excluded by decision.
