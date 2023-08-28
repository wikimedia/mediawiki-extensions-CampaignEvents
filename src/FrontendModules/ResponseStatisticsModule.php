<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use Language;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswers;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use MediaWiki\Utils\MWTimestamp;
use OOUI\IconWidget;
use OOUI\MessageWidget;
use OOUI\Tag;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class ResponseStatisticsModule {

	private const MIN_ANSWERS_PER_QUESTION = 10;
	private const MIN_ANSWERS_PER_OPTION = 5;

	private ParticipantAnswersStore $answersStore;
	private EventAggregatedAnswersStore $aggregatedAnswersStore;
	private EventQuestionsRegistry $questionsRegistry;
	private ParticipantsStore $participantsStore;
	private ITextFormatter $msgFormatter;

	private Language $language;
	private ExistingEventRegistration $event;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param ParticipantAnswersStore $answersStore
	 * @param EventAggregatedAnswersStore $aggregatedAnswersStore
	 * @param EventQuestionsRegistry $questionsRegistry
	 * @param ParticipantsStore $participantsStore
	 * @param ExistingEventRegistration $event
	 * @param Language $language
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		ParticipantAnswersStore $answersStore,
		EventAggregatedAnswersStore $aggregatedAnswersStore,
		EventQuestionsRegistry $questionsRegistry,
		ParticipantsStore $participantsStore,
		ExistingEventRegistration $event,
		Language $language
	) {
		$this->answersStore = $answersStore;
		$this->aggregatedAnswersStore = $aggregatedAnswersStore;
		$this->questionsRegistry = $questionsRegistry;
		$this->participantsStore = $participantsStore;
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
		$this->language = $language;
		$this->event = $event;
	}

	public function createContent(): Tag {
		$eventEndUnix = (int)wfTimestamp( TS_UNIX, $this->event->getEndUTCTimestamp() );
		$eventHasEnded = $eventEndUnix < (int)MWTimestamp::now( TS_UNIX );
		if ( !$eventHasEnded ) {
			throw new LogicException( __METHOD__ . ' called for event that has not ended' );
		}

		$eventID = $this->event->getID();
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

		return $this->makeContentWithAggregates();
	}

	private function makeContentWithAggregates(): Tag {
		$aggregates = $this->aggregatedAnswersStore->getEventAggregatedAnswers( $this->event->getID() );
		$totalParticipants = $this->participantsStore->getFullParticipantCountForEvent( $this->event->getID() );
		$eventQuestions = $this->event->getParticipantQuestions();
		$container = new Tag( 'div' );
		foreach ( $eventQuestions as $questionID ) {
			$container->appendContent( $this->makeQuestionSection( $questionID, $aggregates, $totalParticipants ) );
		}
		return $container;
	}

	private function makeQuestionSection(
		int $questionID,
		EventAggregatedAnswers $aggregates,
		int $totalParticipants
	): Tag {
		// TODO: Use accordion component when available (see T145934 and T338184)
		$container = ( new Tag( 'div' ) )
			->addClasses( [
				'ext-campaignevents-details-stats-question-container',
				'mw-collapsible',
				'mw-collapsed',
			] );

		$labelMsgKey = $this->questionsRegistry->getQuestionLabelForStats( $questionID );
		$header = ( new Tag( 'div' ) )
			->addClasses( [ 'mw-collapsible-toggle' ] )
			->setAttributes( [ 'role' => 'button' ] )
			->appendContent(
				new IconWidget( [
					'icon' => 'expand',
					'label' => $this->msgFormatter->format( MessageValue::new( 'collapsible-expand' ) ),
				] ),
				new IconWidget( [
					'icon' => 'collapse',
					'label' => $this->msgFormatter->format( MessageValue::new( 'collapsible-collapse' ) ),
				] ),
				( new Tag( 'h3' ) )->appendContent( $this->msgFormatter->format( MessageValue::new( $labelMsgKey ) ) )
			);
		$container->appendContent( $header );

		$questionAggregates = $aggregates->getQuestionData( $questionID );
		$totalAnswers = array_sum( $questionAggregates );
		if ( $totalAnswers < self::MIN_ANSWERS_PER_QUESTION ) {
			$notice = new MessageWidget( [
				'type' => 'notice',
				'label' => $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-details-stats-not-enough-answers' )
						->numParams( self::MIN_ANSWERS_PER_QUESTION )
				),
				'inline' => true
			] );
			$content = $notice;
		} else {
			$content = $this->makeAnswerTable( $questionID, $questionAggregates, $totalAnswers, $totalParticipants );
		}
		$content->addClasses( [ 'mw-collapsible-content' ] );
		$container->appendContent( $content );
		return $container;
	}

	/**
	 * @param int $questionID
	 * @param array<int,int> $questionAggregates
	 * @param int $totalAnswers
	 * @param int $totalParticipants
	 * @return Tag
	 */
	private function makeAnswerTable(
		int $questionID,
		array $questionAggregates,
		int $totalAnswers,
		int $totalParticipants
	): Tag {
		$table = ( new Tag( 'table' ) )
			->addClasses( [ 'ext-campaignevents-details-stats-question-table' ] );

		$tableHeaderContents = [
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-details-stats-header-option' ) ),
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-details-stats-header-percentage' ) ),
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-details-stats-header-number' ) )
		];

		$allOptions = $this->questionsRegistry->getQuestionOptionsForStats( $questionID );
		$tableCellContentByRow = [];
		$canShowPercentages = true;
		foreach ( $allOptions as $id => $msgKey ) {
			$rowElements = [
				$this->msgFormatter->format( MessageValue::new( $msgKey ) )
			];

			$numAnswers = $questionAggregates[$id] ?? 0;
			// Note, we're always adding the percentage here, but will remove it later if we find at least 1 option
			// that doesn't have enough answers.
			$percentage = round( $numAnswers / $totalParticipants * 100, 1 );
			$rowElements[] = $this->msgFormatter->format(
				MessageValue::new( 'percent' )->numParams( $percentage )
			);
			if ( $numAnswers < self::MIN_ANSWERS_PER_OPTION ) {
				$canShowPercentages = false;
				$rowElements[] = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-details-stats-few-answers-option' )
						->numParams( self::MIN_ANSWERS_PER_OPTION )
				);
			} else {
				$rowElements[] = $this->language->formatNum( $numAnswers );
			}
			$tableCellContentByRow[] = $rowElements;
		}
		$noResponseNum = $totalParticipants - $totalAnswers;
		$noResponsePercentage = round( $noResponseNum / $totalParticipants * 100, 1 );
		$tableCellContentByRow[] = [
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-details-stats-no-response' ) ),
			$this->msgFormatter->format(
				MessageValue::new( 'percent' )->numParams( $noResponsePercentage )
			),
			$this->language->formatNum( $noResponseNum )
		];

		if ( !$canShowPercentages ) {
			// Remove percentages from the table header and from each row
			unset( $tableHeaderContents[1] );
			array_walk( $tableCellContentByRow, static function ( &$rowContent ) {
				unset( $rowContent[1] );
			} );
		}

		$tableHeader = new Tag( 'thead' );
		$tableHeaderRow = new Tag( 'tr' );
		foreach ( $tableHeaderContents as $header ) {
			$tableHeaderRow->appendContent( ( new Tag( 'th' ) )->appendContent( $header ) );
		}
		$tableHeader->appendContent( $tableHeaderRow );
		$table->appendContent( $tableHeader );

		foreach ( $tableCellContentByRow as $rowContent ) {
			$row = new Tag( 'tr' );
			foreach ( $rowContent as $cellContent ) {
				$row->appendContent( ( new Tag( 'td' ) )->appendContent( $cellContent ) );
			}
			$table->appendContent( $row );
		}
		return $table;
	}
}
