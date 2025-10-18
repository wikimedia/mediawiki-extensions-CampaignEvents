<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Database;

use MediaWiki\Extension\CampaignEvents\Utils;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\LBFactory;

class CampaignsDatabaseHelper {
	public const SERVICE_NAME = 'CampaignEventsDatabaseHelper';

	public function __construct(
		private LBFactory $lbFactory,
	) {
	}

	/**
	 * @param int $type DB_PRIMARY or DB_REPLICA
	 * @return IDatabase|IReadableDatabase
	 */
	public function getDBConnection( int $type ): IReadableDatabase {
		return $type === DB_REPLICA
			? $this->lbFactory->getReplicaDatabase( Utils::VIRTUAL_DB_DOMAIN )
			: $this->lbFactory->getPrimaryDatabase( Utils::VIRTUAL_DB_DOMAIN );
	}
}
