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
use MediaWiki\Rest\SimpleHandler;

class ListOwnEventsForEditHandler extends SimpleHandler {
	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly IEventLookup $eventLookup,
		private readonly GoalProgressFormatter $goalProgressFormatter,
	) {
	}

	/** @phan-return list<array{id:int,name:string}> */
	public function run(): array {
		return PostEditHandler::makeEventList(
			$this->getEvents(), $this->getAuthority(), $this->getResponseLanguageCode(), $this->goalProgressFormatter
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
