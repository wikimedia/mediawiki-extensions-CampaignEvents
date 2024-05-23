<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\ParamValidator\TypeDef\UserDef;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class SetOrganizersHandler extends SimpleHandler {
	use EventIDParamTrait;
	use FailStatusUtilTrait;
	use TokenAwareHandlerTrait;

	private IEventLookup $eventLookup;
	private EditEventCommand $editEventCommand;

	/**
	 * @param IEventLookup $eventLookup
	 * @param EditEventCommand $editEventCommand
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EditEventCommand $editEventCommand
	) {
		$this->eventLookup = $eventLookup;
		$this->editEventCommand = $editEventCommand;
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @param ExistingEventRegistration $event
	 */
	private function validateEventWiki( ExistingEventRegistration $event ): void {
		$wikiID = $event->getPage()->getWikiId();
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-set-organizers-nonlocal-error-message' )
					->params( $wikiID ),
				400
			);
		}
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$this->validateEventWiki( $event );
		$body = $this->getValidatedBody() ?? [];
		$organizers = $body['organizer_usernames'];
		if ( !is_array( $organizers ) || !$organizers ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-set-organizers-empty-list' ),
				400
			);
		}

		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$saveStatus = $this->editEventCommand->doEditIfAllowed( $event, $performer, $organizers );
		// Note that no warnings (e.g., from tracking tools) are expected here
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
	public function getBodyParamSettings(): array {
		return [
			'organizer_usernames' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'user',
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name' ],
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_DEFAULT => [],
			],
		] + $this->getTokenParamDefinition();
	}

}
