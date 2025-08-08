<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Maintenance;

use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * This script takes existing values from the cea_countries column and attempts to match then with a country code
 *
 *
 */

class UpdateCountriesColumn extends Maintenance {
	private ?IDatabase $dbw;
	private ?IDatabase $dbr;
	/**
	 * @var array<string,string>[]
	 */
	private array $countryList;
	private CountryProvider $countryProvider;
	/**
	 * @var int[]
	 */
	private array $idsToPurge = [];
	/**
	 * @var array<string,string>
	 */
	private array $countryListExceptions = [];
	private int $maxRowID;
	/**
	 * @var array<int,string>
	 */
	private array $purgedValues = [];

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'takes existing values from the cea_countries column and attempts to match then with a country code'
		);
		$this->requireExtension( 'CampaignEvents' );
		$this->setBatchSize( 500 );
		$this->addOption(
			'exceptions',
			'Path to file of additional mappings',
			false,
			true
		);
		$this->addOption(
			'commit',
			'Boolean value, if false will output results without committing to database, default false',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$migrationStage = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'CampaignEventsCountrySchemaMigrationStage' );

		if ( !( $migrationStage & SCHEMA_COMPAT_WRITE_NEW ) && $this->getOption( 'commit' ) ) {
			$this->output( "Migration stage is not WRITE_NEW!" );
			return false;
		}
		$dbHelper = CampaignEventsServices::getDatabaseHelper();
		$this->countryProvider = CampaignEventsServices::getCountryProvider();
		$this->dbr = $dbHelper->getDBConnection( DB_REPLICA );
		$this->dbw = $dbHelper->getDBConnection( DB_PRIMARY );
		$batchSize = $this->getBatchSize();
		$this->maxRowID = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'MAX(cea_id)' )
			->from( 'ce_address' )
			->caller( __METHOD__ )
			->fetchField();

		$mainBatchStart = 0;
		$matchedRows = [];

		$this->loadCountryMap();
		$this->maybeLoadExceptions();
		$this->doPurge();
		$this->handleEventsWithNoAddress();

		$unmatchedRows = [];
		do {
			$batchEnd = $mainBatchStart + $batchSize;
			$batchMatchedRows = [];
			$batchUnmatchedRows = [];
			$batchAddresses = [];
			$where = [ $this->dbr->expr( 'cea_id', '>', $mainBatchStart ),
				$this->dbr->expr( 'cea_id', '<=', $mainBatchStart + $batchSize ),
				$this->dbr->expr( 'cea_country_code', "=", null ) ];
			if ( $this->idsToPurge ) {
				$where[] = $this->dbr->expr( 'cea_id', "!=", $this->idsToPurge );
			}
			$freetextCountryResults = $this->dbr->newSelectQueryBuilder()
				->select( [ 'cea_id', 'cea_country', 'cea_full_address' ] )
				->from( 'ce_address' )
				->where( $where )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $freetextCountryResults as $dbRow ) {
				$dbId = $dbRow->cea_id;
				$dbCountry = $dbRow->cea_country;

				if ( $dbCountry === null ) {
					$batchUnmatchedRows[$dbId] = null;
					$batchAddresses[$dbId] = $dbRow->cea_full_address;
					continue;
				}

				$attemptedConversion = $this->convertCountryToCountryCode( $dbCountry );
				if ( $attemptedConversion !== [] ) {
					$batchMatchedRows[$dbId] = $attemptedConversion;
				} else {
					$batchUnmatchedRows[$dbId] = $dbCountry;
				}
				$batchAddresses[$dbId] = $dbRow->cea_full_address;
			}
			$mainBatchStart += $batchSize;
			if ( $this->getOption( 'commit' ) ) {
				$this->writeResults( $batchMatchedRows, $batchUnmatchedRows, $batchAddresses );
			}
			$unmatchedRows += $batchUnmatchedRows;
			$matchedRows += $batchMatchedRows;
		} while ( $batchEnd < $this->maxRowID );

		$this->showOutput( $matchedRows, $unmatchedRows );

		return true;
	}

	/**
	 * @return array<string>
	 */
	public function convertCountryToCountryCode( string $freetextValue ): array {
		if ( array_key_exists( $freetextValue, $this->countryListExceptions ) ) {
			return [
				$freetextValue,
				$this->countryListExceptions[$freetextValue],
				$freetextValue
			];
		}

		$highestSimilarity = 0;
		$bestMatchName = '';
		$normalizedInput = strtolower( $freetextValue );

		foreach ( $this->countryList as $languageCode => $countryMap ) {
			foreach ( $countryMap as $countryCode => $countryName ) {
					$result = $this->checkSimilarity(
						$normalizedInput,
						$countryName,
						$highestSimilarity,
						$bestMatchName );

				if ( $result !== null ) {
					return [
						$freetextValue,
						$countryCode,
						$countryName
					];
				}
			}
		}
		return [];
	}

	private function checkSimilarity(
		string $input,
		string $countryName,
		float &$highestSimilarity,
		string &$bestMatchName
	): ?float {
		similar_text( strtolower( $countryName ), $input, $similarity );

		if ( $similarity > $highestSimilarity ) {
			$highestSimilarity = $similarity;
			$bestMatchName = $countryName;
		}

		return $similarity >= 85 ? $similarity : null;
	}

	/**
	 * @param array<string, mixed> $matchedRows
	 * @param array<string, string> $unmatchedRows
	 */
	public function showOutput( array $matchedRows, array $unmatchedRows ): void {
		$matchedCount = count( $matchedRows );
		$unmatchedCount = count( $unmatchedRows );
		$purgedCount = count( $this->purgedValues );
		$this->output( "========= {$purgedCount} Purged rows =========" . PHP_EOL );
		foreach ( $this->purgedValues as $purgedId => $purgedCountry ) {
			$this->output( "{$purgedId} - \"{$purgedCountry}\"" . PHP_EOL );
		}
		$this->output( "========= {$matchedCount} Matches ========" . PHP_EOL );
		foreach ( $matchedRows as $dbID => [ $dbCountry, $dbConversion, $matchedName ] ) {
			$this->output( "{$dbID} - \"{$dbCountry}\" matched {$dbConversion} - ({$matchedName})" . PHP_EOL );
		}

		$this->output( "========= {$unmatchedCount} Unmatched ========" . PHP_EOL );
		foreach ( $unmatchedRows as $dbId => $dbCountry ) {
			$this->output( "{$dbId} - \"{$dbCountry}\"" . PHP_EOL );
		}
	}

	/**
	 * @param array<string, mixed> $matchedRows
	 * @param array<string, string> $unmatchedRows
	 * @param array<string, string> $addresses
	 */
	private function writeResults(
		array $matchedRows,
		array $unmatchedRows,
		array $addresses
	): void {
		$rowsToUpdate = [];

		foreach ( $matchedRows as $dbID => [ $dbCountry, $countryCode, $matchedName ] ) {
			$fullAddress = $addresses[$dbID];
			$addressWithoutCountry = $this->getAddressWithoutCountry( $fullAddress );

			$rowsToUpdate[] = [
				'cea_id' => (int)$dbID,
				'cea_country_code' => $countryCode,
				'cea_country' => null,
				'cea_full_address' => $addressWithoutCountry,
			];
		}

		if ( $rowsToUpdate ) {
			$this->dbw->newInsertQueryBuilder()
				->insertInto( 'ce_address' )
				->rows( $rowsToUpdate )
				->onDuplicateKeyUpdate()
				->uniqueIndexFields( [ 'cea_id' ] )
				->set( [
					'cea_country_code =' . $this->dbw->buildExcludedValue( 'cea_country_code' ),
					'cea_country =' . $this->dbw->buildExcludedValue( 'cea_country' ),
					'cea_full_address =' . $this->dbw->buildExcludedValue( 'cea_full_address' ),
				] )
				->caller( __METHOD__ )
				->execute();
			$this->waitForReplication();
		}

		if ( $unmatchedRows ) {
			// This is guaranteed to be non-empty because we've already purged unused addresses.
			$eventsToOnline = $this->dbw->newSelectQueryBuilder()
				->select( 'ceea_event' )
				->from( 'ce_event_address' )
				->where( [ 'ceea_address' => array_keys( $unmatchedRows ) ] )
				->caller( __METHOD__ )
				->fetchFieldValues();

			$this->dbw->newUpdateQueryBuilder()
				->update( 'campaign_events' )
				->set( [
					'event_meeting_type' =>
						EventStore::PARTICIPATION_OPTION_MAP[EventRegistration::PARTICIPATION_OPTION_ONLINE]
				] )
				->where( [ 'event_id' => $eventsToOnline ] )
				->caller( __METHOD__ )
				->execute();

			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_address' )
				->where( [ 'cea_id' => array_keys( $unmatchedRows ) ] )
				->caller( __METHOD__ )
				->execute();
			$this->waitForReplication();

			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ce_event_address' )
				->where( [ 'ceea_address' => array_keys( $unmatchedRows ) ] )
				->caller( __METHOD__ )
				->execute();
			$this->waitForReplication();
		}
	}

	public function doPurge(): void {
		$batchSize = $this->getBatchSize();
		$purgeBatchStart = 0;
		do {
			$idsToPurge = [];
			$batchEnd = $purgeBatchStart + $batchSize;
			$purgeRows = $this->dbr->newSelectQueryBuilder()
				->select( [ 'cea_id', 'cea_country' ] )
				->from( 'ce_address' )
				->leftJoin(
					'ce_event_address', null, [ 'ceea_address=cea_id' ]
				)->where( [
					$this->dbr->expr( 'cea_id', '>', $purgeBatchStart ),
					$this->dbr->expr( 'cea_id', '<=', $batchEnd ),
					'ceea_event' => null,
				] )->fetchResultSet();
			foreach ( $purgeRows as $purgeRow ) {
				$idsToPurge[] = $purgeRow->cea_id;
				$this->purgedValues[ $purgeRow->cea_id ] = $purgeRow->cea_country;
			}
			$this->idsToPurge = array_merge( $this->idsToPurge, $idsToPurge );
			if ( $idsToPurge && $this->getOption( 'commit' ) ) {
				$this->dbw->newDeleteQueryBuilder()
					->deleteFrom( 'ce_address' )
					->where( [ 'cea_id' => $idsToPurge ] )
					->caller( __METHOD__ )
					->execute();
				$this->waitForReplication();
			}
			$purgeBatchStart += $batchSize;
		} while ( $batchEnd < $this->maxRowID );
	}

	public function loadCountryMap(): void {
		$languages = ( MediaWikiServices::getInstance()->getLanguageNameUtils() )->getLanguageNames();
		foreach ( $languages as $languageCode => $languageName ) {
			$this->countryList[$languageCode] = $this->countryProvider->getAvailableCountries( $languageCode );
		}
	}

	public function maybeLoadExceptions(): void {
		if ( $this->hasOption( 'exceptions' ) ) {
			$filename = $this->getOption( 'exceptions' );
			$handle = fopen( $filename, 'r' );
			if ( $handle !== false ) {
				// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				while ( ( $data = fgetcsv( $handle, 1000 ) ) !== false ) {
					if ( count( $data ) === 2 && is_string( $data[0] ) && is_string( $data[1] ) ) {
						$this->countryListExceptions[$data[1]] = $data[0];
					}
				}
				fclose( $handle );
			}
		}
	}

	public function getAddressWithoutCountry( string $fullAddress ): string {
		$addressParts = explode( " \n ", $fullAddress );
		array_pop( $addressParts );

		return implode( " \n ", $addressParts );
	}

	private function handleEventsWithNoAddress(): void {
		$batchSize = $this->getBatchSize();
		$batchStart = 0;

			$maxRows = $this->dbw->newSelectQueryBuilder()
				->select( 'MAX(event_id)' )
				->from( 'campaign_events' )
				->leftJoin( 'ce_event_address', null, 'ceea_event = event_id' )
				->where( [
					'event_meeting_type != ' .
					EventStore::PARTICIPATION_OPTION_MAP[EventRegistration::PARTICIPATION_OPTION_ONLINE],
					'ceea_event IS NULL',
				] )
				->caller( __METHOD__ )
				->fetchField();
		$eventsWithNoAddress = [];
		do {
			$batchEnd = $batchStart + $batchSize;
			$batchEventsWithNoAddress = $this->dbw->newSelectQueryBuilder()
				->select( 'event_id' )
				->from( 'campaign_events' )
				->leftJoin( 'ce_event_address', null, 'ceea_event = event_id' )
				->where( [
					$this->dbw->expr(
						'event_meeting_type',
						'!=',
						EventStore::PARTICIPATION_OPTION_MAP[EventRegistration::PARTICIPATION_OPTION_ONLINE]
					),
					'ceea_event' => null,
					$this->dbw->expr( 'event_id', '>', $batchStart ),
					$this->dbw->expr( 'event_id', '<=', $batchEnd ),
				] )
				->caller( __METHOD__ )
				->fetchFieldValues();
			$eventsWithNoAddress = array_merge( $eventsWithNoAddress, $batchEventsWithNoAddress );
			$batchStart = $batchEnd;

			if ( $batchEventsWithNoAddress && $this->getOption( 'commit' ) ) {
				$this->dbw->newUpdateQueryBuilder()
					->update( 'campaign_events' )
					->set( [ 'event_meeting_type' =>
						EventStore::PARTICIPATION_OPTION_MAP[EventRegistration::PARTICIPATION_OPTION_ONLINE]
					] )
					->where( [ 'event_id' => $batchEventsWithNoAddress ] )
					->caller( __METHOD__ )
					->execute();
			}
		} while ( $batchEnd < $maxRows );
		$count = count( $eventsWithNoAddress );
		$this->output( "========= {$count} Events made online =========" );
		$this->output( "\n" . implode( "\n", $eventsWithNoAddress ) . "\n" );
	}
}

$maintClass = UpdateCountriesColumn::class;
require_once RUN_MAINTENANCE_IF_MAIN;
