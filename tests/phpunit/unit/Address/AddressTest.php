<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Address;

use Generator;
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

	/** @dataProvider provideToString */
	public function testToString( Address $address, string $expected ) {
		$this->assertSame( $expected, $address->toString() );
	}

	public static function provideToString(): Generator {
		$address = 'Some address';
		$country = 'Country';

		yield 'Address but no country' => [ new Address( $address, null ), "$address\n" ];
		yield 'Country but no address' => [ new Address( null, $country ), "\n$country" ];
		yield 'Address and country' => [ new Address( $address, $country ), "$address\n$country" ];
	}
}
