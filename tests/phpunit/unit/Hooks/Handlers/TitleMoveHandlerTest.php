<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Hooks\Handlers;

use Generator;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\Handlers\TitleMoveHandler;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\CampaignEvents\Hooks\Handlers\TitleMoveHandler
 */
class TitleMoveHandlerTest extends MediaWikiUnitTestCase {
	public function getHandler(
		?PageEventLookup $pageEventLookup = null,
		?DeleteEventCommand $deleteEventCommand = null,
		array $allowedNamespaces = [ NS_PROJECT ]
	): TitleMoveHandler {
		return new TitleMoveHandler(
			$pageEventLookup ?? $this->createMock( PageEventLookup::class ),
			new HashConfig( [
				'CampaignEventsEventNamespaces' => $allowedNamespaces
			] )
		);
	}

	/**
	 * @dataProvider provideOnTitleMove
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
		yield 'No registration, to non-Permitted namespace' => [ false, NS_PROJECT, null, [ NS_MAIN ] ];
		yield 'Has registration, to Permitted namespace' => [ true, NS_PROJECT, null, [ NS_PROJECT ] ];
		yield 'Has registration, to non-Permitted namespace' => [
			true,
			NS_MAIN,
			'campaignevents-error-move-eventpage-namespace-disallowed',
			[ NS_PROJECT ]
		];
	}
}
