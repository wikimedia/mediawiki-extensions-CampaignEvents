<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MWTimestamp;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

abstract class ParticipantRegistrationHandlerBase extends SimpleHandler {
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
		try {
			$eventRegistration = $this->eventLookup->getEventByID( $id );
		} catch ( EventNotFoundException $_ ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-register-event-not-found' ),
				404
			);
		}

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
		return [
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
