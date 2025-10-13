<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Hook\UnblockUserCompleteHook;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\RenameUser\Hook\RenameUserCompleteHook;
use MediaWiki\User\User;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * This class is part of a series of hook handlers that update event contributions records in case of user changes
 * (renames, deletions, hiding/unhiding).
 * This class in particular deals with changes coming from core
 */
class ContributionUserChangesHandler implements
	BlockIpCompleteHook,
	UnblockUserCompleteHook,
	RenameUserCompleteHook
{
	private CampaignsCentralUserLookup $centralUserLookup;
	private EventContributionStore $eventContributionStore;
	private JobQueueGroup $jobQueueGroup;
	private WANObjectCache $wanCache;
	private bool $isFeatureEnabled;

	public function __construct(
		CampaignsCentralUserLookup $centralUserLookup,
		EventContributionStore $eventContributionStore,
		JobQueueGroup $jobQueueGroup,
		WANObjectCache $wanCache,
		Config $config,
	) {
		$this->centralUserLookup = $centralUserLookup;
		$this->eventContributionStore = $eventContributionStore;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->wanCache = $wanCache;
		$this->isFeatureEnabled = $config->get( 'CampaignEventsEnableContributionTracking' );
	}

	/**
	 * @param DatabaseBlock $block
	 * @param User $user
	 * @param ?DatabaseBlock $priorBlock
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ): void {
		if ( !$this->isFeatureEnabled ) {
			return;
		}

		$targetUser = $block->getTargetUserIdentity();
		if ( !$targetUser ) {
			// E.g., a range block.
			return;
		}

		if (
			( !$priorBlock && !$block->getHideName() ) ||
			( $priorBlock && $block->getHideName() === $priorBlock->getHideName() )
		) {
			// Block doesn't hide user, or leaves previous visibility unchanged.
			return;
		}

		try {
			$centralUser = $this->centralUserLookup->newFromUserIdentity( $targetUser );
		} catch ( UserNotGlobalException ) {
			return;
		}
		if ( !$this->eventContributionStore->hasContributionsFromUser( $centralUser ) ) {
			return;
		}

		$isHidden = $block->getHideName();
		// Optimization: don't look up the username when not needed (it's optional when deleting)
		$userName = $isHidden ? null : $this->centralUserLookup->getUserName( $centralUser );
		$job = new UpdateUserContributionRecordsJob( [
			'type' => UpdateUserContributionRecordsJob::TYPE_VISIBILITY,
			'userID' => $centralUser->getCentralID(),
			'isHidden' => $isHidden,
			'userName' => $userName,
		] );
		$this->jobQueueGroup->push( $job );
	}

	/**
	 * @param DatabaseBlock $block
	 * @param User $user
	 */
	public function onUnblockUserComplete( $block, $user ): void {
		if ( !$this->isFeatureEnabled ) {
			return;
		}

		$targetUser = $block->getTargetUserIdentity();
		if ( !$targetUser ) {
			// E.g., a range block.
			return;
		}

		if ( !$block->getHideName() ) {
			return;
		}

		try {
			$centralUser = $this->centralUserLookup->newFromUserIdentity( $targetUser );
		} catch ( UserNotGlobalException ) {
			return;
		}
		if ( !$this->eventContributionStore->hasContributionsFromUser( $centralUser ) ) {
			return;
		}
		$job = new UpdateUserContributionRecordsJob( [
			'type' => UpdateUserContributionRecordsJob::TYPE_VISIBILITY,
			'userID' => $centralUser->getCentralID(),
			'userName' => $this->centralUserLookup->getUserName( $centralUser ),
			'isHidden' => false,
		] );
		$this->jobQueueGroup->push( $job );
	}

	/**
	 * Note, we handle this and not `RenameUserSQL` because this lets us check if we got local or global user IDs, thus
	 * letting us support both CentralAuth and non-CA wikis, while also avoiding duplicated jobs (same global user,
	 * different wikis).
	 */
	public function onRenameUserComplete( int $uid, string $old, string $new ): void {
		// CentralAuth handles the same hook to unattach the old name and attach the new one. So, depending on the
		// order in which the handler runs (which is the same as the order of the wfLoadExtension calls), we may need
		// to look up the user using the old or the new name. Instead, run our code in a deferred update so we know for
		// sure that the user has been renamed by then.
		DeferredUpdates::addCallableUpdate( function () use ( $new ): void {
			$this->handleRenameUserComplete( $new );
		} );
	}

	private function handleRenameUserComplete( string $new ): void {
		if ( !$this->isFeatureEnabled ) {
			return;
		}

		try {
			// Note, when this runs the user has already been renamed, so we need to look up the new name.
			$centralUser = $this->centralUserLookup->newFromLocalUsername( $new );
		} catch ( UserNotGlobalException ) {
			return;
		}

		// Use a global cached flag to tell if it's a global rename that we already handled on a different wiki,
		// in which case we don't need to do anything.
		$checkKey = $this->wanCache->makeGlobalKey(
			'CampaignEvents-ContributionsRename',
			$centralUser->getCentralID(),
			$new
		);
		$this->wanCache->getWithSetCallback(
			$checkKey,
			WANObjectCache::TTL_WEEK,
			function () use ( $centralUser, $new ): int {
				if ( !$this->eventContributionStore->hasContributionsFromUser( $centralUser ) ) {
					// Cache failure
					return 1;
				}
				$job = new UpdateUserContributionRecordsJob( [
					'type' => UpdateUserContributionRecordsJob::TYPE_RENAME,
					'userID' => $centralUser->getCentralID(),
					'newName' => $new,
				] );
				$this->jobQueueGroup->push( $job );
				return 1;
			}
		);
	}
}
