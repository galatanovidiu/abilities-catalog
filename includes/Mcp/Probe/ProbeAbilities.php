<?php
/**
 * Throwaway probe abilities for mcp-adapter PR #260.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Probe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers one ability per `_meta` / `annotations` case PR #260 describes.
 *
 * These exist only to exercise the adapter's emit paths end-to-end; they carry no
 * product value and this file is not meant to reach trunk. Each ability is named
 * after the scenario it covers in the PR's testing instructions, so a wire capture
 * maps back to a numbered case without a lookup table.
 *
 * Registration is direct rather than through {@see \GalatanOvidiu\AbilitiesCatalog\Registry}:
 * the Registry auto-discovers everything under `includes/Abilities/`, which would
 * publish these into the real catalog whether or not the probe is on. Registering
 * here keeps them behind the `ABILITIES_CATALOG_META_PROBE` constant.
 *
 * Every ability is a read, so the Registry's destructive-annotation gate never
 * applies and no ability here can change site state.
 */
final class ProbeAbilities {

	/**
	 * Ability category slug shared by every probe ability.
	 */
	public const CATEGORY = 'og-probe-meta';

	/**
	 * Ability name prefix. Also the MCP tool-name prefix after sanitization.
	 */
	public const PREFIX = 'probe-meta/';

	/**
	 * A 20x20 opaque PNG (bordered blue/white check), used wherever a scenario
	 * needs real binary content.
	 *
	 * Deliberately not a 1x1 transparent pixel: that renders as a blank tile, so a
	 * client that failed to decode it looks identical to one that decoded it fine.
	 */
	private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAALklEQVR42mOQowAwALFi6WwI+oAE4IK4xAdcM/EakMWHtuYhGlWj8Twy4plsAACXI6A7WPRfEwAAAABJRU5ErkJggg==';

	/**
	 * Hooks category and ability registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'registerAbilities' ) );
	}

	/**
	 * Registers the probe category.
	 *
	 * @return void
	 */
	public static function registerCategory(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Probe: _meta and annotations',
				'description' => 'Throwaway abilities that exercise mcp-adapter PR #260 emit paths.',
			)
		);
	}

	/**
	 * Ability names exposed as MCP resources.
	 *
	 * @return list<string>
	 */
	public static function resourceNames(): array {
		return array(
			self::PREFIX . 'resource-meta-object',
			self::PREFIX . 'resource-meta-list',
			self::PREFIX . 'resource-blob-only',
			self::PREFIX . 'ui-posts-list',
		);
	}

	/**
	 * Ability names exposed as MCP tools.
	 *
	 * @return list<string>
	 */
	public static function toolNames(): array {
		return array(
			self::PREFIX . 'tool-meta-object',
			self::PREFIX . 'tool-meta-list',
			self::PREFIX . 'tool-embedded-nested',
			self::PREFIX . 'tool-embedded-flat',
			self::PREFIX . 'tool-image',
			self::PREFIX . 'tool-no-type',
			self::PREFIX . 'tool-annotations-coerced',
			self::PREFIX . 'tool-result-priority-string',
			self::PREFIX . 'list-posts',
		);
	}

	/**
	 * Ability names exposed as MCP prompts.
	 *
	 * @return list<string>
	 */
	public static function promptNames(): array {
		return array(
			self::PREFIX . 'prompt-invalid-embedded',
		);
	}

	/**
	 * Registers every probe ability.
	 *
	 * @return void
	 */
	public static function registerAbilities(): void {
		foreach ( self::definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * The probe ability definitions, keyed by ability name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function definitions(): array {
		return self::resourceDefinitions()
			+ self::toolDefinitions()
			+ self::promptDefinitions()
			+ self::appDefinitions();
	}

	/**
	 * A real MCP App (SEP-1865): a predeclared UI template plus the tool bound to it.
	 *
	 * This is deliberately NOT the shape the other probes use. SEP-1865 models UI as a
	 * *predeclared* `ui://` resource that a tool references from its descriptor; the
	 * embedded-resource form — returning the resource inside the tool result — is the
	 * MCP-UI approach the SEP considered and explicitly deferred. A host that supports
	 * MCP Apps therefore renders this pair and shows an embedded resource as plain text.
	 *
	 * The contract, all three parts required:
	 *
	 * 1. the resource declares `mimeType: text/html;profile=mcp-app` on its descriptor,
	 *    so a host can find and prefetch the template from `resources/list`;
	 * 2. the tool declares `_meta.ui.resourceUri` pointing at that URI — which is exactly
	 *    the descriptor `_meta` passthrough PR #260 normalizes;
	 * 3. the tool result carries a meaningful text `content` fallback plus the data, so a
	 *    host without MCP Apps support still gets something readable.
	 *
	 * The host then fetches the template itself, renders it in a sandboxed iframe, and
	 * pushes the result in over postMessage.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function appDefinitions(): array {
		return array(

			self::PREFIX . 'ui-posts-list' => self::ability(
				'Posts list UI template',
				'MCP Apps UI template that renders the result of the list-posts tool.',
				static fn (): array => array(
					array(
						'uri'      => 'ui://abilities-catalog/posts-list',
						'mimeType' => 'text/html;profile=mcp-app',
						'text'     => self::postsListTemplate(),
					),
				),
				array(
					'mcp' => array(
						'uri'      => 'ui://abilities-catalog/posts-list',
						// Required by the spec, and required in practice: a host filters
						// resources/list by this to discover UI templates.
						'mimeType' => 'text/html;profile=mcp-app',
					),
				),
				'resource'
			),

			self::PREFIX . 'list-posts'    => self::ability(
				'List posts',
				'Lists recent posts. Renders as an interactive list in hosts that support MCP Apps.',
				static function (): array {
					$posts = get_posts(
						array(
							'numberposts' => 20,
							'post_status' => array( 'publish', 'draft', 'pending', 'future' ),
						)
					);

					$rows = array();
					foreach ( $posts as $post ) {
						$rows[] = array(
							'id'      => $post->ID,
							'title'   => get_the_title( $post ),
							'status'  => $post->post_status,
							'date'    => get_the_date( 'Y-m-d', $post ),
							'author'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
							'link'    => (string) get_permalink( $post ),
							'excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 24 ),
						);
					}

					return array(
						'count' => count( $rows ),
						'posts' => $rows,
					);
				},
				array(
					'mcp' => array(
						// The binding. `ui.resourceUri` is nested, not a flat "ui/resourceUri"
						// key — the flat form appears in early blog posts and is not what the
						// specification settled on.
						'_meta' => array(
							'ui' => array(
								'resourceUri' => 'ui://abilities-catalog/posts-list',
							),
						),
					),
				)
			),
		);
	}

	/**
	 * Resource-typed probe abilities (PR scenarios 1-3).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function resourceDefinitions(): array {
		return array(

			// Scenario 1: `_meta` on resource contents must survive to the wire.
			self::PREFIX . 'resource-meta-object' => self::ability(
				'Probe 1: resource _meta object',
				'Returns one content item whose `_meta` is a JSON object. Expect `result.contents[0]._meta` present.',
				static function (): array {
					return array(
						array(
							'uri'      => 'ui://probe/app',
							'mimeType' => 'text/html;profile=mcp-app',
							'text'     => '<!doctype html><title>Probe 1</title><p>scenario 1</p>',
							'_meta'    => array(
								'ui' => array(
									'prefersBorder' => true,
								),
							),
						),
					);
				},
				array( 'mcp' => array( 'uri' => 'ui://probe/app' ) ),
				'resource'
			),

			// Scenario 2: a list-shaped `_meta` cannot serialize as a JSON object, so
			// the key must be dropped (not emitted as a JSON array) and logged.
			self::PREFIX . 'resource-meta-list'   => self::ability(
				'Probe 2: resource _meta list',
				'Returns one content item whose `_meta` is a list. Expect the `_meta` key absent and a logged warning.',
				static function (): array {
					return array(
						array(
							'uri'      => 'ui://probe/bad-meta',
							'mimeType' => 'text/html;profile=mcp-app',
							'text'     => '<!doctype html><title>Probe 2</title><p>scenario 2</p>',
							'_meta'    => array( 'a', 'b' ),
						),
					);
				},
				array( 'mcp' => array( 'uri' => 'ui://probe/bad-meta' ) ),
				'resource'
			),

			// Scenario 3: a first item carrying only `blob` identifies resource
			// contents, so each item becomes a BlobResourceContents rather than the
			// whole list being JSON-encoded into one text block.
			self::PREFIX . 'resource-blob-only'   => self::ability(
				'Probe 3: blob-only resource contents',
				'Returns items carrying only `blob` and `mimeType`. Expect one BlobResourceContents per item.',
				static function (): array {
					return array(
						array(
							'blob'     => self::PNG_BASE64,
							'mimeType' => 'image/png',
						),
						array(
							'blob'     => self::PNG_BASE64,
							'mimeType' => 'image/png',
						),
					);
				},
				array(
					'mcp' => array(
						'uri' => 'probe://blob',
						// Declared so `resources/list` tells a client this is an image
						// before it ever reads the resource.
						'mimeType' => 'image/png',
					),
				),
				'resource'
			),
		);
	}

	/**
	 * Tool-typed probe abilities (PR scenarios 4-9).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function toolDefinitions(): array {
		return array(

			// Scenario 4a: a descriptor `_meta` that is an object reaches tools/list.
			self::PREFIX . 'tool-meta-object'            => self::ability(
				'Probe 4a: tool descriptor _meta object',
				'Declares `meta.mcp._meta` as an object. Expect it on this tool in tools/list.',
				static fn (): array => array( 'ok' => true ),
				array(
					'mcp' => array(
						'type'  => 'tool',
						'_meta' => array(
							'com.example/hint' => 'descriptor-meta-object',
						),
					),
				)
			),

			// Scenario 4b: a descriptor `_meta` that is a list is omitted entirely.
			self::PREFIX . 'tool-meta-list'              => self::ability(
				'Probe 4b: tool descriptor _meta list',
				'Declares `meta.mcp._meta` as a list. Expect the key omitted from this tool in tools/list.',
				static fn (): array => array( 'ok' => true ),
				array(
					'mcp' => array(
						'type'  => 'tool',
						'_meta' => array( 'a', 'b' ),
					),
				)
			),

			// Scenario 5: the nested form addresses both levels — outer `_meta` on the
			// content block, inner `_meta` on the resource contents.
			self::PREFIX . 'tool-embedded-nested'        => self::ability(
				'Probe 5: nested embedded resource',
				'Returns the nested embedded-resource shape. Expect each `_meta` on its own level.',
				static function (): array {
					return array(
						'type'        => 'resource',
						'resource'    => array(
							'uri'      => 'ui://probe/nested',
							'mimeType' => 'text/html;profile=mcp-app',
							'text'     => '<!doctype html><title>Probe 5</title><p>nested</p>',
							'_meta'    => array( 'level' => 'contents' ),
						),
						'annotations' => array(
							'audience' => array( 'user' ),
						),
						'_meta'       => array( 'level' => 'block' ),
					);
				}
			),

			// Scenario 6: strip `type` from the flat form and what remains is a
			// ResourceContents literal, so its `_meta` describes the contents while
			// `annotations` still describes the block.
			self::PREFIX . 'tool-embedded-flat'          => self::ability(
				'Probe 6: flat embedded resource',
				'Returns the flat embedded-resource shape. Expect `_meta` on the contents and `annotations` on the block.',
				static function (): array {
					return array(
						'type'        => 'resource',
						'uri'         => 'ui://probe/flat',
						'mimeType'    => 'text/html;profile=mcp-app',
						'text'        => '<!doctype html><title>Probe 6</title><p>flat</p>',
						'_meta'       => array( 'level' => 'contents-from-flat' ),
						'annotations' => array(
							'audience' => array( 'assistant' ),
							'priority' => 0.4,
						),
					);
				}
			),

			// Scenario 7: an image result carries the `annotations` and `_meta`
			// written beside it onto the content block.
			self::PREFIX . 'tool-image'                  => self::ability(
				'Probe 7: image with sibling annotations and _meta',
				'Returns a `type: image` result with sibling `annotations` and `_meta`. Expect both on the image block.',
				static function (): array {
					return array(
						'type'        => 'image',
						// `results`, not `data`: the handler's image branch is guarded on
						// `results` and base64-encodes it itself, so it takes raw bytes.
						// A `data` key holding base64 (the MCP wire shape) misses the
						// branch entirely and falls through to the generic path.
						'results'     => base64_decode( self::PNG_BASE64 ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Probe fixture.
						'mimeType'    => 'image/png',
						'annotations' => array(
							'audience' => array( 'user' ),
							'priority' => 0.9,
						),
						'_meta'       => array( 'com.example/source' => 'probe-7' ),
					);
				}
			),

			// Scenario 8: with no `type` key the generic path returns the result
			// verbatim, so `annotations` and `_meta` stay tool data and are never
			// lifted onto the content block.
			self::PREFIX . 'tool-no-type'                => self::ability(
				'Probe 8: no type key',
				'Returns a result with no `type` but with `annotations` and `_meta` keys. Expect them left inside structuredContent.',
				static function (): array {
					return array(
						'report'      => 'scenario 8',
						'annotations' => array(
							'audience' => array( 'user' ),
						),
						'_meta'       => array( 'level' => 'tool-data' ),
					);
				}
			),

			// Scenario 9a: WordPress hands back stored scalars as strings, so a hint
			// written as '1' must coerce to true rather than cost the registration.
			self::PREFIX . 'tool-annotations-coerced'    => self::ability(
				'Probe 9a: string tool annotations',
				'Declares `meta.annotations.readOnlyHint` as the string "1". Expect the tool to register and emit `readOnlyHint: true`.',
				static fn (): array => array( 'ok' => true ),
				array(
					'annotations' => array(
						'readOnlyHint' => '1',
					),
				)
			),

			// Scenario 9b: the same coercion on a result's content annotations, where
			// Annotations::priority is declared a float.
			self::PREFIX . 'tool-result-priority-string' => self::ability(
				'Probe 9b: string priority on a result',
				'Returns `annotations.priority` as the string "0.5". Expect the emitted priority to be the float 0.5.',
				static function (): array {
					return array(
						'type'        => 'resource',
						'uri'         => 'ui://probe/priority',
						'mimeType'    => 'text/plain',
						'text'        => 'scenario 9b',
						'annotations' => array(
							'priority' => '0.5',
							'audience' => array( 'user' ),
						),
					);
				}
			),
		);
	}

	/**
	 * Prompt-typed probe abilities (PR scenario 10).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function promptDefinitions(): array {
		return array(

			// Scenario 10: a block the schema DTOs refuse (embedded contents with no
			// `uri`) is delivered as JSON in a text block and logged, leaving the
			// surrounding messages as written.
			self::PREFIX . 'prompt-invalid-embedded' => self::ability(
				'Probe 10: prompt message with an invalid embedded resource',
				'Returns three messages; the middle one embeds a resource with no `uri`. Expect it rendered as a text block, the others untouched.',
				static function (): array {
					return array(
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => array(
									'type' => 'text',
									'text' => 'Message 1: valid, expect unchanged.',
								),
							),
							array(
								'role'    => 'user',
								'content' => array(
									'type'     => 'resource',
									'resource' => array(
										// No `uri` — the DTOs refuse this.
										'mimeType' => 'text/plain',
										'text'     => 'Message 2: invalid embedded resource.',
									),
								),
							),
							array(
								'role'    => 'assistant',
								'content' => array(
									'type' => 'text',
									'text' => 'Message 3: valid, expect unchanged.',
								),
							),
						),
					);
				},
				array( 'mcp' => array( 'type' => 'prompt' ) ),
				'prompt'
			),
		);
	}

	/**
	 * The MCP App template rendered for `list-posts`.
	 *
	 * Everything is inline. The view runs in a sandboxed iframe, so a CDN script tag or
	 * an external stylesheet is blocked — the SDK-loading examples in the spec's prose do
	 * not survive the security model they are documented beside.
	 *
	 * The handshake is the whole contract: send `ui/initialize`, answer the result with a
	 * `ui/notifications/initialized` notification, then wait for
	 * `ui/notifications/tool-result` and render `params.structuredContent`. The host sends
	 * nothing until initialize completes, so a view that renders eagerly shows an empty
	 * frame forever.
	 *
	 * @return string The template HTML.
	 */
	private static function postsListTemplate(): string {
		return <<<'HTML'
<!doctype html>
<meta charset="utf-8">
<title>Posts</title>
<style>
  :root { color-scheme: light dark; }
  body {
    margin: 0; padding: 12px;
    font: 13px/1.5 var(--font-family-base, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif);
    color: var(--color-text-primary, #16161d);
    background: var(--color-background-primary, transparent);
  }
  h1 { font-size: 14px; margin: 0 0 10px; font-weight: 600; }
  h1 span { font-weight: 400; opacity: .55; }
  table { border-collapse: collapse; width: 100%; }
  th, td {
    text-align: left; padding: 7px 10px;
    border-bottom: 1px solid var(--color-border-primary, rgba(128,128,128,.22));
    vertical-align: top;
  }
  th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; opacity: .55; font-weight: 600; }
  tbody tr:hover { background: var(--color-background-secondary, rgba(128,128,128,.06)); }
  td.title { font-weight: 500; }
  td.num { text-align: right; opacity: .5; font-variant-numeric: tabular-nums; }
  td.date { white-space: nowrap; opacity: .7; font-variant-numeric: tabular-nums; }
  a { color: inherit; text-decoration: none; }
  a:hover { text-decoration: underline; }
  .excerpt { display: block; font-weight: 400; opacity: .6; margin-top: 2px; }
  .badge {
    font-size: 10px; text-transform: uppercase; letter-spacing: .04em;
    padding: 2px 7px; border-radius: 999px; border: 1px solid currentColor;
    opacity: .8; white-space: nowrap;
  }
  .publish { color: #1a7f37; } .draft { color: #9a6700; }
  .pending { color: #8250df; } .future { color: #0969da; }
  @media (prefers-color-scheme: dark) {
    .publish { color: #3fb950; } .draft { color: #d29922; }
    .pending { color: #a371f7; } .future { color: #58a6ff; }
  }
  #diag {
    margin-top: 14px; padding: 10px 12px; font-size: 12px;
    border: 1px dashed var(--color-border-primary, rgba(128,128,128,.35));
    border-radius: 8px; opacity: .85;
  }
  #diag b { font-weight: 600; }
  #diag ol { margin: 6px 0 0; padding-left: 18px; }
  #diag code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; }
  #diag.ok { display: none; }
</style>

<div id="root"></div>
<div id="diag"><b>MCP App view: waiting for host…</b><ol id="log"></ol></div>

<script>
(function () {
  var nextId = 1, pending = {}, rendered = false, log = [];

  function note(text) {
    log.push(text);
    var ol = document.getElementById('log');
    if (!ol) { return; }
    ol.innerHTML = log.map(function (l) { return '<li><code>' + esc(l) + '</code></li>'; }).join('');
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function send(msg) {
    // Some hosts hand the View a MessagePort instead of using the parent window.
    if (window.__mcpPort) { window.__mcpPort.postMessage(msg); return; }
    window.parent.postMessage(msg, '*');
  }

  function request(method, params) {
    var id = nextId++;
    return new Promise(function (resolve, reject) {
      pending[id] = { resolve: resolve, reject: reject };
      send({ jsonrpc: '2.0', id: id, method: method, params: params || {} });
    });
  }

  function notify(method, params) {
    send({ jsonrpc: '2.0', method: method, params: params || {} });
  }

  // The result may arrive as structuredContent, or only as JSON inside the text
  // fallback. Accept either, so the view does not depend on which the host forwards.
  function extract(params) {
    if (!params) { return null; }
    if (params.structuredContent) { return params.structuredContent; }
    var content = params.content || [];
    for (var i = 0; i < content.length; i++) {
      if (content[i] && typeof content[i].text === 'string') {
        try { return JSON.parse(content[i].text); } catch (e) { /* not JSON */ }
      }
    }
    return null;
  }

  function render(data) {
    if (!data || !data.posts) { return false; }

    var rows = data.posts.map(function (p) {
      var title = p.link
        ? '<a href="' + esc(p.link) + '" target="_blank" rel="noreferrer">' + esc(p.title) + '</a>'
        : esc(p.title);
      return '<tr>' +
        '<td class="num">' + esc(p.id) + '</td>' +
        '<td class="title">' + title +
          (p.excerpt ? '<span class="excerpt">' + esc(p.excerpt) + '</span>' : '') + '</td>' +
        '<td><span class="badge ' + esc(p.status) + '">' + esc(p.status) + '</span></td>' +
        '<td>' + esc(p.author || '—') + '</td>' +
        '<td class="date">' + esc(p.date) + '</td>' +
      '</tr>';
    }).join('');

    document.getElementById('root').innerHTML =
      '<h1>Posts <span>(' + data.posts.length + ')</span></h1>' +
      '<table><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Author</th><th>Date</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table>';

    rendered = true;
    document.getElementById('diag').className = 'ok';
    reportSize();
    return true;
  }

  function handle(raw) {
    var msg = raw;
    if (typeof msg === 'string') {
      try { msg = JSON.parse(msg); } catch (e) { return; }
    }
    if (!msg || msg.jsonrpc !== '2.0') { return; }

    if (msg.id != null && pending[msg.id]) {
      var slot = pending[msg.id];
      delete pending[msg.id];
      note('response to id ' + msg.id + (msg.error ? ' ERROR ' + JSON.stringify(msg.error) : ' ok'));
      if (msg.error) { slot.reject(msg.error); } else { slot.resolve(msg.result); }
      return;
    }

    note('recv ' + (msg.method || '(no method)'));

    if (msg.method === 'ui/notifications/tool-result' || msg.method === 'ui/notifications/tool-input') {
      if (render(extract(msg.params))) { note('rendered from ' + msg.method); }
    }
  }

  window.addEventListener('message', function (event) {
    if (event.ports && event.ports.length && !window.__mcpPort) {
      window.__mcpPort = event.ports[0];
      window.__mcpPort.onmessage = function (e) { handle(e.data); };
      note('host supplied a MessagePort');
    }
    handle(event.data);
  });

  // The host sizes the iframe from this notification. A View that never sends it can
  // be mounted at zero height — indistinguishable, on screen, from one that failed to
  // load at all.
  function reportSize() {
    var el = document.documentElement;
    notify('ui/notifications/size-changed', {
      width: Math.ceil(el.scrollWidth),
      height: Math.ceil(Math.max(el.scrollHeight, document.body.scrollHeight))
    });
  }

  if (window.ResizeObserver) {
    new ResizeObserver(reportSize).observe(document.documentElement);
  }
  window.addEventListener('load', reportSize);
  setTimeout(reportSize, 0);

  note('view loaded');

  request('ui/initialize', {
    // `appInfo`, not `clientInfo`, and the MCP Apps protocol version — not the core
    // MCP one. A host that validates this request rejects a wrong shape, and the
    // rejection is silent from the View's side: initialize never resolves, so the
    // View never sends `initialized`, so the host never sends the tool result.
    protocolVersion: '2026-01-26',
    appInfo: { name: 'abilities-catalog-posts-list', version: '1.0.0' },
    appCapabilities: {}
  }).then(function (result) {
    note('ui/initialize ok: ' + JSON.stringify(result && result.hostInfo || {}));
    notify('ui/notifications/initialized', {});
  }).catch(function (err) {
    note('ui/initialize failed: ' + JSON.stringify(err));
  });

  // A View always mounts after the tool has finished, and the spec defines no way to
  // replay a missed result. If no data arrives, ask the host to run the tool for us.
  setTimeout(function () {
    if (rendered) { return; }
    note('no tool-result after 2s — calling tools/call through the host');
    request('tools/call', {
      name: 'probe-meta-list-posts',
      arguments: {}
    }).then(function (result) {
      if (!render(extract(result))) {
        note('tools/call returned nothing renderable: ' + JSON.stringify(result).slice(0, 200));
      }
    }).catch(function (err) {
      note('tools/call failed: ' + JSON.stringify(err));
      document.getElementById('diag').innerHTML =
        '<b>No data reached this view.</b><br>The host mounted the iframe but never sent ' +
        '<code>ui/notifications/tool-result</code>, and <code>tools/call</code> from the view was refused. ' +
        'Message log:<ol>' + log.map(function (l) { return '<li><code>' + esc(l) + '</code></li>'; }).join('') + '</ol>';
    });
  }, 2000);
}());
</script>
HTML;
	}

	/**
	 * Builds one ability definition, filling in everything a probe shares.
	 *
	 * @param string                   $label       Human label.
	 * @param string                   $description What the scenario expects.
	 * @param callable                 $execute     The execute callback.
	 * @param array<string,mixed>      $meta        Extra `meta` keys merged over the shared defaults.
	 * @param string                   $type        MCP component type: tool, resource or prompt.
	 *
	 * @return array<string,mixed> The ability args.
	 */
	private static function ability(
		string $label,
		string $description,
		callable $execute,
		array $meta = array(),
		string $type = 'tool'
	): array {
		// Deliberately no `readonly` here: it maps to readOnlyHint, and a shared default
		// would emit `readOnlyHint: true` on every tool, masking whether scenario 9a's
		// string "1" actually coerced or simply inherited the default.
		$annotations = array(
			'destructive' => false,
		);

		$defaults = array(
			'public' => true,
			'mcp'    => array(
				'type' => $type,
			),
		);

		// Annotations live in a different place per component type, and getting it
		// wrong is silent. RegisterAbilityAsMcpResource reads them through
		// get_mcp_meta(), which prefers `mcp.annotations` and only falls back to the
		// top level with a deprecation warning. RegisterAbilityAsMcpTool reads
		// `meta.annotations` directly and knows nothing about `mcp.annotations`, so a
		// tool that follows that deprecation loses every annotation without a word —
		// including readOnlyHint. Each type therefore gets the location it reads.
		if ( 'tool' === $type ) {
			$defaults['annotations'] = $annotations;
		} else {
			$defaults['mcp']['annotations'] = $annotations;
		}

		return array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			// Empty rather than `type: object`: `resources/read` invokes the ability
			// with no input, which a declared object schema rejects outright.
			'input_schema'        => array(),
			'execute_callback'    => $execute,
			'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
			'meta'                => self::mergeMeta( $defaults, $meta ),
		);
	}

	/**
	 * Merges probe-specific meta over the shared defaults, one level deep.
	 *
	 * A plain `array_merge` would drop the default `mcp.type` whenever a scenario
	 * sets its own `mcp` key, so nested arrays merge rather than replace.
	 *
	 * @param array<string,mixed> $defaults Shared defaults.
	 * @param array<string,mixed> $overrides Scenario-specific keys.
	 *
	 * @return array<string,mixed> The merged meta.
	 */
	private static function mergeMeta( array $defaults, array $overrides ): array {
		foreach ( $overrides as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
				$defaults[ $key ] = array_merge( $defaults[ $key ], $value );
				continue;
			}

			$defaults[ $key ] = $value;
		}

		return $defaults;
	}
}
