<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use SpecialPage;

class SpecialEventRegistration extends SpecialPage {
	public const PAGE_NAME = 'EventRegistration';

	public function __construct() {
		parent::__construct( self::PAGE_NAME );
	}
}
