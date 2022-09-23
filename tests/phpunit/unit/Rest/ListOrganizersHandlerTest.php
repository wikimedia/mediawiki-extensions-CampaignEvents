<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\Organizer;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\RoleFormatter;
use MediaWiki\Extension\CampaignEvents\Organizers\Roles;
use MediaWiki\Extension\CampaignEvents\Rest\ListOrganizersHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListOrganizersHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 */
class ListOrganizersHandlerTest extends MediaWikiUnitTestCase {
	use HandlerTestTrait;

	private const REQ_DATA = [
		'pathParams' => [ 'id' => 42 ]
	];

	private function newHandler(
		IEventLookup $eventLookup = null,
		OrganizersStore $organizersStore = null
	): ListOrganizersHandler {
		$roleFormatter = $this->createMock( RoleFormatter::class );
		// Return the constant value for simplicity
		$roleFormatter->method( 'getDebugName' )->willReturnArgument( 0 );
		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'existsAndIsVisible' )->willReturn( true );
		return new ListOrganizersHandler(
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$organizersStore ?? $this->createMock( OrganizersStore::class ),
			$roleFormatter,
			$centralUserLookup,
			$this->createMock( UserLinker::class )
		);
	}

	/**
	 * @dataProvider provideRunData
	 */
	public function testRun( array $expectedResp, OrganizersStore $organizersStore ) {
		$handler = $this->newHandler( null, $organizersStore );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );
		$this->assertSame( $expectedResp, $respData );
	}

	public function provideRunData(): Generator {
		yield 'No organizers' => [ [], $this->createMock( OrganizersStore::class ) ];

		$user1 = new CentralUser( 1 );

		$singleCreatorStore = $this->createMock( OrganizersStore::class );
		$singleCreatorStore->expects( $this->atLeastOnce() )
			->method( 'getEventOrganizers' )
			->willReturn( [ new Organizer( $user1, [ Roles::ROLE_CREATOR ], 1 ) ] );
		yield 'Single organizer, creator only' => [
			[
				[
					'organizer_id' => 1,
					'user_id' => 1,
					'roles' => [ Roles::ROLE_CREATOR ],
					'user_page' => [
						'path' => '',
						'title' => '',
						'classes' => '',
					],
				],
			],
			$singleCreatorStore
		];

		$user2 = new CentralUser( 2 );
		$multiCreatorStore = $this->createMock( OrganizersStore::class );
		$multiCreatorStore->expects( $this->atLeastOnce() )
			->method( 'getEventOrganizers' )
			->willReturn( [
				new Organizer( $user1, [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ], 2 ),
				new Organizer( $user2, [ Roles::ROLE_ORGANIZER ], 3 ),
			] );
		yield 'Multiple organizers, multiple roles' => [
			[
				[
					'organizer_id' => 2,
					'user_id' => 1,
					'roles' => [ Roles::ROLE_CREATOR, Roles::ROLE_ORGANIZER ],
					'user_page' => [
						'path' => '',
						'title' => '',
						'classes' => '',
					],
				],
				[
					'organizer_id' => 3,
					'user_id' => 2,
					'roles' => [ Roles::ROLE_ORGANIZER ],
					'user_page' => [
						'path' => '',
						'title' => '',
						'classes' => '',
					],
				],
			],
			$multiCreatorStore
		];
	}

	public function testRun__invalidEvent() {
		$eventLookup = $this->createMock( IEventLookup::class );
		$eventLookup->expects( $this->once() )
			->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		$handler = $this->newHandler( $eventLookup );
		try {
			$this->executeHandler( $handler, new RequestData( self::REQ_DATA ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 'campaignevents-rest-event-not-found', $e->getMessageValue()->getKey() );
			$this->assertSame( 404, $e->getCode() );
		}
	}
}
