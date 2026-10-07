<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Worklist\DatabaseBasedWorklistPagesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WikiPageBasedWorklistPagesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWiki\Page\PageIdentity;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesLookupFactory
 * @group Database
 */
class WorklistPagesLookupFactoryTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		// XXX T407288: test users are not made global by CentralAuth...
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'newFromUserIdentity' )
			->willReturnCallback( static fn ( UserIdentity $user ): CentralUser => new CentralUser( $user->getId() ) );
		$this->setService( CampaignsCentralUserLookup::SERVICE_NAME, $centralUserLookup );
	}

	private function getExistingWorklistPage(): PageIdentity {
		$page = $this->getNonexistingTestPage();
		$this->editPage( $page, new WorklistContent( '{}' ) );
		// Run jobs to sync the DB
		$this->runJobs();
		return $page;
	}

	public function testGetLookupForWorklist__pageDoesNotExist() {
		$page = $this->getNonexistingTestPage();
		$factory = CampaignEventsServices::getWorklistPagesLookupFactory();
		$lookup = $factory->getLookupForWorklist( $page );
		$this->assertInstanceOf( WikiPageBasedWorklistPagesLookup::class, $lookup );
	}

	public function testGetLookupForWorklist__noDBRecord() {
		// Disable events so the DB isn't updated for the worklist creation
		$this->setService( 'DomainEventDispatcher', $this->createEventDispatcher() );
		$page = $this->getExistingWorklistPage();
		$factory = CampaignEventsServices::getWorklistPagesLookupFactory();
		$lookup = $factory->getLookupForWorklist( $page );
		$this->assertInstanceOf( WikiPageBasedWorklistPagesLookup::class, $lookup );
	}

	/** @dataProvider provideGetLookupForWorklist */
	public function testGetLookupForWorklist( bool $dbUpToDate, string $expectedClass ) {
		$page = $this->getExistingWorklistPage();
		$worklistSecondaryStore = CampaignEventsServices::getWorklistSecondaryStore();
		$worklistID = $worklistSecondaryStore->getWorklistIDFromPage( WikiMap::getCurrentWikiId(), $page->getId() );

		$pageRev = $this->getServiceContainer()->getRevisionLookup()->getRevisionByTitle( $page )->getId();
		$worklistSecondaryStore->updateWorklistContentSyncedRev( $worklistID, $dbUpToDate ? $pageRev : $pageRev - 1 );

		$factory = CampaignEventsServices::getWorklistPagesLookupFactory();
		$lookup = $factory->getLookupForWorklist( $page );
		$this->assertInstanceOf( $expectedClass, $lookup );
	}

	public static function provideGetLookupForWorklist(): Generator {
		yield 'Database not up-to-date' => [ false, WikiPageBasedWorklistPagesLookup::class ];
		yield 'Database up-to-date' => [ true, DatabaseBasedWorklistPagesLookup::class ];
	}
}
