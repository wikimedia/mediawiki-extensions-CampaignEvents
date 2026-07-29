<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Worklist;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Job\UpdateUsernameRecordsJobBase;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;

/**
 * This job updates worklist records for a given user when that user is renamed, deleted, or suppressed.
 */
class UpdateUserWorklistRecordsJob extends UpdateUsernameRecordsJobBase {
	protected static function getJobName(): string {
		return 'CampaignEventsUpdateUserWorklistRecords';
	}

	protected function updateName( CentralUser $user, string $newName ): void {
		CampaignEventsServices::getWorklistSecondaryStore()->updateUserName( $user, $newName );
	}

	protected function updateVisibility( CentralUser $user, bool $isHidden, ?string $userName = null ): void {
		CampaignEventsServices::getWorklistSecondaryStore()->updateUserVisibility( $user, $isHidden, $userName );
	}
}
