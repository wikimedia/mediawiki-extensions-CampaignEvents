<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\UserMergeContributionUserChangesHandler;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContributionUpdateTestHelperTrait;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\WorklistUpdateTestHelperTrait;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Extension\UserMerge\MergeUser;
use MediaWiki\Extension\UserMerge\UserMergeLogger;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\UserMergeContributionUserChangesHandler
 */
class UserMergeContributionUserChangesHandlerTest extends MediaWikiIntegrationTestCase {
	use EventContributionUpdateTestHelperTrait;
	use WorklistUpdateTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'UserMerge' );
	}

	public function testOnDeleteAccount() {
		$user = $this->getMutableTestUser()->getUser();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $user->getId(), $user->getName() )
		);

		$this->createWorklistForUser( $user->getId(), $user->getName() );

		$um = new MergeUser(
			$user,
			$this->createMock( User::class ),
			$this->createMock( UserMergeLogger::class ),
			$this->getServiceContainer()->getDatabaseBlockStore()
		);

		$um->delete(
			$this->getTestSysop()->getUser(),
			fn (): Message => $this->createMock( Message::class )
		);

		$this->runContributionUserUpdateJob();
		$this->runWorklistUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContrib->getUserId(),
			'Contribution user ID unchanged after deletion'
		);
		$this->assertNull( $storedContrib->getUserName(), 'No contribution username after deletion' );

		$storedWorklistRow = $this->getStoredWorklistRow();
		$this->assertSame(
			$user->getId(),
			(int)$storedWorklistRow->cew_user_id,
			'Worklist user ID unchanged after deletion'
		);
		$this->assertNull( $storedWorklistRow->cew_username, 'No worklist username after deletion' );
	}

	public function testOnDeleteAccount__noChanges() {
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
		$handler = new UserMergeContributionUserChangesHandler(
			$contribsStore,
			$worklistStore,
			$jobQueueGroup,
		);
		$user = $this->createMock( User::class );
		$handler->onDeleteAccount( $user );
		// Rely on the no-op JobQueueGroup mock to soft-assert that nothing was done.
	}

	public function testOnUserMergeAccountFields() {
		$mergeFrom = $this->getMutableTestUser()->getUser();
		$mergeTo = $this->getTestUser()->getUser();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $mergeFrom->getId(), $mergeFrom->getName() )
		);

		$this->createWorklistForUser( $mergeFrom->getId(), $mergeFrom->getName() );

		$um = new MergeUser(
			$mergeFrom,
			$mergeTo,
			$this->createMock( UserMergeLogger::class ),
			$this->getServiceContainer()->getDatabaseBlockStore()
		);

		$um->merge( $this->getTestSysop()->getUser(), __METHOD__ );

		$storedContrib = $this->getStoredContrib();
		$this->assertSame( $mergeTo->getId(), $storedContrib->getUserId(), 'Contribution user ID after merge' );
		$this->assertSame( $mergeTo->getName(), $storedContrib->getUserName(), 'Contribution username after merge' );

		$storedWorklistRow = $this->getStoredWorklistRow();
		$this->assertSame( $mergeTo->getId(), (int)$storedWorklistRow->cew_user_id, 'Worklist user ID after merge' );
		$this->assertSame( $mergeTo->getName(), $storedWorklistRow->cew_username, 'Worklist username after merge' );
	}
}
