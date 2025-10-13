<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\UserMerge\Hooks\AccountFieldsHook;
use MediaWiki\Extension\UserMerge\Hooks\DeleteAccountHook;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\User\User;

/**
 * This class is part of a series of hook handlers that update event contributions records in case of user changes
 * (renames, deletions, hiding/unhiding).
 * This class in particular deals with UserMerge-specific changes. Note that UserMerge is incompatible with either
 * $wgSharedDB or CentralAuth as per extension documentation, so here we assume that the wiki is not part of a wikifarm.
 */
class UserMergeContributionUserChangesHandler implements
	DeleteAccountHook,
	AccountFieldsHook
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

	public function onDeleteAccount( User &$oldUser ): void {
		if ( !$this->isFeatureEnabled ) {
			return;
		}

		// UserMerge is not compatible with wikifarms, so we know the user's local ID is also their "central" ID.
		$user = new CentralUser( $oldUser->getId() );
		if ( !$this->eventContributionStore->hasContributionsFromUser( $user ) ) {
			return;
		}
		$job = new UpdateUserContributionRecordsJob( [
			'type' => UpdateUserContributionRecordsJob::TYPE_DELETE,
			'userID' => $user->getCentralID(),
		] );
		$this->jobQueueGroup->push( $job );
	}

	/** @param list<array<string|int,string|int>> &$updateFields */
	public function onUserMergeAccountFields( array &$updateFields ): void {
		if ( !$this->isFeatureEnabled ) {
			return;
		}

		$updateFields[] = [
			'ce_event_contributions',
			'cec_user_id',
			'cec_user_name',
			'batchKey' => 'cec_id',
		];
	}
}
