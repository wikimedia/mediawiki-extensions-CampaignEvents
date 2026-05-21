<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistArticleHelper;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContentHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Worklist\WorklistArticleHelper
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
		return new WorklistArticleHelper(
			$this->getServiceContainer()->getWikiPageFactory(),
			$this->getServiceContainer()->getTitleFormatter()
		);
	}

	private function worklistTitle(): Title {
		return Title::makeTitle( NS_MAIN, 'My Event/Worklist' );
	}

	/**
	 * @return array<string,list<string>>|null Decoded worklist data, or null if not a worklist page
	 */
	private function getSavedData( Title $title ): ?array {
		$content = $this->getServiceContainer()->getWikiPageFactory()
			->newFromTitle( $title )->getContent();
		if ( !$content instanceof WorklistContent ) {
			return null;
		}
		return json_decode( $content->getText(), true );
	}

	private function latestRevId( Title $title ): int {
		$wikiPage = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
		return $wikiPage->getLatest();
	}

	private function seedWorklist( Title $title, array $data ): void {
		$this->assertStatusGood( $this->getHelper()->applyDelta( $title, $data, [] ) );
	}

	/**
	 * @covers ::applyDelta
	 * @covers ::saveViaEditApi
	 * @covers ::applyChanges
	 */
	public function testAddArticles_createsPageWithArticles(): void {
		$title = $this->worklistTitle();

		$status = $this->getHelper()->applyDelta( $title, [ self::WIKI_ID => [ 'Article One' ] ], [] );

		$this->assertStatusGood( $status );
		$this->assertSame( CONTENT_MODEL_WORKLIST, $title->getContentModel( IDBAccessObject::READ_LATEST ) );
		$this->assertSame( [ self::WIKI_ID => [ 'Article One' ] ], $this->getSavedData( $title ) );
	}

	/**
	 * @covers ::applyDelta
	 * @covers ::applyChanges
	 */
	public function testAddArticles_appendsToExistingWorklist(): void {
		$title = $this->worklistTitle();
		$this->seedWorklist( $title, [ self::WIKI_ID => [ 'Article One' ] ] );

		$status = $this->getHelper()->applyDelta( $title, [ self::WIKI_ID => [ 'Article Two' ] ], [] );

		$this->assertStatusGood( $status );
		$this->assertSame( [ self::WIKI_ID => [ 'Article One', 'Article Two' ] ], $this->getSavedData( $title ) );
	}

	/**
	 * @covers ::applyDelta
	 */
	public function testAddArticles_existingTitleIsNoOp(): void {
		$title = $this->worklistTitle();
		$this->seedWorklist( $title, [ self::WIKI_ID => [ 'Article One' ] ] );
		$revBefore = $this->latestRevId( $title );

		$status = $this->getHelper()->applyDelta( $title, [ self::WIKI_ID => [ 'Article One' ] ], [] );

		$this->assertStatusGood( $status );
		$this->assertSame( $revBefore, $this->latestRevId( $title ), 'A no-op must not create a new revision.' );
	}

	/**
	 * @covers ::applyDelta
	 * @covers ::applyChanges
	 */
	public function testRemoveArticles_removesMatchingTitle(): void {
		$title = $this->worklistTitle();
		$this->seedWorklist( $title, [ self::WIKI_ID => [ 'Article One', 'Article Two' ] ] );

		$status = $this->getHelper()->applyDelta( $title, [], [ self::WIKI_ID => [ 'Article Two' ] ] );

		$this->assertStatusGood( $status );
		$this->assertSame( [ self::WIKI_ID => [ 'Article One' ] ], $this->getSavedData( $title ) );
	}

	/**
	 * @covers ::applyDelta
	 * @covers ::applyChanges
	 */
	public function testRemoveArticles_droppingLastTitleEmptiesWiki(): void {
		$title = $this->worklistTitle();
		$this->seedWorklist( $title, [ self::WIKI_ID => [ 'Article One' ] ] );

		$status = $this->getHelper()->applyDelta( $title, [], [ self::WIKI_ID => [ 'Article One' ] ] );

		$this->assertStatusGood( $status );
		// The wiki key is dropped (content model rejects empty arrays), leaving an empty object.
		$this->assertSame( [], $this->getSavedData( $title ) );
	}

	/**
	 * @covers ::applyDelta
	 */
	public function testRemoveArticles_nonExistentPageIsNoOp(): void {
		$title = $this->worklistTitle();

		$status = $this->getHelper()->applyDelta( $title, [], [ self::WIKI_ID => [ 'Article One' ] ] );

		$this->assertStatusGood( $status );
		$this->assertFalse( $title->exists( IDBAccessObject::READ_LATEST ) );
	}

	/**
	 * @covers ::applyDelta
	 * @covers ::applyChanges
	 */
	public function testApplyDelta_addsAndRemovesInASingleEdit(): void {
		$title = $this->worklistTitle();
		$this->seedWorklist( $title, [ self::WIKI_ID => [ 'Article One', 'Article Two' ] ] );
		$revBefore = $this->latestRevId( $title );

		$status = $this->getHelper()->applyDelta(
			$title,
			[ self::WIKI_ID => [ 'Article Three' ] ],
			[ self::WIKI_ID => [ 'Article One' ] ]
		);

		$this->assertStatusGood( $status );
		$this->assertSame(
			[ self::WIKI_ID => [ 'Article Two', 'Article Three' ] ],
			$this->getSavedData( $title )
		);
		$this->assertNotSame( $revBefore, $this->latestRevId( $title ), 'The delta must create a revision.' );
	}

	/**
	 * @covers ::applyDelta
	 */
	public function testAddArticles_nonWorklistPageReturnsFatal(): void {
		$title = $this->worklistTitle();
		// Pre-create a normal (wikitext) page at the target title.
		$this->editPage( $title, 'Not a worklist' );

		$status = $this->getHelper()->applyDelta( $title, [ self::WIKI_ID => [ 'Article One' ] ], [] );

		$this->assertStatusNotGood( $status );
		$this->assertStatusMessage( 'campaignevents-worklist-page-not-worklist', $status );
	}
}
