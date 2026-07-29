<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob;
use MediaWiki\Extension\CampaignEvents\Job\UpdateUsernameRecordsJobBase;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Worklist\UpdateUserWorklistRecordsJob;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Extension\CentralAuth\Hooks\CentralAuthAccountDeletedHook;
use MediaWiki\Extension\CentralAuth\Hooks\CentralAuthUserVisibilityChangedHook;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\JobQueue\JobQueueGroup;

/**
 * This class is part of a series of hook handlers that update event contributions and worklist records in case of user
 * changes (renames, deletions, hiding/unhiding).
 * This class in particular deals with CentralAuth-specific changes.
 */
class CentralAuthContributionUserChangesHandler implements
	CentralAuthAccountDeletedHook,
	CentralAuthUserVisibilityChangedHook
{
	public function __construct(
		private readonly EventContributionStore $eventContributionStore,
		private readonly WorklistSecondaryStore $worklistSecondaryStore,
		private readonly JobQueueGroup $jobQueueGroup,
	) {
	}

	public function onCentralAuthAccountDeleted( int $userID, string $userName ): void {
		$user = new CentralUser( $userID );
		$jobs = [];
		if ( $this->eventContributionStore->hasContributionsFromUser( $user ) ) {
			$jobs[] = new UpdateUserContributionRecordsJob( [
				'type' => UpdateUserContributionRecordsJob::TYPE_DELETE,
				'userID' => $userID,
			] );
		}
		if ( $this->worklistSecondaryStore->hasWorklistsFromCreator( $user ) ) {
			$jobs[] = new UpdateUserWorklistRecordsJob( [
				'type' => UpdateUserWorklistRecordsJob::TYPE_DELETE,
				'userID' => $userID,
			] );
		}
		$this->jobQueueGroup->push( $jobs );
	}

	public function onCentralAuthUserVisibilityChanged( CentralAuthUser $centralAuthUser, int $newVisibility ): void {
		$user = new CentralUser( $centralAuthUser->getId() );
		/** @return array<string,mixed> */
		$getJobParams = static function () use ( $user, $centralAuthUser, $newVisibility ): array {
			static $cache;
			$cache ??= [
				'type' => UpdateUsernameRecordsJobBase::TYPE_VISIBILITY,
				'userID' => $user->getCentralID(),
				'userName' => $centralAuthUser->getName(),
				'isHidden' => $newVisibility !== CentralAuthUser::HIDDEN_LEVEL_NONE
			];
			return $cache;
		};
		$jobs = [];
		if ( $this->eventContributionStore->hasContributionsFromUser( $user ) ) {
			$jobs[] = new UpdateUserContributionRecordsJob( $getJobParams() );
		}
		if ( $this->worklistSecondaryStore->hasWorklistsFromCreator( $user ) ) {
			$jobs[] = new UpdateUserWorklistRecordsJob( $getJobParams() );
		}
		$this->jobQueueGroup->push( $jobs );
	}
}
