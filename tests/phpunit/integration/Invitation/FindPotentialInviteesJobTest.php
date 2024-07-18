<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Invitation;

use MediaWiki\Extension\CampaignEvents\Invitation\FindPotentialInviteesJob;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationList;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListStore;
use MediaWiki\Extension\CampaignEvents\Invitation\PotentialInviteesFinder;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @covers \MediaWiki\Extension\CampaignEvents\Invitation\FindPotentialInviteesJob
 */
class FindPotentialInviteesJobTest extends MediaWikiIntegrationTestCase {
	/**
	 * @dataProvider provideConstructorArguments
	 */
	public function testConstructor( ?string $expectedError, array $jobParams ) {
		if ( $expectedError ) {
			$this->expectException( 'InvalidArgumentException' );
			$this->expectExceptionMessage( $expectedError );
		}
		new FindPotentialInviteesJob( $jobParams );
		if ( !$expectedError ) {
			$this->addToAssertionCount( 1 );
		}
	}

	public static function provideConstructorArguments() {
		yield 'No parameters' => [
			'Missing parameters: list-id, serialized-worklist',
			[],
		];
		yield 'No list ID' => [
			'Missing parameters: list-id',
			[ 'serialized-worklist' => [] ],
		];
		yield 'No worklist' => [
			'Missing parameters: serialized-worklist',
			[ 'list-id' => 42 ],
		];
		yield 'Good' => [
			null,
			[ 'serialized-worklist' => [], 'list-id' => 42 ],
		];
	}

	public function testRun() {
		$nameToCentralID = [
			'Gandalf' => 1,
			'Rick Astley' => 42,
			'Noam Chomsky' => 100,
			// No central account
			'Anakin Skywalker' => null,
		];
		$potentialInvitees = [
			'Gandalf' => 99,
			'Rick Astley' => 87,
			'Noam Chomsky' => 87,
			'Anakin Skywalker' => 25,
		];
		$listID = 105;

		$inviteesFinder = $this->createMock( PotentialInviteesFinder::class );
		$inviteesFinder->method( 'generate' )->willReturn( $potentialInvitees );
		$this->setService( PotentialInviteesFinder::SERVICE_NAME, $inviteesFinder );

		$centralUserLookup = $this->createMock( CampaignsCentralUserLookup::class );
		$centralUserLookup->method( 'getIDs' )->willReturn( $nameToCentralID );
		$this->setService( CampaignsCentralUserLookup::SERVICE_NAME, $centralUserLookup );

		$invitationListStore = $this->createMock( InvitationListStore::class );
		$invitationListStore->expects( $this->once() )
			->method( 'storeInvitationListUsers' )
			->willReturnCallback( function ( $listIDParam, $invitees ) use ( $listID ): void {
				$expectedInvitees = [
					1 => 99,
					42 => 87,
					100 => 87,
				];
				$this->assertSame( $listID, $listIDParam );
				$this->assertSame( $expectedInvitees, $invitees );
			} );
		$invitationListStore->expects( $this->once() )
			->method( 'updateStatus' )
			->with( $listID, InvitationList::STATUS_READY );
		$this->setService( InvitationListStore::SERVICE_NAME, $invitationListStore );

		$job = new FindPotentialInviteesJob( [ 'list-id' => $listID, 'serialized-worklist' => [] ] );
		$job->run();
	}

	public function testRun__noEditors() {
		$listID = 1234;

		$invitationListStore = $this->createMock( InvitationListStore::class );
		$invitationListStore->expects( $this->never() )
			->method( 'storeInvitationListUsers' );
		$invitationListStore->expects( $this->once() )
			->method( 'updateStatus' )
			->with( $listID, InvitationList::STATUS_READY );
		$this->setService( InvitationListStore::SERVICE_NAME, $invitationListStore );

		$inviteesFinder = $this->createMock( PotentialInviteesFinder::class );
		$inviteesFinder->method( 'generate' )->willReturn( [] );
		$this->setService( PotentialInviteesFinder::SERVICE_NAME, $inviteesFinder );

		$job = new FindPotentialInviteesJob( [ 'list-id' => $listID, 'serialized-worklist' => [] ] );
		$job->run();
	}
}
