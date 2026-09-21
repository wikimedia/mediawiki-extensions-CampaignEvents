<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\DomainEvent;

interface ParticipantRegisteredListener {
	public function handleParticipantRegisteredEvent( ParticipantRegisteredEvent $event ): void;
}
