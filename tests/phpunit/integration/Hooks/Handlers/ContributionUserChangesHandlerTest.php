<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Block\BlockUser;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContributionUpdateTestHelperTrait;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\WorklistUpdateTestHelperTrait;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ContributionUserChangesHandler
 */
class ContributionUserChangesHandlerTest extends MediaWikiIntegrationTestCase {
	use EventContributionUpdateTestHelperTrait;
	use WorklistUpdateTestHelperTrait;

	private function getGlobalUser(): User {
		$user = $this->getMutableTestUser()->getUser();
		if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			// If CentralAuth is loaded, make sure this user gets globally attached. This is necessary because otherwise
			// it won't be found by the central user lookup. And it isn't done automatically either, see T407288.
			$centralUser = CentralAuthUser::getInstance( $user );
			$centralUser->register( 'correcthorsebatterystaple', '' );
			$centralUser->attach( WikiMap::getCurrentWikiId() );
			// Next, register the site to avoid T407298... (CentralAuthUser::queryAttachedBasic relies on this)
			// This was stolen from SpecialCentralAuthTest::setUp.
			$sitesTable = $this->getServiceContainer()->getSiteStore();
			$site = $sitesTable->getSite( WikiMap::getCurrentWikiId() ) ?? new MediaWikiSite();
			$site->setGlobalId( WikiMap::getCurrentWikiId() );
			$site->setPath( MediaWikiSite::PATH_PAGE, "https://en.wikipedia.org/wiki/$1" );
			$sitesTable->saveSite( $site );
		}
		return $user;
	}

	public function testOnBlockIpComplete() {
		$user = $this->getGlobalUser();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $user->getId(), $user->getName() )
		);

		$this->createWorklistForUser( $user->getId(), $user->getName() );

		$blockUserFactory = $this->getServiceContainer()->getBlockUserFactory();
		$blocker = $this->getTestSysop()->getAuthority();

		// First, block with isHideUser to test deletion
		$blockStatus = $blockUserFactory->newBlockUser(
			$user, $blocker, 'infinite', '', [ 'isHideUser' => true ]
		)->placeBlockUnsafe();
		$this->assertStatusGood( $blockStatus, 'First block' );

		$this->runContributionUserUpdateJob();
		$this->runWorklistUpdateJob();

		$storedContribAfterBlockHide = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterBlockHide->getUserId(),
			'Contribution user ID unchanged after block with isHideUser'
		);
		$this->assertNull(
			$storedContribAfterBlockHide->getUserName(),
			'No contribution username after block with isHideUser'
		);

		$storedWorklistRowAfterBlockHide = $this->getStoredWorklistRow();
		$this->assertSame(
			$user->getId(),
			(int)$storedWorklistRowAfterBlockHide->cew_user_id,
			'User ID unchanged after block with isHideUser'
		);
		$this->assertNull(
			$storedWorklistRowAfterBlockHide->cew_username,
			'No worklist username after block with isHideUser'
		);

		// Then reblock without isHideUser to test restoration
		$reblockStatus = $blockUserFactory->newBlockUser(
			$user, $blocker, 'infinite', '', [ 'isHideUser' => false ]
		)->placeBlockUnsafe( BlockUser::CONFLICT_REBLOCK );
		$this->assertStatusGood( $reblockStatus, 'Reblock' );

		$this->runContributionUserUpdateJob();
		$this->runWorklistUpdateJob();

		$storedContribAfterBlockUnhide = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterBlockUnhide->getUserId(),
			'Contribution user ID unchanged after reblocking without isHideUser'
		);
		$this->assertSame(
			$user->getName(),
			$storedContribAfterBlockUnhide->getUserName(),
			'Contribution username is restored after reblocking without isHideUser'
		);

		$storedWorklistRowAfterBlockUnhide = $this->getStoredWorklistRow();
		$this->assertSame(
			$user->getId(),
			(int)$storedWorklistRowAfterBlockUnhide->cew_user_id,
			'Worklist user ID unchanged after reblocking without isHideUser'
		);
		$this->assertSame(
			$user->getName(),
			$storedWorklistRowAfterBlockUnhide->cew_username,
			'Worklist username is restored after reblocking without isHideUser'
		);
	}

	public function testOnUnblockUserComplete() {
		$user = $this->getGlobalUser();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $user->getId(), $user->getName() )
		);

		$this->createWorklistForUser( $user->getId(), $user->getName() );

		$blockUserFactory = $this->getServiceContainer()->getBlockUserFactory();
		$blocker = $this->getTestSysop()->getAuthority();

		$blockStatus = $blockUserFactory->newBlockUser(
			$user, $blocker, 'infinite', '', [ 'isHideUser' => true ]
		)->placeBlockUnsafe();
		$this->assertStatusGood( $blockStatus, 'First block' );

		$unblockUserFactory = $this->getServiceContainer()->getUnblockUserFactory();
		$unblockStatus = $unblockUserFactory->newUnblockUser( $user, $blocker, '' )->unblockUnsafe();
		$this->assertStatusGood( $unblockStatus, 'Reblock' );

		$this->runContributionUserUpdateJob();
		$this->runWorklistUpdateJob();

		$storedContribAfterUnblock = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterUnblock->getUserId(),
			'Contribution user ID unchanged after unblock'
		);
		$this->assertSame(
			$user->getName(),
			$storedContribAfterUnblock->getUserName(),
			'Contribution username is back after unblock'
		);

		$storedWorklistRowAfterUnblock = $this->getStoredWorklistRow();
		$this->assertSame(
			$user->getId(),
			(int)$storedWorklistRowAfterUnblock->cew_user_id,
			'Worklist user ID unchanged after unblock'
		);
		$this->assertSame(
			$user->getName(),
			$storedWorklistRowAfterUnblock->cew_username,
			'Worklist username is back after unblock'
		);
	}

	public function testOnRenameUserComplete() {
		// XXX Would be nice to test both variants here, but per-test extension dependencies aren't a thing.
		$hasCentralAuth = ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' );
		$user = $this->getGlobalUser();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $user->getId(), $user->getName() )
		);

		$this->createWorklistForUser( $user->getId(), $user->getName() );

		$newName = $user->getName() . '-renamed';
		$performer = $this->getTestSysop()->getUser();
		if ( $hasCentralAuth ) {
			$globalRenameFactory = CentralAuthServices::getGlobalRenameFactory();
			$renameStatus = $globalRenameFactory
				->newGlobalRenameUser( $performer, CentralAuthUser::getInstance( $user ), $newName )
				->rename( [
					'movepages' => false,
					'suppressredirects' => false,
					'reason' => '',
				] );
		} else {
			$renameUserFactory = $this->getServiceContainer()->getRenameUserFactory();
			$renameStatus = $renameUserFactory->newRenameUser( $performer, $user, $newName, '' )->renameUnsafe();
		}
		$this->assertStatusGood( $renameStatus );

		// First, let the local rename job run if CentralAuth is installed
		if ( $hasCentralAuth ) {
			$this->runJobs(
				[ 'minJobs' => 1, 'maxJobs' => 1 ],
				[ 'type' => 'LocalRenameUserJob' ]
			);
		}
		// Then wait for our jobs to run. Note that this also runs deferred updates.
		$this->runContributionUserUpdateJob();
		$this->runWorklistUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertSame( $user->getId(), $storedContrib->getUserId(), 'Contribution user ID unchanged after rename' );
		$this->assertSame( $newName, $storedContrib->getUserName(), 'Contribution username updated after rename' );

		$storedWorklistRow = $this->getStoredWorklistRow();
		$this->assertSame(
			$user->getId(),
			(int)$storedWorklistRow->cew_user_id,
			'Worklist user ID unchanged after rename'
		);
		$this->assertSame( $newName, $storedWorklistRow->cew_username, 'Worklist username updated after rename' );
	}
}
