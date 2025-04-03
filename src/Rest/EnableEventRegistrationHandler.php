<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use StatusValue;
use Wikimedia\Message\MessageValue;

class EnableEventRegistrationHandler extends AbstractEditEventRegistrationHandler {
	protected function getSuccessResponse( StatusValue $saveStatus ): Response {
		$id = $saveStatus->getValue();
		$respValue = [
			'id' => $id
		];
		foreach ( $saveStatus->getMessages( 'warning' ) as $msg ) {
			$respValue['warnings'] ??= [];
			// XXX There's no standard way to format warnings.
			$respValue['warnings'][] = [ 'key' => $msg->getKey(), 'params' => $msg->getParams() ];
		}
		$resp = $this->getResponseFactory()->createJson( $respValue );
		$resp->setStatus( 201 );
		$resp->setHeader( 'Location', $this->getRouter()->getRouteUrl( "/campaignevents/v0/event_registration/$id" ) );
		return $resp;
	}

	/**
	 * @inheritDoc
	 */
	protected function checkPermissions( Authority $performer ): void {
		if ( !$this->permissionChecker->userCanEnableRegistrations( $performer ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-enable-registration-permission-denied' ),
				403
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function createEventObject( array $body ): EventRegistration {
		$meetingType = 0;
		if ( $body['online_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_ONLINE;
		}
		if ( $body['inperson_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_IN_PERSON;
		}

		$participantQuestionNames = $this->eventQuestionsRegistry->getAvailableQuestionNames();

		$rawWikis = $body['wikis'] ?? [];
		$allWikis = $this->wikiLookup->getAllWikis();
		// Compare the counts, not the arrays, because order does not matter
		$wikis = count( $rawWikis ) === count( $allWikis ) ? EventRegistration::ALL_WIKIS : $rawWikis;

		return $this->eventFactory->newEvent(
			null,
			$body['event_page'],
			$body['chat_url'],
			$wikis,
			$body['topics'] ?? [],
			$body['tracking_tool_id'],
			$body['tracking_tool_event_id'],
			EventRegistration::STATUS_OPEN,
			$body['timezone'],
			$body['start_time'],
			$body['end_time'],
			// TODO MVP Get this from the request body
			EventRegistration::TYPE_GENERIC,
			$meetingType,
			$body['meeting_url'],
			$body['meeting_country'],
			$body['meeting_address'],
			$participantQuestionNames,
			null,
			null,
			null,
			$body['is_test_event']
		);
	}
}
