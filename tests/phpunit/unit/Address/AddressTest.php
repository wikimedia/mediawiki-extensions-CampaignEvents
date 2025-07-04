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
		$country = 'Some country';
		$obj = new Address( $addressWithoutCountry, $country );

		$this->assertSame( $addressWithoutCountry, $obj->getAddressWithoutCountry(), 'Address without country' );
		$this->assertSame( $country, $obj->getCountry(), 'Country' );
	}

	public function testConstruct__bothNull() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '$addressWithoutCountry and $country cannot be both null' );
		new Address( null, null );
	}
}
