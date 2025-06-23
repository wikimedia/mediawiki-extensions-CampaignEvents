<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Address;

use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWikiIntegrationTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Address\CountryProvider
 */
class CountryProviderTest extends MediaWikiIntegrationTestCase {

	private CountryProvider $countryProvider;

	protected function setUp(): void {
		parent::setUp();
		$this->countryProvider = new CountryProvider();
	}

	/**
	 * @covers \MediaWiki\Extension\CampaignEvents\Address\CountryProvider::getAvailableCountries
	 * @dataProvider provideAvailableCountries
	 */
	public function testGetAvailableCountriesReturnsLocalizedNames( string $lang, string $code, string $expected ) {
		$countries = $this->countryProvider->getAvailableCountries( $lang );

		$this->assertArrayHasKey( $code, $countries );
		$this->assertSame( $expected, $countries[$code] );
	}

	public static function provideAvailableCountries(): array {
		return [
			'English' => [ 'en', 'BR', 'Brazil' ],
			'French' => [ 'fr', 'BR', 'Brésil' ],
		];
	}

	/**
	 * @covers ::getAvailableCountries
	 */
	public function testExcludedCountriesAreNotPresent() {
		$countries = $this->countryProvider->getAvailableCountries( 'en' );

		foreach ( CountryProvider::EXCLUDED_COUNTRY_CODES as $code ) {
			$this->assertArrayNotHasKey( $code, $countries, "Excluded code $code should not be present" );
		}
	}

	/**
	 * @covers ::isValidCountryCode
	 * @dataProvider provideCountryCodes
	 */
	public function testIsValidCountryCode( ?string $code, bool $expected ) {
		$this->assertSame(
			$expected,
			$this->countryProvider->isValidCountryCode( $code ),
			"Expected result for code '$code'"
		);
	}

	/**
	 * @return array[]
	 */
	public static function provideCountryCodes(): array {
		return [
			'valid code BR' => [ 'BR', true ],
			'excluded code UN' => [ 'UN', false ],
			'invalid code ZZ' => [ 'ZZ', false ],
			'empty string' => [ '', false ],
			'null value' => [ null, false ],
		];
	}
}
