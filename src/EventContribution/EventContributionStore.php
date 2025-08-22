<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

/**
 * Store for managing event contributions.
 */
class EventContributionStore {

	public const SERVICE_NAME = 'CampaignEventsEventContributionStore';

	private CampaignsDatabaseHelper $dbHelper;

	public function __construct( CampaignsDatabaseHelper $dbHelper ) {
		$this->dbHelper = $dbHelper;
	}

	/**
	 * Associate an edit with an event.
	 *
	 * @param EventContribution $editObject The edit contribution to associate
	 */
	public function saveEventContribution( EventContribution $editObject ): void {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'ce_event_contributions' )
			->row( [
				'cec_event_id' => $editObject->getEventId(),
				'cec_user_id' => $editObject->getUserId(),
				'cec_wiki' => $editObject->getWiki(),
				'cec_page_id' => $editObject->getPageId(),
				'cec_page_prefixedtext' => $editObject->getPagePrefixedtext(),
				'cec_revision_id' => $editObject->getRevisionId(),
				'cec_edit_flags' => $editObject->getEditFlags(),
				'cec_bytes_delta' => $editObject->getBytesDelta(),
				'cec_links_delta' => $editObject->getLinksDelta(),
				'cec_timestamp' => $dbw->timestamp( $editObject->getTimestamp() ),
				'cec_deleted' => $editObject->isDeleted() ? 1 : 0,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Create a new EventContribution from a database row.
	 *
	 * @param \stdClass $row Database row object containing contribution data
	 * @return EventContribution New EventContribution instance
	 */
	public function newFromRow( \stdClass $row ): EventContribution {
		$this->assertValidRow( $row );

		return new EventContribution(
			(int)$row->cec_event_id,
			(int)$row->cec_user_id,
			$row->cec_wiki,
			$row->cec_page_prefixedtext,
			(int)$row->cec_page_id,
			(int)$row->cec_revision_id,
			(int)$row->cec_edit_flags,
			(int)$row->cec_bytes_delta,
			(int)$row->cec_links_delta,
			$row->cec_timestamp,
			(bool)$row->cec_deleted
		);
	}

	/**
	 * Assert that a database row has the required fields.
	 *
	 * @param \stdClass $row The row to validate
	 */
	private function assertValidRow( \stdClass $row ): void {
		$requiredFields = [
			'cec_event_id', 'cec_user_id', 'cec_wiki', 'cec_page_id', 'cec_page_prefixedtext',
			'cec_revision_id', 'cec_edit_flags', 'cec_bytes_delta', 'cec_links_delta', 'cec_timestamp', 'cec_deleted'
		];

		foreach ( $requiredFields as $field ) {
			if ( !isset( $row->$field ) ) {
				throw new InvalidArgumentException( "Missing required field: $field" );
			}
		}
	}
}
