<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\MediaWikiEventIngress;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\PageEventIngress;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPageProxy;
use MediaWiki\Page\Event\PageDeletedEvent;
use MediaWiki\Page\Event\PageMovedEvent;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\MediaWikiEventIngress\PageEventIngress
 */
class PageEventIngressTest extends MediaWikiUnitTestCase {
	public function getPageEventIngress(
		CampaignsPageFactory $campaignsPageFactory,
		IEventStore $iEventStore,
		PageEventLookup $pageEventLookup,
		DeleteEventCommand $deleteEventCommand,
		TitleFormatter $titleFormatter
	): PageEventIngress {
		return new PageEventIngress(
			$campaignsPageFactory,
			$deleteEventCommand,
			$iEventStore,
			$pageEventLookup,
			$titleFormatter
		);
	}

	/**
	 * @dataProvider providePageDelete
	 */
	public function testHandlePageDeletedEvent(
		bool $expectsEventDeletion,
		bool $pageHasRegistration,
		bool $registrationIsDeleted
	) {
		$registration = null;
		if ( $pageHasRegistration ) {
			$registration = $this->createMock( ExistingEventRegistration::class );
			$registration->method( 'getDeletionTimestamp' )
				->willReturn( $registrationIsDeleted ? '1700000000' : null );
		}

		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( $registration );

		$deleteEventCommand = $this->createMock( DeleteEventCommand::class );
		if ( $expectsEventDeletion ) {
			$deleteEventCommand->expects( $this->once() )->method( 'deleteUnsafe' );
		} else {
			$deleteEventCommand->expects( $this->never() )->method( 'deleteUnsafe' );
		}

		$campaignsPageFactory = $this->createMock( CampaignsPageFactory::class );
		$iEventStore = $this->createMock( IEventStore::class );
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$pageEventIngress = $this->getPageEventIngress(
			$campaignsPageFactory, $iEventStore,
			$pageEventLookup, $deleteEventCommand, $titleFormatter
		);

		$pageRecordBefore = $this->createMock( ExistingPageRecord::class );
		$pageRecordBefore->method( 'exists' )->willReturn( true );
		$latestRevisionBefore = $this->createMock( RevisionRecord::class );
		$user = new UserIdentityValue( 0, "User" );
		$event = new PageDeletedEvent(
			$pageRecordBefore,
			$latestRevisionBefore,
			$user,
			[], [], "", "", 1
		);
		$pageEventIngress->handlePageDeletedEvent( $event );
		// We use soft assertions above
		$this->addToAssertionCount( 1 );
	}

	public static function providePageDelete(): Generator {
		yield 'No registration for page' => [ false, false, false ];
		yield 'Page has deleted event registration' => [ false, true, true ];
		yield 'Page has active event registration' => [ true, true, false ];
	}

	/**
	 * @dataProvider provideOnPageMove
	 */
	public function testPageMovedEvent( bool $hasRegistration ) {
		$pageEventLookup = $this->createMock( PageEventLookup::class );
		$registration = null;
		if ( $hasRegistration ) {
			$registration = $this->createMock( ExistingEventRegistration::class );
			$registration->method( 'getStartLocalTimestamp' )
				->willReturn( '20240815120000' );
			$registration->method( 'getEndLocalTimestamp' )
				->willReturn( '20240816120000' );
			$registration->method( 'getTypes' )
				->willReturn( [ 1 ] );
			$registration->method( 'getTrackingTools' )
				->willReturn( [ 1 ] );

		}
		$pageEventLookup->method( 'getRegistrationForLocalPage' )->willReturn( $registration );
		$campaignsPageFactory = $this->createMock( CampaignsPageFactory::class );
		$iEventStore = $this->createMock( IEventStore::class );
		$iEventStore->method( 'saveRegistration' )->willReturn( 1 );
		$deleteEventCommand = $this->createMock( DeleteEventCommand::class );
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getText' )->willReturn( "Title" );

		$pageEventIngress = $this->getPageEventIngress(
			$campaignsPageFactory, $iEventStore,
			$pageEventLookup, $deleteEventCommand, $titleFormatter
		);

		$pageRecordBeforeAndAfter = $this->createMock( ExistingPageRecord::class );
		$pageRecordBeforeAndAfter->method( 'exists' )->willReturn( true );
		$user = new UserIdentityValue( 0, "User" );
		$event = new PageMovedEvent(
			$pageRecordBeforeAndAfter,
			$pageRecordBeforeAndAfter,
			$user, ""
		);
		if ( $hasRegistration ) {
			$campaignsPageFactory->expects( $this->once() )
				->method( 'newFromLocalMediaWikiPage' )
				->with( $pageRecordBeforeAndAfter )
				->willReturn( $this->createMock( MWPageProxy::class ) );
			$iEventStore->expects( $this->once() )->method( 'saveRegistration' );
		}
		$res = $pageEventIngress->handlePageMovedEvent( $event );

		if ( !$hasRegistration ) {
			$this->assertNull( $res );
		}
	}

	public static function provideOnPageMove(): Generator {
		yield 'No registration' => [ false ];
		yield 'Has registration' => [ true ];
	}
}
