<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;

/**
 * This job updates event contribution records for a given user when that user is renamed, deleted, or suppressed.
 */
class UpdateUserContributionRecordsJob extends Job implements GenericParameterJob {
	public const TYPE_RENAME = 'rename';
	public const TYPE_DELETE = 'delete';
	public const TYPE_VISIBILITY = 'visibility';

	private string $type;
	private int $userID;

	/**
	 * @inheritDoc
	 * @phan-param array{type:string,userID:int,newName?:string,isHidden?:bool} $params
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'CampaignEventsUpdateUserContributionRecords', $params );

		$requiredParams = match ( $params['type'] ?? null ) {
			self::TYPE_RENAME => [ 'type', 'userID', 'newName' ],
			self::TYPE_DELETE => [ 'type', 'userID' ],
			self::TYPE_VISIBILITY => [ 'type', 'userID', 'userName', 'isHidden' ],
			default => throw new InvalidArgumentException( 'Invalid type "' . ( $params['type'] ?? null ) . '".' )
		};
		$missingParams = array_diff( $requiredParams, array_keys( $params ) );
		if ( $missingParams ) {
			throw new InvalidArgumentException( "Missing parameters: " . implode( ', ', $missingParams ) );
		}

		$this->type = $params['type'];
		$this->userID = $params['userID'];
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		$store = CampaignEventsServices::getEventContributionStore();

		$user = new CentralUser( $this->userID );
		if ( $this->type === self::TYPE_RENAME ) {
			$store->updateUserName( $user, $this->params['newName'] );
		} else {
			// The isHidden parameter is only set for TYPE_VIBILITY, as for TYPE_DELETE it would always be true.
			$isHidden = $this->params['isHidden'] ?? true;
			$store->updateUserVisibility( $user, $isHidden, $this->params['userName'] ?? null );
		}

		return true;
	}
}
