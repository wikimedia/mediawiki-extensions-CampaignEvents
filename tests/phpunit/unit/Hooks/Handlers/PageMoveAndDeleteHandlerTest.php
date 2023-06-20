<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks\Handlers;

use Generator;
use ManualLogEntry;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PageMoveAndDeleteHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWEventLookupFromPage;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiUnitTestCase;
use TitleFormatter;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PageMoveAndDeleteHandler
 * @covers ::__construct
 */
class PageMoveAndDeleteHandlerTest extends MediaWikiUnitTestCase {
	public function getHandler(
		MWEventLookupFromPage $eventLookupFromPage = null,
		DeleteEventCommand $deleteEventCommand = null
	): PageMoveAndDeleteHandler {
		return new PageMoveAndDeleteHandler(
			$eventLookupFromPage ?? $this->createMock( MWEventLookupFromPage::class ),
			$this->createMock( IEventStore::class ),
			$deleteEventCommand ?? $this->createMock( DeleteEventCommand::class ),
			$this->createMock( PageStore::class ),
			$this->createMock( TitleFormatter::class )
		);
	}

	/**
	 * @param bool $expectsEventDeletion
	 * @param MWEventLookupFromPage $eventLookupFromPage
	 * @dataProvider providePageDelete
	 * @covers ::onPageDeleteComplete
	 */
	public function testOnPageDeleteComplete(
		bool $expectsEventDeletion,
		MWEventLookupFromPage $eventLookupFromPage
	) {
		$deleteEventCommand = $this->createMock( DeleteEventCommand::class );
		if ( $expectsEventDeletion ) {
			$deleteEventCommand->expects( $this->once() )->method( 'deleteUnsafe' );
		} else {
			$deleteEventCommand->expects( $this->never() )->method( 'deleteUnsafe' );
		}
		$this->getHandler( $eventLookupFromPage, $deleteEventCommand )->onPageDeleteComplete(
			$this->createMock( ProperPageIdentity::class ),
			$this->createMock( Authority::class ),
			'Reason',
			42,
			$this->createMock( RevisionRecord::class ),
			$this->createMock( ManualLogEntry::class ),
			1
		);

		// We use soft assertions above
		$this->addToAssertionCount( 1 );
	}

	public function providePageDelete(): Generator {
		$noRegistrationLookup = $this->createMock( MWEventLookupFromPage::class );
		$noRegistrationLookup->method( 'getRegistrationForPage' )->willReturn( null );
		yield 'No registration for page' => [ false, $noRegistrationLookup ];

		$existingRegistrationLookup = $this->createMock( MWEventLookupFromPage::class );
		$existingRegistrationLookup->method( 'getRegistrationForPage' )
			->willReturn( $this->createMock( ExistingEventRegistration::class ) );
		yield 'Page has event registration' => [ true, $existingRegistrationLookup ];
	}
}
