<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use Generator;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventWikisStore;
use MediaWiki\WikiMap\WikiMap;

/**
 * Helper trait to test event list pagers.
 */
trait ListPagersTestHelperTrait {
	/** TODO: Make these constants when we support PHP 8.2+ */
	private static string $EVENT_NAME = 'Test event dQw4w9WgXcQ';
	private static int $EVENT_START = 1600000000;
	private static int $EVENT_END = 1700000000;
	private static string $EVENT_TOPIC = 'some-topic';

	public function addDBDataOnce(): void {
		$dbw = $this->getDb();
		$curTS = $dbw->timestamp();
		$row = [
			'event_name' => self::$EVENT_NAME,
			'event_page_namespace' => NS_EVENT,
			'event_page_title' => self::$EVENT_NAME,
			'event_page_prefixedtext' => 'Event:' . self::$EVENT_NAME,
			'event_page_wiki' => WikiMap::getCurrentWikiId(),
			'event_chat_url' => '',
			'event_status' => 1,
			'event_timezone' => 'UTC',
			'event_start_local' => $dbw->timestamp( self::$EVENT_START ),
			'event_start_utc' => $dbw->timestamp( self::$EVENT_START ),
			'event_end_local' => $dbw->timestamp( self::$EVENT_END ),
			'event_end_utc' => $dbw->timestamp( self::$EVENT_END ),
			'event_type' => 'generic',
			'event_meeting_type' => 3,
			'event_meeting_url' => '',
			'event_created_at' => $curTS,
			'event_last_edit' => $curTS,
			'event_deleted_at' => null,
		];
		$dbw->newInsertQueryBuilder()
			->insertInto( 'campaign_events' )
			->row( $row )
			->caller( __METHOD__ )
			->execute();

		$organizerRow = [
			'ceo_event_id' => 1,
			'ceo_user_id' => 1,
			'ceo_roles' => 1,
			'ceo_created_at' => $curTS,
			'ceo_deleted_at' => null,
			'ceo_agreement_timestamp' => null,
		];
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_organizers' )
			->row( $organizerRow )
			->caller( __METHOD__ )
			->execute();

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_wikis' )
			->row( [
				'ceew_event_id' => 1,
				'ceew_wiki' => EventWikisStore::ALL_WIKIS_DB_VALUE,
			] )
			->caller( __METHOD__ )
			->execute();

		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ce_event_topics' )
			->row( [
				'ceet_event_id' => 1,
				'ceet_topic' => self::$EVENT_TOPIC,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private static function getBaseTestCases(): array {
		$delta = 10000;

		$baseCases = [
			'No filters' => [
				'from' => null,
				'to' => null,
				'ongoing' => null,
				'upcoming' => true,
			],

			'Start only, before event' => [
				'from' => self::$EVENT_START - $delta,
				'to' => null,
				'ongoing' => false,
				'upcoming' => true,
			],
			'Start only, during event' => [
				'from' => self::$EVENT_START + $delta,
				'to' => null,
				'ongoing' => true,
				'upcoming' => false,
			],
			'Start only, after event' => [
				'from' => self::$EVENT_END + $delta,
				'to' => null,
				'ongoing' => false,
				'upcoming' => false,
			],

			'End only, before event' => [
				'from' => null,
				'to' => self::$EVENT_START - $delta,
				'ongoing' => null,
				'upcoming' => false,
			],
			'End only, during event' => [
				'from' => null,
				'to' => self::$EVENT_START + $delta,
				'ongoing' => null,
				'upcoming' => true,
			],
			'End only, after event' => [
				'from' => null,
				'to' => self::$EVENT_END + $delta,
				'ongoing' => null,
				'upcoming' => true,
			],

			'Start before, end before' => [
				'from' => self::$EVENT_START - $delta,
				'to' => self::$EVENT_START - $delta / 2,
				'ongoing' => false,
				'upcoming' => false,
			],
			'Start before, end during' => [
				'from' => self::$EVENT_START - $delta,
				'to' => self::$EVENT_START + $delta,
				'ongoing' => false,
				'upcoming' => true,
			],
			'Start before, end after' => [
				'from' => self::$EVENT_START - $delta,
				'to' => self::$EVENT_END + $delta,
				'ongoing' => false,
				'upcoming' => true,
			],
			'Start during, end during' => [
				'from' => self::$EVENT_START + $delta / 2,
				'to' => self::$EVENT_START + $delta,
				'ongoing' => true,
				'upcoming' => false,
			],
			'Start during, end after' => [
				'from' => self::$EVENT_START + $delta,
				'to' => self::$EVENT_END + $delta,
				'ongoing' => true,
				'upcoming' => false,
			],
			'Start after, end after' => [
				'from' => self::$EVENT_END + $delta / 2,
				'to' => self::$EVENT_END + $delta,
				'ongoing' => false,
				'upcoming' => false,
			],
		];

		// Make sure test cases are valid.
		foreach ( $baseCases as $testName => $testData ) {
			if ( $testData['ongoing'] && $testData['upcoming'] ) {
				throw new LogicException( "Ongoing and upcoming overlap for test set '$testName'" );
			}
			if ( $testData['from'] !== null && $testData['ongoing'] === null ) {
				throw new LogicException( "Test set '$testName' should test ongoing events." );
			}
		}

		return $baseCases;
	}

	public static function provideOngoingDateFilters(): Generator {
		$allCases = self::getBaseTestCases();
		foreach ( $allCases as $testName => $testData ) {
			if ( $testData['ongoing'] !== null ) {
				yield $testName => [ $testData['from'], $testData['to'], $testData['ongoing'] ];
			}
		}
	}

	public static function provideUpcomingDateFilters(): Generator {
		$allCases = self::getBaseTestCases();
		foreach ( $allCases as $testName => $testData ) {
			if ( $testData['upcoming'] !== null ) {
				yield $testName => [ $testData['from'], $testData['to'], $testData['upcoming'] ];
			}
		}
	}
}
