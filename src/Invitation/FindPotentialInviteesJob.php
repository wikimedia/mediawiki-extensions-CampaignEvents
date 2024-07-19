<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Invitation;

use GenericParameterJob;
use InvalidArgumentException;
use Job;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;

class FindPotentialInviteesJob extends Job implements GenericParameterJob {
	private int $listID;
	private Worklist $worklist;

	/**
	 * @inheritDoc
	 * @phan-param array{list-id:int,serialized-worklist:array} $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'CampaignEventsFindPotentialInvitees', $params );
		$missingParams = array_diff( [ 'list-id', 'serialized-worklist' ], array_keys( $params ) );
		if ( $missingParams ) {
			throw new InvalidArgumentException( "Missing parameters: " . implode( ', ', $missingParams ) );
		}
		$this->listID = $params['list-id'];
		$this->worklist = Worklist::fromPlainArray( $params['serialized-worklist'] );
	}

	/**
	 * @inheritDoc
	 */
	public function run(): bool {
		$finder = CampaignEventsServices::getPotentialInviteesFinder();
		$inviteesByName = $finder->generate( $this->worklist );

		$centralUserLookup = CampaignEventsServices::getCentralUserLookup();
		$usernameMap = array_fill_keys( array_keys( $inviteesByName ), null );
		// Note: we are potentially dropping users without a global account here. That shouldn't
		// really happen in practice though.
		$nameToIDMap = array_filter(
			$centralUserLookup->getIDs( $usernameMap ),
			static fn ( $id ) => $id !== null
		);
		$centralInviteesByName = array_intersect_key( $inviteesByName, $nameToIDMap );
		$inviteesByID = [];
		foreach ( $centralInviteesByName as $username => $score ) {
			$inviteesByID[$nameToIDMap[$username]] = $score;
		}

		$invitationListStore = CampaignEventsServices::getInvitationListStore();
		if ( $inviteesByID ) {
			$invitationListStore->storeInvitationListUsers( $this->listID, $inviteesByID );
		}
		$invitationListStore->updateStatus( $this->listID, InvitationList::STATUS_READY );

		return true;
	}
}
