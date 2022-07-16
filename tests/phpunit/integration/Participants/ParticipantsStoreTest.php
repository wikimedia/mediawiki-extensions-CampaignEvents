<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Participants;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWikiIntegrationTestCase;
use MWTimestamp;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore
 * @covers ::__construct()
 */
class ParticipantsStoreTest extends MediaWikiIntegrationTestCase {
	/** @inheritDoc */
	protected $tablesUsed = [ 'ce_participants' ];

	/**
	 * @inheritDoc
	 */
	public function addDBData(): void {
		$rows = [];
		for ( $eventID = 1; $eventID < 4; $eventID++ ) {
			$rows = array_merge(
				$rows,
				[
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 101,
						'cep_registered_at' => $this->db->timestamp( '20220315120000' ),
						'cep_unregistered_at' => null
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 102,
						'cep_registered_at' => $this->db->timestamp( '20220315120000' ),
						'cep_unregistered_at' => $this->db->timestamp( '20220324120000' ),
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 104,
						'cep_registered_at' => $this->db->timestamp( '20220316120000' ),
						'cep_unregistered_at' => null
					],
				]
			);
		}
		$this->db->insert( 'ce_participants', $rows );
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param bool $expected
	 * @covers ::addParticipantToEvent
	 * @dataProvider provideParticipantsToStore
	 */
	public function testAddParticipantToEvent( int $eventID, int $userID, bool $expected ) {
		$user = $this->createMock( ICampaignsUser::class );
		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$userLookup->method( 'getCentralID' )
			->with( $user )
			->willReturn( $userID );
		$store = new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			$userLookup
		);
		$this->assertSame( $expected, $store->addParticipantToEvent( $eventID, $user ) );
	}

	public function provideParticipantsToStore(): Generator {
		yield 'First participant' => [ 2, 102, true ];
		yield 'Add participant to existing event' => [ 1, 103, true ];
		yield 'Already an active participant' => [ 1, 101, false ];
		yield 'Had unregistered' => [ 1, 102, true ];
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param bool $expected
	 * @covers ::removeParticipantFromEvent
	 * @dataProvider provideParticipantsToRemove
	 */
	public function testRemoveParticipantFromEvent( int $eventID, int $userID, bool $expected ) {
		$user = $this->createMock( ICampaignsUser::class );
		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$userLookup->method( 'getCentralID' )
			->with( $user )
			->willReturn( $userID );
		$store = new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			$userLookup
		);
		$this->assertSame( $expected, $store->removeParticipantFromEvent( $eventID, $user ) );
	}

	public function provideParticipantsToRemove(): Generator {
		yield 'Actively registered' => [ 1, 101, true ];
		yield 'Never registered' => [ 4, 101, false ];
		yield 'Already deleted' => [ 1, 102, false ];
	}

	/**
	 * @covers ::addParticipantToEvent
	 * @covers ::removeParticipantFromEvent
	 */
	public function testRegistrationTimestamp() {
		$eventID = 42;
		$userID = 100;
		$user = $this->createMock( ICampaignsUser::class );
		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$userLookup->method( 'getCentralID' )
			->with( $user )
			->willReturn( $userID );
		$store = new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			$userLookup
		);
		$getActualTS = function () use ( $eventID, $userID ): string {
			$ts = $this->db->selectField(
				'ce_participants',
				'cep_registered_at',
				[ 'cep_event_id' => $eventID, 'cep_user_id' => $userID ]
			);
			return $ts === false ? $ts : wfTimestamp( TS_MW, $ts );
		};

		$ts1 = '20220227120001';
		MWTimestamp::setFakeTime( $ts1 );
		$store->addParticipantToEvent( $eventID, $user );
		$this->assertSame( $ts1, $getActualTS(), 'Registering for the first time' );

		$ts2 = '20220227120002';
		MWTimestamp::setFakeTime( $ts2 );
		$store->removeParticipantFromEvent( $eventID, $user );
		$this->assertSame( $ts1, $getActualTS(), 'Unregistering does not change the timestamp' );

		$ts3 = '20220227120003';
		MWTimestamp::setFakeTime( $ts3 );
		$store->addParticipantToEvent( $eventID, $user );
		$this->assertSame( $ts3, $getActualTS(), 'Registering after having unregistered resets the timestamp' );

		$ts4 = '20220227120004';
		MWTimestamp::setFakeTime( $ts4 );
		$store->addParticipantToEvent( $eventID, $user );
		$this->assertSame( $ts3, $getActualTS(), 'Registering when already registered does not change the timestamp' );
	}

	/**
	 * @covers ::getEventParticipants
	 * @dataProvider provideGetEventParticipants
	 */
	public function testGetEventParticipants( int $eventID, array $expectedParticipants ) {
		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$userLookup->method( 'getLocalUser' )
		->willReturnCallback( function ( int $centralID ) {
			$user = $this->createMock( ICampaignsUser::class );
			$user->method( 'getLocalID' )->willReturn( $centralID );
			return $user;
		} );

		$store = new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			$userLookup
		);

		$actualUsers = $store->getEventParticipants( $eventID );

		$this->assertSame( count( $actualUsers ), count( $expectedParticipants ) );
		foreach ( $actualUsers as $participant ) {
			$participantID = $participant->getUser()->getLocalID();
			$this->assertSame(
				wfTimestamp( TS_UNIX, $expectedParticipants[ $participantID ][ 'registeredAt' ] ),
				$participant->getRegisteredAt()
			);
		}
	}

	public function provideGetEventParticipants(): Generator {
		yield 'Only inludes non-deleted participants' => [
			1,
			[
				'101' => [
					'registeredAt' => '20220315120000'
				],
				'104' => [
					'registeredAt' => '20220316120000'
				],
			]
		];
		yield 'No participants' => [
			5,
			[]
		];
	}

	/**
	 * @covers ::getEventParticipants
	 */
	public function testGetEventParticipants__limit() {
		$store = new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			$this->createMock( CampaignsCentralUserLookup::class )
		);
		$this->assertCount( 2, $store->getEventParticipants( 1 ), 'precondition' );
		$limit = 0;
		$this->assertCount( $limit, $store->getEventParticipants( 1, $limit ) );
	}

	/**
	 * @covers ::userParticipatesToEvent
	 */
	public function testUserParticipatesToEvent() {
		$participant = $this->createMock( ICampaignsUser::class );
		$participant->method( 'isRegistered' )->willReturn( true );
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getCentralID' )->with( $participant )->willReturn( 1234 );
		$store = new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			$centralUserLookup
		);
		$eventID = 42;
		$this->assertFalse( $store->userParticipatesToEvent( $eventID, $participant ), 'precondition' );
		$store->addParticipantToEvent( $eventID, $participant );
		$this->assertTrue( $store->userParticipatesToEvent( $eventID, $participant ) );
	}

	/**
	 * @param int $event
	 * @param int $expected
	 * @dataProvider provideParticipantCount
	 * @covers ::getParticipantCountForEvent
	 */
	public function testGetParticipantCountForEvent( int $event, int $expected ) {
		$store = new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			$this->createMock( CampaignsCentralUserLookup::class )
		);
		$this->assertSame( $expected, $store->getParticipantCountForEvent( $event ) );
	}

	public function provideParticipantCount(): array {
		return [
			'Two participant (and a deleted one)' => [ 1, 2 ],
			'No participants' => [ 1000, 0 ],
		];
	}

	/**
	 * @param int $eventID
	 * @param array|null $userIDs
	 * @param int $expected
	 * @covers ::removeParticipantsFromEvent
	 * @dataProvider provideParticipantsToRemoveFromEvent
	 */
	public function testRemoveParticipantsFromEvent(
		int $eventID,
		?array $userIDs,
		int $expected
	) {
		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$store = new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			$userLookup
		);
		$this->assertSame( $expected, $store->removeParticipantsFromEvent( $eventID, $userIDs ) );
	}

	public function provideParticipantsToRemoveFromEvent(): Generator {
		yield 'Remove two participants' => [ 2, [ 101, 104 ], 2 ];
		yield 'Remove all participants' => [ 3, null, 2 ];
		yield 'Empty user ids' => [ 3, [], 0 ];
		yield 'Remove one participant' => [ 1, [ 101 ], 1 ];
	}
}
