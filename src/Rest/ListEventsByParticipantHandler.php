<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

class ListEventsByParticipantHandler extends AbstractListEventsByUserHandler {
	/**
	 * @param int $userID
	 * @param int $resultLimit
	 * @return array
	 */
	protected function getEventsByUser( int $userID, int $resultLimit ): array {
		return $this->buildResultStructure( $this->eventLookup->getEventsByParticipant( $userID, $resultLimit ) );
	}
}
