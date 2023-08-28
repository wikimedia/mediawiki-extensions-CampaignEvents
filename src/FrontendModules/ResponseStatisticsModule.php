<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use MediaWiki\Utils\MWTimestamp;
use OOUI\MessageWidget;
use OOUI\Tag;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class ResponseStatisticsModule {

	private ParticipantAnswersStore $answersStore;
	private EventAggregatedAnswersStore $aggregatedAnswersStore;
	private ITextFormatter $msgFormatter;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param ParticipantAnswersStore $answersStore
	 * @param EventAggregatedAnswersStore $aggregatedAnswersStore
	 * @param Language $language
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		ParticipantAnswersStore $answersStore,
		EventAggregatedAnswersStore $aggregatedAnswersStore,
		Language $language
	) {
		$this->answersStore = $answersStore;
		$this->aggregatedAnswersStore = $aggregatedAnswersStore;
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
	}

	public function createContent( ExistingEventRegistration $event ): Tag {
		$eventEndUnix = (int)wfTimestamp( TS_UNIX, $event->getEndUTCTimestamp() );
		$eventHasEnded = $eventEndUnix < (int)MWTimestamp::now( TS_UNIX );
		if ( !$eventHasEnded ) {
			throw new LogicException( __METHOD__ . ' called for event that has not ended' );
		}

		$eventID = $event->getID();
		$hasAnswers = $this->answersStore->eventHasAnswers( $eventID );
		$hasAggregates = $this->aggregatedAnswersStore->eventHasAggregates( $eventID );
		if ( !$hasAnswers && !$hasAggregates ) {
			// No participants ever answered any questions.
			return new MessageWidget( [
				'type' => 'notice',
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-details-stats-no-responses' )
				),
				'inline' => true
			] );
		}

		if ( $hasAnswers ) {
			// Probably a recently finished event, we still have to aggregate the responses
			return new MessageWidget( [
				'type' => 'notice',
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-details-stats-not-ready' )
				),
				'inline' => true
			] );
		}

		// TODO Replace placeholder with actual data
		return ( new Tag( 'p' ) )->appendContent( 'Nothing to see here' );
	}
}
