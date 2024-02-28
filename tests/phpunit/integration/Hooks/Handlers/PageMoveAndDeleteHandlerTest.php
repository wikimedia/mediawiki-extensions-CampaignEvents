<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks\Handlers;

use Generator;
use ManualLogEntry;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PageMoveAndDeleteHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PageMoveAndDeleteHandler
 * @covers ::__construct
 * @todo Make this a unit test once it's possible to use namespace constants (T310375)
 */
class PageMoveAndDeleteHandlerTest extends MediaWikiIntegrationTestCase {
	public function getHandler(
		PageEventLookup $pageEventLookup = null,
		DeleteEventCommand $deleteEventCommand = null
	): PageMoveAndDeleteHandler {
		return new PageMoveAndDeleteHandler(
			$pageEventLookup ?? $this->createMock( PageEventLookup::class ),
			$this->createMock( IEventStore::class ),
			$deleteEventCommand ?? $this->createMock( DeleteEventCommand::class ),
			$this->createMock( TitleFormatter::class ),
			$this->createMock( CampaignsPageFactory::class )
		);
	}

	/**
	 * @param bool $expectsEventDeletion
	 * @param PageEventLookup $pageEventLookup
	 * @dataProvider providePageDelete
	 * @covers ::onPageDeleteComplete
	 */
	public function testOnPageDeleteComplete(
		bool $expectsEventDeletion,
		PageEventLookup $pageEventLookup
	) {
		$deleteEventCommand = $this->createMock( DeleteEventCommand::class );
		if ( $expectsEventDeletion ) {
			$deleteEventCommand->expects( $this->once() )->method( 'deleteUnsafe' );
		} else {
			$deleteEventCommand->expects( $this->never() )->method( 'deleteUnsafe' );
		}
		$this->getHandler( $pageEventLookup, $deleteEventCommand )->onPageDeleteComplete(
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
		$noRegistrationLookup = $this->createMock( PageEventLookup::class );
		$noRegistrationLookup->method( 'getRegistrationForLocalPage' )->willReturn( null );
		yield 'No registration for page' => [ false, $noRegistrationLookup ];

		$existingRegistrationLookup = $this->createMock( PageEventLookup::class );
		$existingRegistrationLookup->method( 'getRegistrationForLocalPage' )
			->willReturn( $this->createMock( ExistingEventRegistration::class ) );
		yield 'Page has event registration' => [ true, $existingRegistrationLookup ];
	}

	/**
	 * @dataProvider provideOnTitleMove
	 * @covers ::onTitleMove
	 */
	public function testOnTitleMove( bool $hasRegistration, int $toNamespace, ?string $expectedError ) {
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$registration = $hasRegistration ? $this->createMock( ExistingEventRegistration::class ) : null;
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( $registration );
		$handler = $this->getHandler( $pageEventLookup );

		$status = Status::newGood();
		$newTitle = Title::makeTitle( $toNamespace, __METHOD__ );
		$res = $handler->onTitleMove(
			$this->createMock( Title::class ),
			$newTitle,
			$this->createMock( User::class ),
			'Test move',
			$status
		);

		if ( $expectedError === null ) {
			$this->assertTrue( $res );
			$this->assertStatusGood( $status );
		} else {
			$this->assertFalse( $res );
			$this->assertStatusMessage( $expectedError, $status );
		}
	}

	public static function provideOnTitleMove(): Generator {
		yield 'No registration, to Event namespace' => [ false, NS_EVENT, null ];
		yield 'No registration, to non-Event namespace' => [ false, NS_PROJECT, null ];
		yield 'Has registration, to Event namespace' => [ true, NS_EVENT, null ];
		yield 'Has registration, to non-Event namespace' => [
			true,
			NS_PROJECT,
			'campaignevents-error-move-eventpage-namespace'
		];
	}
}
