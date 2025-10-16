<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;

/**
 * Handler used to set up a JavaScript modal shown after edits, where users can choose to associate
 * their edit with an event.
 */
class PostEditHandler implements BeforePageDisplayHook {
	private CampaignsCentralUserLookup $centralUserLookup;
	private IEventLookup $eventLookup;

	public function __construct(
		CampaignsCentralUserLookup $centralUserLookup,
		IEventLookup $eventLookup
	) {
		$this->centralUserLookup = $centralUserLookup;
		$this->eventLookup = $eventLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$out->getConfig()->get( 'CampaignEventsEnableContributionTracking' ) ) {
			return;
		}
		if ( $out->getTitle()->inNamespace( NS_EVENT ) ) {
			// Don't show the dialog in the Event: namespace, T406672
			return;
		}

		if ( !self::isPostEditReload( $out ) ) {
			return;
		}

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $out->getAuthority() );
		} catch ( UserNotGlobalException ) {
			// They can't be participating in any events without a global account.
			return;
		}

		$events = $this->eventLookup->getEventsForContributionAssociationByParticipant(
			$centralUser->getCentralID(),
			50
		);

		if ( !$events ) {
			return;
		}

		$eventData = [];
		foreach ( $events as $event ) {
			$eventData[] = [ 'id' => $event->getID(), 'name' => $event->getName() ];
		}

		$out->addModules( 'ext.campaignEvents.postEdit' );
		$out->addJsConfigVars( 'wgCampaignEventsEventsForAssociation', $eventData );
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
}
