<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventDiscovery\IDiscoveryPromotionStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\GetPreferencesHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Returns the events whose worklist contains the given page and for which the current user should be
 * shown the event discovery dialog, recording the once-per-user-and-event suppression as it does so.
 *
 * This mirrors, for the VisualEditor (in-place save) flow, what EventDiscoveryHandler does server-side
 * on a full page reload after a source-editor save: VE never triggers BeforePageDisplay, so the
 * ext.campaignEvents.eventDiscovery module (loaded as a VE plugin) calls this endpoint from the
 * post-edit hook instead.
 */
class ListDiscoverableEventsForPageHandler extends SimpleHandler {
	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly IEventLookup $eventLookup,
		private readonly IDiscoveryPromotionStore $promotionStore,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly TitleFactory $titleFactory,
	) {
	}

	/** @phan-return list<array{id:int,name:string}> */
	public function run(): array {
		$authority = $this->getAuthority();
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

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $authority );
		} catch ( UserNotGlobalException ) {
			return [];
		}

		$title = $this->titleFactory->newFromText( $this->getValidatedParams()['page'] );
		if ( !$title || !$title->getArticleID() ) {
			return [];
		}

		$events = $this->eventLookup->getEventsForDiscoveryByPage(
			$title->getPrefixedText(),
			WikiMap::getCurrentWikiId(),
			$centralUser,
			50
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
			 * @return array{id:int,name:string}
			 */
			static fn ( ExistingEventRegistration $event ): array =>
				[ 'id' => $event->getID(), 'name' => $event->getName() ],
			$newlyPromoted
		);
	}

	public function getParamSettings(): array {
		return [
			'page' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
