<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsEditorsPager;
use MediaWiki\MediaWikiServices;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Pager\EventContributionsEditorsPager
 */
class EventContributionsEditorsPagerTest extends AbstractContributionsPagerTestBase {
	public function testAggregatePagination(): void {
		$firstResPager = $this->getPager( [ 'sort' => 'bytes', 'desc' => 1, 'limit' => 1 ] );
		$firstResPager->doQuery();
		$firstRes = $firstResPager->mResult;

		$this->assertCount( 2, $firstRes, 'First batch should contain 2 rows (1 result, 1 padding)' );

		$firstRowForComparison = get_object_vars( $firstRes->fetchObject() );
		ksort( $firstRowForComparison );
		$this->assertSame(
			[
				'COALESCE(cec_user_name, "")' => 'Bob',
				'articles_added' => '1',
				'articles_edited' => '0',
				'bytes' => '99',
				'cec_user_id' => '1',
				'cec_user_name' => 'Bob',
				'cep_private' => '0',
				'edit_count' => '1',
			],
			$firstRowForComparison,
			'First row'
		);

		$secondPageOffset = $firstResPager->getPagingQueries()['next']['offset'];
		$secondResPager = $this->getPager( [
			'sort' => 'bytes',
			'desc' => 1,
			'limit' => 1,
			'offset' => $secondPageOffset
		] );
		$secondResPager->doQuery();
		$secondRes = $secondResPager->mResult;

		$this->assertCount( 2, $secondRes, 'Second batch should contain 2 rows (1 result, 1 padding)' );

		$secondRowForComparison = get_object_vars( $secondRes->fetchObject() );
		ksort( $secondRowForComparison );
		$this->assertSame(
			[
				'COALESCE(cec_user_name, "")' => '',
				'articles_added' => '0',
				'articles_edited' => '1',
				'bytes' => '88',
				'cec_user_id' => '2',
				'cec_user_name' => null,
				'cep_private' => '0',
				'edit_count' => '1',
			],
			$secondRowForComparison,
			'Second row'
		);
	}

	protected function getPager( array $requestValues ): EventContributionsEditorsPager {
		$context = $this->createContext( $requestValues );

		$event = $this->createEventMock();

		return CampaignEventsServices::getEventContributionsPagerFactory()->newEditorsPager(
			$context,
			MediaWikiServices::getInstance()->getLinkRenderer(),
			$event,
		);
	}
}
