<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Participants;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWikiIntegrationTestCase;

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
		$rows = [
			[ 'cep_event_id' => 1, 'cep_user_id' => 101, 'cep_unregistered_at' => null ],
			[ 'cep_event_id' => 1, 'cep_user_id' => 102, 'cep_unregistered_at' => '20220324120000' ],
		];
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
}
