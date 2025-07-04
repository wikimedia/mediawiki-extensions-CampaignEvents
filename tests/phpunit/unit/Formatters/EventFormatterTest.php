<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Formatters;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\Formatters\EventFormatter;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Formatters\EventFormatter
 */
class EventFormatterTest extends MediaWikiUnitTestCase {
	private const TEST_COUNTRY_NAMES = [
		'en' => [
			'FR' => 'France',
		],
		'it' => [
			'FR' => 'Francia',
		],
	];

	private function getFormatter(): EventFormatter {
		$countryProvider = $this->createMock( CountryProvider::class );
		$countryProvider->method( 'getCountryName' )
			->willReturnCallback( static function ( string $countryCode, string $languageCode ) {
				return self::TEST_COUNTRY_NAMES[$languageCode][$countryCode] ??
					throw new InvalidArgumentException( "Invalid codes: $languageCode, $countryCode" );
			} );
		return new EventFormatter( $countryProvider );
	}

	/** @dataProvider provideFormatAddress */
	public function testFormatAddress( Address $address, string $expected, string $languageCode = 'en' ) {
		$formatter = $this->getFormatter();
		$this->assertSame( $expected, $formatter->formatAddress( $address, $languageCode ) );
	}

	public static function provideFormatAddress(): Generator {
		$address = 'Some address';
		$countryCode = 'FR';
		$countryEnglish = self::TEST_COUNTRY_NAMES['en'][$countryCode];
		$countryItalian = self::TEST_COUNTRY_NAMES['it'][$countryCode];

		yield 'Address, no country, no country code' => [
			new Address( $address, null, null ),
			"$address\n",
		];
		yield 'Country, no address, no country code' => [
			new Address( null, $countryEnglish, null ),
			"\n$countryEnglish",
		];
		yield 'Country code, no address, no country' => [
			new Address( null, null, $countryCode ),
			"\n$countryEnglish",
		];
		yield 'Country and country code but no address' => [
			new Address( null, $countryEnglish, null ),
			"\n$countryEnglish",
		];
		yield 'Address and country, no country code' => [
			new Address( $address, $countryEnglish, null ),
			"$address\n$countryEnglish",
		];
		yield 'Address and country code, no country' => [
			new Address( $address, null, $countryCode ),
			"$address\n$countryEnglish",
		];
		yield 'Address, country, and country code' => [
			new Address( $address, $countryEnglish, $countryCode ),
			"$address\n$countryEnglish",
		];

		yield 'Full address, different language' => [
			new Address( $address, $countryItalian, $countryCode ),
			"$address\n$countryItalian",
			'it',
		];
	}
}
