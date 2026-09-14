<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Worklist\WorklistPagesSecondaryStore;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Message\MessageValue;
use Wikimedia\Timestamp\TimestampFormat as TS;

/**
 * GET endpoint listing when each article in an event's worklist was added.
 *
 * The worklist page records which articles belong to a worklist, but not when they were added;
 * that lives only in the secondary store. The card view therefore asks for it separately, once it
 * has the articles themselves, in the same way it fills in their short descriptions: the list is
 * what the reader is waiting for, and the dates can arrive after it.
 *
 * Dates are formatted here rather than in the client, so that they read exactly as they do in the
 * worklist table and no date-formatting module has to be loaded to render them. The unformatted
 * timestamp comes along for the `datetime` attribute of the element that shows them.
 */
class GetWorklistPagesMetadataHandler extends SimpleHandler {
	use EventIDParamTrait;

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly WorklistPagesSecondaryStore $worklistPagesSecondaryStore,
	) {
	}

	protected function run( int $eventID ): Response {
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		if ( $registration->getDeletionTimestamp() !== null ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-get-registration-deleted' ),
				404
			);
		}

		$context = RequestContext::getMain();
		$language = $context->getLanguage();
		$user = $this->getAuthority()->getUser();

		$respVal = [];
		foreach ( $this->worklistPagesSecondaryStore->getPagesMetadataForEvent( $eventID ) as $row ) {
			$respVal[] = [
				'wiki' => $row['wiki'],
				'title' => $row['prefixedtext'],
				'added' => $language->userTimeAndDate( $row['timestamp'], $user ),
				'added_at' => MWTimestamp::convert( TS::ISO_8601, $row['timestamp'] ),
			];
		}

		return $this->getResponseFactory()->createJson( [ 'pages' => $respVal ] );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
