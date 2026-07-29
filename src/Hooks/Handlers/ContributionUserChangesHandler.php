<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\EventContribution\UpdateUserContributionRecordsJob;
use MediaWiki\Extension\CampaignEvents\Job\UpdateUsernameRecordsJobBase;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Worklist\UpdateUserWorklistRecordsJob;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\RenameUser\Hook\RenameUserCompleteHook;
use MediaWiki\Specials\Hook\BlockIpCompleteHook;
use MediaWiki\Specials\Hook\UnblockUserCompleteHook;
use MediaWiki\User\User;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * This class is part of a series of hook handlers that update event contributions and worklist records in case of user
 * changes (renames, deletions, hiding/unhiding).
 * This class in particular deals with changes coming from core.
 */
class ContributionUserChangesHandler implements
	BlockIpCompleteHook,
	UnblockUserCompleteHook,
	RenameUserCompleteHook
{
	public function __construct(
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly EventContributionStore $eventContributionStore,
		private readonly WorklistSecondaryStore $worklistSecondaryStore,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly WANObjectCache $wanCache,
	) {
	}

	/**
	 * @param DatabaseBlock $block
	 * @param User $user
	 * @param ?DatabaseBlock $priorBlock
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ): void {
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

		/** @return array<string,mixed> */
		$getJobParams = function () use ( $block, $centralUser ): array {
			static $cache;
			if ( !$cache ) {
				$isHidden = $block->getHideName();
				// Optimization: don't look up the username when not needed (it's optional when deleting)
				$userName = $isHidden ? null : $this->centralUserLookup->getUserName( $centralUser );
				$cache = [
					'type' => UpdateUsernameRecordsJobBase::TYPE_VISIBILITY,
					'userID' => $centralUser->getCentralID(),
					'isHidden' => $isHidden,
					'userName' => $userName,
				];
			}
			return $cache;
		};

		$jobs = [];
		if ( $this->eventContributionStore->hasContributionsFromUser( $centralUser ) ) {
			$jobs[] = new UpdateUserContributionRecordsJob( $getJobParams() );
		}
		if ( $this->worklistSecondaryStore->hasWorklistsFromCreator( $centralUser ) ) {
			$jobs[] = new UpdateUserWorklistRecordsJob( $getJobParams() );
		}
		$this->jobQueueGroup->push( $jobs );
	}

	/**
	 * @param DatabaseBlock $block
	 * @param User $user
	 */
	public function onUnblockUserComplete( $block, $user ): void {
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

		/** @return array<string,mixed> */
		$getJobParams = function () use ( $centralUser ): array {
			static $cache;
			$cache ??= [
				'type' => UpdateUsernameRecordsJobBase::TYPE_VISIBILITY,
				'userID' => $centralUser->getCentralID(),
				'userName' => $this->centralUserLookup->getUserName( $centralUser ),
				'isHidden' => false,
			];
			return $cache;
		};

		$jobs = [];
		if ( $this->eventContributionStore->hasContributionsFromUser( $centralUser ) ) {
			$jobs[] = new UpdateUserContributionRecordsJob( $getJobParams() );
		}
		if ( $this->worklistSecondaryStore->hasWorklistsFromCreator( $centralUser ) ) {
			$jobs[] = new UpdateUserWorklistRecordsJob( $getJobParams() );
		}
		$this->jobQueueGroup->push( $jobs );
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
				$jobs = [];
				if ( $this->eventContributionStore->hasContributionsFromUser( $centralUser ) ) {
					$jobs[] = new UpdateUserContributionRecordsJob( [
						'type' => UpdateUserContributionRecordsJob::TYPE_RENAME,
						'userID' => $centralUser->getCentralID(),
						'newName' => $new,
					] );
				}
				if ( $this->worklistSecondaryStore->hasWorklistsFromCreator( $centralUser ) ) {
					$jobs[] = new UpdateUserWorklistRecordsJob( [
						'type' => UpdateUserWorklistRecordsJob::TYPE_RENAME,
						'userID' => $centralUser->getCentralID(),
						'newName' => $new,
					] );
				}
				$this->jobQueueGroup->push( $jobs );
				return 1;
			}
		);
	}
}
