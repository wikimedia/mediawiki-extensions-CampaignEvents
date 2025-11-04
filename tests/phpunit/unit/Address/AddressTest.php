<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Address;

use MediaWiki\Extension\CampaignEvents\Address\Address;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Address\Address
 */
class AddressTest extends MediaWikiUnitTestCase {
	public function testConstructAndGetters() {
		$addressWithoutCountry = 'Some address';
		$countryCode = 'FR';
		$obj = new Address( $addressWithoutCountry, $countryCode );

		$this->assertSame( $addressWithoutCountry, $obj->getAddressWithoutCountry(), 'Address without country' );
		$this->assertSame( $countryCode, $obj->getCountryCode(), 'Country code' );
	}
}
