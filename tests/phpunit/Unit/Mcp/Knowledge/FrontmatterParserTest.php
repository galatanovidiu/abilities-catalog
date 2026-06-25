<?php
/**
 * Unit tests for the OKF frontmatter parser.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Mcp\Knowledge;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\FrontmatterParser;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The parser reads only the leading `---`…`---` block and returns the body opaque, so
 * these assert: a valid block parses, both `tags` forms work, a body full of colons and
 * block markup survives untouched, and a malformed or type-less block is a skip
 * (`null`) rather than a fatal.
 */
final class FrontmatterParserTest extends TestCase {

	/**
	 * A complete block parses into its scalar fields.
	 *
	 * @return void
	 */
	public function test_parses_scalar_fields(): void {
		$fields = FrontmatterParser::parse( "---\ntype: Skill\ntitle: Do the thing\ndescription: When you must do it.\n---\nBody.\n" );

		$this->assertIsArray( $fields );
		$this->assertSame( 'Skill', $fields['type'] );
		$this->assertSame( 'Do the thing', $fields['title'] );
		$this->assertSame( 'When you must do it.', $fields['description'] );
	}

	/**
	 * No leading `---` is not a frontmatter block, so the concept is skipped.
	 *
	 * @return void
	 */
	public function test_missing_opening_delimiter_is_skipped(): void {
		$this->assertNull( FrontmatterParser::parse( "type: Skill\ntitle: No block\n" ) );
	}

	/**
	 * An unterminated block (no closing `---`) is skipped.
	 *
	 * @return void
	 */
	public function test_unterminated_block_is_skipped(): void {
		$this->assertNull( FrontmatterParser::parse( "---\ntype: Skill\ntitle: Never closes\nBody leaks in.\n" ) );
	}

	/**
	 * A block with no non-empty `type` is skipped (the OKF required key).
	 *
	 * @return void
	 */
	public function test_missing_type_is_skipped(): void {
		$this->assertNull( FrontmatterParser::parse( "---\ntitle: Has no type\n---\nBody.\n" ) );
		$this->assertNull( FrontmatterParser::parse( "---\ntype:\ntitle: Empty type\n---\nBody.\n" ) );
	}

	/**
	 * An inline `tags: [a, b]` list parses into trimmed, unquoted items.
	 *
	 * @return void
	 */
	public function test_parses_inline_tags(): void {
		$fields = FrontmatterParser::parse( "---\ntype: Reference\ntags: [alpha, \"beta\", gamma]\n---\nBody.\n" );

		$this->assertSame( array( 'alpha', 'beta', 'gamma' ), $fields['tags'] );
	}

	/**
	 * A block `tags:` list (`- item` lines) parses, and stops at the next field.
	 *
	 * @return void
	 */
	public function test_parses_block_tags(): void {
		$fields = FrontmatterParser::parse( "---\ntype: Reference\ntags:\n  - one\n  - two\ntitle: After tags\n---\nBody.\n" );

		$this->assertSame( array( 'one', 'two' ), $fields['tags'] );
		$this->assertSame( 'After tags', $fields['title'] );
	}

	/**
	 * The body is returned verbatim, including colons and block-comment markup.
	 *
	 * @return void
	 */
	public function test_body_is_left_intact(): void {
		$markup = "<!-- wp:heading {\"level\":2} -->\n<h2>Title: a colon</h2>\n<!-- /wp:heading -->";
		$body   = FrontmatterParser::body( "---\ntype: Skill\n---\n" . $markup . "\n" );

		$this->assertSame( $markup, trim( $body ) );
	}

	/**
	 * The parser stops at the FIRST closing `---`; a later `---` in the body is body.
	 *
	 * @return void
	 */
	public function test_stops_at_first_closing_delimiter(): void {
		$fields = FrontmatterParser::parse( "---\ntype: Skill\n---\nBody line.\n---\nA horizontal rule, not frontmatter.\n" );

		$this->assertSame( 'Skill', $fields['type'] );
		$this->assertArrayNotHasKey( 'A horizontal rule, not frontmatter.', $fields );

		$body = FrontmatterParser::body( "---\ntype: Skill\n---\nBody line.\n---\nA horizontal rule, not frontmatter.\n" );
		$this->assertStringContainsString( '---', $body );
		$this->assertStringContainsString( 'horizontal rule', $body );
	}

	/**
	 * CRLF line endings parse the same as LF.
	 *
	 * @return void
	 */
	public function test_handles_crlf_line_endings(): void {
		$fields = FrontmatterParser::parse( "---\r\ntype: Skill\r\ntitle: Windows\r\n---\r\nBody.\r\n" );

		$this->assertSame( 'Skill', $fields['type'] );
		$this->assertSame( 'Windows', $fields['title'] );
	}
}
