<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\CentralAuthContributionUserChangesHandler;
use MediaWiki\Extension\CampaignEvents\Tests\Integration\EventContributionUpdateTestHelperTrait;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\CentralAuthContributionUserChangesHandler
 */
class CentralAuthContributionUserChangesHandlerTest extends MediaWikiIntegrationTestCase {
	use EventContributionUpdateTestHelperTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CentralAuth' );
		$this->overrideConfigValue( 'CampaignEventsEnableContributionTracking', true );
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

		$this->runUserUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertSame( $user->getId(), $storedContrib->getUserId(), 'User ID unchanged after deletion' );
		$this->assertNull( $storedContrib->getUserName(), 'No username after deletion' );
	}

	public function testOnCentralAuthAccountDeleted__featureDisabled() {
		$handler = new CentralAuthContributionUserChangesHandler(
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			new HashConfig( [ 'CampaignEventsEnableContributionTracking' => false ] )
		);
		$handler->onCentralAuthAccountDeleted( 1234, 'Some name' );
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnCentralAuthAccountDeleted__noContributions() {
		$contribsStore = $this->createMock( EventContributionStore::class );
		$contribsStore->expects( $this->once() )
			->method( 'hasContributionsFromUser' )
			->willReturn( false );
		$handler = new CentralAuthContributionUserChangesHandler(
			$contribsStore,
			$this->createNoOpMock( JobQueueGroup::class ),
			new HashConfig( [ 'CampaignEventsEnableContributionTracking' => true ] )
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

		$hideStatus = $centralAuthUser->adminSetHidden( CentralAuthUser::HIDDEN_LEVEL_SUPPRESSED );
		$this->assertStatusGood( $hideStatus );

		$this->runUserUpdateJob();

		$storedContribAfterHide = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterHide->getUserId(),
			'User ID unchanged after suppression'
		);
		$this->assertNull( $storedContribAfterHide->getUserName(), 'No username after suppression' );

		$unhideStatus = $centralAuthUser->adminSetHidden( CentralAuthUser::HIDDEN_LEVEL_NONE );
		$this->assertStatusGood( $unhideStatus );

		$this->runUserUpdateJob();

		$storedContribAfterUnhide = $this->getStoredContrib();
		$this->assertSame(
			$user->getId(),
			$storedContribAfterUnhide->getUserId(),
			'User ID unchanged after restore'
		);
		$this->assertSame(
			$user->getName(),
			$storedContribAfterUnhide->getUserName(),
			'Username is back after restore'
		);
	}

	public function testOnCentralAuthUserVisibilityChanged__featureDisabled() {
		$handler = new CentralAuthContributionUserChangesHandler(
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			new HashConfig( [ 'CampaignEventsEnableContributionTracking' => false ] )
		);
		$handler->onCentralAuthUserVisibilityChanged(
			$this->createNoOpMock( CentralAuthUser::class ),
			CentralAuthUser::HIDDEN_LEVEL_SUPPRESSED
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnCentralAuthUserVisibilityChanged__noContributions() {
		$contribsStore = $this->createMock( EventContributionStore::class );
		$contribsStore->expects( $this->once() )
			->method( 'hasContributionsFromUser' )
			->willReturn( false );
		$handler = new CentralAuthContributionUserChangesHandler(
			$contribsStore,
			$this->createNoOpMock( JobQueueGroup::class ),
			new HashConfig( [ 'CampaignEventsEnableContributionTracking' => true ] )
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
