<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator;
use MediaWiki\Extension\CampaignEvents\EventDiscovery\DiscoverableEventsLookup;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PostEditHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\Language\Language;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Skin;

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
		?WorklistEventsStore $worklistEventsStore = null,
		?EventContributionValidator $eventContributionValidator = null,
		?DiscoverableEventsLookup $discoverableEventsLookup = null,
	): PostEditHandler {
		return new PostEditHandler(
			$centralUserLookup ?? $this->createNoOpMock( CampaignsCentralUserLookup::class ),
			$eventLookup ?? $this->createNoOpMock( IEventLookup::class ),
			$goalProgressFormatter ?? $this->makeGoalProgressFormatter(),
			$worklistEventsStore ?? $this->makeWorklistEventsStore(),
			$eventContributionValidator ?? $this->createNoOpMock( EventContributionValidator::class ),
			new HashConfig( [ 'CampaignEventsEnableWorklists' => $featureEnabled ] ),
			$discoverableEventsLookup ?? $this->makeDiscoverableEventsLookup(),
		);
	}

	private function makeDiscoverableEventsLookup( array $events = [] ): DiscoverableEventsLookup {
		$lookup = $this->createMock( DiscoverableEventsLookup::class );
		$lookup->method( 'getAndRecordPromotableEvents' )->willReturn( $events );
		return $lookup;
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

	private function makeWorklistEventsStore( array $autoAssociableEventIDs = [] ): WorklistEventsStore {
		$store = $this->createMock( WorklistEventsStore::class );
		$store->method( 'filterEventsByPageInWorklist' )->willReturn( $autoAssociableEventIDs );
		return $store;
	}

	private function makeGoalProgressFormatter(): GoalProgressFormatter {
		$formatter = $this->createMock( GoalProgressFormatter::class );
		$formatter->method( 'getProgressData' )->willReturn( null );
		return $formatter;
	}

	private function makeEvent( int $id = 1 ): ExistingEventRegistration {
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( $id );
		$event->method( 'getName' )->willReturn( "Event $id" );
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

	public function testAutoAssociation_signalsConfirmationInsteadOfDialog(): void {
		$out = $this->makeOutputPage();
		$out->method( 'getRevisionId' )->willReturn( 555 );
		// The single auto-associated event is signalled to the client (which shows a lightweight
		// confirmation), and the association dialog's event list is not set.
		$out->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.campaignEvents.postEdit' );
		$out->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( 'wgCampaignEventsAutoAssociatedEvent', [ 'id' => 1, 'name' => 'Event 1' ] );

		$validator = $this->createMock( EventContributionValidator::class );
		$validator->expects( $this->once() )->method( 'scheduleAssociationJob' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup( associationEvents: [ $this->makeEvent( 1 ) ] ),
			worklistEventsStore: $this->makeWorklistEventsStore( [ 1 ] ),
			eventContributionValidator: $validator,
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testAssociationTakesPrecedenceOverDiscovery(): void {
		$out = $this->makeOutputPage();
		// Only the association dialog is loaded, and exactly one config var is set.
		$out->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.campaignEvents.postEdit' );
		$out->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( 'wgCampaignEventsEventsForAssociation', $this->anything() );

		// Discovery must not even be consulted when the association dialog is shown, so its one-time
		// promotion is not consumed for a dialog the user never sees.
		$discoverableEventsLookup = $this->createMock( DiscoverableEventsLookup::class );
		$discoverableEventsLookup->expects( $this->never() )->method( 'getAndRecordPromotableEvents' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup( associationEvents: [ $this->makeEvent( 1 ) ] ),
			discoverableEventsLookup: $discoverableEventsLookup,
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_skipsWhenFeatureDisabled(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->never() )->method( 'addModules' );

		// The discovery service must not even be consulted when worklists are disabled.
		$discoverableEventsLookup = $this->createMock( DiscoverableEventsLookup::class );
		$discoverableEventsLookup->expects( $this->never() )->method( 'getAndRecordPromotableEvents' );

		$this->getHandler(
			featureEnabled: false,
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(),
			discoverableEventsLookup: $discoverableEventsLookup,
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_skipsWhenServiceReturnsNoEvents(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->never() )->method( 'addModules' );

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(),
			discoverableEventsLookup: $this->makeDiscoverableEventsLookup( [] ),
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testDiscovery_showsDialogForEventsFromService(): void {
		$out = $this->makeOutputPage();
		$out->expects( $this->once() )->method( 'addModules' )
			->with( 'ext.campaignEvents.postEdit' );
		$out->expects( $this->once() )->method( 'addJsConfigVars' )
			->with( 'wgCampaignEventsDiscoveryEvents',
				[ [ 'id' => 1, 'name' => 'Event 1', 'url' => '/wiki/Event:Event 1' ] ]
			);

		$discoverableEventsLookup = $this->makeDiscoverableEventsLookup(
			[ [ 'id' => 1, 'name' => 'Event 1', 'url' => '/wiki/Event:Event 1' ] ]
		);

		$this->getHandler(
			centralUserLookup: $this->makeCentralUserLookup(),
			eventLookup: $this->makeEventLookup(),
			discoverableEventsLookup: $discoverableEventsLookup,
		)->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}
}
