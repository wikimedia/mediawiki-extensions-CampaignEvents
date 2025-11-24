<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks\Handlers;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContributionStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ContributionUserChangesHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use WANObjectCache;
use Wikimedia\ObjectCache\EmptyBagOStuff;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * Lightweight tests for early-return scenarios in these hook handlers. Similar scenarios for other hooks are tested
 * in an integration test due to the dependencies on other extensions.
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\ContributionUserChangesHandler
 */
class ContributionUserChangesHandlerTest extends MediaWikiUnitTestCase {
	/** Returns an instance of the handler where all dependencies expect NOT to be used. */
	private function getNoOpHandler(): ContributionUserChangesHandler {
		return new ContributionUserChangesHandler(
			$this->createNoOpMock( CampaignsCentralUserLookup::class ),
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			$this->createNoOpMock( WANObjectCache::class ),
		);
	}

	private function getValidBlock( bool $expectsTargetCheck = true ): DatabaseBlock {
		$block = $this->createMock( DatabaseBlock::class );
		$block->expects( $expectsTargetCheck ? $this->atLeastOnce() : $this->any() )
			->method( 'getTargetUserIdentity' )
			->willReturn( $this->createMock( UserIdentity::class ) );
		$block->expects( $this->atLeastOnce() )->method( 'getHideName' )->willReturn( true );
		return $block;
	}

	public function testOnBlockIpComplete__noTarget() {
		$block = $this->createMock( DatabaseBlock::class );
		$block->expects( $this->atLeastOnce() )->method( 'getTargetUserIdentity' )->willReturn( null );
		$this->getNoOpHandler()->onBlockIpComplete(
			$block,
			$this->createNoOpMock( User::class ),
			null
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnBlockIpComplete__noHideUserNoPreviousBlock() {
		$block = $this->createMock( DatabaseBlock::class );
		$block->expects( $this->atLeastOnce() )->method( 'getTargetUserIdentity' )
			->willReturn( $this->createMock( UserIdentity::class ) );
		$block->expects( $this->atLeastOnce() )->method( 'getHideName' )->willReturn( false );
		$this->getNoOpHandler()->onBlockIpComplete(
			$block,
			$this->createNoOpMock( User::class ),
			null
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnBlockIpComplete__sameVisibilityAsPreviousBlock() {
		$this->getNoOpHandler()->onBlockIpComplete(
			$this->getValidBlock(),
			$this->createNoOpMock( User::class ),
			$this->getValidBlock( false )
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnBlockIpComplete__userNotGlobal() {
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'newFromUserIdentity' )
			->willThrowException( new UserNotGlobalException( 12345 ) );
		$handler = new ContributionUserChangesHandler(
			$centralUserLookup,
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			$this->createNoOpMock( WANObjectCache::class ),
		);
		$handler->onBlockIpComplete(
			$this->getValidBlock(),
			$this->createNoOpMock( User::class ),
			null
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnBlockIpComplete__noContributions() {
		$contribsStore = $this->createMock( EventContributionStore::class );
		$contribsStore->expects( $this->once() )
			->method( 'hasContributionsFromUser' )
			->willReturn( false );
		$handler = new ContributionUserChangesHandler(
			$this->createMock( CampaignsCentralUserLookup::class ),
			$contribsStore,
			$this->createNoOpMock( JobQueueGroup::class ),
			$this->createNoOpMock( WANObjectCache::class ),
		);
		$handler->onBlockIpComplete(
			$this->getValidBlock(),
			$this->createNoOpMock( User::class ),
			null
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnUnblockUserComplete__noTarget() {
		$block = $this->createMock( DatabaseBlock::class );
		$block->expects( $this->atLeastOnce() )->method( 'getTargetUserIdentity' )->willReturn( null );
		$this->getNoOpHandler()->onUnblockUserComplete(
			$block,
			$this->createNoOpMock( User::class )
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnUnblockUserComplete__noHideUser() {
		$block = $this->createMock( DatabaseBlock::class );
		$block->expects( $this->atLeastOnce() )->method( 'getTargetUserIdentity' )
			->willReturn( $this->createMock( UserIdentity::class ) );
		$block->expects( $this->atLeastOnce() )->method( 'getHideName' )->willReturn( false );
		$this->getNoOpHandler()->onUnblockUserComplete(
			$block,
			$this->createNoOpMock( User::class )
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnUnblockUserComplete__userNotGlobal() {
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'newFromUserIdentity' )
			->willThrowException( new UserNotGlobalException( 12345 ) );
		$handler = new ContributionUserChangesHandler(
			$centralUserLookup,
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			$this->createNoOpMock( WANObjectCache::class ),
		);
		$handler->onUnblockUserComplete(
			$this->getValidBlock(),
			$this->createNoOpMock( User::class )
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnUnblockUserComplete__noContributions() {
		$contribsStore = $this->createMock( EventContributionStore::class );
		$contribsStore->expects( $this->once() )
			->method( 'hasContributionsFromUser' )
			->willReturn( false );
		$handler = new ContributionUserChangesHandler(
			$this->createMock( CampaignsCentralUserLookup::class ),
			$contribsStore,
			$this->createNoOpMock( JobQueueGroup::class ),
			$this->createNoOpMock( WANObjectCache::class ),
		);
		$handler->onUnblockUserComplete(
			$this->getValidBlock(),
			$this->createNoOpMock( User::class )
		);
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnRenameUserComplete__userNotGlobal() {
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'newFromLocalUsername' )
			->willThrowException( new UserNotGlobalException( 12345 ) );
		$handler = new ContributionUserChangesHandler(
			$centralUserLookup,
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			$this->createNoOpMock( WANObjectCache::class ),
		);
		$handler->onRenameUserComplete( 1, 'Old', 'New' );
		DeferredUpdates::doUpdates();
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnRenameUserComplete__alreadyProcessed() {
		$centralID = 9876;
		$newName = 'Some new name';

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->expects( $this->atLeastOnce() )
			->method( 'newFromLocalUsername' )
			->willReturn( new CentralUser( $centralID ) );

		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff( [] ) ] );
		$cacheKey = $wanCache->makeGlobalKey( 'CampaignEvents-ContributionsRename', $centralID, $newName );
		$wanCache->set( $cacheKey, 1 );

		$handler = new ContributionUserChangesHandler(
			$centralUserLookup,
			$this->createNoOpMock( EventContributionStore::class ),
			$this->createNoOpMock( JobQueueGroup::class ),
			$wanCache,
		);
		$handler->onRenameUserComplete( 1, 'Old', $newName );
		DeferredUpdates::doUpdates();
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}

	public function testOnRenameUserComplete__noContributions() {
		$contribsStore = $this->createMock( EventContributionStore::class );
		$contribsStore->expects( $this->once() )
			->method( 'hasContributionsFromUser' )
			->willReturn( false );
		$handler = new ContributionUserChangesHandler(
			$this->createMock( CampaignsCentralUserLookup::class ),
			$contribsStore,
			$this->createNoOpMock( JobQueueGroup::class ),
			new WANObjectCache( [ 'cache' => new EmptyBagOStuff() ] ),
		);
		$handler->onRenameUserComplete( 1, 'Old', 'New' );
		DeferredUpdates::doUpdates();
		// Rely on soft assertions from the no-op mocks to assert that nothing was done.
	}
}
