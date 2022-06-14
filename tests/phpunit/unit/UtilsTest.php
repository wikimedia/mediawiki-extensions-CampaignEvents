<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit;

use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Utils
 */
class UtilsTest extends MediaWikiUnitTestCase {
	/**
	 * @param string $str
	 * @param string $expected
	 * @dataProvider provideStringDirection
	 * @covers ::guessStringDirection
	 */
	public function testGuessStringDirection( string $str, string $expected ) {
		$this->assertSame( $expected, Utils::guessStringDirection( $str ) );
	}

	public function provideStringDirection(): array {
		return [
			'Italian' => [ 'Perché permetterò più preludî.', 'ltr' ],
			'French' => [ 'Noël n\'est pas en aôut, garçon', 'ltr' ],
			'German' => [ 'Der Straßenkörper ist gefährlich', 'ltr' ],
			'Russian' => [ 'я не знаю этого языка', 'ltr' ],
			'Chinese' => [ '我不懂这种语言', 'ltr' ],
			'Hebrew' => [ 'אני לא מכיר את השפה הזו', 'rtl' ],
			'Arabic' => [ 'أنا آكل الفأر مع التوابل', 'rtl' ],
			'Aramaic' => [ 'ܟܠ ܒܪܢܫܐ ܒܪܝܠܗ ܚܐܪܐ ܘܒܪܒܪ', 'rtl' ],
			'Farsi' => [ 'تمام افراد بشر آزاد به دنيا مي‌آيند', 'rtl' ],
			'N\'Ko' => [ 'ߓߏ߬ߟߏ߲߬ߘߊ', 'rtl' ],
			'English + Hebrew' => [ 'Here is your Hebrew string: הנה אני', 'rtl' ],
			'Arabic + Hebrew' => [ 'اسمي עִמָּנוּאֵל', 'rtl' ],
			'Chinese + Arabic' => [ "-告诉我一些事情\n-ٱلسَّلَامُ عَلَيْكُمْ", 'rtl' ],
		];
	}
}
