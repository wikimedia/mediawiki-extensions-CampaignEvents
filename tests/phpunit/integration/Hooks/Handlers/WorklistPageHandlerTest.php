<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Hooks\Handlers;

use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContent;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistContentHandler;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistEventsStore;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistSecondaryStore;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Title\Title;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\WorklistPageHandler
 * @group Database
 */
class WorklistPageHandlerTest extends ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Keep anonymous users as plain IP users so the rejection comes from the worklist hook.
		$this->overrideConfigValue( MainConfigNames::AutoCreateTempUser, [ 'enabled' => false ] );
		// Register the worklist content model (normally gated behind the feature flag).
		$this->mergeMwGlobalArrayValue(
			'wgContentHandlers',
			[ CONTENT_MODEL_WORKLIST => WorklistContentHandler::class ]
		);
		// Editing a worklist page fires the sync ingress, which writes to the worklist stores; those
		// are out of scope here, so stub them out.
		$this->setService(
			WorklistSecondaryStore::SERVICE_NAME,
			$this->createMock( WorklistSecondaryStore::class )
		);
		$this->setService(
			WorklistEventsStore::SERVICE_NAME,
			$this->createMock( WorklistEventsStore::class )
		);
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( null );
		$this->setService( PageEventLookup::SERVICE_NAME, $pageEventLookup );
		// WorklistContent::validate() resolves the WikiLookup, whose real wiring needs site config.
		$this->setService( WikiLookup::SERVICE_NAME, $this->createMock( WikiLookup::class ) );
	}

	public function testAnonymousUserCannotEditWorklistPage(): void {
		// Create the page as a named user so it exists with the worklist content model.
		$title = Title::makeTitle( NS_MAIN, 'My Event/Worklist' );
		$this->editPage(
			$title,
			new WorklistContent( '{}' ),
			'',
			NS_MAIN,
			$this->getTestSysop()->getUser()
		);

		// Go through the Action API edit endpoint (the real user-facing path), which enforces the
		// 'edit' permission before saving; the low-level WikiPage save does not.
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous();
		try {
			$this->doApiRequestWithToken(
				[
					'action' => 'edit',
					'title' => $title->getPrefixedText(),
					'contentmodel' => CONTENT_MODEL_WORKLIST,
					'text' => '{}',
				],
				null,
				$anon
			);
			$this->fail( 'Editing a worklist page as an anonymous user should have been denied.' );
		} catch ( ApiUsageException $e ) {
			$this->assertStatusMessage(
				'campaignevents-worklist-edit-permission-denied',
				$e->getStatusValue()
			);
		}
	}
}
