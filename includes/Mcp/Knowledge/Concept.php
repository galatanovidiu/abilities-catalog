<?php
/**
 * One OKF concept: its frontmatter fields plus a confined, lazy body loader.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single concept in a {@see KnowledgeBundle}: a markdown file's frontmatter, plus a
 * loader that reads the body only when asked.
 *
 * The frontmatter (`type`, `title`, `description`, `tags`, `timestamp`, `resource`)
 * loads eagerly at scan time so an index can list the concept without spending its
 * body. The body stays on disk until {@see body()} reads it — and that read
 * **re-checks the realpath confinement** the scanner first applied, so a symlink
 * swapped between scan and load (a TOCTOU race) cannot escape the bundle root.
 *
 * @since 0.4.0
 */
final class Concept {

	/**
	 * Cap on the body bytes read from disk (a hostile third-party file must not
	 * inflate a response without bound).
	 */
	private const MAX_BODY_BYTES = 262144;

	/**
	 * The concept id within its bundle (its file path minus `.md`).
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The owning bundle's slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * The concept type (`Skill` / `Guideline` / `Reference` / any free string).
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * The display title (falls back to the id when frontmatter omits it).
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * The one-line description, or an empty string.
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * The frontmatter tags.
	 *
	 * @var list<string>
	 */
	private array $tags;

	/**
	 * The realpath of the concept file, as resolved at scan time.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * The realpath of the bundle root, for the load-time confinement re-check.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Builds a concept from its parsed frontmatter and confined file path.
	 *
	 * @param string              $id          The concept id (file path minus `.md`).
	 * @param string              $slug        The owning bundle's slug.
	 * @param array<string,mixed> $frontmatter The parsed frontmatter fields.
	 * @param string              $path        The realpath of the concept file.
	 * @param string              $root        The realpath of the bundle root.
	 */
	public function __construct( string $id, string $slug, array $frontmatter, string $path, string $root ) {
		$this->id          = $id;
		$this->slug        = $slug;
		$this->type        = self::field( $frontmatter, 'type' );
		$this->title       = self::field( $frontmatter, 'title', $id );
		$this->description = self::field( $frontmatter, 'description' );
		$this->path        = $path;
		$this->root        = $root;

		$tags       = isset( $frontmatter['tags'] ) && is_array( $frontmatter['tags'] ) ? $frontmatter['tags'] : array();
		$this->tags = array_values( array_filter( $tags, 'is_string' ) );
	}

	/**
	 * The concept id within its bundle.
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The fully-qualified concept uri (`<bundle-slug>/<id>`).
	 *
	 * @return string The uri.
	 */
	public function uri(): string {
		return $this->slug . '/' . $this->id;
	}

	/**
	 * The concept type.
	 *
	 * @return string The type.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The display title.
	 *
	 * @return string The title.
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * The one-line description.
	 *
	 * @return string The description, or an empty string.
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * The frontmatter tags.
	 *
	 * @return list<string> The tags.
	 */
	public function tags(): array {
		return $this->tags;
	}

	/**
	 * Reads the concept body, re-checking confinement at load time.
	 *
	 * The realpath of the file is recomputed and re-confined to the bundle root before
	 * any read, so a symlink swapped in after the scan cannot escape the root (TOCTOU).
	 * The read is byte-capped and the frontmatter is stripped, leaving the body markdown
	 * verbatim. A confinement failure or unreadable file yields a `WP_Error` (status
	 * 500) the caller folds for the agent, never a fatal.
	 *
	 * @return string|\WP_Error The body markdown, or a `WP_Error` when the file cannot
	 *         be confined or read.
	 */
	public function body() {
		$resolved = realpath( $this->path );
		if ( false === $resolved || ! str_starts_with( $resolved, $this->root . DIRECTORY_SEPARATOR ) ) {
			return self::unreadable();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading the plugin's own bundled, realpath-confined markdown on disk, not a remote URL; WP_Filesystem is for writes / credentialed hosts, not a read sandbox.
		$raw = file_get_contents( $resolved, false, null, 0, self::MAX_BODY_BYTES );
		if ( false === $raw ) {
			return self::unreadable();
		}

		return trim( FrontmatterParser::body( $raw ) );
	}

	/**
	 * The "body cannot be read" error, shared by the confinement and read failures.
	 *
	 * @return \WP_Error The error, carrying a 500 status for the caller's fold.
	 */
	private static function unreadable(): WP_Error {
		return new WP_Error(
			'abilities_catalog_mcp_unreadable_knowledge',
			__( 'This knowledge concept has no readable body.', 'abilities-catalog' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Reads a string frontmatter field, or a default when it is absent or non-string.
	 *
	 * @param array<string,mixed> $frontmatter The parsed frontmatter.
	 * @param string              $key         The field name.
	 * @param string              $fallback    The value when the field is absent or non-string.
	 * @return string The field value.
	 */
	private static function field( array $frontmatter, string $key, string $fallback = '' ): string {
		return isset( $frontmatter[ $key ] ) && is_string( $frontmatter[ $key ] ) ? $frontmatter[ $key ] : $fallback;
	}
}
