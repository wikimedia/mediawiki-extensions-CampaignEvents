<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Participants;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore
 * @covers ::__construct()
 */
class ParticipantsStoreTest extends MediaWikiIntegrationTestCase {
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
						'cep_registered_at' => $this->getDb()->timestamp( '20220315120000' ),
						'cep_unregistered_at' => null,
						'cep_first_answer_timestamp' => null,
						'cep_aggregation_timestamp' => null,
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 102,
						'cep_private' => false,
						'cep_registered_at' => $this->getDb()->timestamp( '20220315120000' ),
						'cep_unregistered_at' => $this->getDb()->timestamp( '20220324120000' ),
						'cep_first_answer_timestamp' => null,
						'cep_aggregation_timestamp' => null,
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 104,
						'cep_private' => false,
						'cep_registered_at' => $this->getDb()->timestamp( '20220316120000' ),
						'cep_unregistered_at' => null,
						'cep_first_answer_timestamp' => null,
						'cep_aggregation_timestamp' => null,
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 106,
						'cep_private' => true,
						'cep_registered_at' => $this->getDb()->timestamp( '20220316120000' ),
						'cep_unregistered_at' => null,
						'cep_first_answer_timestamp' => $this->getDb()->timestamp( '20220316120000' ),
						'cep_aggregation_timestamp' => $this->getDb()->timestamp( '20230316120000' ),
					],
					[
						'cep_event_id' => $eventID,
						'cep_user_id' => 107,
						'cep_private' => false,
						'cep_registered_at' => $this->getDb()->timestamp( '20220315120000' ),
						'cep_unregistered_at' => $this->getDb()->timestamp( '20220324120000' ),
						'cep_first_answer_timestamp' => $this->getDb()->timestamp( '20220316120000' ),
						'cep_aggregation_timestamp' => $this->getDb()->timestamp( '20230316120000' ),
					],
				]
			);
		}
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_participants' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();
	}

	private function getStore(): ParticipantsStore {
		return new ParticipantsStore(
			CampaignEventsServices::getDatabaseHelper(),
			CampaignEventsServices::getCentralUserLookup(),
			CampaignEventsServices::getParticipantAnswersStore()
		);
	}

	/**
	 * @param int $eventID
	 * @param int $userID
	 * @param bool $private
	 * @param Answer[] $answers
	 * @param int $expected
	 * @covers ::addParticipantToEvent
	 * @dataProvider provideParticipantsToStore
	 */
	public function testAddParticipantToEvent(
		int $eventID,
		int $userID,
		bool $private,
		array $answers,
		int $expected
	) {
		$user = new CentralUser( $userID );
		$this->assertSame( $expected, $this->getStore()->addParticipantToEvent( $eventID, $user, $private, $answers ) );
	}

	public static function provideParticipantsToStore(): Generator {
		yield 'First participant' => [ 10, 102, false, [], ParticipantsStore::MODIFIED_REGISTRATION ];
		yield 'Add participant to existing event' => [ 1, 103, false, [], ParticipantsStore::MODIFIED_REGISTRATION ];
		yield 'Add private participant to existing event' => [
			3,
			107,
			true,
			[],
			ParticipantsStore::MODIFIED_REGISTRATION
		];
		yield 'Changing a participant from private to public' => [
			1,
			106,
			false,
			[],
			ParticipantsStore::MODIFIED_INFO
		];
		yield 'Changing a participant from public to private' => [
			1,
			101,
			true,
			[],
			ParticipantsStore::MODIFIED_INFO
		];
		yield 'Setting to private a participant that is already private' => [
			1,
			106,
			true,
			[],
			ParticipantsStore::MODIFIED_NOTHING
		];
		yield 'Already an active participant' => [ 1, 101, false, [], ParticipantsStore::MODIFIED_NOTHING ];
		yield 'Had unregistered' => [ 1, 102, false, [], ParticipantsStore::MODIFIED_REGISTRATION ];
		yield 'Already a participant, add answers, visibility unchanged' => [
			1,
			101,
			false,
			[ new Answer( 1, 1, null ) ],
			ParticipantsStore::MODIFIED_INFO
		];
		yield 'Already a participant, add answers, change visibility' => [
			1,
			101,
			true,
			[ new Answer( 1, 1, null ) ],
			ParticipantsStore::MODIFIED_INFO
		];
		yield 'Add new participant with answers' => [
			1,
			103,
			false,
			[ new Answer( 1, 1, null ) ],
			ParticipantsStore::MODIFIED_REGISTRATION
		];
		yield 'Restore deleted participant, add answers' => [
			1,
			102,
			false,
			[ new Answer( 1, 1, null ) ],
			ParticipantsStore::MODIFIED_REGISTRATION
		];
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

	public static function provideParticipantsToRemove(): Generator {
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
			$ts = $this->getDb()->newSelectQueryBuilder()
				->select( 'cep_registered_at' )
				->from( 'ce_participants' )
				->where( [ 'cep_event_id' => $eventID, 'cep_user_id' => $userID ] )
				->fetchField();
			if ( $ts === false ) {
				$this->fail( 'No actual timestamp' );
			}
			return wfTimestamp( TS_MW, $ts );
		};

		$ts1 = '20220227120001';
		MWTimestamp::setFakeTime( $ts1 );
		$store->addParticipantToEvent( $eventID, $user, false, [] );
		$this->assertSame( $ts1, $getActualTS(), 'Registering for the first time' );

		$ts2 = '20220227120002';
		MWTimestamp::setFakeTime( $ts2 );
		$store->removeParticipantFromEvent( $eventID, $user );
		$this->assertSame( $ts1, $getActualTS(), 'Unregistering does not change the timestamp' );

		$ts3 = '20220227120003';
		MWTimestamp::setFakeTime( $ts3 );
		$store->addParticipantToEvent( $eventID, $user, false, [] );
		$this->assertSame( $ts3, $getActualTS(), 'Registering after having unregistered resets the timestamp' );

		$ts4 = '20220227120004';
		MWTimestamp::setFakeTime( $ts4 );
		$store->addParticipantToEvent( $eventID, $user, false, [] );
		$this->assertSame( $ts3, $getActualTS(), 'Registering when already registered does not change the timestamp' );
	}

	/**
	 * @covers ::getEventParticipants
	 * @dataProvider provideGetEventParticipants_Specific
	 */
	public function testGetEventParticipants_SpecificUsers(
		int $eventID,
		array $expectedParticipants,
		array $specificUserIDs,
		?int $limit = null,
		?int $offset = null
	) {
		$actualUsers = $this->getStore()->getEventParticipants(
			$eventID,
			$limit,
			$offset,
			null,
			$specificUserIDs,
			true
		);

		$this->checkParticipants( $actualUsers, $expectedParticipants );
	}

	/**
	 * @covers ::getEventParticipants
	 * @dataProvider provideGetEventParticipants_Public
	 */
	public function testGetEventParticipants_Public(
		int $eventID,
		array $expectedParticipants,
		?int $limit = null,
		?int $offset = null
	) {
		$actualUsers = $this->getStore()->getEventParticipants(
			$eventID,
			$limit,
			$offset
		);

		$this->checkParticipants( $actualUsers, $expectedParticipants );
	}

	/**
	 * @covers ::getEventParticipants
	 * @dataProvider provideGetEventParticipants_Private
	 */
	public function testGetEventParticipants_Private(
		int $eventID,
		array $expectedParticipants,
		?int $limit = null,
		?int $offset = null
	) {
		$actualUsers = $this->getStore()->getEventParticipants(
			$eventID,
			$limit,
			$offset,
			null,
			null,
			true );

		$this->checkParticipants( $actualUsers, $expectedParticipants );
	}

	/**
	 * @param array $actualUsers
	 * @param array $expectedParticipants
	 * @return void
	 */
	public function checkParticipants( array $actualUsers, array $expectedParticipants ): void {
		$this->assertSameSize( $actualUsers, $expectedParticipants );
		foreach ( $actualUsers as $participant ) {
			$participantID = $participant->getUser()->getCentralID();
			$this->assertSame(
				wfTimestamp( TS_UNIX, $expectedParticipants[$participantID]['registeredAt'] ),
				$participant->getRegisteredAt()
			);
		}
	}

	public static function provideGetEventParticipants_Specific(): Generator {
		yield 'Only inludes non-deleted public participants' => [
			1,
			[
				'104' => [
					'registeredAt' => '20220316120000'
				],
			],
			[ 104 ],
		];
		yield 'Test limit and offset' => [
			1,
			[
				'104' => [
					'registeredAt' => '20220316120000'
				],
			],
			[ 104 ],
			2,
			1,
		];
	}

	public static function provideGetEventParticipants_Public(): Generator {
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

	public static function provideGetEventParticipants_Private(): Generator {
		yield 'Only includes non-deleted participants' => [
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

	public static function provideGetEventParticipant(): Generator {
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
		$store->addParticipantToEvent( $eventID, $participant, false, [] );
		$this->assertTrue( $store->userParticipatesInEvent( $eventID, $participant, true ) );
	}

	/**
	 * @dataProvider provideUserHasAggregatedAnswers
	 * @covers ::userHasAggregatedAnswers
	 */
	public function testUserHasAggregatedAnswers( int $event, int $userID, bool $expected ) {
		$this->assertSame(
			$expected,
			$this->getStore()->userHasAggregatedAnswers( $event, new CentralUser( $userID ) )
		);
	}

	public static function provideUserHasAggregatedAnswers() {
		yield 'Active participant, no aggregation' => [ 1, 101, false ];
		yield 'Active participant, has aggregation' => [ 1, 106, true ];
		yield 'Deleted participant, no aggregation' => [ 1, 102, false ];
		yield 'Deleted participant, has aggregation' => [ 1, 107, true ];
		yield 'Not a participant' => [ 1, 99999, false ];
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

	public static function provideParticipantCount(): array {
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

	public static function providePrivateParticipantCount(): array {
		return [
			'One private participant (and a deleted one)' => [ 1, 1 ],
			'No participants' => [ 1000, 0 ],
		];
	}

	/**
	 * @covers ::removeParticipantsFromEvent
	 * @dataProvider provideParticipantsToRemoveFromEvent
	 */
	public function testRemoveParticipantsFromEvent(
		int $eventID,
		?array $userIDs,
		array $expected
	) {
		$this->assertSame( $expected, $this->getStore()->removeParticipantsFromEvent( $eventID, $userIDs ) );
	}

	public static function provideParticipantsToRemoveFromEvent(): Generator {
		yield 'Remove two participants' => [
			2,
			[ new CentralUser( 101 ), new CentralUser( 104 ) ],
			[ 'public' => 2, 'private' => 0 ]
		];
		yield 'Remove all participants' => [ 3, null, [ 'public' => 2, 'private' => 1 ] ];
		yield 'Remove one participant' => [ 1, [ new CentralUser( 101 ) ], [ 'public' => 1, 'private' => 0 ] ];
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

	public static function provideParticipantsToRemoveFromEvent__error(): Generator {
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
