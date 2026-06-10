<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Worklist;

use MediaWiki\Content\ValidationParams;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContentHandler;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Worklist\WorklistContentHandler
 *
 * Database access is needed for link existence checks
 * @group Database
 */
class WorklistContentHandlerTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		// TODO: Drop this when dropping the `CampaignEventsEnableWorklists` feature flag.
		$this->mergeMwGlobalArrayValue(
			'wgContentHandlers',
			[ CONTENT_MODEL_WORKLIST => WorklistContentHandler::class ]
		);
	}

	public function testFillParserOutput() {
		$linkText = 'Some title 12345';
		$localWiki = WikiMap::getCurrentWikiId();
		$wikiLookup = $this->createMock( WikiLookup::class );
		$wikiLookup->method( 'getAllWikis' )->willReturn( [ $localWiki ] );
		$this->setService( WikiLookup::SERVICE_NAME, $wikiLookup );

		$content = new WorklistContent( json_encode(
			[
				$localWiki => [ $linkText ]
			]
		) );
		$page = PageIdentityValue::localIdentity( 1, NS_MAIN, __METHOD__ );
		$pOut = $this->getServiceContainer()->getContentRenderer()->getParserOutput( $content, $page );
		$internalLinks = $pOut->getLinkList( ParserOutputLinkTypes::LOCAL );
		$this->assertCount( 1, $internalLinks );
		$linkTarget = $internalLinks[0]['link'];
		$this->assertSame( $linkText, $linkTarget->getText() );
	}

	public function testValidateSave() {
		$invalidContent = new WorklistContent( json_encode( [ 123 ] ) );
		$page = PageIdentityValue::localIdentity( 1, NS_MAIN, __METHOD__ );
		$validationParams = new ValidationParams( $page, 0 );
		$status = $this->getServiceContainer()
			->getContentHandlerFactory()
			->getContentHandler( CONTENT_MODEL_WORKLIST )
			->validateSave( $invalidContent, $validationParams );
		$this->assertStatusError( 'campaignevents-worklist-content-not-object', $status );
	}
}
