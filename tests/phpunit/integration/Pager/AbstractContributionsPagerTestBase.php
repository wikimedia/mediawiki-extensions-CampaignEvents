<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use Generator;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Request\FauxRequest;
use MediaWikiIntegrationTestCase;

abstract class AbstractContributionsPagerTestBase extends MediaWikiIntegrationTestCase {

	protected const EVENT_ID = 1;

	public function addDBDataOnce(): void {
		$db = $this->getDB();
		$startTS = 1700000000;
		$contribRows = [
			[
				'cec_event_id' => self::EVENT_ID,
				'cec_user_id' => 1,
				'cec_user_name' => 'Bob',
				'cec_wiki' => 'awiki',
				'cec_page_id' => 11,
				'cec_page_prefixedtext' => 'Page 11',
				'cec_revision_id' => 101,
				'cec_edit_flags' => 1,
				'cec_bytes_delta' => 99,
				'cec_links_delta' => 9,
				'cec_timestamp' => $db->timestamp( $startTS ),
				'cec_deleted' => 0,
			],
			[
				'cec_event_id' => self::EVENT_ID,
				'cec_user_id' => 2,
				'cec_user_name' => null,
				'cec_wiki' => 'awiki',
				'cec_page_id' => 11,
				'cec_page_prefixedtext' => 'Page 11',
				'cec_revision_id' => 102,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 88,
				'cec_links_delta' => 8,
				'cec_timestamp' => $db->timestamp( $startTS + 1 ),
				'cec_deleted' => 0,
			],
			[
				'cec_event_id' => self::EVENT_ID,
				'cec_user_id' => 3,
				'cec_user_name' => 'Alice',
				'cec_wiki' => 'awiki',
				'cec_page_id' => 11,
				'cec_page_prefixedtext' => 'Page 11',
				'cec_revision_id' => 103,
				'cec_edit_flags' => 0,
				'cec_bytes_delta' => 77,
				'cec_links_delta' => 7,
				'cec_timestamp' => $db->timestamp( $startTS + 2 ),
				'cec_deleted' => 0,
			],
		];

		$db->newInsertQueryBuilder()->insertInto( 'ce_event_contributions' )->rows( $contribRows )->caller(
			__METHOD__
		)->execute();

		$participantRows = [
			[
				'cep_event_id' => self::EVENT_ID,
				'cep_user_id' => 1,
				'cep_private' => false,
				'cep_registered_at' => $db->timestamp(),
				'cep_unregistered_at' => null,
				'cep_first_answer_timestamp' => null,
				'cep_aggregation_timestamp' => null,
				'cep_hide_contribution_association_prompt' => false,
			],
			[
				'cep_event_id' => self::EVENT_ID,
				'cep_user_id' => 2,
				'cep_private' => false,
				'cep_registered_at' => $db->timestamp(),
				'cep_unregistered_at' => null,
				'cep_first_answer_timestamp' => null,
				'cep_aggregation_timestamp' => null,
				'cep_hide_contribution_association_prompt' => false,
			],
			[
				'cep_event_id' => self::EVENT_ID,
				'cep_user_id' => 3,
				'cep_private' => false,
				'cep_registered_at' => $db->timestamp(),
				'cep_unregistered_at' => null,
				'cep_first_answer_timestamp' => null,
				'cep_aggregation_timestamp' => null,
				'cep_hide_contribution_association_prompt' => false,
			],
		];

		$db->newInsertQueryBuilder()
			->insertInto( 'ce_participants' )
			->rows( $participantRows )
			->caller( __METHOD__ )
			->execute();
	}

	protected function createEventMock(): ExistingEventRegistration {
		$event = $this->createMock( ExistingEventRegistration::class );
		$event->method( 'getID' )->willReturn( self::EVENT_ID );

		return $event;
	}

	protected function createContext( array $requestValues ): RequestContext {
		$context = new RequestContext();
		$context->setRequest( new FauxRequest( $requestValues ) );

		return $context;
	}

	/**
	 * @dataProvider provideNullUsernames
	 */
	public function testNullUsernames( array $sortDirParams, array $expectedNamesOrdered ): void {
		// Note: mSort cannot be set after instantiation, so we need different pager instances.
		$firstResPager = $this->getPager(
			[
				'sort' => 'username',
				'limit' => 1
			] + $sortDirParams
		);
		$firstResPager->doQuery();
		$firstRes = $firstResPager->mResult;
		$this->assertCount( 2, $firstRes, 'First batch should contain 2 rows (1 result, 1 padding)' );
		$firstRow = $firstRes->fetchObject();
		$this->assertSame( $expectedNamesOrdered[0], $firstRow->cec_user_name, 'First row name' );
		$secondPageOffset = $firstResPager->getPagingQueries()['next']['offset'];

		$secondResPager = $this->getPager(
			[
				'sort' => 'username',
				'limit' => 1,
				'offset' => $secondPageOffset
			] + $sortDirParams
		);
		$secondResPager->doQuery();
		$secondRes = $secondResPager->mResult;
		$this->assertCount( 2, $secondRes, 'Second batch should contain 2 rows (1 result, 1 padding)' );
		$secondRow = $secondRes->fetchObject();
		$this->assertSame( $expectedNamesOrdered[1], $secondRow->cec_user_name, 'Second row name' );
		$thirdPageOffset = $secondResPager->getPagingQueries()['next']['offset'];

		$thirdResPager = $this->getPager(
			[
				'sort' => 'username',
				'limit' => 1,
				'offset' => $thirdPageOffset
			] + $sortDirParams
		);
		$thirdResPager->doQuery();
		$thirdRes = $thirdResPager->mResult;
		$this->assertCount( 1, $thirdRes, 'Third batch should contain 1 row (no next page)' );
		$thirdRow = $thirdRes->fetchObject();
		$this->assertSame( $expectedNamesOrdered[2], $thirdRow->cec_user_name, 'Third row name' );
	}

	public static function provideNullUsernames(): Generator {
		yield 'Default direction (ascending)' => [
			[],
			[
				null,
				'Alice',
				'Bob'
			],
		];
		yield 'Ascending order' => [
			[
				'asc' => 1,
				'desc' => ''
			],
			[
				null,
				'Alice',
				'Bob'
			],
		];
		yield 'Descending order' => [
			[
				'asc' => '',
				'desc' => 1
			],
			[
				'Bob',
				'Alice',
				null
			],
		];
	}

	/**
	 * Subclasses must implement pager construction.
	 */
	abstract protected function getPager( array $requestValues );
}
