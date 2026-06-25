<?php
/**
 * Unit tests for the Porter stemmer.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\PorterStemmer;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The stemmer is pure, so these assert it directly against Porter's own published
 * step examples — the canonical proof the algorithm is transcribed correctly — plus
 * the inflection collapses the {@see \GalatanOvidiu\AbilitiesCatalog\Mcp\AbilityIndex}
 * search relies on.
 */
final class PorterStemmerTest extends TestCase {

	/**
	 * Word -> expected stem, drawn from Porter's published step rules.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function porterExamples(): array {
		return array(
			// Step 1a.
			'caresses -> caress'     => array( 'caresses', 'caress' ),
			'ponies -> poni'         => array( 'ponies', 'poni' ),
			'caress -> caress'       => array( 'caress', 'caress' ),
			'cats -> cat'            => array( 'cats', 'cat' ),
			// Step 1b.
			'feed -> feed'           => array( 'feed', 'feed' ),
			'agreed -> agre'         => array( 'agreed', 'agre' ),
			'plastered -> plaster'   => array( 'plastered', 'plaster' ),
			'motoring -> motor'      => array( 'motoring', 'motor' ),
			'sing -> sing'           => array( 'sing', 'sing' ),
			// "conflate"/"trouble" are step-1b intermediates; step 5 then strips the
			// terminal "e" (measure > 1), so the full-algorithm stems are shorter.
			'conflated -> conflat'   => array( 'conflated', 'conflat' ),
			'troubling -> troubl'    => array( 'troubling', 'troubl' ),
			'hopping -> hop'         => array( 'hopping', 'hop' ),
			'falling -> fall'        => array( 'falling', 'fall' ),
			'hissing -> hiss'        => array( 'hissing', 'hiss' ),
			// Step 1c.
			'happy -> happi'         => array( 'happy', 'happi' ),
			'sky -> sky'             => array( 'sky', 'sky' ),
			// Steps 2-4.
			'relational -> relat'    => array( 'relational', 'relat' ),
			'conditional -> condit'  => array( 'conditional', 'condit' ),
			'rational -> ration'     => array( 'rational', 'ration' ),
			'callousness -> callous' => array( 'callousness', 'callous' ),
			'formative -> form'      => array( 'formative', 'form' ),
			'allowance -> allow'     => array( 'allowance', 'allow' ),
			// Step 5.
			'probate -> probat'      => array( 'probate', 'probat' ),
			'cease -> ceas'          => array( 'cease', 'ceas' ),
			'controll -> control'    => array( 'controll', 'control' ),
		);
	}

	/**
	 * @dataProvider porterExamples
	 *
	 * @param string $word     The input word.
	 * @param string $expected The expected stem.
	 * @return void
	 */
	public function test_stems_porter_examples( string $word, string $expected ): void {
		$this->assertSame( $expected, PorterStemmer::stem( $word ) );
	}

	/**
	 * Word pairs the search relies on: an inflected word and its base must share a stem.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function inflectionPairs(): array {
		return array(
			'plugins/plugin'   => array( 'plugins', 'plugin' ),
			'themes/theme'     => array( 'themes', 'theme' ),
			'updates/updating' => array( 'updates', 'updating' ),
			'updated/update'   => array( 'updated', 'update' ),
			'comments/comment' => array( 'comments', 'comment' ),
			'settings/setting' => array( 'settings', 'setting' ),
			'creating/created' => array( 'creating', 'created' ),
		);
	}

	/**
	 * @dataProvider inflectionPairs
	 *
	 * @param string $a First surface word.
	 * @param string $b Second surface word.
	 * @return void
	 */
	public function test_inflections_collapse_to_one_stem( string $a, string $b ): void {
		$this->assertSame(
			PorterStemmer::stem( $a ),
			PorterStemmer::stem( $b ),
			"\"$a\" and \"$b\" must stem to the same token so the search matches them."
		);
	}

	/**
	 * Two letters or fewer are returned unchanged.
	 *
	 * @return void
	 */
	public function test_short_words_pass_through(): void {
		$this->assertSame( 'is', PorterStemmer::stem( 'is' ) );
		$this->assertSame( 'a', PorterStemmer::stem( 'a' ) );
	}
}
