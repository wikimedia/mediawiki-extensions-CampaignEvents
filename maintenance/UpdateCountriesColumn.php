<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Maintenance;

use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
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
		$this->maybeDoPurge();

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
			if ( count( $freetextCountryResults ) === 0 ) {
				break;
			}
			foreach ( $freetextCountryResults as $dbRow ) {
				$dbId = $dbRow->cea_id;
				$dbCountry = $dbRow->cea_country;

				$this->output( "Processing ID: {$dbId} - {$dbCountry}" . PHP_EOL );

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

		if ( !$this->getOption( 'commit' ) ) {
			$this->showOutput( $matchedRows, $unmatchedRows );
		}
	}

	/**
	 * @param string $freetextValue
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
		string &$bestMatchName ): ?float {
		// @phan-suppress-next-line PhanPluginUseReturnValueInternalKnown
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
	 *
	 * @return void
	 */
	public function showOutput( array $matchedRows, array $unmatchedRows ): void {
		$matchedCount = count( $matchedRows );
		$unmatchedCount = count( $unmatchedRows );
		$this->output( "========= Purged rows =========" . PHP_EOL );
		foreach ( $this->idsToPurge as $purgedId ) {
			$this->output( "{$purgedId}" . PHP_EOL );
		}
		$this->output( "========= {$matchedCount} Matches ========" . PHP_EOL );
		foreach ( $matchedRows as $dbID => [ $dbCountry, $dbConversion, $matchedName ] ) {
			$this->output( "{$dbID} - {$dbCountry} matched {$dbConversion} - {$matchedName}" . PHP_EOL );
		}

		$this->output( "========= {$unmatchedCount} Unmatched ========" . PHP_EOL );
		foreach ( $unmatchedRows as $dbId => $dbCountry ) {
			$this->output( "{$dbId} - {$dbCountry}" . PHP_EOL );
		}
	}

	/**
	 * @param array<string, mixed> $matchedRows
	 * @param array<string, string> $unmatchedRows
	 * @param array<string, string> $addresses
	 *
	 * @return void
	 */
	private function writeResults(
		array $matchedRows,
		array $unmatchedRows,
		array $addresses
	): void {
		foreach ( $matchedRows as $dbID => [ $dbCountry, $dbConversion, $matchedName ] ) {
			$this->dbw->newUpdateQueryBuilder()
				->update( 'ce_address' )
				->set( [
					'cea_country_code' => $dbConversion,
					'cea_country' => null,
				] )
				->where( [ 'cea_id' => $dbID ] )
				->execute();
			$this->waitForReplication();
		}
		foreach ( $unmatchedRows as $dbID => $dbCountry ) {
			$this->dbw->newUpdateQueryBuilder()
				->update( 'ce_address' )
				->set( [ 'cea_country_code' => 'VA' ] )
				->where( [ 'cea_id' => $dbID ] )
				->execute();
			$this->waitForReplication();
		}
		foreach ( $addresses as $dbID => $fullAddress ) {
			$addressParts = explode( " \n ", $fullAddress );
			array_pop( $addressParts );
			$addressWithoutCountry = implode( " \n ", $addressParts );
			$where = [ 'cea_id' => $dbID ];
			if ( $this->idsToPurge ) {
				$where[] = $this->dbr->expr( 'cea_id', "!=", $this->idsToPurge );
			}
			$this->dbw->newUpdateQueryBuilder()
				->update( 'ce_address' )
				->set( [
					'cea_full_address' => $addressWithoutCountry,
				] )
				->where( $where )
				->execute();
			$this->waitForReplication();
		}
	}

	public function maybeDoPurge(): void {
		$batchSize = $this->getBatchSize();
		$purgeBatchStart = 0;
		do {
			$batchEnd = $purgeBatchStart + $batchSize;
			$purgeIds = $this->dbr->newSelectQueryBuilder()
				->select( 'cea_id' )
				->from( 'ce_address' )
				->leftJoin(
					'ce_event_address', null, [ 'ceea_address=cea_id' ]
				)->where( [
					$this->dbr->expr( 'cea_id', '>', $purgeBatchStart ),
					$this->dbr->expr( 'cea_id', '<=', $batchEnd ),
					'ceea_event' => null,
				] )->fetchFieldValues();
			if ( $purgeIds && $this->getOption( 'commit' ) ) {
				$this->dbw->newDeleteQueryBuilder()
					->deleteFrom( 'ce_address' )
					->where( [ 'cea_id' => $purgeIds ] )
					->execute();
				$this->waitForReplication();
			}
			$this->idsToPurge = array_merge( $this->idsToPurge, $purgeIds );
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
}

$maintClass = UpdateCountriesColumn::class;
require_once RUN_MAINTENANCE_IF_MAIN;
