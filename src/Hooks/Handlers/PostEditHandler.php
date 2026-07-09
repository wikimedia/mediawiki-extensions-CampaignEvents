<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventDiscovery\IDiscoveryPromotionStore;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\WikiMap\WikiMap;
use Wikibase\Repo\WikibaseRepo;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * Handler for the JavaScript modals shown after an edit reload: the contribution-association dialog
 * (where users associate their edit with an event) and, when no association dialog applies, the
 * event-discovery/promotion dialog. Only one dialog is shown per page load, since showing both at
 * once breaks the page (T431571); the association dialog takes precedence.
 */
class PostEditHandler implements BeforePageDisplayHook {
	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly IEventLookup $eventLookup,
		private readonly GoalProgressFormatter $goalProgressFormatter,
		private readonly Config $config,
		private readonly IDiscoveryPromotionStore $promotionStore,
		private readonly UserOptionsLookup $userOptionsLookup,
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
	 * can be associated with.
	 *
	 * @return bool Whether the dialog was shown.
	 */
	private function maybeShowAssociationDialog(
		OutputPage $out,
		Authority $authority,
		CentralUser $centralUser
	): bool {
		$events = $this->eventLookup->getEventsForContributionAssociationByParticipant(
			$centralUser,
			50
		);

		if ( !$events ) {
			return false;
		}

		$eventData = self::makeEventList(
			$events, $authority, $out->getLanguage()->getCode(), $this->goalProgressFormatter
		);

		$out->addModules( 'ext.campaignEvents.postEdit' );
		$out->addJsConfigVars( 'wgCampaignEventsEventsForAssociation', $eventData );
		return true;
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

		// Temporary accounts are registered but not named, so isNamed() (not isRegistered())
		// is required to exclude them.
		if ( !$authority->isNamed() ) {
			return;
		}

		if ( !$this->userOptionsLookup->getBoolOption(
			$authority->getUser(),
			GetPreferencesHandler::OPT_OUT_EVENT_DISCOVERY_PREFERENCE
		) ) {
			return;
		}

		$title = $out->getTitle();
		if ( !$title->getArticleID() ) {
			return;
		}

		$events = $this->eventLookup->getEventsForDiscoveryByPage(
			$title->getPrefixedText(),
			WikiMap::getCurrentWikiId(),
			$centralUser,
			3
		);

		if ( !$events ) {
			return;
		}

		$newlyPromoted = [];
		foreach ( $events as $event ) {
			if ( $this->promotionStore->tryRecordPromotion(
				$event->getID(),
				$centralUser,
				$event->getEndUTCTimestamp()
			) ) {

				$newlyPromoted[] = $event;
			}
		}

		if ( !$newlyPromoted ) {
			return;
		}

		$out->addJsConfigVars( 'wgCampaignEventsDiscoveryEvents', array_map(
			/**
			 * @return array{id:int,name:string}
			 */
			static fn ( ExistingEventRegistration $event ): array =>
				[ 'id' => $event->getID(), 'name' => $event->getName() ],
			$newlyPromoted
		) );
		$out->addModules( 'ext.campaignEvents.eventDiscovery' );
	}

	/**
	 * Given a list of events, returns an array structure that can be passed to the post-edit dialog frontend.
	 *
	 * @param ExistingEventRegistration[] $events
	 * @param Authority $authority
	 * @param string $languageCode
	 * @param GoalProgressFormatter $goalProgressFormatter
	 * @return list<array{id:int,name:string,goalProgress?:string}>
	 */
	public static function makeEventList(
		array $events,
		Authority $authority,
		string $languageCode,
		GoalProgressFormatter $goalProgressFormatter
	): array {
		// XXX: Avoid global state access in ListOwnEventsForEditHandlerTest
		$templateCache = defined( 'MW_PHPUNIT_TEST' ) ? new HashBagOStuff() : null;
		$templateParser = new TemplateParser( __DIR__ . '/../../../templates', $templateCache );
		$eventData = [];
		foreach ( $events as $event ) {
			$entry = [
				'id' => $event->getID(),
				'name' => $event->getName(),
			];
			$goalProgressData = $goalProgressFormatter->getProgressData( $event, $authority, $languageCode );
			if ( $goalProgressData !== null ) {
				// TODO: Replace with a Vue version once that is available (T407638)
				$entry['goalProgress'] = $templateParser->processTemplate( 'GoalProgressBar', $goalProgressData );
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
		if ( isset( $out->getJsConfigVars()['wgPostEdit'] ) ) {
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
