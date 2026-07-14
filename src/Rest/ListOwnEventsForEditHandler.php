<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\EventGoal\GoalProgressFormatter;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PostEditHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\ParamValidator\TypeDef\TitleDef;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ParamValidator\ParamValidator;

class ListOwnEventsForEditHandler extends SimpleHandler {
	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly IEventLookup $eventLookup,
		private readonly GoalProgressFormatter $goalProgressFormatter,
		private readonly WorklistEventsStore $worklistEventsStore,
		private readonly TitleFormatter $titleFormatter,
	) {
	}

	/** @phan-return list<array{id:int,name:string,goalProgress?:string,autoAssociable:bool}> */
	public function run(): array {
		$events = $this->getEvents();
		$eventData = PostEditHandler::makeEventList(
			$events, $this->getAuthority(), $this->getResponseLanguageCode(), $this->goalProgressFormatter
		);
		$autoAssociableEventIDs = $this->getAutoAssociableEventIDs( $events );
		return array_map(
			/**
			 * @param array{id:int,name:string} $entry
			 * @phan-return array{id:int,name:string,goalProgress?:string,autoAssociable:bool}
			 */
			static function ( array $entry ) use ( $autoAssociableEventIDs ): array {
				$entry['autoAssociable'] = in_array( $entry['id'], $autoAssociableEventIDs, true );
				return $entry;
			},
			$eventData
		);
	}

	/** @return list<ExistingEventRegistration> */
	private function getEvents(): array {
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->getAuthority() );
		} catch ( UserNotGlobalException ) {
			return [];
		}

		return $this->eventLookup->getEventsForContributionAssociationByParticipant( $centralUser, 50 );
	}

	/**
	 * When exactly one event is returned, the client auto-associates the edit instead of showing
	 * the dialog, mirroring the server-side reload path.
	 *
	 * @param list<ExistingEventRegistration> $events
	 * @return int[]
	 */
	private function getAutoAssociableEventIDs( array $events ): array {
		$title = $this->getValidatedParams()['title'];
		if ( $title === null || !$events ) {
			return [];
		}
		$eventIDs = array_map(
			static fn ( ExistingEventRegistration $event ): int => $event->getID(),
			$events
		);
		return $this->worklistEventsStore->filterEventsByPageInWorklist(
			$eventIDs,
			WikiMap::getCurrentWikiId(),
			$this->titleFormatter->getPrefixedText( $title )
		);
	}

	public function getParamSettings(): array {
		return [
			// Named "title" rather than "page" to avoid clashing with pager parameters.
			'title' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => false,
				TitleDef::PARAM_RETURN_OBJECT => true,
			],
		];
	}

	/**
	 * Temporary (?) helper to get the language to use in the response, given T269492.
	 */
	private function getResponseLanguageCode(): string {
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			// Avoid global state in ListOwnEventsForEditHandlerTest
			return 'qqx';
		}
		return RequestContext::getMain()->getLanguage()->getCode();
	}
}
