<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\TrackingTool;

use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolAssociation
 */
class TrackingToolAssociationTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::__construct
	 * @covers ::getToolID
	 * @covers ::getToolEventID
	 * @covers ::getSyncStatus
	 * @covers ::getLastSyncTimestamp
	 */
	public function testGetters() {
		$toolID = 42;
		$toolEventID = 'foobar';
		$syncStatus = TrackingToolAssociation::SYNC_STATUS_SYNCED;
		$syncTS = wfTimestamp();

		$assoc = new TrackingToolAssociation(
			$toolID,
			$toolEventID,
			$syncStatus,
			$syncTS
		);

		$this->assertSame( $toolID, $assoc->getToolID(), 'tool ID' );
		$this->assertSame( $toolEventID, $assoc->getToolEventID(), 'tool event ID' );
		$this->assertSame( $syncStatus, $assoc->getSyncStatus(), 'status' );
		$this->assertSame( $syncTS, $assoc->getLastSyncTimestamp(), 'ts' );
	}

	/**
	 * @covers ::asUpdatedWith
	 */
	public function testAsUpdatedWith() {
		$assoc = new TrackingToolAssociation(
			42,
			'foobar',
			TrackingToolAssociation::SYNC_STATUS_UNKNOWN,
			null
		);

		$newStatus = TrackingToolAssociation::SYNC_STATUS_SYNCED;
		$newTS = wfTimestamp();
		$updatedAssoc = $assoc->asUpdatedWith( $newStatus, $newTS );
		$this->assertSame( $newStatus, $updatedAssoc->getSyncStatus(), 'status' );
		$this->assertSame( $newTS, $updatedAssoc->getLastSyncTimestamp(), 'ts' );
	}
}
