<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class ListEventsByParticipantHandler extends AbstractListEventsByUserHandler {
	/**
	 * @return list<array<string,mixed>>
	 */
	protected function getEventsByUser( CentralUser $user, int $resultLimit ): array {
		return $this->buildResultStructure(
			$this->eventLookup->getEventsByParticipant( $user->getCentralID(), $resultLimit )
		);
	}
}
