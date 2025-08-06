<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Maintenance;

use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * This script takes existing values from the cea_countries column and attempts to match then with a country code
 *
 *
 */

class UpdateCountriesColumn extends LoggedUpdateMaintenance {
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

	private bool $countdownShown = false;

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
			'dry-run',
			'Boolean value, if true will output results without committing to database, default false',
		);
		$this->addOption( 'nowarn', 'Suppresses the countdown warning' );
	}

	/**
	 * @inheritDoc
	 */
	public function doDBUpdates(): bool {
		$this->output( "Updating event country schema...\n" );

		$migrationStage = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'CampaignEventsCountrySchemaMigrationStage' );

		if ( !( $migrationStage & SCHEMA_COMPAT_WRITE_NEW ) && !$this->getOption( 'dry-run' ) ) {
			$this->output( "Cannot update countries because migration stage does not include WRITE_NEW!\n" );
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
		$eventsToOnline = [];
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

			$batchEventsToOnline = $this->maybeWriteResults( $batchMatchedRows, $batchUnmatchedRows, $batchAddresses );

			$mainBatchStart += $batchSize;
			$eventsToOnline = array_merge( $eventsToOnline, $batchEventsToOnline );
			$unmatchedRows += $batchUnmatchedRows;
			$matchedRows += $batchMatchedRows;
		} while ( $batchEnd < $this->maxRowID );

		$this->showOutput( $matchedRows, $unmatchedRows, $eventsToOnline );

		return !$this->hasOption( 'dry-run' );
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
	 * @param int[] $eventsToOnline
	 */
	public function showOutput( array $matchedRows, array $unmatchedRows, array $eventsToOnline ): void {
		$matchedCount = count( $matchedRows );
		$unmatchedCount = count( $unmatchedRows );
		$purgedCount = count( $this->purgedValues );
		$this->output( "========= {$purgedCount} purged address rows (unused) =========" . PHP_EOL );
		foreach ( $this->purgedValues as $purgedId => $purgedCountry ) {
			$this->output( "{$purgedId} - \"{$purgedCountry}\"" . PHP_EOL );
		}
		$this->output( "========= {$matchedCount} address rows updated ========" . PHP_EOL );
		foreach ( $matchedRows as $dbID => [ $dbCountry, $dbConversion, $matchedName ] ) {
			$this->output( "{$dbID} - \"{$dbCountry}\" matched {$dbConversion} ({$matchedName})" . PHP_EOL );
		}

		$this->output( "========= {$unmatchedCount} unmatched address rows ========" . PHP_EOL );
		foreach ( $unmatchedRows as $dbId => $dbCountry ) {
			$this->output( "{$dbId} - \"{$dbCountry}\"" . PHP_EOL );
		}

		$onlineCount = count( $eventsToOnline );
		$this->output( "========= $onlineCount events without country made online =========" );
		$this->printEventList( $eventsToOnline );
	}

	/**
	 * @param array<string, mixed> $matchedRows
	 * @param array<string, string> $unmatchedRows
	 * @param array<string, string> $addresses
	 * @return int[] IDs of events that have been made online
	 */
	private function maybeWriteResults(
		array $matchedRows,
		array $unmatchedRows,
		array $addresses
	): array {
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

		if ( $rowsToUpdate && $this->checkShouldMakeWrites() ) {
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

		if ( !$unmatchedRows ) {
			return [];
		}
		// This is guaranteed to be non-empty because we've already purged unused addresses.
		$eventsToOnline = $this->dbw->newSelectQueryBuilder()
			->select( 'ceea_event' )
			->from( 'ce_event_address' )
			->where( [ 'ceea_address' => array_keys( $unmatchedRows ) ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		if ( $this->checkShouldMakeWrites() ) {
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

		return $eventsToOnline;
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
			if ( $idsToPurge && $this->checkShouldMakeWrites() ) {
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
		$filename = $this->hasOption( 'exceptions' )
			? $this->getOption( 'exceptions' )
			: __DIR__ . '/countryExceptionMappings.csv';

		if ( !$filename ) {
			return;
		}

		$handle = fopen( $filename, 'r' );
		if ( $handle === false ) {
			$this->fatalError( 'Failed to open exception file: ' . $filename );
		}
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( ( $data = fgetcsv( $handle, 1000 ) ) !== false ) {
			if ( count( $data ) === 2 && is_string( $data[0] ) && is_string( $data[1] ) ) {
				$this->countryListExceptions[$data[1]] = $data[0];
			}
		}
		fclose( $handle );
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

			if ( $batchEventsWithNoAddress && $this->checkShouldMakeWrites() ) {
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
		$this->output( "========= {$count} events without address made online =========" );
		$this->printEventList( $eventsWithNoAddress );
	}

	/**
	 * Checks whether we should make writes, and display a warning and countdown if needed. This is done here, as
	 * opposed as the beginning of the script, to avoid the annoying countdown when there's nothing to update (e.g.,
	 * if the wiki was just created like in CI, or if there are no events, or if the script was already run from a
	 * different wiki and is not in the updatelog table for this wiki).
	 */
	private function checkShouldMakeWrites(): bool {
		if ( $this->getOption( 'dry-run' ) ) {
			return false;
		}
		if ( !$this->countdownShown && !$this->getOption( 'nowarn' ) ) {
			$this->output(
				'The UpdateCountriesColumn script is about to update stored countries for all events. This is ' .
				'potentially DESTRUCTIVE, because countries that can\'t be mapped to a valid country code will be ' .
				'deleted, and the respective events will be changed to online. If you wish to take a closer look, ' .
				'abort with control-c in the next 15 seconds and run the script manually. (skip this countdown ' .
				'with --nowarn) ... '
			);
			$this->countDown( 15 );
			$this->countdownShown = true;
		}
		return true;
	}

	/**
	 * @param int[] $eventIDs
	 */
	private function printEventList( array $eventIDs ): void {
		if ( !$eventIDs ) {
			$this->output( "\n" );
			return;
		}
		$eventIDRows = array_map(
			/** @param int[] $curEvents */
			static fn ( array $curEvents ): string => implode( ', ', $curEvents ) . ',',
			array_chunk( $eventIDs, 15 )
		);
		$this->output( "\n" . implode( "\n", $eventIDRows ) . "\n" );
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	protected function getUpdateKey(): string {
		return __CLASS__;
	}
}

$maintClass = UpdateCountriesColumn::class;
require_once RUN_MAINTENANCE_IF_MAIN;
