<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Job;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;

/**
 * Base class for jobs that run when users are renamed, deleted, or suppressed, to update database records pointing to
 * those users with the new username.
 */
abstract class UpdateUsernameRecordsJobBase extends Job implements GenericParameterJob {
	public const TYPE_RENAME = 'rename';
	public const TYPE_DELETE = 'delete';
	public const TYPE_VISIBILITY = 'visibility';

	protected string $type;
	protected int $userID;

	/**
	 * @inheritDoc
	 * @phan-param array{type:string,userID:int,newName?:string,isHidden?:bool} $params
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( static::getJobName(), $params );

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
		$user = new CentralUser( $this->userID );
		if ( $this->type === self::TYPE_RENAME ) {
			$this->updateName( $user, $this->params['newName'] );
		} else {
			// The isHidden parameter is only set for TYPE_VIBILITY, as for TYPE_DELETE it would always be true.
			$isHidden = $this->params['isHidden'] ?? true;
			$this->updateVisibility( $user, $isHidden, $this->params['userName'] ?? null );
		}

		return true;
	}

	abstract protected static function getJobName(): string;

	abstract protected function updateName( CentralUser $user, string $newName ): void;

	abstract protected function updateVisibility( CentralUser $user, bool $isHidden, ?string $userName = null ): void;
}
