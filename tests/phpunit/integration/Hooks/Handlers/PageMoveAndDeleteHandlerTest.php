<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks\Handlers;

use Generator;
use ManualLogEntry;
use MediaWiki\Config\HashConfig;
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
		?PageEventLookup $pageEventLookup = null,
		?DeleteEventCommand $deleteEventCommand = null,
		array $allowedNamespaces = [ NS_EVENT ]
	): PageMoveAndDeleteHandler {
		return new PageMoveAndDeleteHandler(
			$pageEventLookup ?? $this->createMock( PageEventLookup::class ),
			$this->createMock( IEventStore::class ),
			$deleteEventCommand ?? $this->createMock( DeleteEventCommand::class ),
			$this->createMock( TitleFormatter::class ),
			$this->createMock( CampaignsPageFactory::class ),
			new HashConfig( [
				'CampaignEventsEventNamespaces' => $allowedNamespaces
			] )
		);
	}

	/**
	 * @dataProvider providePageDelete
	 * @covers ::onPageDeleteComplete
	 */
	public function testOnPageDeleteComplete(
		bool $expectsEventDeletion,
		bool $pageHasRegistration
	) {
		$registration = $pageHasRegistration ? $this->createMock( ExistingEventRegistration::class ) : null;
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( $registration );

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

	public static function providePageDelete(): Generator {
		yield 'No registration for page' => [ false, false ];
		yield 'Page has event registration' => [ true, true ];
	}

	/**
	 * @dataProvider provideOnTitleMove
	 * @covers ::onTitleMove
	 */
	public function testOnTitleMove(
		bool $hasRegistration,
		int $toNamespace,
		?string $expectedError,
		?array $allowedNamespaces ) {
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$registration = $hasRegistration ? $this->createMock( ExistingEventRegistration::class ) : null;
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( $registration );
		$handler = $this->getHandler( $pageEventLookup, null, $allowedNamespaces );

		$status = Status::newGood();
		$newTitle = Title::makeTitle( $toNamespace, __METHOD__ );
		$res = $handler->onTitleMove(
			$this->createMock( Title::class ),
			$newTitle,
			$this->createMock( User::class ),
			'Test move',
			$status,

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
		yield 'No registration, to Permitted namespace' => [ false, NS_PROJECT, null, [ NS_PROJECT ] ];
		yield 'No registration, to non-Permitted namespace' => [ false, NS_PROJECT, null, [ NS_EVENT ] ];
		yield 'Has registration, to Permitted namespace' => [ true, NS_EVENT, null, [ NS_EVENT ] ];
		yield 'Has registration, to non-Permitted namespace' => [
			true,
			NS_PROJECT,
			'campaignevents-error-move-eventpage-namespace-disallowed',
			[ NS_EVENT ]
		];
	}
}
