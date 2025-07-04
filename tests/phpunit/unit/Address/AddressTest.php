<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Address;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Address\Address
 */
class AddressTest extends MediaWikiUnitTestCase {
	public function testConstructAndGetters() {
		$addressWithoutCountry = 'Some address';
		$country = 'France';
		$countryCode = 'FR';
		$obj = new Address( $addressWithoutCountry, $country, $countryCode );

		$this->assertSame( $addressWithoutCountry, $obj->getAddressWithoutCountry(), 'Address without country' );
		$this->assertSame( $country, $obj->getCountry(), 'Country' );
		$this->assertSame( $countryCode, $obj->getCountryCode(), 'Country code' );
	}

	public function testConstruct__bothNull() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Need at least one of address and country' );
		new Address( null, null, null );
	}
}
