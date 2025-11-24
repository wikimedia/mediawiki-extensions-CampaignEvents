<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Block\BlockUser;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContributionUpdateTestHelperTrait;
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

		$blockUserFactory = $this->getServiceContainer()->getBlockUserFactory();
		$blocker = $this->getTestSysop()->getAuthority();

		// First, block with isHideUser to test deletion
		$blockStatus = $blockUserFactory->newBlockUser(
			$user, $blocker, 'infinite', '', [ 'isHideUser' => true ]
		)->placeBlockUnsafe();
		$this->assertStatusGood( $blockStatus, 'First block' );

		$this->runUserUpdateJob();

		$storedContribAfterBlockHide = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterBlockHide->getUserId(),
			'User ID unchanged after block with isHideUser'
		);
		$this->assertNull( $storedContribAfterBlockHide->getUserName(), 'No username after block with isHideUser' );

		// Then reblock without isHideUser to test restoration
		$reblockStatus = $blockUserFactory->newBlockUser(
			$user, $blocker, 'infinite', '', [ 'isHideUser' => false ]
		)->placeBlockUnsafe( BlockUser::CONFLICT_REBLOCK );
		$this->assertStatusGood( $reblockStatus, 'Reblock' );

		$this->runUserUpdateJob();

		$storedContribAfterBlockUnhide = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterBlockUnhide->getUserId(),
			'User ID unchanged after reblocking without isHideUser'
		);
		$this->assertSame(
			$user->getName(),
			$storedContribAfterBlockUnhide->getUserName(),
			'Username is restored after reblocking without isHideUser'
		);
	}

	public function testOnUnblockUserComplete() {
		$user = $this->getGlobalUser();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $user->getId(), $user->getName() )
		);

		$blockUserFactory = $this->getServiceContainer()->getBlockUserFactory();
		$blocker = $this->getTestSysop()->getAuthority();

		$blockStatus = $blockUserFactory->newBlockUser(
			$user, $blocker, 'infinite', '', [ 'isHideUser' => true ]
		)->placeBlockUnsafe();
		$this->assertStatusGood( $blockStatus, 'First block' );

		$unblockUserFactory = $this->getServiceContainer()->getUnblockUserFactory();
		$unblockStatus = $unblockUserFactory->newUnblockUser( $user, $blocker, '' )->unblockUnsafe();
		$this->assertStatusGood( $unblockStatus, 'Reblock' );

		$this->runUserUpdateJob();

		$storedContribAfterUnblock = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterUnblock->getUserId(),
			'User ID unchanged after unblock'
		);
		$this->assertSame(
			$user->getName(),
			$storedContribAfterUnblock->getUserName(),
			'Username is back after unblock'
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
		// Then wait for our job to run. Note that this also runs deferred updates.
		$this->runUserUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertSame( $user->getId(), $storedContrib->getUserId(), 'User ID unchanged after rename' );
		$this->assertSame( $newName, $storedContrib->getUserName(), 'Username updated after rename' );
	}
}
