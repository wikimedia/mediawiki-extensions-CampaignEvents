<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\MediaWikiEventIngress;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\EventContribution\EventContribution;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\ContributionAssociationPageEventIngress
 */
class ContributionAssociationPageEventIngressTest extends MediaWikiIntegrationTestCase {
	private static function makeContribution( ProperPageIdentity $page, int $revID = 789 ): EventContribution {
		return new EventContribution(
			123,
			456,
			WikiMap::getCurrentWikiId(),
			$page->getDBkey(),
			$page->getId( $page->getWikiId() ),
			$revID,
			0,
			111,
			22,
			ConvertibleTimestamp::now(),
			false
		);
	}

	private function getStoredContrib(): EventContribution {
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_event_contributions' )
			->fetchRow();
		$store = CampaignEventsServices::getEventContributionStore();
		return $store->newFromRow( $row );
	}

	private function runUpdateJob(): void {
		$this->runJobs(
			[ 'minJobs' => 1, 'maxJobs' => 1 ],
			[ 'type' => 'CampaignEventsUpdatePageContributionRecords' ]
		);
	}

	public function testDeleteAndRestore() {
		$page = $this->getExistingTestPage();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution( self::makeContribution( $page ) );

		$this->deletePage( $page );
		$this->runUpdateJob();

		$this->assertTrue( $this->getStoredContrib()->isDeleted() );

		$undeleteStatus = $this->getServiceContainer()->getUndeletePageFactory()
			->newUndeletePage( $page, $this->getTestSysop()->getAuthority() )
			->undeleteUnsafe( __METHOD__ );
		$this->assertStatusGood( $undeleteStatus, 'Could not undelete page' );
		$this->runUpdateJob();

		$this->assertFalse( $this->getStoredContrib()->isDeleted() );
	}

	public function testHandlePageMovedEvent() {
		$page = $this->getExistingTestPage();

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution( self::makeContribution( $page ) );

		$newTitle = $this->getNonexistingTestPage();
		$this->getServiceContainer()
			->getMovePageFactory()
			->newMovePage( $page, $newTitle )
			->move( $this->getTestUser()->getUser() );
		$this->runUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertSame(
			$page->getId( $page->getWikiId() ),
			$storedContrib->getPageId(),
			'Page ID should be unchanged'
		);
		$newPrefixedText = $this->getServiceContainer()->getTitleFormatter()->getPrefixedText( $newTitle );
		$this->assertSame(
			$newPrefixedText,
			$storedContrib->getPagePrefixedtext(),
			'Page prefixedtext should have changed'
		);
	}

	public function testHandlePageHistoryVisibilityChangedEvent() {
		$page = $this->getExistingTestPage();
		$firstRevID = $page->getRevisionRecord()->getId( $page->getWikiId() );

		$eventContributionsStore = CampaignEventsServices::getEventContributionStore();
		$eventContributionsStore->saveEventContribution( self::makeContribution( $page, $firstRevID ) );

		$this->editPage( $page, 'Create a new revision' );

		// Delete first revision
		$this->revisionDelete( $firstRevID, [ RevisionRecord::DELETED_USER => 1 ] );
		$this->runUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertTrue( $storedContrib->isDeleted(), 'Contribution should have been deleted' );

		// Undelete first revision
		$this->revisionDelete( $firstRevID, [ RevisionRecord::DELETED_USER => 0 ] );
		$this->runUpdateJob();

		$storedContrib = $this->getStoredContrib();
		$this->assertFalse( $storedContrib->isDeleted(), 'Contribution should have been undeleted' );
	}
}
