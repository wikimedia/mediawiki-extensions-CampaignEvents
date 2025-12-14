<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Rest\SimpleHandler;

class ListOwnEventsForEditHandler extends SimpleHandler {
	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly IEventLookup $eventLookup,
	) {
	}

	/** @phan-return list<array{id:int,name:string}> */
	public function run(): array {
		$ret = [];
		foreach ( $this->getEvents() as $event ) {
			$ret[] = [ 'id' => $event->getID(), 'name' => $event->getName() ];
		}

		return $ret;
	}

	/** @return list<ExistingEventRegistration> */
	private function getEvents(): array {
		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->getAuthority() );
		} catch ( UserNotGlobalException ) {
			return [];
		}

		return $this->eventLookup->getEventsForContributionAssociationByParticipant( $centralUser->getCentralID(), 50 );
	}
}
