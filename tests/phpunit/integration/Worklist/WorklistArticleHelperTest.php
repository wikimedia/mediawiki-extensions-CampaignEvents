<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use Generator;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\IWorklistArticlesLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistArticleHelper;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContentHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Assert\PreconditionException;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistArticleHelper
 * @group Database
 */
class WorklistArticleHelperTest extends MediaWikiIntegrationTestCase {

	private const WIKI_ID = 'testwiki';

	protected function setUp(): void {
		parent::setUp();
		// Register the worklist content model (normally gated behind the feature flag) so the
		// internal edit API can save pages that use it.
		$this->mergeMwGlobalArrayValue(
			'wgContentHandlers',
			[ CONTENT_MODEL_WORKLIST => WorklistContentHandler::class ]
		);
		// WorklistContent::validate() validates wiki IDs against the WikiLookup.
		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ self::WIKI_ID ] );
		$this->setService( WikiLookup::SERVICE_NAME, $wikiLookup );
		// Editing a worklist page fires the sync ingress (WorklistPageEventIngress), which writes to
		// the worklist secondary-store tables. That path is out of scope here, so stub it out.
		$secondaryStore = $this->createMock( WorklistSecondaryStore::class );
		$secondaryStore->method( 'createWorklist' )->willReturn( 1 );
		$secondaryStore->method( 'getWorklistIDFromPage' )->willReturn( 1 );
		$this->setService( WorklistSecondaryStore::SERVICE_NAME, $secondaryStore );
		// The same ingress resolves the owning event via PageEventLookup; stub it so it never hits
		// the (out-of-scope) event tables.
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( null );
		$this->setService( PageEventLookup::SERVICE_NAME, $pageEventLookup );
		// Act as a named user: the edit API runs the worklist permission hook.
		RequestContext::getMain()->setUser( $this->getTestUser()->getUser() );
	}

	private function getHelper(): WorklistArticleHelper {
		return $this->getServiceContainer()->get( WorklistArticleHelper::SERVICE_NAME );
	}

	/**
	 * @return array<string,list<string>>|null Decoded worklist data, or null if not a worklist page
	 */
	private function getSavedData( PageIdentity $page ): ?array {
		$content = $this->getServiceContainer()->getWikiPageFactory()
			->newFromTitle( $page )->getContent();
		if ( !$content instanceof WorklistContent ) {
			return null;
		}
		return json_decode( $content->getText(), true );
	}

	private function latestRevId( PageIdentity $page ): int {
		$wikiPage = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $page );
		return $wikiPage->getLatest();
	}

	private function seedWorklist( PageIdentity $page, array $data ): void {
		$this->assertStatusGood( $this->editPage( $page, new WorklistContent( json_encode( $data ) ) ) );
	}

	public function testAddArticles_createsPageWithArticles(): void {
		$page = $this->getNonexistingTestPage();

		$status = $this->getHelper()->applyDelta( $page, [ self::WIKI_ID => [ 'Article One' ] ], [] );

		$this->assertStatusGood( $status );
		$this->assertSame( CONTENT_MODEL_WORKLIST, $page->getContentModel() );
		$this->assertSame( [ self::WIKI_ID => [ 'Article One' ] ], $this->getSavedData( $page ) );
	}

	public function testAddArticles_appendsToExistingWorklist(): void {
		$page = $this->getNonexistingTestPage();
		$this->seedWorklist( $page, [ self::WIKI_ID => [ 'Article One' ] ] );

		$status = $this->getHelper()->applyDelta( $page, [ self::WIKI_ID => [ 'Article Two' ] ], [] );

		$this->assertStatusGood( $status );
		$this->assertSame( [ self::WIKI_ID => [ 'Article One', 'Article Two' ] ], $this->getSavedData( $page ) );
	}

	public function testAddArticles_existingTitleIsNoOp(): void {
		$page = $this->getNonexistingTestPage();
		$this->seedWorklist( $page, [ self::WIKI_ID => [ 'Article One' ] ] );
		$revBefore = $this->latestRevId( $page );

		$status = $this->getHelper()->applyDelta( $page, [ self::WIKI_ID => [ 'Article One' ] ], [] );

		$this->assertStatusGood( $status );
		$this->assertSame( $revBefore, $this->latestRevId( $page ), 'A no-op must not create a new revision.' );
	}

	public function testRemoveArticles_removesMatchingTitle(): void {
		$page = $this->getNonexistingTestPage();
		$this->seedWorklist( $page, [ self::WIKI_ID => [ 'Article One', 'Article Two' ] ] );

		$status = $this->getHelper()->applyDelta( $page, [], [ self::WIKI_ID => [ 'Article Two' ] ] );

		$this->assertStatusGood( $status );
		$this->assertSame( [ self::WIKI_ID => [ 'Article One' ] ], $this->getSavedData( $page ) );
	}

	public function testRemoveArticles_droppingLastTitleEmptiesWiki(): void {
		$page = $this->getNonexistingTestPage();
		$this->seedWorklist( $page, [ self::WIKI_ID => [ 'Article One' ] ] );

		$status = $this->getHelper()->applyDelta( $page, [], [ self::WIKI_ID => [ 'Article One' ] ] );

		$this->assertStatusGood( $status );
		// The wiki key is dropped (content model rejects empty arrays), leaving an empty object.
		$this->assertSame( [], $this->getSavedData( $page ) );
	}

	public function testRemoveArticles_nonExistentPageIsNoOp(): void {
		$page = $this->getNonexistingTestPage();

		$status = $this->getHelper()->applyDelta( $page, [], [ self::WIKI_ID => [ 'Article One' ] ] );

		$this->assertStatusGood( $status );
		$this->assertFalse( $page->exists() );
	}

	public function testApplyDelta_addsAndRemovesInASingleEdit(): void {
		$page = $this->getNonexistingTestPage();
		$this->seedWorklist( $page, [ self::WIKI_ID => [ 'Article One', 'Article Two' ] ] );
		$revBefore = $this->latestRevId( $page );

		$status = $this->getHelper()->applyDelta(
			$page,
			[ self::WIKI_ID => [ 'Article Three' ] ],
			[ self::WIKI_ID => [ 'Article One' ] ]
		);

		$this->assertStatusGood( $status );
		$this->assertSame(
			[ self::WIKI_ID => [ 'Article Two', 'Article Three' ] ],
			$this->getSavedData( $page )
		);
		$this->assertNotSame( $revBefore, $this->latestRevId( $page ), 'The delta must create a revision.' );
	}

	public function testAddArticles_nonWorklistPageReturnsFatal(): void {
		$page = $this->getNonexistingTestPage();
		// Pre-create a normal (wikitext) page at the target title.
		$this->editPage( $page, 'Not a worklist' );

		$status = $this->getHelper()->applyDelta( $page, [ self::WIKI_ID => [ 'Article One' ] ], [] );

		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-worklist-page-not-worklist', $status );
	}

	public function testApplyDelta__canonicalizesLocalTitles() {
		$curWikiID = WikiMap::getCurrentWikiId();
		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ $curWikiID ] );
		$this->setService( WikiLookup::SERVICE_NAME, $wikiLookup );

		$page = $this->getNonexistingTestPage();
		$this->seedWorklist( $page, [ $curWikiID => [ 'Article One' ] ] );

		$status = $this->getHelper()->applyDelta(
			$page,
			[ $curWikiID => [ 'article_Two' ], 'some_other_wiki' => [] ],
			[ $curWikiID => [ 'article_One' ], 'some_other_wiki' => [] ],
		);

		$this->assertStatusGood( $status );
		$this->assertSame( [ $curWikiID => [ 'Article Two' ] ], $this->getSavedData( $page ) );
	}

	public function testApplyDelta__doesNotCanonicalizeForeignTitles() {
		$otherWikiID = WikiMap::getCurrentWikiId() . '_other';
		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ $otherWikiID ] );
		$this->setService( WikiLookup::SERVICE_NAME, $wikiLookup );

		$page = $this->getNonexistingTestPage();
		$this->seedWorklist( $page, [ $otherWikiID => [ 'Article One' ] ] );

		$status = $this->getHelper()->applyDelta(
			$page,
			[ $otherWikiID => [ 'article_Two' ] ],
			[ $otherWikiID => [ 'article_One' ] ],
		);

		$this->assertStatusError( 'campaignevents-worklist-content-title-non-canonical', $status );
	}

	/** @dataProvider provideInvalidTitleCases */
	public function testApplyDelta__invalidTitlesFailEarly( bool $isAddition ) {
		$curWikiID = WikiMap::getCurrentWikiId();
		$invalidTitleData = [ $curWikiID => [ '|' ] ];
		$page = $this->getNonexistingTestPage();
		$status = $this->getHelper()->applyDelta(
			$page,
			$isAddition ? $invalidTitleData : [],
			$isAddition ? [] : $invalidTitleData,
		);

		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-worklist-content-invalid-title', $status );
	}

	public static function provideInvalidTitleCases(): Generator {
		// For simplicity, the invalid data is created in the test method because we can't access the cur wiki ID here
		yield 'Addition' => [ true ];
		yield 'Removal' => [ false ];
	}

	public function testApplyDelta__foreignWorklist() {
		$otherWikiID = WikiMap::getCurrentWikiId() . '_other';
		$foreignPage = new PageReferenceValue( NS_MAIN, 'Foreign_worklist', $otherWikiID );

		$this->expectException( PreconditionException::class );
		$this->expectExceptionMessage( 'to belong to the local wiki' );
		$this->getHelper()->applyDelta(
			$foreignPage,
			[ self::WIKI_ID => [ 'Article' ] ],
			[]
		);
	}

	private function getHelperWithStores(
		WorklistSecondaryStore $worklistSecondaryStore,
		WorklistPagesSecondaryStore $worklistPagesSecondaryStore
	): WorklistArticleHelper {
		$services = $this->getServiceContainer();
		return new WorklistArticleHelper(
			$services->getRevisionStoreFactory(),
			$services->getTitleFormatter(),
			$services->getTitleParser(),
			$worklistSecondaryStore,
			$worklistPagesSecondaryStore,
			$services->getWANObjectCache(),
		);
	}

	public function testGetWorklistArticles(): void {
		$articles = [ [ 'wiki' => self::WIKI_ID, 'prefixedtext' => 'Cat' ] ];
		// The worklist is looked up by page ID, so the page has to exist to have one.
		$worklistPage = $this->getExistingTestPage( 'My Event/Worklist' )->getTitle();

		$worklistStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistStore->expects( $this->once() )
			->method( 'getWorklistIDFromPage' )
			->with( WikiMap::getCurrentWikiId(), $worklistPage->getId() )
			->willReturn( 7 );
		$pagesStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesStore->expects( $this->once() )
			->method( 'getPagesForWorklist' )
			->with(
				7,
				0,
				0,
				IWorklistArticlesLookup::DESCENDING,
				IWorklistArticlesLookup::TIMESTAMP_SORT
			)
			->willReturn( $articles );

		$this->assertSame(
			$articles,
			$this->getHelperWithStores( $worklistStore, $pagesStore )->getWorklistArticles(
				$worklistPage,
				0,
				0,
				IWorklistArticlesLookup::DESCENDING,
				IWorklistArticlesLookup::TIMESTAMP_SORT
			)
		);
	}

	public function testGetWorklistArticles__noWorklistForPage(): void {
		$worklistStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistStore->method( 'getWorklistIDFromPage' )->willReturn( null );
		$pagesStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesStore->expects( $this->never() )->method( 'getPagesForWorklist' );

		$this->assertSame(
			[],
			$this->getHelperWithStores( $worklistStore, $pagesStore )->getWorklistArticles(
				$this->getNonexistingTestPage(),
				0,
				0,
				IWorklistArticlesLookup::DESCENDING,
				IWorklistArticlesLookup::TIMESTAMP_SORT
			)
		);
	}

	public function testFilterWorklistArticles(): void {
		$worklistPage = $this->getExistingTestPage( 'My Event/Worklist' )->getTitle();

		$worklistStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistStore->expects( $this->once() )
			->method( 'getWorklistIDFromPage' )
			->with( WikiMap::getCurrentWikiId(), $worklistPage->getId() )
			->willReturn( 7 );
		$pagesStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesStore->expects( $this->once() )
			->method( 'filterPagesInWorklist' )
			->with( 7, 'awiki', [ 'Beavers', 'Otters' ] )
			->willReturn( [ 'Beavers' ] );

		$this->assertSame(
			[ 'Beavers' ],
			$this->getHelperWithStores( $worklistStore, $pagesStore )
				->filterWorklistArticles( $worklistPage, 'awiki', [ 'Beavers', 'Otters' ] )
		);
	}

	public function testFilterWorklistArticles__noWorklistForPage(): void {
		$worklistStore = $this->createMock( WorklistSecondaryStore::class );
		$worklistStore->method( 'getWorklistIDFromPage' )->willReturn( null );
		$pagesStore = $this->createMock( WorklistPagesSecondaryStore::class );
		$pagesStore->expects( $this->never() )->method( 'filterPagesInWorklist' );

		$this->assertSame(
			[],
			$this->getHelperWithStores( $worklistStore, $pagesStore )
				->filterWorklistArticles( $this->getNonexistingTestPage(), 'awiki', [ 'Beavers' ] )
		);
	}

	public function testGetRawWorklistContentCached() {
		$page = $this->getNonexistingTestPage();
		$this->seedWorklist( $page, [ self::WIKI_ID => [ 'Article One', 'Article Two' ] ] );

		$revisionStore = $this->createMock( RevisionStore::class );
		// This should only be called once, as the second call below should read from cache instead
		$revisionStore->expects( $this->once() )
			->method( 'getRevisionByTitle' )
			->with( $page )
			->willReturn( $page->getRevisionRecord() );
		$revisionStoreFactory = $this->createMock( RevisionStoreFactory::class );
		$revisionStoreFactory->method( 'getRevisionStore' )->willReturn( $revisionStore );
		$this->setService( 'RevisionStoreFactory', $revisionStoreFactory );

		$this->assertIsArray( $this->getHelper()->getRawWorklistContentCached( $page ) );
		$this->assertIsArray( $this->getHelper()->getRawWorklistContentCached( $page ) );
	}
}
