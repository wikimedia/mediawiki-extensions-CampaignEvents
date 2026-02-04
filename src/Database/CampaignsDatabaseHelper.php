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

	public function getReplicaConnection(): IReadableDatabase {
		return $this->lbFactory->getReplicaDatabase( Utils::VIRTUAL_DB_DOMAIN );
	}

	public function getPrimaryConnection(): IDatabase {
		return $this->lbFactory->getPrimaryDatabase( Utils::VIRTUAL_DB_DOMAIN );
	}
}
