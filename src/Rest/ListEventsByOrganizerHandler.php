<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

class ListEventsByOrganizerHandler extends AbstractListEventsByUserHandler {
	/**
	 * @param int $userID
	 * @param int $resultLimit
	 * @return array
	 */
	protected function getEventsByUser( int $userID, int $resultLimit ): array {
		$events = $this->eventLookup->getEventsByOrganizer( $userID, $resultLimit );
		$filter = [];

		foreach ( $events as $event ) {
			$filter[] = [
				'event_id' => $event->getID(),
				'event_name' => $event->getName()
			];
		}

		return $filter;
	}
}
