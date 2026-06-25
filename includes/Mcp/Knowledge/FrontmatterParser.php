<?php
/**
 * A minimal, fail-safe parser for OKF concept frontmatter.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the leading `---`…`---` YAML frontmatter block of an OKF concept.
 *
 * This is deliberately *not* a YAML parser. An OKF concept body is full markdown —
 * colons, `{…}` JSON attributes, and `<!-- wp:… -->` block comments — so a real YAML
 * pass over the whole file would choke or, worse, silently reinterpret body text. This
 * parser instead stops at the closing `---` and returns the body **verbatim and
 * opaque** (see {@see body()}); {@see parse()} reads only the small set of flat scalar
 * keys plus `tags`, and treats a block that will not parse, or one without a non-empty
 * `type`, as a skip signal (`null`) rather than a fatal.
 *
 * The shipped frontmatter is authored in this simple subset: one `key: value` per
 * line, scalars optionally quoted, and `tags` as either an inline `[a, b]` list or a
 * block list of `- item` lines. Any value containing a colon must be quoted.
 *
 * @since 0.4.0
 */
final class FrontmatterParser {

	/**
	 * Parses the leading frontmatter block into a flat field map.
	 *
	 * Returns the scalar keys it recognizes (`type`, `title`, `description`,
	 * `resource`, `timestamp`, and any other simple `key: value` line) plus a `tags`
	 * list. A concept with no leading `---`…`---` block, or whose block carries no
	 * non-empty `type`, is a skip: this returns `null` so the scanner drops it.
	 *
	 * @param string $content The concept file content (or a bounded head of it).
	 * @return array<string,mixed>|null The frontmatter fields, or `null` when there is
	 *         no valid block or no non-empty `type`.
	 */
	public static function parse( string $content ): ?array {
		$split = self::split( $content );
		if ( null === $split ) {
			return null;
		}

		$fields = self::fields( $split[0] );

		$type = isset( $fields['type'] ) && is_string( $fields['type'] ) ? trim( $fields['type'] ) : '';
		if ( '' === $type ) {
			return null;
		}

		$fields['type'] = $type;

		return $fields;
	}

	/**
	 * Returns the concept body — everything after the closing `---`.
	 *
	 * The body is returned untouched. When the content carries no recognizable
	 * frontmatter block the whole content is the body, so a concept that somehow lost
	 * its frontmatter between scan and load still yields its text rather than an empty
	 * string.
	 *
	 * @param string $content The concept file content.
	 * @return string The body markdown, verbatim.
	 */
	public static function body( string $content ): string {
		$split = self::split( $content );

		return null === $split ? $content : $split[1];
	}

	/**
	 * Splits content into its frontmatter block and its body.
	 *
	 * The first line must be exactly `---`; the block ends at the next line that is
	 * exactly `---`. Without an opening or a closing delimiter there is no block, so
	 * this returns `null`.
	 *
	 * @param string $content The concept file content.
	 * @return array{0:string,1:string}|null `[ frontmatter, body ]`, or `null` when
	 *         there is no complete `---`…`---` block.
	 */
	private static function split( string $content ): ?array {
		$lines = explode( "\n", $content );

		if ( '---' !== rtrim( $lines[0], "\r" ) ) {
			return null;
		}

		$count = count( $lines );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( '---' === rtrim( $lines[ $i ], "\r" ) ) {
				$frontmatter = implode( "\n", array_slice( $lines, 1, $i - 1 ) );
				$body        = implode( "\n", array_slice( $lines, $i + 1 ) );

				return array( $frontmatter, $body );
			}
		}

		return null;
	}

	/**
	 * Reduces the frontmatter block to a flat field map.
	 *
	 * Each `key: value` line becomes a string field; `tags` becomes a list, read from
	 * either an inline `[a, b]` value or a following block of `- item` lines. Blank
	 * lines and `#` comments are ignored, as is any line without a colon.
	 *
	 * @param string $frontmatter The text between the delimiters.
	 * @return array<string,mixed> The parsed fields, always carrying a `tags` list.
	 */
	private static function fields( string $frontmatter ): array {
		$fields = array( 'tags' => array() );

		$lines = explode( "\n", $frontmatter );
		$count = count( $lines );
		for ( $i = 0; $i < $count; $i++ ) {
			$line    = rtrim( $lines[ $i ], "\r" );
			$trimmed = trim( $line );
			if ( '' === $trimmed || '#' === $trimmed[0] ) {
				continue;
			}

			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}

			$key   = trim( substr( $line, 0, $colon ) );
			$value = trim( substr( $line, $colon + 1 ) );
			if ( '' === $key ) {
				continue;
			}

			if ( 'tags' === $key ) {
				$fields['tags'] = '' === $value
					? self::blockList( $lines, $count, $i )
					: self::inlineList( $value );

				continue;
			}

			$fields[ $key ] = self::unquote( $value );
		}

		return $fields;
	}

	/**
	 * Reads a block-style `tags:` list (`- item` lines) following the key, advancing $i.
	 *
	 * @param list<string> $lines The frontmatter lines.
	 * @param int          $count The line count.
	 * @param int          $i     The index of the `tags:` line; advanced past consumed items.
	 * @return list<string> The tag values.
	 */
	private static function blockList( array $lines, int $count, int &$i ): array {
		$tags = array();

		while ( $i + 1 < $count ) {
			$next = trim( rtrim( $lines[ $i + 1 ], "\r" ) );
			if ( '-' !== $next && 0 !== strncmp( $next, '- ', 2 ) ) {
				break;
			}

			$tag = self::unquote( trim( substr( $next, 1 ) ) );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}

			++$i;
		}

		return $tags;
	}

	/**
	 * Parses an inline `[a, b, c]` list value into trimmed, unquoted items.
	 *
	 * @param string $value The inline list value.
	 * @return list<string> The list items.
	 */
	private static function inlineList( string $value ): array {
		if ( str_starts_with( $value, '[' ) && str_ends_with( $value, ']' ) ) {
			$value = substr( $value, 1, -1 );
		}

		if ( '' === trim( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( explode( ',', $value ) as $item ) {
			$item = self::unquote( trim( $item ) );
			if ( '' === $item ) {
				continue;
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Strips one layer of matching surrounding single or double quotes.
	 *
	 * @param string $value The raw value.
	 * @return string The unquoted value.
	 */
	private static function unquote( string $value ): string {
		$length = strlen( $value );
		if ( $length < 2 ) {
			return $value;
		}

		$first = $value[0];
		$last  = $value[ $length - 1 ];
		if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
			return substr( $value, 1, -1 );
		}

		return $value;
	}
}
