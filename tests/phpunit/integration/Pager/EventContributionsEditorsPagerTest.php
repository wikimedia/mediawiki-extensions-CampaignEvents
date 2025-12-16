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
	public function testSimpleAggregates(): void {
		$pager = $this->getPager( [ 'sort' => 'username', 'desc' => 1 ] );
		$pager->doQuery();
		$result = $pager->mResult;

		$this->assertCount( 3, $result, 'Three editor rows should be returned' );

		$row = $result->fetchObject();

		// Assertions
		$this->assertSame( 1, (int)$row->cec_user_id );
		$this->assertSame( 'Bob', $row->cec_user_name );

		// Aggregates
		$this->assertSame( 1, (int)$row->articles_added, 'One page creation' );
		$this->assertSame( 1, (int)$row->edit_count, 'One total edit' );
		$this->assertSame( 99, (int)$row->bytes, 'Bytes summed correctly' );
	}

	protected function getPager( array $requestValues ): EventContributionsEditorsPager {
		$context = $this->createContext( $requestValues );

		$event = $this->createEventMock();

		return new EventContributionsEditorsPager(
			$this->getDB(),
			MediaWikiServices::getInstance()->getLinkRenderer(),
			MediaWikiServices::getInstance()->getLinkBatchFactory(),
			CampaignEventsServices::getUserLinker(),
			CampaignEventsServices::getPermissionChecker(),
			CampaignEventsServices::getCentralUserLookup(),
			$event,
			CampaignEventsServices::getEventContributionStore(),
			$context
		);
	}
}
