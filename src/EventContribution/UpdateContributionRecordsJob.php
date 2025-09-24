<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;

/**
 * Job to update any stored records associated with the given page or edits, in the event of page move or
 * page/revision deletion.
 * @note This may be subject to race conditions, especially if JobQueue execution is lagged, when the same page or
 * revision is updated multiple times within a short timeframe in conflicting ways (e.g., a revision is deleted by
 * mistake and immediately restored).
 */
class UpdateContributionRecordsJob extends Job implements GenericParameterJob {
	public const TYPE_MOVE = 'move';
	public const TYPE_DELETE = 'delete';
	public const TYPE_RESTORE = 'restore';
	public const TYPE_REV_DELETE = 'rev-delete';

	private string $type;
	private string $wiki;

	/**
	 * @inheritDoc
	 * @phpcs:ignore Generic.Files.LineLength.TooLong
	 * @phan-param array{type:string,wiki:string,pageID?:int,newPrefixedText?:string,deletedRevIDs?:list<int>,restoredRevIDs?:list<int>} $params
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'CampaignEventsUpdatePageContributionRecords', $params );

		$requiredParams = match ( $params['type'] ?? null ) {
			self::TYPE_MOVE => [ 'type', 'wiki', 'pageID', 'newPrefixedText' ],
			self::TYPE_DELETE, self::TYPE_RESTORE => [ 'type', 'wiki', 'pageID' ],
			self::TYPE_REV_DELETE => [ 'type', 'wiki', 'pageID', 'deletedRevIDs', 'restoredRevIDs' ],
			default => throw new InvalidArgumentException( 'Invalid type "' . ( $params['type'] ?? null ) . '".' )
		};
		$missingParams = array_diff( $requiredParams, array_keys( $params ) );
		if ( $missingParams ) {
			throw new InvalidArgumentException( "Missing parameters: " . implode( ', ', $missingParams ) );
		}
		$this->type = $params['type'];
		$this->wiki = $params['wiki'];
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		$store = CampaignEventsServices::getEventContributionStore();

		if ( $this->type === self::TYPE_MOVE ) {
			$store->updateTitle( $this->wiki, $this->params['pageID'], $this->params['newPrefixedText'] );
		} elseif ( $this->type === self::TYPE_DELETE ) {
			$store->updateForPageDeleted( $this->wiki, $this->params['pageID'] );
		} elseif ( $this->type === self::TYPE_RESTORE ) {
			$store->updateForPageRestored( $this->wiki, $this->params['pageID'] );
		} else {
			// TYPE_REV_DELETE
			$store->updateRevisionVisibility(
				$this->wiki,
				$this->params['pageID'],
				$this->params['deletedRevIDs'],
				$this->params['restoredRevIDs']
			);
		}

		return true;
	}
}
