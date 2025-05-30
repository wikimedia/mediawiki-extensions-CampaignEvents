<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

class ListEventsByOrganizerHandler extends AbstractListEventsByUserHandler {
	/**
	 * @param CentralUser $user
	 * @param int $resultLimit
	 * @return list<array<string,mixed>>
	 */
	protected function getEventsByUser( CentralUser $user, int $resultLimit ): array {
		return $this->buildResultStructure(
			$this->eventLookup->getEventsByOrganizer( $user->getCentralID(), $resultLimit )
		);
	}
}
