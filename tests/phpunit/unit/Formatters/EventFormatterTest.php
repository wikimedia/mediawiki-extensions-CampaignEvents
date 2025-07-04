<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Formatters;

use Generator;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\Formatters\EventFormatter;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Formatters\EventFormatter
 */
class EventFormatterTest extends MediaWikiUnitTestCase {
	private function getFormatter(): EventFormatter {
		return new EventFormatter();
	}

	/** @dataProvider provideFormatAddress */
	public function testFormatAddress( Address $address, string $expected ) {
		$formatter = $this->getFormatter();
		$languageCode = 'en';
		$this->assertSame( $expected, $formatter->formatAddress( $address, $languageCode ) );
	}

	public static function provideFormatAddress(): Generator {
		$address = 'Some address';
		$country = 'Country';

		yield 'Address but no country' => [ new Address( $address, null ), "$address\n" ];
		yield 'Country but no address' => [ new Address( null, $country ), "\n$country" ];
		yield 'Address and country' => [ new Address( $address, $country ), "$address\n$country" ];
	}
}
