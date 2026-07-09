<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventDiscovery\IDiscoveryPromotionStore;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\GetPreferencesHandler;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PostEditHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Language\Language;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Skin;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * Integration test because PostEditHandler reaches ExtensionRegistry (via isWikibaseEntityPage),
 * which is disabled in the unit test suite.
 *
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PostEditHandler
 */
class PostEditHandlerTest extends MediaWikiIntegrationTestCase {

	private function getHandler(
		bool $featureEnabled = true,
		?CampaignsCentralUserLookup $centralUserLookup = null,
		?IEventLookup $eventLookup = null,
		?GoalProgressFormatter $goalProgressFormatter = null,
		?IDiscoveryPromotionStore $promotionStore = null,
		?UserOptionsLookup $userOptionsLookup = null,
	): PostEditHandler {
		return new PostEditHandler(
			$centralUserLookup ?? $this->createNoOpMock( CampaignsCentralUserLookup::class ),
			$eventLookup ?? $this->createNoOpMock( IEventLookup::class ),
			$goalProgressFormatter ?? $this->makeGoalProgressFormatter(),
			new HashConfig( [ 'CampaignEventsEnableWorklists' => $featureEnabled ] ),
			$promotionStore ?? $this->createNoOpMock( IDiscoveryPromotionStore::class ),
			$userOptionsLookup ?? $this->createNoOpMock( UserOptionsLookup::class ),
		);
	}

	private function makeOutputPage(
		bool $isPostEdit = true,
		bool $isNamed = true,
		bool $hasPageID = true,
		bool $isRegistered = true,
		bool $inEventNamespace = false,
	): OutputPage {
		$out = $this->createMock( OutputPage::class );
		$out->method( 'getJsConfigVars' )
			->willReturn( $isPostEdit ? [ 'wgPostEdit' => true ] : [] );

		$request = $this->createMock( WebRequest::class );
		$request->method( 'getCheck' )->willReturn( false );
		$out->method( 'getRequest' )->willReturn( $request );

		$authority = $this->createMock( Authority::class );
		$authority->method( 'isNamed' )->willReturn( $isNamed );
		$authority->method( 'isRegistered' )->willReturn( $isRegistered );
		$authority->method( 'getUser' )->willReturn( $this->createMock( User::class ) );
		$out->method( 'getAuthority' )->willReturn( $authority );

		$title = $this->createMock( Title::class );
		$title->method( 'inNamespace' )->willReturn( $inEventNamespace );
		$title->method( 'getArticleID' )->willReturn( $hasPageID ? 42 : 0 );
		$title->method( 'getPrefixedText' )->willReturn( 'Test Article' );
		$out->method( 'getTitle' )->willReturn( $title );

		$language = $this->createMock( Language::class );
		$language->method( 'getCode' )->willReturn( 'en' );
		$out->method( 'getLanguage' )->willReturn( $language );

		return $out;
	}

	private function makeCentralUserLookup( bool $hasGlobalAccount = true ): CampaignsCentralUserLookup {
		$lookup = $this->createMock( CampaignsCentralUserLookup::class );
		if ( $hasGlobalAccount ) {
			$lookup->method( 'newFromAuthority' )->willReturn( new CentralUser( 1 ) );
		} else {
			$lookup->method( 'newFromAuthority' )
				->willThrowException( new UserNotGlobalException( 1 ) );
		}
		return $lookup;
	}

	/**
	 * @param ExistingEventRegistration[] $associationEvents
	 * @param ExistingEventRegistration[] $discoveryEvents
	 */
	private function makeEventLookup(
		array $associationEvents = [],
		array $discoveryEvents = []
	): IEventLookup {
		$lookup = $this->createMock( IEventLookup::class );
		$lookup->method( 'getEventsForContributionAssociationByParticipant' )
			->willReturn( $associationEvents );
		$lookup->method( 'getEventsForDiscoveryByPage' )->willReturn( $discoveryEvents );
		return $lookup;
	}

	private function makeGoalProgressFormatter(): GoalProgressFormatter {
		$formatter = $this->createMock( GoalProgressFormatter::class );
		$formatter->method( 'getProgressData' )->willReturn( null );
		return $formatter;
	}

	private function makeOptedInLookup( bool $optedIn ): UserOptionsLookup {
		$lookup = $this->createMock( UserOptionsLookup::class );
		$lookup->method( 'getBoolOption' )
			->with( $this->anything(), GetPreferencesHandler::OPT_OUT_EVENT_DISCOVERY_PREFERENCE )
			->willReturn( $optedIn );
		return $lookup;
	}

	private function makeEvent( int $id = 1 ): ExistingEventRegistration {
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( $id );
		$event->method( 'getName' )->willReturn( "Event $id" );
		$event->method( 'getEndUTCTimestamp' )->willReturn( wfTimestamp( TS::MW, time() + 3600 ) );
		return $event;
	}

	public function testSkip_eventNamespace(): void {
		$out = $this->makeOutputPage( inEventNamespace: true );
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler()->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testSkip_notPostEdit(): void {
		$out = $this->makeOutputPage( isPostEdit: false );
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler()->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testSkip_noGlobalAccount(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup( false ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testShowsAssociationDialog(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.campaignEvents.postEdit' );
		$out->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( 'wgCampaignEventsEventsForAssociation', $this->anything() );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup( associationEvents: [ $this->makeEvent() ] ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testAssociationTakesPrecedenceOverDiscovery(): void {
		$out = $this->makeOutputPage();
		// Only the association dialog is loaded, and exactly one config var is set.
		$out->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.campaignEvents.postEdit' );
		$out->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( 'wgCampaignEventsEventsForAssociation', $this->anything() );

		// The promotion must not be recorded when the discovery dialog is not shown, otherwise the
		// one-time promotion would be consumed for a dialog the user never sees.
		$promotionStore = $this->createMock( IDiscoveryPromotionStore::class );
		$promotionStore->expects( $this->never() )->method( 'tryRecordPromotion' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(
				associationEvents: [ $this->makeEvent( 1 ) ],
				discoveryEvents: [ $this->makeEvent( 2 ) ],
			),
			promotionStore: $promotionStore,
			userOptionsLookup: $this->makeOptedInLookup( true ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_skipsWhenFeatureDisabled(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler(
			featureEnabled: false,
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_skipsTemporaryAccount(): void {
		// Temporary accounts are registered but not named; they must not trigger the dialog.
		$out = $this->makeOutputPage( isNamed: false, isRegistered: true );
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_skipsWhenOptedOut(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(),
			userOptionsLookup: $this->makeOptedInLookup( false ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_skipsWhenNoPageID(): void {
		$out = $this->makeOutputPage( hasPageID: false );
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(),
			userOptionsLookup: $this->makeOptedInLookup( true ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_skipsWhenNoMatchingEvents(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(),
			userOptionsLookup: $this->makeOptedInLookup( true ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_skipsWhenAllAlreadyPromoted(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->never() )->method( 'addModules' );

		$promotionStore = $this->createMock( IDiscoveryPromotionStore::class );
		$promotionStore->method( 'tryRecordPromotion' )->willReturn( false );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup( discoveryEvents: [ $this->makeEvent() ] ),
			promotionStore: $promotionStore,
			userOptionsLookup: $this->makeOptedInLookup( true ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_showsDialogForNewlyPromotedEvent(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.campaignEvents.eventDiscovery' );
		$out->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( 'wgCampaignEventsDiscoveryEvents', [ [ 'id' => 1, 'name' => 'Event 1' ] ] );

		$promotionStore = $this->createMock( IDiscoveryPromotionStore::class );
		$promotionStore->method( 'tryRecordPromotion' )->willReturn( true );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup( discoveryEvents: [ $this->makeEvent( 1 ) ] ),
			promotionStore: $promotionStore,
			userOptionsLookup: $this->makeOptedInLookup( true ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_signalsOnlyNewlyPromotedEvents(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( 'wgCampaignEventsDiscoveryEvents', [ [ 'id' => 2, 'name' => 'Event 2' ] ] );

		$promotionStore = $this->createMock( IDiscoveryPromotionStore::class );
		// Event 1 already promoted, event 2 is new.
		$promotionStore->method( 'tryRecordPromotion' )->willReturnOnConsecutiveCalls( false, true );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(
				discoveryEvents: [ $this->makeEvent( 1 ), $this->makeEvent( 2 ) ],
			),
			promotionStore: $promotionStore,
			userOptionsLookup: $this->makeOptedInLookup( true ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}
}
