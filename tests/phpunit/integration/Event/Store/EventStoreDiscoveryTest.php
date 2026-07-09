<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Event\Store;

use DateTimeZone;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Event\Store\EventStore::getEventsForDiscoveryByPage
 * @group Database
 */
class EventStoreDiscoveryTest extends MediaWikiIntegrationTestCase {

	private const WORKLIST_ID = 1;
	private const PAGE_TITLE = 'Test discovery article';

	private function saveTestEvent(): int {
		$this->editPage( Title::makeTitle( NS_MAIN, 'Test_event_page' ), 'Event page' );
		$event = new EventRegistration(
			null,
			'Test discovery event',
			new MWPageProxy(
				new PageIdentityValue( 0, NS_MAIN, 'Test_event_page', PageIdentityValue::LOCAL ),
				'Test event page'
			),
			EventRegistration::STATUS_OPEN,
			new DateTimeZone( 'UTC' ),
			wfTimestamp( TS::MW, time() - 3600 ),
			wfTimestamp( TS::MW, time() + 3600 ),
			[ EventTypesRegistry::EVENT_TYPE_OTHER ],
			EventRegistration::ALL_WIKIS,
			[],
			EventRegistration::PARTICIPATION_OPTION_ONLINE,
			null,
			null,
			null,
			false,
			false,
			null,
			[],
			[],
			null,
			null,
			null,
		);
		return CampaignEventsServices::getEventStore()->saveRegistration( $event );
	}

	private function linkEventToPage( int $eventID, string $pageTitle, string $wikiID ): void {
		$db = $this->getDb();
		$db->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_events' )
			->row( [ 'cewe_cew_id' => self::WORKLIST_ID, 'cewe_event_id' => $eventID ] )
			->caller( __METHOD__ )
			->execute();
		$db->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_pages' )
			->row( [
				'cewp_wiki' => $wikiID,
				'cewp_page_prefixedtext' => $pageTitle,
				'cewp_user_id' => 1,
				'cewp_cew_id' => self::WORKLIST_ID,
				'cewp_timestamp' => $db->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public function testReturnsEventsForMatchingPage(): void {
		$eventID = $this->saveTestEvent();
		$wikiID = WikiMap::getCurrentWikiId();
		$this->linkEventToPage( $eventID, self::PAGE_TITLE, $wikiID );

		$results = CampaignEventsServices::getEventLookup()->getEventsForDiscoveryByPage(
			self::PAGE_TITLE,
			$wikiID,
			new CentralUser( 999 ),
			10
		);

		$this->assertCount( 1, $results );
		$this->assertArrayHasKey( $eventID, $results );
	}

	public function testExcludesParticipatingUser(): void {
		$eventID = $this->saveTestEvent();
		$wikiID = WikiMap::getCurrentWikiId();
		$this->linkEventToPage( $eventID, self::PAGE_TITLE, $wikiID );

		$centralUserID = 888;
		$db = $this->getDb();
		$db->newInsertQueryBuilder()
			->insertInto( 'ce_participants' )
			->row( [
				'cep_event_id' => $eventID,
				'cep_user_id' => $centralUserID,
				'cep_private' => 0,
				'cep_registered_at' => $db->timestamp(),
				'cep_unregistered_at' => null,
				'cep_first_answer_timestamp' => null,
				'cep_aggregation_timestamp' => null,
				'cep_hide_contribution_association_prompt' => 0,
			] )
			->caller( __METHOD__ )
			->execute();

		$results = CampaignEventsServices::getEventLookup()->getEventsForDiscoveryByPage(
			self::PAGE_TITLE,
			$wikiID,
			new CentralUser( $centralUserID ),
			10
		);

		$this->assertCount( 0, $results );
	}

	public function testExcludesPageOnDifferentWiki(): void {
		$eventID = $this->saveTestEvent();
		$this->linkEventToPage( $eventID, self::PAGE_TITLE, 'otherwiki' );

		$results = CampaignEventsServices::getEventLookup()->getEventsForDiscoveryByPage(
			self::PAGE_TITLE,
			WikiMap::getCurrentWikiId(),
			new CentralUser( 999 ),
			10
		);

		$this->assertCount( 0, $results );
	}

	public function testExcludesDifferentPageTitle(): void {
		$eventID = $this->saveTestEvent();
		$wikiID = WikiMap::getCurrentWikiId();
		$this->linkEventToPage( $eventID, 'Different article', $wikiID );

		$results = CampaignEventsServices::getEventLookup()->getEventsForDiscoveryByPage(
			self::PAGE_TITLE,
			$wikiID,
			new CentralUser( 999 ),
			10
		);

		$this->assertCount( 0, $results );
	}
}
