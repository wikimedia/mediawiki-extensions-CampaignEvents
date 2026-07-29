<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\CentralAuthContributionUserChangesHandler;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContributionUpdateTestHelperTrait;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\WorklistUpdateTestHelperTrait;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\CentralAuthContributionUserChangesHandler
 */
class CentralAuthContributionUserChangesHandlerTest extends MediaWikiIntegrationTestCase {
	use EventContributionUpdateTestHelperTrait;
	use WorklistUpdateTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );
	}

	public function testOnCentralAuthAccountDeleted() {
		$user = $this->getMutableTestUser()->getUserIdentity();
		$centralAuthUser = CentralAuthUser::getInstance( $user );
		// Need to do this manually due to T407288.
		$centralAuthUser->register( 'correcthorsebatterystaple', '' );

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $centralAuthUser->getId(), $centralAuthUser->getName() )
		);

		$status = $centralAuthUser->adminDelete( __METHOD__, $this->getTestSysop()->getUserIdentity() );
		$this->assertStatusGood( $status );

		$this->runContributionUserUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertSame( $user->getId(), $storedContrib->getUserId(), 'User ID unchanged after deletion' );
		$this->assertNull( $storedContrib->getUserName(), 'No username after deletion' );
	}

	public function testOnCentralAuthAccountDeleted__noChanges() {
		$contribsStore = $this->createMock( EventContributionStore::class );
		$contribsStore->expects( $this->once() )
			->method( 'hasContributionsFromUser' )
			->willReturn( false );
		$worklistStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistStore->expects( $this->once() )
			->method( 'hasWorklistsFromCreator' )
			->willReturn( false );
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'push' )->with( [] );
		$handler = new CentralAuthContributionUserChangesHandler(
			$contribsStore,
			$worklistStore,
			$jobQueueGroup,
		);
		$handler->onCentralAuthAccountDeleted( 42, 'Name' );
		// Rely on the no-op JobQueueGroup mock to soft-assert that nothing was done.
	}

	public function testOnCentralAuthUserVisibilityChanged() {
		$user = $this->getMutableTestUser()->getUserIdentity();
		$centralAuthUser = CentralAuthUser::getInstance( $user );
		// Need to do this manually due to T407288.
		$centralAuthUser->register( 'correcthorsebatterystaple', '' );

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $centralAuthUser->getId(), $centralAuthUser->getName() )
		);

		$this->createWorklistForUser( $centralAuthUser->getId(), $centralAuthUser->getName() );

		$hideStatus = $centralAuthUser->adminSetHidden( CentralAuthUser::HIDDEN_LEVEL_SUPPRESSED );
		$this->assertStatusGood( $hideStatus );

		$this->runContributionUserUpdateJob();
		$this->runWorklistUpdateJob();

		$storedContribAfterHide = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterHide->getUserId(),
			'Contribution user ID unchanged after suppression'
		);
		$this->assertNull( $storedContribAfterHide->getUserName(), 'No contribution username after suppression' );

		$storedWorklistRowAfterHide = $this->getStoredWorklistRow();
		$this->assertSame(
			$user->getId(),
			(int)$storedWorklistRowAfterHide->cew_user_id,
			'Worklist user ID unchanged after suppression'
		);
		$this->assertNull( $storedWorklistRowAfterHide->cew_username, 'No worklist username after suppression' );

		$unhideStatus = $centralAuthUser->adminSetHidden( CentralAuthUser::HIDDEN_LEVEL_NONE );
		$this->assertStatusGood( $unhideStatus );

		$this->runContributionUserUpdateJob();
		$this->runWorklistUpdateJob();

		$storedContribAfterUnhide = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterUnhide->getUserId(),
			'Contribution user ID unchanged after restore'
		);
		$this->assertSame(
			$user->getName(),
			$storedContribAfterUnhide->getUserName(),
			'Contribution username is back after restore'
		);

		$storedWorklistRowAfterUnhide = $this->getStoredWorklistRow();
		$this->assertSame(
			$user->getId(),
			(int)$storedWorklistRowAfterUnhide->cew_user_id,
			'Worklist user ID unchanged after restore'
		);
		$this->assertSame(
			$user->getName(),
			$storedWorklistRowAfterUnhide->cew_username,
			'Worklist username is back after restore'
		);
	}

	public function testOnCentralAuthUserVisibilityChanged__noContributions() {
		$contribsStore = $this->createMock( EventContributionStore::class );
		$contribsStore->expects( $this->once() )
			->method( 'hasContributionsFromUser' )
			->willReturn( false );
		$worklistStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistStore->expects( $this->once() )
			->method( 'hasWorklistsFromCreator' )
			->willReturn( false );
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->once() )->method( 'push' )->with( [] );
		$handler = new CentralAuthContributionUserChangesHandler(
			$contribsStore,
			$worklistStore,
			$jobQueueGroup,
		);
		$centralUser = $this->createMock( CentralAuthUser::class );
		$centralUser->method( 'getId' )->willReturn( 42 );
		$handler->onCentralAuthUserVisibilityChanged(
			$centralUser,
			CentralAuthUser::HIDDEN_LEVEL_SUPPRESSED
		);
		// Rely on the no-op JobQueueGroup mock to soft-assert that nothing was done.
	}
}
