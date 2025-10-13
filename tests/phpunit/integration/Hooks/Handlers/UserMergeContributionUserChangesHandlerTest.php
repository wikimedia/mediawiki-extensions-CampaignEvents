<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\UserMergeContributionUserChangesHandler;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContributionUpdateTestHelperTrait;
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

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'UserMerge' );
		$this->overrideConfigValue( 'CampaignEventsEnableContributionTracking', true );
	}

	public function testOnDeleteAccount() {
		$user = $this->getMutableTestUser()->getUser();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution(
			self::makeContributionWithUser( $user->getId(), $user->getName() )
		);

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

		$this->runUserUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertSame( $user->getId(), $storedContrib->getUserId(), 'User ID unchanged after deletion' );
		$this->assertNull( $storedContrib->getUserName(), 'No username after deletion' );
	}

	public function testOnDeleteAccount__featureDisabled() {
		$handler = new UserMergeContributionUserChangesHandler(
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			new HashConfig( [ 'CampaignEventsEnableContributionTracking' => false ] )
		);
		$user = $this->createNoOpMock( User::class );
		$handler->onDeleteAccount( $user );
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnDeleteAccount__noContributions() {
		$contribsStore = $this->createMock( EventContributionStore::class );
		$contribsStore->expects( $this->once() )
			->method( 'hasContributionsFromUser' )
			->willReturn( false );
		$handler = new UserMergeContributionUserChangesHandler(
			$contribsStore,
			$this->createNoOpMock( JobQueueGroup::class ),
			new HashConfig( [ 'CampaignEventsEnableContributionTracking' => true ] )
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

		$um = new MergeUser(
			$mergeFrom,
			$mergeTo,
			$this->createMock( UserMergeLogger::class ),
			$this->getServiceContainer()->getDatabaseBlockStore()
		);

		$um->merge( $this->getTestSysop()->getUser(), __METHOD__ );

		$storedContrib = $this->getStoredContrib();
		$this->assertSame( $mergeTo->getId(), $storedContrib->getUserId(), 'User ID after merge' );
		$this->assertSame( $mergeTo->getName(), $storedContrib->getUserName(), 'Username after merge' );
	}

	public function testOnUserMergeAccountFields__featureDisabled() {
		$handler = new UserMergeContributionUserChangesHandler(
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			new HashConfig( [ 'CampaignEventsEnableContributionTracking' => false ] )
		);

		$fields = [];
		$handler->onUserMergeAccountFields( $fields );
		$this->assertSame( [], $fields, 'No fields should be added' );
	}
}
