<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Integration;

use LogicException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use stdClass;

/**
 * Helpers for tests involving code that updates worklist rows.
 */
trait WorklistUpdateTestHelperTrait {
	private function getStoredWorklistRow(): stdClass {
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'ce_worklists' )
			->fetchRow();
		if ( !$row ) {
			throw new LogicException( 'No stored worklist' );
		}
		return $row;
	}

	private function createWorklistForUser( int $userID, string $userName ): void {
		$worklistStore = CampaignEventsServices::getWorklistSecondaryStore();
		$worklistStore->createWorklist(
			WikiMap::getCurrentWikiId(),
			1234,
			'Some page',
			new CentralUser( $userID ),
			$userName,
			MWTimestamp::getInstance()
		);
	}

	private function runWorklistUpdateJob(): void {
		$this->runJobs(
			[ 'minJobs' => 1, 'maxJobs' => 1 ],
			[ 'type' => 'CampaignEventsUpdateUserWorklistRecords' ]
		);
	}
}
