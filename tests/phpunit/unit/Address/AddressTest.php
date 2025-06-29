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
		$country = 'Some country';
		$obj = new Address( $addressWithoutCountry, $country );

		$this->assertSame( $addressWithoutCountry, $obj->getAddressWithoutCountry(), 'Address without country' );
		$this->assertSame( $country, $obj->getCountry(), 'Country' );
	}
}
