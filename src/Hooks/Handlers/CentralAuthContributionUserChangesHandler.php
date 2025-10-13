<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CentralAuth\Hooks\CentralAuthAccountDeletedHook;
use MediaWiki\Extension\CentralAuth\Hooks\CentralAuthUserVisibilityChangedHook;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\JobQueue\JobQueueGroup;

/**
 * This class is part of a series of hook handlers that update event contributions records in case of user changes
 * (renames, deletions, hiding/unhiding).
 * This class in particular deals with CentralAuth-specific changes.
 */
class CentralAuthContributionUserChangesHandler implements
	CentralAuthAccountDeletedHook,
	CentralAuthUserVisibilityChangedHook
{
	private EventContributionStore $eventContributionStore;
	private JobQueueGroup $jobQueueGroup;
	private bool $isFeatureEnabled;

	public function __construct(
		EventContributionStore $eventContributionStore,
		JobQueueGroup $jobQueueGroup,
		Config $config,
	) {
		$this->eventContributionStore = $eventContributionStore;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->isFeatureEnabled = $config->get( 'CampaignEventsEnableContributionTracking' );
	}

	public function onCentralAuthAccountDeleted( int $userID, string $userName ): void {
		if ( !$this->isFeatureEnabled ) {
			return;
		}

		if ( !$this->eventContributionStore->hasContributionsFromUser( new CentralUser( $userID ) ) ) {
			return;
		}
		$job = new UpdateUserContributionRecordsJob( [
			'type' => UpdateUserContributionRecordsJob::TYPE_DELETE,
			'userID' => $userID,
		] );
		$this->jobQueueGroup->push( $job );
	}

	public function onCentralAuthUserVisibilityChanged( CentralAuthUser $centralAuthUser, int $newVisibility ): void {
		if ( !$this->isFeatureEnabled ) {
			return;
		}

		$user = new CentralUser( $centralAuthUser->getId() );
		if ( !$this->eventContributionStore->hasContributionsFromUser( $user ) ) {
			return;
		}
		$job = new UpdateUserContributionRecordsJob( [
			'type' => UpdateUserContributionRecordsJob::TYPE_VISIBILITY,
			'userID' => $user->getCentralID(),
			'userName' => $centralAuthUser->getName(),
			'isHidden' => $newVisibility !== CentralAuthUser::HIDDEN_LEVEL_NONE
		] );
		$this->jobQueueGroup->push( $job );
	}
}
