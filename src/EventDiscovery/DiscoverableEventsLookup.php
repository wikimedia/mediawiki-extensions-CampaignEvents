<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventDiscovery;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\GetPreferencesHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\Options\UserOptionsLookup;

/**
 * Selects the event-discovery events to promote to a user for a given page, recording the
 * once-per-user-and-event promotion as it does so. Shared by the post-edit reload flow
 * (PostEditHandler) and the in-place client-edit flow (ListDiscoverableEventsForPageHandler).
 */
class DiscoverableEventsLookup {
	public const SERVICE_NAME = 'CampaignEventsDiscoverableEventsLookup';

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly IDiscoveryPromotionStore $promotionStore,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly PageURLResolver $pageURLResolver,
	) {
	}

	/**
	 * Returns the events to promote to the user on this page, recording the promotion so each event
	 * is only promoted once. Returns an empty list when discovery does not apply: a temporary
	 * account, an opted-out user, or no newly-promoted events.
	 *
	 * @return list<array{id:int,name:string,url:string}>
	 */
	public function getAndRecordPromotableEvents(
		Authority $authority,
		CentralUser $centralUser,
		string $pagePrefixedText,
		string $wikiID,
		int $limit
	): array {
		// Temporary accounts are registered but not named, so isNamed() (not isRegistered())
		// is required to exclude them.
		if ( !$authority->isNamed() ) {
			return [];
		}

		if ( !$this->userOptionsLookup->getBoolOption(
			$authority->getUser(),
			GetPreferencesHandler::OPT_OUT_EVENT_DISCOVERY_PREFERENCE
		) ) {
			return [];
		}

		$events = $this->eventLookup->getEventsForDiscoveryByPage(
			$pagePrefixedText,
			$wikiID,
			$centralUser,
			$limit
		);

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

		return array_map(
			/**
			 * @return array{id:int,name:string,url:string}
			 */
			fn ( ExistingEventRegistration $event ): array => [
				'id' => $event->getID(),
				'name' => $event->getName(),
				// The event page may be on a foreign wiki, so resolve the URL server-side
				// rather than building it client-side with mw.util.getUrl (local-only).
				'url' => $this->pageURLResolver->getUrl( $event->getPage() ),
			],
			$newlyPromoted
		);
	}
}
