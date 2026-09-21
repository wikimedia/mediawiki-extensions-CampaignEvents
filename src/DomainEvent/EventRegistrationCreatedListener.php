<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\DomainEvent;

interface EventRegistrationCreatedListener {
	public function handleEventRegistrationCreatedEvent( EventRegistrationCreatedEvent $event ): void;
}
