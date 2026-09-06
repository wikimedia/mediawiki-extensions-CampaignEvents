<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration\Pager;

use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Pager\EventContributionsEditsPager;

/**
 * @group Database
 * @covers \MediaWiki\Extension\CampaignEvents\Pager\EventContributionsEditsPager
 */
class EventContributionsEditsPagerTest extends AbstractContributionsPagerTestBase {
	public function testSimpleQueryReturnsContribution(): void {
		$pager = $this->getPager( [] );
		$pager->doQuery();
		$result = $pager->mResult;

		$this->assertCount( 3, $result, 'Three contribution rows should be returned' );

		$row = $result->fetchObject();

		$this->assertSame( 3, (int)$row->cec_user_id );
		$this->assertSame( 'Alice', $row->cec_user_name );
		$this->assertSame( 'Page 11', $row->cec_page_prefixedtext );
		$this->assertSame( 77, (int)$row->cec_bytes_delta );
		$this->assertSame( 103, (int)$row->cec_revision_id );
	}

	/**
	 * @param array<string,string> $requestValues
	 *
	 * @return EventContributionsEditsPager
	 */
	public function getPager( array $requestValues ): EventContributionsEditsPager {
		$context = $this->createContext( $requestValues );
		$event = $this->createEventMock();
		$services = $this->getServiceContainer();

		return CampaignEventsServices::getEventContributionsPagerFactory( $services )->newEditsPager(
			$context,
			$services->getLinkRenderer(),
			$event,
		);
	}
}
