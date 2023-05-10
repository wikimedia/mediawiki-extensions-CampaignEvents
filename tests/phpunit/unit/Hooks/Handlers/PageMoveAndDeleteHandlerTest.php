<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks\Handlers;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\PageMoveAndDeleteHandler;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWEventLookupFromPage;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWikiUnitTestCase;
use StatusValue;
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
	 * @param bool $expectedReturn
	 * @param string|null $expectedStatusError Null if the status is expected to be good
	 * @param MWEventLookupFromPage $eventLookupFromPage
	 * @param DeleteEventCommand|null $deleteEventCommand
	 * @dataProvider providePageDelete
	 * @covers ::onPageDelete
	 */
	public function testOnPageDelete(
		bool $expectedReturn,
		?string $expectedStatusError,
		MWEventLookupFromPage $eventLookupFromPage,
		DeleteEventCommand $deleteEventCommand = null
	) {
		$status = StatusValue::newGood();
		$ret = $this->getHandler( $eventLookupFromPage, $deleteEventCommand )->onPageDelete(
			$this->createMock( ProperPageIdentity::class ),
			$this->createMock( Authority::class ),
			'Reason',
			$status,
			false
		);

		$this->assertSame( $expectedReturn, $ret );
		if ( $expectedStatusError === null ) {
			$this->assertStatusGood( $status );
		} else {
			$this->assertStatusError( $expectedStatusError, $status );
		}
	}

	public function providePageDelete(): Generator {
		$noRegistrationLookup = $this->createMock( MWEventLookupFromPage::class );
		$noRegistrationLookup->method( 'getRegistrationForPage' )->willReturn( null );
		yield 'No registration for page' => [ true, null, $noRegistrationLookup ];

		$existingRegistrationLookup = $this->createMock( MWEventLookupFromPage::class );
		$existingRegistrationLookup->method( 'getRegistrationForPage' )
			->willReturn( $this->createMock( ExistingEventRegistration::class ) );

		$deletionError = 'some-error';
		$errorDeleteCommand = $this->createMock( DeleteEventCommand::class );
		$errorDeleteCommand->method( 'deleteUnsafe' )->willReturn( StatusValue::newFatal( $deletionError ) );
		yield 'Deletion error' => [ false, $deletionError, $existingRegistrationLookup, $errorDeleteCommand ];

		$successDeleteCommand = $this->createMock( DeleteEventCommand::class );
		$successDeleteCommand->method( 'deleteUnsafe' )->willReturn( StatusValue::newGood() );
		yield 'Deletion succeeds' => [ true, null, $existingRegistrationLookup, $successDeleteCommand ];
	}
}
