<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Database;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsDatabase;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWDatabaseProxy;
use MediaWiki\Extension\CampaignEvents\Utils;
use Wikimedia\Rdbms\LBFactory;

class CampaignsDatabaseHelper {
	public const SERVICE_NAME = 'CampaignEventsDatabaseHelper';

	private LBFactory $lbFactory;

	/**
	 * @param LBFactory $lbFactory
	 */
	public function __construct( LBFactory $lbFactory ) {
		$this->lbFactory = $lbFactory;
	}

	/**
	 * @param int $type DB_PRIMARY or DB_REPLICA
	 * @return ICampaignsDatabase
	 */
	public function getDBConnection( int $type ): ICampaignsDatabase {
		$conn = $type === DB_REPLICA
			? $this->lbFactory->getReplicaDatabase( Utils::VIRTUAL_DB_DOMAIN )
			: $this->lbFactory->getPrimaryDatabase( Utils::VIRTUAL_DB_DOMAIN );
		return new MWDatabaseProxy( $conn );
	}

	/**
	 * Waits for the replica DBs to catch up to the current primary position
	 */
	public function waitForReplication(): void {
		$this->lbFactory->waitForReplication();
	}
}
