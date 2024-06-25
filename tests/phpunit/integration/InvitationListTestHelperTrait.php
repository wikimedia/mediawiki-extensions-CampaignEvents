<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use MediaWiki\Extension\CampaignEvents\Maintenance\GenerateInvitationList;
use Wikimedia\Timestamp\ConvertibleTimestamp;

trait InvitationListTestHelperTrait {
	private function insertWorklistData() {
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
		$oldRevTS = static fn () => $db->timestamp(
			random_int(
				(int)ConvertibleTimestamp::convert( TS_UNIX, '20010115192713' ),
				$curTime - $revCutoff - 1000
			)
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
					'rev_parent_id' => null,
					'rev_comment_id' => 1000,
				],
				[
					// 'rev_id' => 2
					'rev_page' => 1,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 1,
					'rev_len' => 142,
					'rev_parent_id' => 1,
					'rev_comment_id' => 1000,
				],
				[
					// 'rev_id' => 3
					'rev_page' => 1,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 1,
					'rev_len' => 132,
					'rev_parent_id' => 2,
					'rev_comment_id' => 1000,
				],
				[
					// 'rev_id' => 4
					'rev_page' => 2,
					'rev_timestamp' => $oldRevTS(),
					'rev_actor' => 2,
					'rev_len' => 10000,
					'rev_parent_id' => 3,
					'rev_comment_id' => 1000,
				],
				[
					// 'rev_id' => 5
					'rev_page' => 2,
					'rev_timestamp' => $oldRevTS(),
					'rev_actor' => 1,
					'rev_len' => 20000,
					'rev_parent_id' => 4,
					'rev_comment_id' => 1000,
				],
				[
					// 'rev_id' => 6
					'rev_page' => 2,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 2,
					'rev_len' => 20005,
					'rev_parent_id' => 5,
					'rev_comment_id' => 1000,
				],
				[
					// 'rev_id' => 7
					'rev_page' => 3,
					'rev_timestamp' => $oldRevTS(),
					'rev_actor' => 1,
					'rev_len' => 1000,
					'rev_parent_id' => null,
					'rev_comment_id' => 1000,
				],
				[
					// 'rev_id' => 8
					'rev_page' => 3,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 3,
					'rev_len' => 31000,
					'rev_parent_id' => 7,
					'rev_comment_id' => 1000,
				],
				[
					// 'rev_id' => 9
					'rev_page' => 3,
					'rev_timestamp' => $newRevTS(),
					'rev_actor' => 1,
					'rev_len' => 40000,
					'rev_parent_id' => 8,
					'rev_comment_id' => 1000,
				],
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
