<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool\Tool;

/**
 * This class implements the WikiEduDashboard software as a tracking tool.
 */
class WikiEduDashboard extends TrackingTool {
	/** @var string */
	private $apiSecret;

	/**
	 * @inheritDoc
	 */
	public function __construct( int $dbID, string $baseURL, array $extra ) {
		parent::__construct( $dbID, $baseURL, $extra );
		$this->apiSecret = $extra['secret'];
	}
}
