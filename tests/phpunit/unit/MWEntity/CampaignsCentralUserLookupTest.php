<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use Generator;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup
 * @covers ::__construct
 */
class CampaignsCentralUserLookupTest extends MediaWikiUnitTestCase {
	private const EXISTING_ID = 1;
	private const NONEXISTING_ID = 2;
	private const HIDDEN_ID = 3;
	/** Note, this is in the format used by CentralIdLookup, not ours. */
	private const ID_NAME_MAP = [
		self::EXISTING_ID => 'Some existing username',
		self::NONEXISTING_ID => null,
		self::HIDDEN_ID => '',
	];

	private function getLookup(
		CentralIdLookup $centralIdLookup = null
	): CampaignsCentralUserLookup {
		if ( !$centralIdLookup ) {
			$centralIdLookup = $this->createMock( CentralIdLookup::class );
			$centralIdLookup->method( 'lookupCentralIds' )
				->willReturnCallback( fn ( array $map ) => array_intersect_key( self::ID_NAME_MAP, $map ) );
		}
		return new CampaignsCentralUserLookup(
			$centralIdLookup,
			$this->createMock( UserFactory::class ),
			$this->createMock( UserNameUtils::class )
		);
	}

	/**
	 * @covers ::getUserName
	 */
	public function testGetUserName__exists() {
		$id = self::EXISTING_ID;
		$this->assertSame( self::ID_NAME_MAP[$id], $this->getLookup()->getUserName( new CentralUser( $id ) ) );
	}

	/**
	 * @covers ::getUserName
	 * @dataProvider provideGetUserName__exceptions
	 */
	public function testGetUserName__exceptions( int $centralID, string $excepClass ) {
		$this->expectException( $excepClass );
		$this->getLookup()->getUserName( new CentralUser( $centralID ) );
	}

	public static function provideGetUserName__exceptions(): Generator {
		yield 'User not found' => [ self::NONEXISTING_ID, CentralUserNotFoundException::class ];
		yield 'User hidden' => [ self::HIDDEN_ID, HiddenCentralUserException::class ];
	}

	/**
	 * @covers ::getNames
	 */
	public function testGetNames() {
		$idsMap = array_fill_keys( array_keys( self::ID_NAME_MAP ), null );
		$expected = [ self::EXISTING_ID => self::ID_NAME_MAP[self::EXISTING_ID] ];
		$this->assertSame( $expected, $this->getLookup()->getNames( $idsMap ) );
	}

	/**
	 * @covers ::getNamesIncludingDeletedAndSuppressed
	 */
	public function testGetNamesIncludingDeletedAndSuppressed() {
		$idsMap = array_fill_keys( array_keys( self::ID_NAME_MAP ), null );
		$expected = [
			self::EXISTING_ID => self::ID_NAME_MAP[self::EXISTING_ID],
			self::NONEXISTING_ID => CampaignsCentralUserLookup::USER_NOT_FOUND,
			self::HIDDEN_ID => CampaignsCentralUserLookup::USER_HIDDEN,
		];
		$this->assertSame( $expected, $this->getLookup()->getNamesIncludingDeletedAndSuppressed( $idsMap ) );
	}

	public static function provideCacheTestCases(): Generator {
		yield 'User exists' => [ self::EXISTING_ID ];
		yield 'User not found' => [ self::NONEXISTING_ID ];
		yield 'User hidden' => [ self::HIDDEN_ID ];
	}

	/**
	 * Helper for cache tests.
	 *
	 * @param int $userID
	 * @param callable $populateCacheCb Callback for populating the cache; should take the CentralUserLookup as first
	 * argument, and a CentralUser object as second argument.
	 */
	private function cacheTester( int $userID, callable $populateCacheCb ): void {
		$singleUseIdLookup = $this->createNoOpMock( CentralIdLookup::class, [ 'lookupCentralIds' ] );
		// The key assertion: only the lookup should only run once.
		$singleUseIdLookup->expects( $this->once() )
			->method( 'lookupCentralIds' )
			->willReturn( self::ID_NAME_MAP );
		$lookup = $this->getLookup( $singleUseIdLookup );

		$centralUser = new CentralUser( $userID );
		$populateCacheCb( $lookup, $centralUser );
		// Test that we only get cache hits.
		try {
			$lookup->getUserName( $centralUser );
		} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
		}
		$lookup->getNames( [ $userID => null ] );
		$lookup->getNamesIncludingDeletedAndSuppressed( [ $userID => null ] );
	}

	/**
	 * @covers ::getUserName
	 * @covers ::getNames
	 * @covers ::getNamesIncludingDeletedAndSuppressed
	 * @dataProvider provideCacheTestCases
	 */
	public function testGetUserName__populatesCache( int $userID ) {
		$this->cacheTester(
			$userID,
			static function ( CampaignsCentralUserLookup $lookup, CentralUser $user ): void {
				try {
					$lookup->getUserName( $user );
				} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
				}
			}
		);
	}

	/**
	 * @covers ::getUserName
	 * @covers ::getNames
	 * @covers ::getNamesIncludingDeletedAndSuppressed
	 * @dataProvider provideCacheTestCases
	 */
	public function testGetNames__populatesCache( int $userID ) {
		$this->cacheTester(
			$userID,
			static function ( CampaignsCentralUserLookup $lookup, CentralUser $user ): void {
				$lookup->getNames( [ $user->getCentralID() => null ] );
			}
		);
	}

	/**
	 * @covers ::getUserName
	 * @covers ::getNames
	 * @covers ::getNamesIncludingDeletedAndSuppressed
	 * @dataProvider provideCacheTestCases
	 */
	public function testGetNamesIncludingDeletedAndSuppressed__populatesCache( int $userID ) {
		$this->cacheTester(
			$userID,
			static function ( CampaignsCentralUserLookup $lookup, CentralUser $user ): void {
				$lookup->getNamesIncludingDeletedAndSuppressed( [ $user->getCentralID() => null ] );
			}
		);
	}
}
