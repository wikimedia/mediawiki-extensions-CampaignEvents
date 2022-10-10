<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\TrackingTool;
use MediaWiki\Extension\CampaignEvents\TrackingTool\Tool\WikiEduDashboard;
use RuntimeException;

/**
 * This is a registry of known tracking tools, which defines how each tool should be represented in the
 * database and also acts as a factory for TrackingTool objects.
 */
class TrackingToolRegistry {
	public const SERVICE_NAME = 'CampaignEventsTrackingToolRegistry';

	public const CONSTRUCTOR_OPTIONS = [
		'CampaignEventsProgramsAndEventsDashboardInstance',
		'CampaignEventsProgramsAndEventsDashboardAPISecret',
	];

	/** @var ServiceOptions */
	private $options;

	/** @var array|null Mock registry that can be set in tests. */
	private $registryForTests;

	/**
	 * @param ServiceOptions $options
	 */
	public function __construct( ServiceOptions $options ) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	/**
	 * This method returns the internal registry of known tracking tools. This list can potentially be affected by
	 * config. Array keys are only used as an internal representation for readability, and For each element
	 * the following keys must be set:
	 *  - display-name-msg (string): Key of i18n message with the display name of the tool
	 *  - base-url (string): Base URL of the tool's instance
	 *  - class (string): Name of the class that handles this tool (must extend the TrackingTool abstract class)
	 *  - db-id (int): ID of the tool stored in the DB
	 *  - user-id (string): Identifier of the tool as provided by users via the UI or API
	 *  - extra (array): Any additional information needed by the tool, like an API key. The structure of this
	 *    array is dependent on the class which uses it.
	 * Display names, user IDs, and DB IDs are guaranteed to be unique. Classes and base URLs are not, because
	 * potentially there could be more instances of the same tool, or a server could host more than one tool,
	 * respectively.
	 * @return array[]
	 * @phan-return array<array{display-name-msg:string,base-url:string,class:class-string,db-id:int,user-id:string,extra:array}>
	 */
	private function getRegistry(): array {
		if ( $this->registryForTests !== null ) {
			return $this->registryForTests;
		}

		$registry = [];

		$peDashboardData = $this->getConfiguredPEDashboardData();
		if ( $peDashboardData !== null ) {
			$registry['P&E Dashboard'] = $peDashboardData;
		}

		return $registry;
	}

	/**
	 * Returns the registry definition of the P&E Dashboard, if configured, and null otherwise.
	 *
	 * @return array{display-name-msg:string,base-url:string,class:class-string,db-id:int,user-id:string,extra:array}|null
	 */
	private function getConfiguredPEDashboardData(): ?array {
		$peDashboardInstance = $this->options->get( 'CampaignEventsProgramsAndEventsDashboardInstance' );
		if ( $peDashboardInstance === null ) {
			return null;
		}

		$dashboardUrl = $peDashboardInstance === 'production'
			? 'https://outreachdashboard.wmflabs.org/'
				: 'https://dashboard-testing.wikiedu.org/';
		$apiSecret = $this->options->get( 'CampaignEventsProgramsAndEventsDashboardAPISecret' );
		if ( !is_string( $apiSecret ) ) {
			throw new RuntimeException(
				'"CampaignEventsProgramsAndEventsDashboardAPISecret" must be configured in order to ' .
					' use the P&E Dashboard.'
			);
		}
		return [
			'display-name-msg' => 'campaignevents-tracking-tool-p&e-dashboard-name',
			'base-url' => $dashboardUrl,
			'class' => WikiEduDashboard::class,
			'db-id' => 1,
			'user-id' => 'wikimedia-pe-dashboard',
			'extra' => [
				'secret' => $apiSecret
			],
		];
	}

	/**
	 * Public version of getRegistry used in tests.
	 * @return array[]
	 * @codeCoverageIgnore
	 */
	public function getRegistryForTesting(): array {
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new RuntimeException( 'This method should only be used in tests' );
		}
		return $this->getRegistry();
	}

	/**
	 * Allows changing the internal registry in tests
	 * @param array $registry
	 * @codeCoverageIgnore
	 */
	public function setRegistryForTesting( array $registry ): void {
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new RuntimeException( 'This method should only be used in tests' );
		}
		$this->registryForTests = $registry;
	}

	/**
	 * Returns a TrackingTool subclass for a tool specified by its DB ID.
	 *
	 * @param int $dbID
	 * @return TrackingTool
	 * @throws ToolNotFoundException
	 */
	public function newFromDBID( int $dbID ): TrackingTool {
		foreach ( $this->getRegistry() as $entry ) {
			if ( $entry['db-id'] === $dbID ) {
				return $this->newFromRegistryEntry( $entry );
			}
		}
		throw new ToolNotFoundException( "No tool with DB ID $dbID" );
	}

	/**
	 * Returns data about known tracking tools that can be used to build the edit registration form. This is a subset
	 * of the internal registry, only including the 'display-name-msg' and 'user-id' keys. See documentation of
	 * getRegistry() for their purpose.
	 *
	 * @return array[]
	 * @phan-return list<array{display-name-msg:string,user-id:string}>
	 */
	public function getDataForForm(): array {
		$ret = [];
		foreach ( $this->getRegistry() as $entry ) {
			$ret[] = array_intersect_key( $entry, [ 'display-name-msg' => true, 'user-id' => true ] );
		}
		return $ret;
	}

	/**
	 * Returns a TrackingTool subclass for a tool specified by its user identifier.
	 *
	 * @param string $userIdentifier
	 * @return TrackingTool
	 * @throws ToolNotFoundException
	 */
	public function newFromUserIdentifier( string $userIdentifier ): TrackingTool {
		foreach ( $this->getRegistry() as $entry ) {
			if ( $entry['user-id'] === $userIdentifier ) {
				return $this->newFromRegistryEntry( $entry );
			}
		}
		throw new ToolNotFoundException( "No tool with user ID $userIdentifier" );
	}

	/**
	 * @param array $entry
	 * @phpcs:ignore Generic.Files.LineLength
	 * @phan-param array{display-name-msg:string,base-url:string,class:class-string,db-id:int,user-id:string,extra:array} $entry
	 * @return TrackingTool
	 */
	private function newFromRegistryEntry( array $entry ): TrackingTool {
		$class = $entry['class'];
		return new $class( $entry['db-id'], $entry['base-url'], $entry['extra'] );
	}
}
