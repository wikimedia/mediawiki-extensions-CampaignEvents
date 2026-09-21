<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\DomainEvent;

use MediaWiki\DomainEvent\DomainEvent;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\User\UserIdentity;

class ParticipantRegisteredEvent extends DomainEvent {
	public const TYPE = 'ParticipantRegistered';

	public function __construct(
		private readonly ExistingEventRegistration $event,
		private readonly UserIdentity $participant,
		private readonly CentralUser $participantCentralUser,
		private readonly bool $isPrivateParticipant,
	) {
		parent::__construct();
		$this->declareEventType( self::TYPE );
	}

	public function getEvent(): ExistingEventRegistration {
		return $this->event;
	}

	public function getParticipant(): UserIdentity {
		return $this->participant;
	}

	public function getParticipantCentralUser(): CentralUser {
		return $this->participantCentralUser;
	}

	public function isPrivateParticipant(): bool {
		return $this->isPrivateParticipant;
	}
}
