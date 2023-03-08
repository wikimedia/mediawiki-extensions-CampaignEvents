<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use Config;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class SetOrganizersHandler extends SimpleHandler {
	use EventIDParamTrait;
	use FailStatusUtilTrait;
	use TokenAwareHandlerTrait;

	/** @var IEventLookup */
	private IEventLookup $eventLookup;
	/** @var EditEventCommand */
	private EditEventCommand $editEventCommand;
	/** @var bool */
	private bool $endpointEnabled;

	/**
	 * @param IEventLookup $eventLookup
	 * @param EditEventCommand $editEventCommand
	 * @param Config $config
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EditEventCommand $editEventCommand,
		Config $config
	) {
		$this->eventLookup = $eventLookup;
		$this->editEventCommand = $editEventCommand;
		$this->endpointEnabled = $config->get( 'CampaignEventsEnableMultipleOrganizers' );
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		if ( !$this->endpointEnabled ) {
			throw new HttpException(
				// No need to localize this, since the feature flag is temporary.
				'This endpoint is not enabled on this wiki.',
				421
			);
		}

		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$body = $this->getValidatedBody();
		$organizers = $body['organizer_usernames'];
		if ( !is_array( $organizers ) || !$organizers ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-set-organizers-empty-list' ),
				400
			);
		}

		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$saveStatus = $this->editEventCommand->doEditIfAllowed( $event, $performer, $organizers );
		if ( !$saveStatus->isGood() ) {
			$httptStatus = $saveStatus instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $saveStatus, $httptStatus );
		}

		return $this->getResponseFactory()->createNoContent();
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ): BodyValidator {
		if ( $contentType !== 'application/json' ) {
			return new UnsupportedContentTypeBodyValidator( $contentType );
		}

		return new JsonBodyValidator( [
			// NOTE: The param types are not validated yet, see T305973
			'organizer_usernames' => [
				ParamValidator::PARAM_REQUIRED => true,
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_DEFAULT => [],
			],
		] + $this->getTokenParamDefinition() );
	}

}
