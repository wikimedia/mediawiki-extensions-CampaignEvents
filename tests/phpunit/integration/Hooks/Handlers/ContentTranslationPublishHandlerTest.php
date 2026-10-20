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
			new PageIdentityValue( 10, $namespace, 'Translated_article', PageIdentityValue::LOCAL ),
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

	public function testSkip_unknownSourceWiki(): void {
		// The no-op event lookup ensures that we bail out before looking for events.
		$this->runHook( $this->getHandler(), sourceLanguage: 'xx' );
	}

	public function testSkip_noEvents(): void {
		$this->runHook( $this->getHandler( eventLookup: $this->makeEventLookup( [] ) ) );
	}

	public function testAutoAssociatesWithSingleMatchingEvent(): void {
		$curWikiID = WikiMap::getCurrentWikiId();

		$worklistEventsStore = $this->createMock( WorklistEventsStore::class );
		$worklistEventsStore->expects( $this->once() )
			->method( 'filterEventsByPageInWorklist' )
			// Must be matched against the source page, as prefixed text.
			->with( [ 1, 2 ], 'enwiki', 'Some article' )
			->willReturn( [ 2 ] );

		$validator = $this->createMock( EventContributionValidator::class );
		$validator->expects( $this->once() )
			->method( 'scheduleAssociationJob' )
			->with( self::REVISION_ID, $curWikiID, 2, self::CENTRAL_USER_ID );

		$this->runHook( $this->getHandler(
			eventLookup: $this->makeEventLookup( [ 1, 2 ] ),
			worklistEventsStore: $worklistEventsStore,
			eventContributionValidator: $validator,
		) );
	}

	/**
	 * @dataProvider provideNoSingleMatch
	 */
	public function testDoesNotAssociateWithoutSingleMatch( array $matchingEventIDs ): void {
		$worklistEventsStore = $this->createMock( WorklistEventsStore::class );
		$worklistEventsStore->method( 'filterEventsByPageInWorklist' )->willReturn( $matchingEventIDs );

		$validator = $this->createMock( EventContributionValidator::class );
		$validator->expects( $this->never() )->method( 'scheduleAssociationJob' );

		$this->runHook( $this->getHandler(
			eventLookup: $this->makeEventLookup( [ 1, 2 ] ),
			worklistEventsStore: $worklistEventsStore,
			eventContributionValidator: $validator,
		) );
	}

	public static function provideNoSingleMatch(): Generator {
		yield 'No matching events' => [ [] ];
		yield 'Multiple matching events' => [ [ 1, 2 ] ];
	}

	/**
	 * Asserts that the handler looks up the source page on the given wiki, or not at all if null.
	 */
	private function assertSourceWikiLookedUp( string $sourceLanguage, ?string $expectedWikiID ): void {
		$worklistEventsStore = $this->createMock( WorklistEventsStore::class );
		if ( $expectedWikiID !== null ) {
			$worklistEventsStore->expects( $this->once() )
				->method( 'filterEventsByPageInWorklist' )
				->with( [ 1 ], $expectedWikiID, 'Some article' )
				->willReturn( [] );
		} else {
			$worklistEventsStore->expects( $this->never() )->method( 'filterEventsByPageInWorklist' );
		}

		$this->runHook(
			$this->getHandler(
				eventLookup: $this->makeEventLookup( [ 1 ] ),
				worklistEventsStore: $worklistEventsStore,
			),
			sourceLanguage: $sourceLanguage
		);
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
		$this->assertSourceWikiLookedUp( $sourceLanguage, $expectedWikiID );
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
		$this->assertSourceWikiLookedUp( 'en', 'enwiki' );
	}
}
