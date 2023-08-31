<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Rest;

use Generator;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Participants\Participant;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Rest\ListParticipantsHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\UserFactory;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\ListParticipantsHandler
 * @covers \MediaWiki\Extension\CampaignEvents\Rest\EventIDParamTrait
 * TODO: Make a unit test once Language is available in the REST framework (T269492) and we get rid
 * of User/UserArray.
 */
class ListParticipantsHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const REQ_DATA = [
		'pathParams' => [ 'id' => 42 ],
		'queryParams' => [ 'include_private' => false ],
	];

	protected function setUp(): void {
		// Make sure that the user language is English so that we can verify the formatted timestamp
		$this->setUserLang( 'en' );
	}

	private function newHandler(
		PermissionChecker $permissionChecker = null,
		IEventLookup $eventLookup = null,
		ParticipantsStore $participantsStore = null,
		CampaignsCentralUserLookup $centralUserLookup = null,
		UserFactory $userFactory = null,
		UserLinker $userLink = null
	): ListParticipantsHandler {
		return new ListParticipantsHandler(
			$permissionChecker ?? $this->createMock( PermissionChecker::class ),
			$eventLookup ?? $this->createMock( IEventLookup::class ),
			$participantsStore ?? $this->createMock( ParticipantsStore::class ),
			$centralUserLookup ?? $this->createMock( CampaignsCentralUserLookup::class ),
			$userLink ?? $this->createMock( UserLinker::class ),
			$userFactory ?? $this->createMock( UserFactory::class ),
			$this->createMock( CampaignsUserMailer::class )
		);
	}

	/**
	 * @dataProvider provideRunData
	 */
	public function testRun(
		array $expectedResp,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup = null
	) {
		$userLink = $this->createMock( UserLinker::class );
		$userLink->method( 'getUserPagePath' )->willReturn( [
			'path' => '',
			'title' => '',
			'classes' => '',
		] );
		$handler = $this->newHandler( null, null, $participantsStore, $centralUserLookup, null, $userLink );
		$respData = $this->executeHandlerAndGetBodyData( $handler, new RequestData( self::REQ_DATA ) );
		$this->assertArrayEquals( $expectedResp, $respData );
	}

	public function provideRunData(): Generator {
		yield 'No participants' => [
			[],
			$this->createMock( ParticipantsStore::class )
		];

		$participants = [];
		$expected = [];
		$usernames = [];
		for ( $i = 1; $i < 4; $i++ ) {
			$participants[] = new Participant( new CentralUser( $i ), '20220315120000', $i, false, [], null, null );
			$usernames[$i] = "Test user $i";

			$expected[] = [
				'participant_id' => $i,
				'user_id' => $i,
				'user_name' => $usernames[$i],
				'user_page' => [
					'path' => '',
					'title' => '',
					'classes' => ''
				],
				'user_registered_at' => wfTimestamp( TS_MW, '20220315120000' ),
				'user_registered_at_formatted' => '12:00, 15 March 2022',
				'private' => false,
				'user_is_valid_recipient' => false,
			];
		}

		$partStore = $this->createMock( ParticipantsStore::class );
		$partStore->expects( $this->atLeastOnce() )->method( 'getEventParticipants' )->willReturn( $participants );
		$userLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$userLookup->method( 'getNamesIncludingDeletedAndSuppressed' )->willReturn( $usernames );
		yield 'Has participants' => [
			$expected,
			$partStore,
			$userLookup,
		];

		$deletedUserID = 1;
		$deletedParticipant = new Participant(
			new CentralUser( $deletedUserID ),
			'20220315120000',
			1,
			false,
			[],
			null,
			null
		);
		$deletedUserExpected = [
			[
				'participant_id' => 1,
				'user_id' => $deletedUserID,
				'hidden' => true,
				'user_registered_at' => wfTimestamp( TS_MW, '20220315120000' ),
				'user_registered_at_formatted' => '12:00, 15 March 2022',
				'private' => false,
			]
		];
		$delPartStore = $this->createMock( ParticipantsStore::class );
		$delPartStore->expects( $this->atLeastOnce() )
			->method( 'getEventParticipants' )
			->willReturn( [ $deletedParticipant ] );
		$delUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$delUserLookup->method( 'getNamesIncludingDeletedAndSuppressed' )
			->willReturn( [ $deletedUserID => CampaignsCentralUserLookup::USER_HIDDEN ] );
		yield 'Deleted user' => [ $deletedUserExpected, $delPartStore, $delUserLookup ];
	}

	/**
	 * @dataProvider provideRunErrors
	 */
	public function testRun__invalid(
		string $expectedMsg,
		int $expectedCode,
		array $reqData,
		PermissionChecker $permissionChecker = null,
		IEventLookup $eventLookup = null
	) {
		$handler = $this->newHandler( $permissionChecker, $eventLookup );

		try {
			$this->executeHandler( $handler, new RequestData( $reqData ) );
			$this->fail( 'No exception thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( $expectedMsg, $e->getMessageValue()->getKey() );
			$this->assertSame( $expectedCode, $e->getCode() );
		}
	}

	public function provideRunErrors(): Generator {
		$eventNotFoundLookup = $this->createMock( IEventLookup::class );
		$eventNotFoundLookup->expects( $this->once() )
			->method( 'getEventByID' )
			->willThrowException( $this->createMock( EventNotFoundException::class ) );
		yield 'Event not found' => [
			'campaignevents-rest-event-not-found',
			404,
			self::REQ_DATA,
			null,
			$eventNotFoundLookup
		];

		$getDataWithParams = static function ( array $params ): array {
			$ret = self::REQ_DATA;
			$ret['queryParams'] = $params + $ret['queryParams'];
			return $ret;
		};
		yield 'Empty username filter' => [
			'campaignevents-rest-list-participants-empty-filter',
			400,
			$getDataWithParams( [ 'username_filter' => '' ] )
		];

		$unauthorizedPermChecker = $this->createMock( PermissionChecker::class );
		$unauthorizedPermChecker->expects( $this->atLeastOnce() )
			->method( 'userCanViewPrivateParticipants' )
			->willReturn( false );
		yield 'Cannot see private participants' => [
			'campaignevents-rest-list-participants-cannot-see-private',
			403,
			$getDataWithParams( [ 'include_private' => true ] ),
			$unauthorizedPermChecker
		];
	}
}
