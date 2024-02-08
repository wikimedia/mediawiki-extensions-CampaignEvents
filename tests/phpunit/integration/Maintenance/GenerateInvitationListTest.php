<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Maintenance;

use MediaWiki\Extension\CampaignEvents\Maintenance\GenerateInvitationList;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Test
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Maintenance\GenerateInvitationList
 */
class GenerateInvitationListTest extends MaintenanceBaseTestCase {
	/**
	 * @inheritDoc
	 */
	protected function getMaintenanceClass(): string {
		return GenerateInvitationList::class;
	}

	public function addDBDataOnce(): void {
		// TODO: Integrate the data better with the test methods (i.e., avoid assumptions)
		$db = $this->getDb();

		// Bypass TestUserRegistry, PageStore, RevisionStore etc. so that we can insert all the rows we need at once.

		// Ignored but required fields
		$baseUserRow = [
			'user_password' => 'correct horse battery staple',
			'user_newpassword' => '',
			'user_email' => '',
			'user_touched' => $db->timestamp(),
		];
		$db->newInsertQueryBuilder()
			->insertInto( 'user' )
			->rows( [
				[
					'user_name' => 'User1',
					'user_editcount' => 561,
				] + $baseUserRow,
				[
					'user_name' => 'User2',
					'user_editcount' => 1105,
				] + $baseUserRow,
				[
					'user_name' => 'User3',
					'user_editcount' => 1729,
				] + $baseUserRow,
			] )
			->caller( __METHOD__ )
			->execute();

		$db->newInsertQueryBuilder()
			->insertInto( 'actor' )
			->rows( [
				[
					'actor_user' => 1,
					'actor_name' => 'User1'
				],
				[
					'actor_user' => 2,
					'actor_name' => 'User2'
				],
				[
					'actor_user' => 3,
					'actor_name' => 'User3'
				],
			] )
			->caller( __METHOD__ )
			->execute();

		// These fields are ignored by the script.
		$basePageRow = [
			'page_random' => 0,
			'page_touched' => $db->timestamp(),
			'page_latest' => 1234567,
			'page_len' => 7654321,
		];
		$db->newInsertQueryBuilder()
			->insertInto( 'page' )
			->rows( [
				[
					'page_namespace' => NS_MAIN,
					'page_title' => 'Page_1',
				] + $basePageRow,
				[
					'page_namespace' => NS_MAIN,
					'page_title' => 'Page_2',
				] + $basePageRow,
				[
					'page_namespace' => NS_MAIN,
					'page_title' => 'Page_3',
				] + $basePageRow,
			] )
			->caller( __METHOD__ )
			->execute();

		$revCutoff = GenerateInvitationList::CUTOFF_DAYS * 24 * 60 * 60;
		$curTime = ConvertibleTimestamp::time();
		// Randomize the revision dates. This isn't really necessary, but it more closely resembles real data.
		$newRevTS = static fn () => $db->timestamp( random_int( $curTime - $revCutoff + 1000, $curTime ) );
		$oldRevTS = static fn () => $db->timestamp( random_int(
			(int)ConvertibleTimestamp::convert( TS_UNIX, '20010115192713' ),
			$curTime - $revCutoff - 1000 )
		);
		$db->newInsertQueryBuilder()
			->insertInto( 'revision' )
			->rows( [
				[
					// 'rev_id' => 1
					'rev_page' => 1,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 1,
					'rev_len' => 42,
					'rev_parent_id' => null
				],
				[
					// 'rev_id' => 2
					'rev_page' => 1,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 1,
					'rev_len' => 142,
					'rev_parent_id' => 1
				],
				[
					// 'rev_id' => 3
					'rev_page' => 1,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 1,
					'rev_len' => 132,
					'rev_parent_id' => 2
				],
				[
					// 'rev_id' => 4
					'rev_page' => 2,
					'rev_timestamp' => $oldRevTS(),
					'rev_actor' => 2,
					'rev_len' => 10000,
					'rev_parent_id' => 3
				],
				[
					// 'rev_id' => 5
					'rev_page' => 2,
					'rev_timestamp' => $oldRevTS(),
					'rev_actor' => 1,
					'rev_len' => 20000,
					'rev_parent_id' => 4
				],
				[
					// 'rev_id' => 6
					'rev_page' => 2,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 2,
					'rev_len' => 20005,
					'rev_parent_id' => 5
				],
				[
					// 'rev_id' => 7
					'rev_page' => 3,
					'rev_timestamp' => $oldRevTS(),
					'rev_actor' => 1,
					'rev_len' => 1000,
					'rev_parent_id' => null
				],
				[
					// 'rev_id' => 8
					'rev_page' => 3,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 3,
					'rev_len' => 31000,
					'rev_parent_id' => 7
				],
				[
					// 'rev_id' => 9
					'rev_page' => 3,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 1,
					'rev_len' => 40000,
					'rev_parent_id' => 8
				],
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public function testExecute() {
		$listFilePath = $this->getNewTempFile();
		$list = implode(
			"\n",
			[
				':Page 1',
				':Page 2',
				':Page 3',
			]
		);
		file_put_contents( $listFilePath, $list );
		$this->maintenance->setOption( 'listfile', $listFilePath );
		$this->maintenance->execute();

		$output = $this->getActualOutputForAssertion();
		$outSections = preg_split( '/(?=^==.+==$)/m', $output, -1, PREG_SPLIT_NO_EMPTY );
		$this->assertCount( 4, $outSections );
		[ $articlesSection, $contributionsSection, $scoresDebugSection ] = $outSections;

		$progressInfo = 'Running batch #1 from pageID=0, ts=20000101000000, rev=0';
		$this->assertStringContainsString( $progressInfo, $articlesSection );
		$articlesSection = str_replace( $progressInfo, '', $articlesSection );
		$curWikiID = WikiMap::getCurrentWikiId();
		$this->assertSame(
			"==Articles==\n[0:Page_1]@$curWikiID\n[0:Page_2]@$curWikiID\n[0:Page_3]@$curWikiID",
			trim( $articlesSection )
		);
		$expectedContribs = "==Contributions==\n" .
			"User1 - [0:Page_1]@$curWikiID - 132\n" .
			"User1 - [0:Page_3]@$curWikiID - 9000\n" .
			"User2 - [0:Page_2]@$curWikiID - 5\n" .
			"User3 - [0:Page_3]@$curWikiID - 30000";
		$this->assertSame( $expectedContribs, trim( $contributionsSection ) );
		$this->assertStringContainsString( 'User User1 edit count 561,', $scoresDebugSection );
		$this->assertStringContainsString( 'User User2 edit count 1105,', $scoresDebugSection );
		$this->assertStringContainsString( 'User User3 edit count 1729,', $scoresDebugSection );
	}
}
