<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Address;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;

/**
 * This class abstracts access to the ce_address DB table.
 * @note This class is not very useful right now, but it will be expanded when implementing geocoding support for
 * events (T316126). The schema will also be updated to have a unique identifier for each address.
 */
class AddressStore {
	public const SERVICE_NAME = 'CampaignEventsAddressStore';

	/** @var CampaignsDatabaseHelper */
	private $dbHelper;

	/**
	 * @param CampaignsDatabaseHelper $dbHelper
	 */
	public function __construct(
		CampaignsDatabaseHelper $dbHelper
	) {
		$this->dbHelper = $dbHelper;
	}

	/**
	 * Returns the ID that identifies the given address in the database. This may return the ID of an existing entry,
	 * or insert a new entry.
	 *
	 * @param string $fullAddress
	 * @param string|null $country
	 * @return int
	 */
	public function acquireAddressID( string $fullAddress, ?string $country ): int {
		$dbw = $this->dbHelper->getDBConnection( DB_PRIMARY );
		// TODO This query is not indexed; for the future we will need to use some indexed field (like unique
		// address identifiers) instead of the full address.
		$addressID = $dbw->selectField( 'ce_address', 'cea_id', [ 'cea_full_address' => $fullAddress ] );
		if ( $addressID !== false ) {
			$addressID = (int)$addressID;
		} else {
			$dbw->insert(
				'ce_address',
				[
					'cea_full_address' => $fullAddress,
					'cea_country' => $country
				]
			);
			$addressID = $dbw->insertId();
		}
		return $addressID;
	}
}
