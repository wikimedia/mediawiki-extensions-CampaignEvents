<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore
 * @covers ::__construct()
 */
class ParticipantsStoreTest extends MediaWikiUnitTestCase {
	private function getParticipantsStore( CampaignsCentralUserLookup $centralUserLookup = null ): ParticipantsStore {
		return new ParticipantsStore(
			$this->createMock( CampaignsDatabaseHelper::class ),
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class )
		);
	}

	/**
	 * @covers ::userParticipatesToEvent
	 */
	public function testUserParticipatesToEvent__loggedOut() {
		$store = $this->getParticipantsStore();
		$loggedOutUser = $this->createMock( ICampaignsUser::class );
		$loggedOutUser->expects( $this->atLeastOnce() )->method( 'isRegistered' )->willReturn( false );
		$this->assertFalse( $store->userParticipatesToEvent( 1, $loggedOutUser ) );
	}

	/**
	 * @covers ::addParticipantToEvent
	 */
	public function testAddParticipantToEvent__loggedOut() {
		$loggedOutUser = $this->createMock( ICampaignsUser::class );
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'getCentralID' )
			->with( $loggedOutUser )
			->willThrowException( $this->createMock( CentralUserNotFoundException::class ) );
		$store = $this->getParticipantsStore( $centralUserLookup );

		$this->expectException( CentralUserNotFoundException::class );
		$store->addParticipantToEvent( 1, $loggedOutUser );
	}

	/**
	 * @covers ::removeParticipantFromEvent
	 */
	public function testRemoveParticipantToEvent__loggedOut() {
		$loggedOutUser = $this->createMock( ICampaignsUser::class );
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'getCentralID' )
			->with( $loggedOutUser )
			->willThrowException( $this->createMock( CentralUserNotFoundException::class ) );
		$store = $this->getParticipantsStore( $centralUserLookup );

		$this->expectException( CentralUserNotFoundException::class );
		$store->removeParticipantFromEvent( 1, $loggedOutUser );
	}
}
