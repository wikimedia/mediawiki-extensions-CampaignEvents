<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MWTimestamp;
use Wikimedia\Message\MessageValue;

abstract class ParticipantRegistrationHandlerBase extends SimpleHandler {
	use EventIDParamTrait;

	/** @var IEventLookup */
	private $eventLookup;

	/**
	 * @param IEventLookup $eventLookup
	 */
	public function __construct( IEventLookup $eventLookup ) {
		$this->eventLookup = $eventLookup;
	}

	/**
	 * @param int $id
	 * @throws LocalizedHttpException
	 */
	protected function validateEventWithID( int $id ): void {
		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $id );
		$endTS = $eventRegistration->getEndTimestamp();
		if ( (int)$endTS < (int)MWTimestamp::now( TS_UNIX ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-register-event-past' ),
				400
			);
		}

		$this->doAdditionalEventValidation( $eventRegistration );
	}

	/**
	 * @param EventRegistration $eventRegistration
	 */
	protected function doAdditionalEventValidation( EventRegistration $eventRegistration ): void {
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
