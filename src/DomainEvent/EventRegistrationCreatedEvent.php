<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\DomainEvent;

use MediaWiki\DomainEvent\DomainEvent;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Permissions\Authority;

class EventRegistrationCreatedEvent extends DomainEvent {
	public const TYPE = 'EventRegistrationCreated';

	public function __construct(
		private readonly EventRegistration $event,
		private readonly Authority $performer,
	) {
		parent::__construct();
		$this->declareEventType( self::TYPE );
	}

	public function getEvent(): EventRegistration {
		return $this->event;
	}

	public function getPerformer(): Authority {
		return $this->performer;
	}
}
