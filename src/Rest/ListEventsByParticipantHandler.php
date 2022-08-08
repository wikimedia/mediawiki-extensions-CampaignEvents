<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class ListEventsByParticipantHandler extends AbstractListEventsByUserHandler {
	/**
	 * @param CentralUser $user
	 * @param int $resultLimit
	 * @return array
	 */
	protected function getEventsByUser( CentralUser $user, int $resultLimit ): array {
		return $this->buildResultStructure(
			$this->eventLookup->getEventsByParticipant( $user->getCentralID(), $resultLimit )
		);
	}
}
