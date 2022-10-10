<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\TrackingTool\Tool;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use StatusValue;

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

	/**
	 * @inheritDoc
	 */
	public function validateToolAddition(
		EventRegistration $event,
		array $organizers,
		string $toolEventID
	): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function addToEvent( EventRegistration $event, array $organizers, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validateToolRemoval( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function removeFromEvent( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validateEventDeletion( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function onEventDeleted( ExistingEventRegistration $event, string $toolEventID ): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validateParticipantAdded(
		ExistingEventRegistration $event,
		string $toolEventID,
		CentralUser $participant,
		bool $private
	): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function addParticipant(
		ExistingEventRegistration $event,
		string $toolEventID,
		CentralUser $participant,
		bool $private
	): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function validateParticipantsRemoved(
		ExistingEventRegistration $event,
		string $toolEventID,
		?array $participants,
		bool $invertSelection
	): StatusValue {
		return StatusValue::newGood();
	}

	/**
	 * @inheritDoc
	 */
	public function removeParticipants(
		ExistingEventRegistration $event,
		string $toolEventID,
		?array $participants,
		bool $invertSelection
	): StatusValue {
		return StatusValue::newGood();
	}
}
