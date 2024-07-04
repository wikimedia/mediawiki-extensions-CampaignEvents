<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use Exception;

class InvitationListNotFoundException extends Exception {
	public function __construct( int $listID ) {
		parent::__construct( "Invitation list $listID not found." );
	}
}
