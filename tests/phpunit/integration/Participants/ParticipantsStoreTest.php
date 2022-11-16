<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Participants;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
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
						'cep_private' => false,
						'cep_registered_at' => $this->db->timestamp( '20220315120000' ),
						'cep_unregistered_at' => null
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 102,
						'cep_private' => false,
						'cep_registered_at' => $this->db->timestamp( '20220315120000' ),
						'cep_unregistered_at' => $this->db->timestamp( '20220324120000' ),
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 104,
						'cep_private' => false,
						'cep_registered_at' => $this->db->timestamp( '20220316120000' ),
						'cep_unregistered_at' => null
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 106,
						'cep_private' => true,
						'cep_registered_at' => $this->db->timestamp( '20220316120000' ),
						'cep_unregistered_at' => null
					],
				]
			);
		}
		$this->db->insert( 'ce_participants', $rows );
	}

	private function getStore(): ParticipantsStore {
		return new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			CampaignEventsServices::getCampaignsCentralUserLookup()
		);
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param bool $private
	 * @param bool $expected
	 * @covers ::addParticipantToEvent
	 * @dataProvider provideParticipantsToStore
	 */
	public function testAddParticipantToEvent( int $eventID, int $userID, bool $private, bool $expected ) {
		$user = new CentralUser( $userID );
		$this->assertSame( $expected, $this->getStore()->addParticipantToEvent( $eventID, $user, $private ) );
	}

	public function provideParticipantsToStore(): Generator {
		yield 'First participant' => [ 10, 102, false , true ];
		yield 'Add participant to existing event' => [ 1, 103, false , true ];
		yield 'Add private participant to existing event' => [ 3, 107, true , true ];
		yield 'Changing a participant from private to public' => [ 1, 106, false, true ];
		yield 'Changing a participant from public to private' => [ 1, 101, true, true ];
		yield 'Setting to private a participant that is already private' => [ 1, 106, true, false ];
		yield 'Already an active participant' => [ 1, 101, false , false ];
		yield 'Had unregistered' => [ 1, 102, false, true ];
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param bool $expected
	 * @covers ::removeParticipantFromEvent
	 * @dataProvider provideParticipantsToRemove
	 */
	public function testRemoveParticipantFromEvent( int $eventID, int $userID, bool $expected ) {
		$user = new CentralUser( $userID );
		$this->assertSame( $expected, $this->getStore()->removeParticipantFromEvent( $eventID, $user ) );
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
		$user = new CentralUser( $userID );
		$store = $this->getStore();
		$getActualTS = function () use ( $eventID, $userID ): ?string {
			$ts = $this->db->selectField(
				'ce_participants',
				'cep_registered_at',
				[ 'cep_event_id' => $eventID, 'cep_user_id' => $userID ]
			);
			if ( $ts === false ) {
				$this->fail( 'No actual timestamp' );
			}
			return wfTimestamp( TS_MW, $ts );
		};

		$ts1 = '20220227120001';
		MWTimestamp::setFakeTime( $ts1 );
		$store->addParticipantToEvent( $eventID, $user, false );
		$this->assertSame( $ts1, $getActualTS(), 'Registering for the first time' );

		$ts2 = '20220227120002';
		MWTimestamp::setFakeTime( $ts2 );
		$store->removeParticipantFromEvent( $eventID, $user );
		$this->assertSame( $ts1, $getActualTS(), 'Unregistering does not change the timestamp' );

		$ts3 = '20220227120003';
		MWTimestamp::setFakeTime( $ts3 );
		$store->addParticipantToEvent( $eventID, $user, false );
		$this->assertSame( $ts3, $getActualTS(), 'Registering after having unregistered resets the timestamp' );

		$ts4 = '20220227120004';
		MWTimestamp::setFakeTime( $ts4 );
		$store->addParticipantToEvent( $eventID, $user, false );
		$this->assertSame( $ts3, $getActualTS(), 'Registering when already registered does not change the timestamp' );
	}

	/**
	 * @covers ::getEventParticipants
	 * @dataProvider provideGetEventParticipants_Public
	 */
	public function testGetEventParticipants_Public(
		int $eventID,
		array $expectedParticipants,
		int $limit = null,
		int $offset = null
	) {
		$actualUsers = $this->getStore()->getEventParticipants( $eventID, $limit, $offset );

		$this->checkParticipants( $actualUsers, $expectedParticipants );
	}

	/**
	 * @covers ::getEventParticipants
	 * @dataProvider provideGetEventParticipants_Private
	 */
	public function testGetEventParticipants_Private(
		int $eventID,
		array $expectedParticipants,
		int $limit = null,
		int $offset = null
	) {
		$actualUsers = $this->getStore()->getEventParticipants( $eventID, $limit, $offset, null, true );

		$this->checkParticipants( $actualUsers, $expectedParticipants );
	}

	/**
	 * @param array $actualUsers
	 * @param array $expectedParticipants
	 * @return void
	 */
	public function checkParticipants( array $actualUsers, array $expectedParticipants ): void {
		$this->assertCount( count( $actualUsers ), $expectedParticipants );
		foreach ( $actualUsers as $participant ) {
			$participantID = $participant->getUser()->getCentralID();
			$this->assertSame(
				wfTimestamp( TS_UNIX, $expectedParticipants[$participantID]['registeredAt'] ),
				$participant->getRegisteredAt()
			);
		}
	}

	public function provideGetEventParticipants_Public(): Generator {
		yield 'Only inludes non-deleted public participants' => [
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
		yield 'Test limit and offset' => [
			1,
			[
				'104' => [
					'registeredAt' => '20220316120000'
				],
			],
			2,
			1
		];
		yield 'No participants' => [
			5,
			[]
		];
	}

	public function provideGetEventParticipants_Private(): Generator {
		yield 'Only inludes non-deleted participants' => [
			1,
			[
				'101' => [
					'registeredAt' => '20220315120000'
				],
				'104' => [
					'registeredAt' => '20220316120000'
				],
				'106' => [
					'registeredAt' => '20220316120000'
				],
			]
		];
		yield 'Test limit and offset' => [
			1,
			[
				'104' => [
					'registeredAt' => '20220316120000'
				],
				'106' => [
					'registeredAt' => '20220316120000'
				],
			],
			2,
			2
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
		$store = $this->getStore();
		$this->assertCount( 2, $store->getEventParticipants( 1 ), 'precondition' );
		$limit = 0;
		$this->assertCount( $limit, $store->getEventParticipants( 1, $limit ) );
	}

	/**
	 * @covers ::getEventParticipant
	 * @dataProvider provideGetEventParticipant
	 */
	public function testGetEventParticipant( int $event, int $userID, bool $showPrivate, bool $expectedFound ) {
		$store = $this->getStore();
		$res = $store->getEventParticipant( $event, new CentralUser( $userID ), $showPrivate );
		if ( $expectedFound ) {
			$this->assertInstanceOf( Participant::class, $res );
		} else {
			$this->assertNull( $res );
		}
	}

	public function provideGetEventParticipant(): Generator {
		yield 'Not a participant' => [ 1, 12345678, true, false ];
		yield 'Unregistered' => [ 1, 102, true, false ];
		yield 'Private, but showPrivate is false' => [ 1, 106, false, false ];
		yield 'Public' => [ 1, 101, true, true ];
		yield 'Private and showPrivate is true' => [ 1, 106, true, true ];
	}

	/**
	 * @covers ::userParticipatesInEvent
	 */
	public function testUserParticipatesToEvent() {
		$participant = new CentralUser( 1234 );
		$store = $this->getStore();
		$eventID = 42;
		$this->assertFalse( $store->userParticipatesInEvent( $eventID, $participant, true ), 'precondition' );
		$store->addParticipantToEvent( $eventID, $participant, false );
		$this->assertTrue( $store->userParticipatesInEvent( $eventID, $participant, true ) );
	}

	/**
	 * @param int $event
	 * @param int $expected
	 * @dataProvider provideParticipantCount
	 * @covers ::getFullParticipantCountForEvent
	 * @covers ::getParticipantCountForEvent
	 */
	public function testGetFullParticipantCountForEvent( int $event, int $expected ) {
		$this->assertSame( $expected, $this->getStore()->getFullParticipantCountForEvent( $event ) );
	}

	public function provideParticipantCount(): array {
		return [
			'Three participants (and a deleted one)' => [ 1, 3 ],
			'No participants' => [ 1000, 0 ],
		];
	}

	/**
	 * @param int $event
	 * @param int $expected
	 * @dataProvider providePrivateParticipantCount
	 * @covers ::getPrivateParticipantCountForEvent
	 * @covers ::getParticipantCountForEvent
	 */
	public function testGetPrivateParticipantCountForEvent( int $event, int $expected ) {
		$this->assertSame( $expected, $this->getStore()->getPrivateParticipantCountForEvent( $event ) );
	}

	public function providePrivateParticipantCount(): array {
		return [
			'One private participant (and a deleted one)' => [ 1, 1 ],
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
		$this->assertSame( $expected, $this->getStore()->removeParticipantsFromEvent( $eventID, $userIDs ) );
	}

	public function provideParticipantsToRemoveFromEvent(): Generator {
		yield 'Remove two participants' => [ 2, [ new CentralUser( 101 ), new CentralUser( 104 ) ], 2 ];
		yield 'Remove all participants' => [ 3, null, 3 ];
		yield 'Remove one participant' => [ 1, [ new CentralUser( 101 ) ], 1 ];
	}

	/**
	 * @param int $eventID
	 * @param array|null $userIDs
	 * @param bool $invertUsers
	 * @param string $errorMessage
	 * @covers ::removeParticipantsFromEvent
	 * @dataProvider provideParticipantsToRemoveFromEvent__error
	 */
	public function testRemoveParticipantsFromEvent__error(
		int $eventID,
		?array $userIDs,
		bool $invertUsers,
		string $errorMessage
	) {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $errorMessage );
		$participantStore = $this->getStore();
		$participantStore->removeParticipantsFromEvent( $eventID, $userIDs, $invertUsers );
	}

	public function provideParticipantsToRemoveFromEvent__error(): Generator {
		yield 'Empty user ids' => [
			3, [], false,
			'The users must be an array of user ids, or null (to remove all users)'
		];
		yield 'User ids null and invert users true' => [
			3, null, true,
			'The users must be an array of user ids if invertUsers is true'
		];
	}
}
