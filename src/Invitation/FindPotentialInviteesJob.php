<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use GenericParameterJob;
use InvalidArgumentException;
use Job;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;

class FindPotentialInviteesJob extends Job implements GenericParameterJob {
	private Worklist $worklist;

	/**
	 * @inheritDoc
	 * @phan-param array{serialized-worklist:array} $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'CampaignEventsFindPotentialInvitees', $params );
		if ( !isset( $params['serialized-worklist'] ) ) {
			throw new InvalidArgumentException( "Worklist not specified" );
		}
		$this->worklist = Worklist::fromPlainArray( $params['serialized-worklist'] );
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		$finder = CampaignEventsServices::getPotentialInviteesFinder();
		$invitees = $finder->generate( $this->worklist );

		return true;
	}
}
