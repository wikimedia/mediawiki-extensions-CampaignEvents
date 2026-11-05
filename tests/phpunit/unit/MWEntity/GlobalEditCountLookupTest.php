<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MWEntity;

use Generator;
use MediaWiki\Extension\CampaignEvents\MWEntity\GlobalEditCountLookup;
use MediaWiki\Extension\CentralAuth\CentralAuthEditCounter;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\MWEntity\GlobalEditCountLookup
 */
class GlobalEditCountLookupTest extends MediaWikiUnitTestCase {
	private function newEditTracker( ?int $localEditCount ): UserEditTracker {
		$editTracker = $this->createMock( UserEditTracker::class );
		$editTracker->method( 'getUserEditCount' )->willReturn( $localEditCount );
		return $editTracker;
	}

	/**
	 * @dataProvider provideGetGlobalEditCount__withoutCentralAuth
	 */
	public function testGetGlobalEditCount__withoutCentralAuth( ?int $localEditCount, int $expected ): void {
		$lookup = new GlobalEditCountLookup( $this->newEditTracker( $localEditCount ), null );
		$this->assertSame( $expected, $lookup->getGlobalEditCount( new UserIdentityValue( 42, 'Some user' ) ) );
	}

	public static function provideGetGlobalEditCount__withoutCentralAuth(): Generator {
		yield 'Local edits' => [ 5, 5 ];
		yield 'No edits' => [ 0, 0 ];
		yield 'No count (not registered)' => [ null, 0 ];
	}

	/**
	 * @dataProvider provideGetGlobalEditCount__withCentralAuth
	 */
	public function testGetGlobalEditCount__withCentralAuth(
		bool $hasGlobalAccount,
		?int $storedGlobalCount,
		int $expected
	): void {
		if ( !class_exists( CentralAuthEditCounter::class ) ) {
			$this->markTestSkipped( 'CentralAuth is not available' );
		}
		$centralUser = $this->createMock( CentralAuthUser::class );
		$centralUser->method( 'exists' )->willReturn( $hasGlobalAccount );

		$editCounter = $this->createMock( CentralAuthEditCounter::class );
		// The count is only read from the replica. It is never calculated again.
		$editCounter->expects( $this->never() )->method( 'getCount' );
		$editCounter->method( 'getCountIfInitialized' )->with( $centralUser )->willReturn( $storedGlobalCount );

		$lookup = $this->getMockBuilder( GlobalEditCountLookup::class )
			->setConstructorArgs( [ $this->newEditTracker( 7 ), $editCounter ] )
			->onlyMethods( [ 'getCentralAuthUser' ] )
			->getMock();
		$lookup->method( 'getCentralAuthUser' )->willReturn( $centralUser );

		$this->assertSame( $expected, $lookup->getGlobalEditCount( new UserIdentityValue( 42, 'Some user' ) ) );
	}

	public static function provideGetGlobalEditCount__withCentralAuth(): Generator {
		// The local edit count is always 7.
		yield 'Stored global count' => [ true, 1000, 1000 ];
		yield 'Stored global count of zero' => [ true, 0, 0 ];
		yield 'No global account: local count' => [ false, null, 7 ];
		yield 'No stored global count yet: local count' => [ true, null, 7 ];
	}
}
