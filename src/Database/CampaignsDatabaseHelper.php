<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Database;

use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsDatabase;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWDatabaseProxy;
use Wikimedia\Rdbms\LBFactory;

class CampaignsDatabaseHelper {
	public const SERVICE_NAME = 'CampaignEventsDatabaseHelper';

	/** @var LBFactory */
	private $lbFactory;
	/** @var false|string */
	private $dbCluster;
	/** @var false|string */
	private $dbName;

	/**
	 * @param LBFactory $lbFactory
	 * @param string|false $dbCluster
	 * @param string|false $dbName
	 */
	public function __construct( LBFactory $lbFactory, $dbCluster, $dbName ) {
		$this->lbFactory = $lbFactory;
		$this->dbCluster = $dbCluster;
		$this->dbName = $dbName;
	}

	/**
	 * @param int $type DB_PRIMARY or DB_REPLICA
	 * @return ICampaignsDatabase
	 */
	public function getDBConnection( int $type ): ICampaignsDatabase {
		$lb = $this->dbCluster === false
			? $this->lbFactory->getMainLB( $this->dbName )
			: $this->lbFactory->getExternalLB( $this->dbCluster );
		return new MWDatabaseProxy( $lb->getConnection( $type, [], $this->dbName ) );
	}

	/**
	 * Waits for the replica DBs to catch up to the current primary position
	 */
	public function waitForReplication(): void {
		$this->lbFactory->waitForReplication();
	}
}
