<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Job\UpdateUsernameRecordsJobBase;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

/**
 * This job updates event contribution records for a given user when that user is renamed, deleted, or suppressed.
 */
class UpdateUserContributionRecordsJob extends UpdateUsernameRecordsJobBase {
	protected static function getJobName(): string {
		return 'CampaignEventsUpdateUserContributionRecords';
	}

	protected function updateName( CentralUser $user, string $newName ): void {
		CampaignEventsServices::getEventContributionStore()->updateUserName( $user, $newName );
	}

	protected function updateVisibility( CentralUser $user, bool $isHidden, ?string $userName = null ): void {
		CampaignEventsServices::getEventContributionStore()->updateUserVisibility( $user, $isHidden, $userName );
	}
}
