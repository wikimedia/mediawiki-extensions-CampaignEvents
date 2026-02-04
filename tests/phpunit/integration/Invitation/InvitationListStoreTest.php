<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Invitation;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationList;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListNotFoundException;
use MediaWiki\Extension\CampaignEvents\Invitation\Worklist;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Invitation\InvitationListStore
 */
class InvitationListStoreTest extends MediaWikiIntegrationTestCase {
	private const FAKE_TIME = 123456789;

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( self::FAKE_TIME );
	}

	/**
	 * @dataProvider provideInvitationListRoundtrip
	 */
	public function testInvitationListRoundtrip( string $name, ?int $eventID, int $userID ) {
		$store = CampaignEventsServices::getInvitationListStore();

		$creator = new CentralUser( $userID );
		$listID = $store->createInvitationList( $name, $eventID, $creator );

		$storedList = $store->getInvitationList( $listID );
		$this->assertSame( $name, $storedList->getName() );
		$this->assertSame( $eventID, $storedList->getEventID() );
		$this->assertSame( InvitationList::STATUS_PENDING, $storedList->getStatus() );
		$this->assertSame( $userID, $storedList->getCreator()->getCentralID() );
		$this->assertSame( WikiMap::getCurrentWikiId(), $storedList->getWiki() );
		$this->assertSame( wfTimestamp( TS::MW, self::FAKE_TIME ), $storedList->getCreationTime() );
	}

	public static function provideInvitationListRoundtrip() {
		yield 'Has event ID' => [
			'My event invitation list',
			42,
			123,
		];
		yield 'Does not have event ID' => [
			'My event invitation list',
			null,
			123,
		];
	}

	public function testGetInvitationList__doesNotExist() {
		$store = CampaignEventsServices::getInvitationListStore();
		$this->expectException( InvitationListNotFoundException::class );
		$store->getInvitationList( 1234556789 );
	}

	public function testWorklistRoundtrip() {
		$store = CampaignEventsServices::getInvitationListStore();
		$listID = $store->createInvitationList( __METHOD__, null, new CentralUser( 42 ) );
		$existingPage = $this->getExistingTestPage();
		$deletedPage = $this->getExistingTestPage();
		$wikiID = WikiMap::getCurrentWikiId();
		$articles = [
			$existingPage,
			$deletedPage
		];
		$worklist = new Worklist( [
			$wikiID => $articles
		] );
		$store->storeWorklist( $listID, $worklist );

		$this->deletePage( $deletedPage );

		$storedWorklist = $store->getWorklist( $listID );
		$storedArticlesByWiki = $storedWorklist->getPagesByWiki();
		$this->assertCount( 1, $storedArticlesByWiki );
		$this->assertArrayHasKey( $wikiID, $storedArticlesByWiki );
		$storedArticles = $storedArticlesByWiki[$wikiID];
		$articleToString = static fn ( PageIdentity $article ): string =>
			$article->getNamespace() . ':' . $article->getDBkey();
		$this->assertEquals(
			array_map( $articleToString, $articles ),
			array_map( $articleToString, $storedArticles )
		);
	}

	public function testWorklistRoundtrip__movedPage() {
		$existingPage = $this->getExistingTestPage();
		$oldPage = new PageIdentityValue(
			$existingPage->getId(),
			$existingPage->getNamespace(),
			// Fake a previous move by changing the title on the fly.
			$existingPage->getDBkey() . '_oldname',
			WikiAwareEntity::LOCAL
		);

		$store = CampaignEventsServices::getInvitationListStore();
		$wikiID = WikiMap::getCurrentWikiId();
		$listID = $store->createInvitationList( __METHOD__, null, new CentralUser( 42 ) );
		$store->storeWorklist( $listID, new Worklist( [ $wikiID => [ $oldPage ] ] ) );
		$storedWorklist = $store->getWorklist( $listID );
		$storedArticlesByWiki = $storedWorklist->getPagesByWiki();
		$this->assertCount( 1, $storedArticlesByWiki );
		$this->assertArrayHasKey( $wikiID, $storedArticlesByWiki );
		$storedArticles = $storedArticlesByWiki[$wikiID];
		$this->assertCount( 1, $storedArticles );
		$storedPage = $storedArticles[0];
		$this->assertSame( $existingPage->getId(), $storedPage->getId() );
		// The DB key should match that of the new title, even if it was stored under the old name.
		$this->assertSame( $existingPage->getDBkey(), $storedPage->getDBkey() );
	}

	public function testUpdateStatus() {
		$store = CampaignEventsServices::getInvitationListStore();
		$listID = $store->createInvitationList( __METHOD__, 42, new CentralUser( 1 ) );
		$store->updateStatus( $listID, InvitationList::STATUS_READY );
		$storedList = $store->getInvitationList( $listID );
		$this->assertSame( InvitationList::STATUS_READY, $storedList->getStatus() );
	}

	public function testUsersRoundtrip() {
		$store = CampaignEventsServices::getInvitationListStore();
		$users = [
			1 => 50,
			10 => 10,
			1000 => 99,
			30 => 80,
			999 => 1,
		];
		$listID = 10;
		$store->storeInvitationListUsers( $listID, $users );
		arsort( $users );
		$this->assertSame( $users, $store->getInvitationListUsers( $listID ) );
	}
}
