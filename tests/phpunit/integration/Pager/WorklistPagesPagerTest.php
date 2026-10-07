<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPager;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Request\FauxRequest;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Pager\WorklistPagesPager
 */
class WorklistPagesPagerTest extends MediaWikiIntegrationTestCase {

	private const WORKLIST_ID = 1;

	public function addDBData(): void {
		$db = $this->getDb();
		$startTS = 1700000000;

		$db->newInsertQueryBuilder()
			->insertInto( 'ce_worklist_pages' )
			->rows( [
				[
					'cewp_cew_id' => self::WORKLIST_ID,
					'cewp_wiki' => 'awiki',
					'cewp_page_prefixedtext' => 'Page 11',
					'cewp_user_id' => 2,
					'cewp_timestamp' => $db->timestamp( $startTS ),
				],
				[
					'cewp_cew_id' => self::WORKLIST_ID,
					'cewp_wiki' => 'awiki',
					'cewp_page_prefixedtext' => 'Page 12',
					'cewp_user_id' => 3,
					'cewp_timestamp' => $db->timestamp( $startTS + 1 ),
				],
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param bool $namedUser Whether the performer is a named (logged-in) user.
	 * @param ?PageIdentity $worklistPage The local worklist page, or null for a foreign/unresolved
	 *   page (in which case only isNamed() gates the actions column).
	 */
	private function newPager( bool $namedUser, ?PageIdentity $worklistPage = null ): WorklistPagesPager {
		$services = $this->getServiceContainer();

		$context = new RequestContext();
		$context->setRequest( new FauxRequest( [] ) );
		$context->setUser(
			$namedUser ? $this->getTestUser()->getUser() : $services->getUserFactory()->newAnonymous()
		);

		$worklistSecondaryStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistSecondaryStore->method( 'getWorklistIDFromPage' )->willReturn( self::WORKLIST_ID );

		return new WorklistPagesPager(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			$services->getLinkBatchFactory(),
			$services->getTitleFactory(),
			$this->createMock( WikiLookup::class ),
			$worklistSecondaryStore,
			$services->get( WorklistPagesSecondaryStore::SERVICE_NAME ),
			$context,
			$services->getLinkRenderer(),
			$worklistPage ?? $this->getLocalWorklistPage(),
		);
	}

	private function getLocalWorklistPage(): PageIdentity {
		return PageIdentityValue::localIdentity(
			1,
			NS_EVENT,
			"Worklist_test/Worklist"
		);
	}

	public function testEligibleUserSeesActionsColumnAndButtonOnEveryRow(): void {
		$pager = $this->newPager( true );
		$pager->doQuery();
		$wrapper = TestingAccessWrapper::newFromObject( $pager );

		$this->assertArrayHasKey( 'actions', $wrapper->getFieldNames() );

		$rowCount = 0;
		foreach ( $pager->mResult as $row ) {
			$rowCount++;
			$this->assertStringContainsString(
				'ext-campaignevents-delete-worklist-page-btn',
				$wrapper->formatActions( $row ),
				'An eligible user should see a remove button on every row'
			);
		}
		$this->assertSame( 2, $rowCount, 'Both worklist pages linked to the event should be listed' );
	}

	public function testIneligibleNamedUserSeesActionsColumnForForeignWorklist(): void {
		$this->setGroupPermissions( [ '*' => [ 'edit' => false ], 'user' => [ 'edit' => false ] ] );
		$foreignPage = new PageIdentityValue(
			123,
			NS_EVENT,
			'Test event/Worklist',
			WikiMap::getCurrentWikiId() . '-other'
		);
		$pager = $this->newPager( true, $foreignPage );
		$pager->doQuery();
		$wrapper = TestingAccessWrapper::newFromObject( $pager );

		$this->assertArrayHasKey( 'actions', $wrapper->getFieldNames() );
	}

	public function testIneligibleUserSeesNoActionsColumn(): void {
		$pager = $this->newPager( false );
		$pager->doQuery();
		$wrapper = TestingAccessWrapper::newFromObject( $pager );

		$this->assertArrayNotHasKey(
			'actions',
			$wrapper->getFieldNames(),
			'A user who cannot remove articles should not see the actions column'
		);
	}

	public function testNamedUserWhoCanEditLocalWorklistPageSeesColumn(): void {
		$pager = $this->newPager( true, $this->getLocalWorklistPage() );
		$wrapper = TestingAccessWrapper::newFromObject( $pager );

		$this->assertArrayHasKey(
			'actions',
			$wrapper->getFieldNames(),
			'A named user who can edit the local worklist page should see the actions column'
		);
	}

	public function testNamedUserWhoCannotEditLocalWorklistPageSeesNoColumn(): void {
		// Remove the 'edit' right so probablyCan( 'edit', $worklistPage ) is false for the performer.
		$this->setGroupPermissions( [ '*' => [ 'edit' => false ], 'user' => [ 'edit' => false ] ] );

		$pager = $this->newPager( true, $this->getLocalWorklistPage() );
		$wrapper = TestingAccessWrapper::newFromObject( $pager );

		$this->assertArrayNotHasKey(
			'actions',
			$wrapper->getFieldNames(),
			'A named user who cannot edit the local worklist page should not see the actions column'
		);
	}
}
