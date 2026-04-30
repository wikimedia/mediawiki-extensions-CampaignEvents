<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionValidator;
use MediaWiki\Extension\CampaignEvents\EventDiscovery\DiscoverableEventsLookup;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use Wikibase\Repo\WikibaseRepo;

/**
 * Handler for the JavaScript modals shown after an edit reload: the contribution-association dialog
 * (where users associate their edit with an event) and, when no association dialog applies, the
 * event-discovery/promotion dialog. Only one dialog is shown per page load, since showing both at
 * once breaks the page (T431571); the association dialog takes precedence.
 */
class PostEditHandler implements BeforePageDisplayHook {
	private const MAX_EVENTS = 50;

	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly IEventLookup $eventLookup,
		private readonly GoalProgressFormatter $goalProgressFormatter,
		private readonly WorklistEventsStore $worklistEventsStore,
		private readonly EventContributionValidator $eventContributionValidator,
		private readonly Config $config,
		private readonly DiscoverableEventsLookup $discoverableEventsLookup,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $out->getTitle()->inNamespace( NS_EVENT ) ) {
			// Don't show a dialog in the Event: namespace, T406672
			return;
		}

		if ( !self::isPostEditReload( $out ) ) {
			if ( self::isWikibaseEntityPage( $out ) ) {
				// Load the module on page view, without including events for performance. Those will be
				// lazy-loaded on the client side.
				$out->addModules( 'ext.campaignEvents.postEdit' );
			}
			return;
		}

		$authority = $out->getAuthority();
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $authority );
		} catch ( UserNotGlobalException ) {
			// Without a global account they can neither be participating in nor be promoted events.
			return;
		}

		// The association dialog takes precedence: only fall through to event discovery when there
		// is no association dialog to show, so the two dialogs never appear together (T431571).
		if ( $this->maybeShowAssociationDialog( $out, $authority, $centralUser ) ) {
			return;
		}

		$this->maybeShowDiscoveryDialog( $out, $authority, $centralUser );
	}

	/**
	 * Show the contribution-association dialog when the user participates in events that their edit
	 * can be associated with. When exactly one candidate event has this page on its worklist, the
	 * edit is auto-associated (via a job) instead of showing the dialog.
	 *
	 * @return bool Whether the association was handled (dialog shown or auto-associated); when true,
	 *   the caller skips the discovery dialog.
	 */
	private function maybeShowAssociationDialog(
		OutputPage $out,
		Authority $authority,
		CentralUser $centralUser
	): bool {
		$events = $this->eventLookup->getEventsForContributionAssociationByParticipant(
			$centralUser,
			self::MAX_EVENTS
		);

		if ( !$events ) {
			return false;
		}

		$eventIDs = array_map( static fn ( ExistingEventRegistration $e ): int => $e->getID(),
			$events );
		$wikiID = WikiMap::getCurrentWikiId();
		$autoAssociableEventIDs = $this->worklistEventsStore->filterEventsByPageInWorklist(
			$eventIDs,
			$wikiID,
			$out->getTitle()->getPrefixedText()
		);

		if ( count( $autoAssociableEventIDs ) === 1 ) {
			// Exactly one candidate event has this page on its worklist: auto-associate without
			// showing the modal. Validation is skipped here because
			// getEventsForContributionAssociationByParticipant already guarantees each event is
			// ongoing, has contributions enabled, and the user is a participant. The job's
			// removeDuplicates flag handles any duplicate dispatches (e.g. repeated page reloads).
			$autoAssociatedEventID = $autoAssociableEventIDs[0];
			$this->eventContributionValidator->scheduleAssociationJob(
				$out->getRevisionId(),
				$wikiID,
				$autoAssociatedEventID,
				$centralUser->getCentralID()
			);

			// Signal the frontend to show lightweight confirmation feedback in place of the modal.
			$out->addJsConfigVars( 'wgCampaignEventsAutoAssociatedEvent', [
				'id' => $autoAssociatedEventID,
				'name' => self::findEventName( $events, $autoAssociatedEventID ),
			] );
			$out->addModules( 'ext.campaignEvents.postEdit' );
			// Auto-associated without a dialog: treat as handled so discovery is skipped.
			return true;
		}

		$eventData = self::makeEventList(
			$events, $authority, $out->getLanguage()->getCode(), $this->goalProgressFormatter
		);
		$out->addModules( 'ext.campaignEvents.postEdit' );
		$out->addJsConfigVars( 'wgCampaignEventsEventsForAssociation', $eventData );
		return true;
	}

	/**
	 * @param ExistingEventRegistration[] $events
	 */
	private static function findEventName( array $events, int $eventID ): string {
		foreach ( $events as $event ) {
			if ( $event->getID() === $eventID ) {
				return $event->getName();
			}
		}
		// $eventID is always drawn from $events (via filterEventsByPageInWorklist), so this is
		// unreachable in practice.
		throw new RuntimeException( "Event $eventID not found in the candidate list" );
	}

	/**
	 * Show the event-discovery/promotion dialog for named users who are participants of events
	 * promoted on the current page. Records the promotion so each event is only promoted once; this
	 * is why it must run only when the association dialog is not shown, to avoid consuming the
	 * one-time promotion for a dialog the user never sees.
	 */
	private function maybeShowDiscoveryDialog(
		OutputPage $out,
		Authority $authority,
		CentralUser $centralUser
	): void {
		if ( !$this->config->get( 'CampaignEventsEnableWorklists' ) ) {
			return;
		}

		$title = $out->getTitle();

		$events = $this->discoverableEventsLookup->getAndRecordPromotableEvents(
			$authority,
			$centralUser,
			$title->getPrefixedText(),
			WikiMap::getCurrentWikiId(),
			3
		);

		if ( !$events ) {
			return;
		}

		$out->addJsConfigVars( 'wgCampaignEventsDiscoveryEvents', $events );
		$out->addModules( 'ext.campaignEvents.postEdit' );
	}

	/**
	 * Given a list of events, returns an array structure that can be passed to the post-edit dialog frontend.
	 *
	 * @param ExistingEventRegistration[] $events
	 * @param Authority $authority
	 * @param string $languageCode
	 * @param GoalProgressFormatter $goalProgressFormatter
	 *
	 * @return list<array{id:int,name:string,goalPercent?:float,goalDescription?:string,goalNumericText?:string}>
	 */
	public static function makeEventList(
		array $events,
		Authority $authority,
		string $languageCode,
		GoalProgressFormatter $goalProgressFormatter
	): array {
		$eventData = [];

		foreach ( $events as $event ) {
			$entry = [
				'id' => $event->getID(),
				'name' => $event->getName(),
			];

			$goalProgressData = $goalProgressFormatter->getProgressData(
				$event,
				$authority,
				$languageCode
			);

			if ( $goalProgressData !== null ) {
				$entry['goalPercent'] = $goalProgressData['percentComplete'];
				$entry['goalDescription'] = $goalProgressData['description'];
				$entry['goalNumericText'] = $goalProgressData['numericText'];
			}

			$eventData[] = $entry;
		}

		return $eventData;
	}

	/**
	 * Check whether an edit just occurred and the page just reloaded. This only works for editors that cause a reload
	 * (e.g., the source editor, but not VE). VE edits are handled separately by registering our module as a VE plugin.
	 * Not VE page creations though, as those also cause a reload BUT do not load VE plugins. So, those are handled here
	 * by checking for venotify instead. And similarly for MobileFrontend edits. This code is based on
	 * GrowthExperiments' LevelingUpHooks::onBeforePageDisplay.
	 * XXX This whole things is really ugly but there don't seem to be better options.
	 */
	private static function isPostEditReload( OutputPage $out ): bool {
		$configVars = $out->getJsConfigVars();
		if ( isset( $configVars['wgPostEdit'] ) ) {
			return true;
		}

		$request = $out->getRequest();
		return $request->getCheck( 'venotify' ) || $request->getCheck( 'mfnotify' );
	}

	private static function isWikibaseEntityPage( OutputPage $out ): bool {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'WikibaseRepository' ) ) {
			return false;
		}
		return WikibaseRepo::getEntityNamespaceLookup()->isEntityNamespace( $out->getTitle()->getNamespace() );
	}
}
