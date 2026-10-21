<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ContentTranslationPublishHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * Integration test because the handler relies on ContentTranslation's site mapping, which reads global config.
 *
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ContentTranslationPublishHandler
 */
class ContentTranslationPublishHandlerTest extends MediaWikiIntegrationTestCase {
	private const CENTRAL_USER_ID = 1;
	private const REVISION_ID = 555;
	private const SOURCE_TITLE = 'Some_article';
	private const TARGET_TITLE = 'Translated_article';

	private WikiLookup $wikiLookup;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'ContentTranslation' );
		// Make the source language "en" map to the "enwiki" wiki, and any other language to an unknown wiki.
		$this->setUpWikis(
			[ 'enwiki' => 'https://en.example.org/w/api.php' ],
			[],
			'https://$1.example.org/w/api.php'
		);
	}

	/**
	 * @param array<string,string|null> $apiUrls Map of wiki ID to the URL of its api.php, as returned by
	 *   WikiLookup::getAllApiUrls()
	 * @param array<string,string> $domainCodeMapping
	 * @param string $apiTemplate
	 */
	private function setUpWikis( array $apiUrls, array $domainCodeMapping, string $apiTemplate ): void {
		$this->wikiLookup = $this->createMock( WikiLookup::class );
		$this->wikiLookup->method( 'getAllApiUrls' )->willReturn( $apiUrls );
		$this->overrideConfigValue( 'ContentTranslationDomainCodeMapping', $domainCodeMapping );
		$this->overrideConfigValue( 'ContentTranslationSiteTemplates', [ 'api' => $apiTemplate ] );
	}

	private function getHandler(
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?IEventLookup $eventLookup = null,
		?WorklistEventsStore $worklistEventsStore = null,
		?EventContributionValidator $eventContributionValidator = null,
	): ContentTranslationPublishHandler {
		return new ContentTranslationPublishHandler(
			$centralUserLookup ?? $this->makeCentralUserLookup(),
			$eventLookup ?? $this->createNoOpMock( IEventLookup::class ),
			$worklistEventsStore ?? $this->createNoOpMock( WorklistEventsStore::class ),
			$eventContributionValidator ?? $this->createNoOpMock( EventContributionValidator::class ),
			$this->getServiceContainer()->getNamespaceInfo(),
			$this->getServiceContainer()->getTitleFormatter(),
			$this->wikiLookup,
			$this->getServiceContainer()->getMainConfig(),
		);
	}

	private function makeCentralUserLookup( bool $hasGlobalAccount = true ): CampaignsCentralUserLookup {
		$lookup = $this->createMock( CampaignsCentralUserLookup::class );
		if ( $hasGlobalAccount ) {
			$lookup->method( 'newFromUserIdentity' )->willReturn( new CentralUser( self::CENTRAL_USER_ID ) );
		} else {
			$lookup->method( 'newFromUserIdentity' )
				->willThrowException( new UserNotGlobalException( self::CENTRAL_USER_ID ) );
		}
		return $lookup;
	}

	/**
	 * @param int[] $eventIDs
	 */
	private function makeEventLookup( array $eventIDs ): IEventLookup {
		$events = [];
		foreach ( $eventIDs as $id ) {
			$event = $this->createMock( ExistingEventRegistration::class );
			$event->method( 'getID' )->willReturn( $id );
			$events[] = $event;
		}
		$lookup = $this->createMock( IEventLookup::class );
		$lookup->method( 'getEventsForContributionAssociationByParticipant' )->willReturn( $events );
		return $lookup;
	}

	private function runHook(
		ContentTranslationPublishHandler $handler,
		int $namespace = NS_MAIN,
		string $sourceLanguage = 'en'
	): void {
		$handler->onContentTranslationAfterPublish(
			new UserIdentityValue( 42, 'Translator' ),
			new PageIdentityValue( 10, $namespace, self::TARGET_TITLE, PageIdentityValue::LOCAL ),
			self::REVISION_ID,
			$sourceLanguage,
			self::SOURCE_TITLE,
			'fr',
			false
		);
	}

	public function testSkip_nonContentNamespace(): void {
		$this->runHook(
			$this->getHandler( centralUserLookup: $this->createNoOpMock( CampaignsCentralUserLookup::class ) ),
			NS_USER
		);
	}

	public function testSkip_noGlobalAccount(): void {
		$this->runHook( $this->getHandler( centralUserLookup: $this->makeCentralUserLookup( false ) ) );
	}

	public function testSkip_noEvents(): void {
		$this->runHook( $this->getHandler( eventLookup: $this->makeEventLookup( [] ) ) );
	}

	/**
	 * Returns a worklist store where each page is in the worklist of the given events. Pages are identified by
	 * wiki ID and prefixed text.
	 *
	 * @param array<string,array<string,int[]>> $eventsByWikiAndPage
	 */
	private function makeWorklistEventsStore( array $eventsByWikiAndPage ): WorklistEventsStore {
		$store = $this->createMock( WorklistEventsStore::class );
		$store->method( 'filterEventsByPageInWorklist' )->willReturnCallback(
			static fn ( array $eventIDs, string $wikiID, string $prefixedText ): array => array_values(
				array_intersect( $eventIDs, $eventsByWikiAndPage[$wikiID][$prefixedText] ?? [] )
			)
		);
		return $store;
	}

	/**
	 * @dataProvider provideSingleMatch
	 */
	public function testAutoAssociatesWithSingleMatchingEvent(
		array $sourcePageEvents,
		array $targetPageEvents,
		string $sourceLanguage,
		int $expectedEventID
	): void {
		$curWikiID = WikiMap::getCurrentWikiId();
		// Worklist pages are matched as prefixed text.
		$worklistEventsStore = $this->makeWorklistEventsStore( [
			'enwiki' => [ 'Some article' => $sourcePageEvents ],
			$curWikiID => [ 'Translated article' => $targetPageEvents ],
		] );

		$validator = $this->createMock( EventContributionValidator::class );
		$validator->expects( $this->once() )
			->method( 'scheduleAssociationJob' )
			->with( self::REVISION_ID, $curWikiID, $expectedEventID, self::CENTRAL_USER_ID );

		$this->runHook(
			$this->getHandler(
				eventLookup: $this->makeEventLookup( [ 1, 2, 3 ] ),
				worklistEventsStore: $worklistEventsStore,
				eventContributionValidator: $validator,
			),
			sourceLanguage: $sourceLanguage
		);
	}

	public static function provideSingleMatch(): Generator {
		yield 'Source page in worklist' => [ [ 2 ], [], 'en', 2 ];
		yield 'Target page in worklist' => [ [], [ 2 ], 'en', 2 ];
		yield 'Source and target page in the same worklist' => [ [ 2 ], [ 2 ], 'en', 2 ];
		yield 'Only events the translator can associate edits with' => [ [ 2, 4 ], [], 'en', 2 ];
		yield 'Unknown source wiki, target page in worklist' => [ [ 1 ], [ 2 ], 'xx', 2 ];
	}

	/**
	 * @dataProvider provideNoSingleMatch
	 */
	public function testDoesNotAssociateWithoutSingleMatch(
		array $sourcePageEvents,
		array $targetPageEvents
	): void {
		$worklistEventsStore = $this->makeWorklistEventsStore( [
			'enwiki' => [ 'Some article' => $sourcePageEvents ],
			WikiMap::getCurrentWikiId() => [ 'Translated article' => $targetPageEvents ],
		] );

		$validator = $this->createMock( EventContributionValidator::class );
		$validator->expects( $this->never() )->method( 'scheduleAssociationJob' );

		$this->runHook( $this->getHandler(
			eventLookup: $this->makeEventLookup( [ 1, 2 ] ),
			worklistEventsStore: $worklistEventsStore,
			eventContributionValidator: $validator,
		) );
	}

	public static function provideNoSingleMatch(): Generator {
		yield 'No matching events' => [ [], [] ];
		yield 'Source page in multiple worklists' => [ [ 1, 2 ], [] ];
		yield 'Target page in multiple worklists' => [ [], [ 1, 2 ] ];
		yield 'Source and target page in different worklists' => [ [ 1 ], [ 2 ] ];
	}

	/**
	 * Returns the IDs of the wikis where the handler looks up the source page.
	 *
	 * @return string[]
	 */
	private function getSourceWikiIDs( string $sourceLanguage ): array {
		$sourceWikiIDs = [];
		$worklistEventsStore = $this->createMock( WorklistEventsStore::class );
		$worklistEventsStore->method( 'filterEventsByPageInWorklist' )->willReturnCallback(
			static function ( array $eventIDs, string $wikiID, string $prefixedText ) use ( &$sourceWikiIDs ): array {
				if ( $prefixedText === 'Some article' ) {
					$sourceWikiIDs[] = $wikiID;
				}
				return [];
			}
		);

		$this->runHook(
			$this->getHandler(
				eventLookup: $this->makeEventLookup( [ 1 ] ),
				worklistEventsStore: $worklistEventsStore,
			),
			sourceLanguage: $sourceLanguage
		);
		return $sourceWikiIDs;
	}

	/**
	 * Wikis that share a host and only differ by path, which WikiMap::getWikiFromUrl() can't tell apart (T441941).
	 *
	 * @dataProvider provideSourceWikiWithSharedHost
	 */
	public function testSourceWikiWithSharedHost( string $sourceLanguage, ?string $expectedWikiID ): void {
		$this->setUpWikis(
			[
				'enwiki' => 'http://localhost:8080/w/api.php',
				'frwiki' => 'http://localhost:8080/w2/api.php',
				'dewiki' => null,
			],
			[ 'en' => 'w', 'fr' => 'w2' ],
			'http://localhost:8080/$1/api.php'
		);
		$this->assertSame(
			$expectedWikiID !== null ? [ $expectedWikiID ] : [],
			$this->getSourceWikiIDs( $sourceLanguage )
		);
	}

	public static function provideSourceWikiWithSharedHost(): Generator {
		yield 'First wiki' => [ 'en', 'enwiki' ];
		yield 'Second wiki' => [ 'fr', 'frwiki' ];
		yield 'Unknown wiki on the same host' => [ 'de', null ];
	}

	/**
	 * The 'api' template may be protocol-relative. ContentTranslation adds HTTPS to it.
	 */
	public function testSourceWikiWithProtocolRelativeTemplate(): void {
		$this->setUpWikis(
			[ 'enwiki' => 'https://en.example.org/w/api.php' ],
			[],
			'//$1.example.org/w/api.php'
		);
		$this->assertSame( [ 'enwiki' ], $this->getSourceWikiIDs( 'en' ) );
	}
}
