<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use IContextSource;
use Language;
use LogicException;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Organizers\Organizer;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswers;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use OOUI\IconWidget;
use OOUI\MessageWidget;
use OOUI\Tag;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

class ResponseStatisticsModule {

	private const MIN_ANSWERS_PER_QUESTION = 10;
	private const MIN_ANSWERS_PER_OPTION = 5;

	private ITextFormatter $msgFormatter;
	private ParticipantAnswersStore $answersStore;
	private EventAggregatedAnswersStore $aggregatedAnswersStore;
	private EventQuestionsRegistry $questionsRegistry;
	private ParticipantsStore $participantsStore;
	private FrontendModulesFactory $frontendModulesFactory;

	private Language $language;
	private ExistingEventRegistration $event;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param ParticipantAnswersStore $answersStore
	 * @param EventAggregatedAnswersStore $aggregatedAnswersStore
	 * @param EventQuestionsRegistry $questionsRegistry
	 * @param ParticipantsStore $participantsStore
	 * @param FrontendModulesFactory $frontendModulesFactory
	 * @param ExistingEventRegistration $event
	 * @param Language $language
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		ParticipantAnswersStore $answersStore,
		EventAggregatedAnswersStore $aggregatedAnswersStore,
		EventQuestionsRegistry $questionsRegistry,
		ParticipantsStore $participantsStore,
		FrontendModulesFactory $frontendModulesFactory,
		ExistingEventRegistration $event,
		Language $language
	) {
		$this->msgFormatter = $messageFormatterFactory->getTextFormatter( $language->getCode() );
		$this->answersStore = $answersStore;
		$this->aggregatedAnswersStore = $aggregatedAnswersStore;
		$this->questionsRegistry = $questionsRegistry;
		$this->participantsStore = $participantsStore;
		$this->frontendModulesFactory = $frontendModulesFactory;
		$this->language = $language;
		$this->event = $event;
	}

	public function createContent( Organizer $organizer, IContextSource $context, string $pageURL ): Tag {
		if ( !$this->event->isPast() ) {
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

		$formModule = $this->frontendModulesFactory->newClickwrapFormModule( $this->event, $this->language );
		$form = $formModule->createContent( $context, $pageURL );
		if ( $form['isSubmitted'] || $organizer->getClickwrapAcceptance() ) {
			return $this->makeContentWithAggregates();
		}

		return ( new Tag( 'div' ) )->appendContent( $form['content'] );
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
			$content = $this->makeAnswerTable( $questionID, $questionAggregates, $totalParticipants );
		}
		$content->addClasses( [ 'mw-collapsible-content' ] );
		$container->appendContent( $content );
		return $container;
	}

	/**
	 * @param int $questionID
	 * @param array<int,int> $questionAggregates
	 * @param int $totalParticipants
	 * @return Tag
	 */
	private function makeAnswerTable(
		int $questionID,
		array $questionAggregates,
		int $totalParticipants
	): Tag {
		$table = ( new Tag( 'table' ) )
			->addClasses( [ 'ext-campaignevents-details-stats-question-table' ] );

		$tableHeaderContents = [
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-details-stats-header-option' ) ),
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-details-stats-header-percentage' ) ),
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-details-stats-header-number' ) )
		];

		// Calculate the percentage corresponding to MIN_ANSWERS_PER_OPTION, used when the percentage is shown
		// as a range.
		$percentageThreshold = round( 100 * ( self::MIN_ANSWERS_PER_OPTION - 1 ) / $totalParticipants, 1 );
		$allOptions = $this->questionsRegistry->getQuestionOptionsForStats( $questionID );
		$tableCellContentByRow = [];
		// See https://www.mediawiki.org/wiki/Extension:CampaignEvents/Aggregating_participants%27_responses for
		// the formulas used here.
		$answersBelowThreshold = 0;
		$knownAnswersNum = 0;
		foreach ( $allOptions as $id => $msgKey ) {
			$rowElements = [
				$this->msgFormatter->format( MessageValue::new( $msgKey ) )
			];

			$numAnswers = $questionAggregates[$id] ?? 0;
			if ( $numAnswers < self::MIN_ANSWERS_PER_OPTION ) {
				$answersBelowThreshold++;
				$rowElements[] = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-details-stats-range-percentage' )
						->numParams( 0, $percentageThreshold )
				);
				$rowElements[] = $this->msgFormatter->format(
					MessageValue::new( 'campaignevents-details-stats-few-answers-option' )
						->numParams( self::MIN_ANSWERS_PER_OPTION )
				);
			} else {
				$knownAnswersNum += $numAnswers;
				$percentage = round( $numAnswers / $totalParticipants * 100, 1 );
				$rowElements[] = $this->msgFormatter->format(
					MessageValue::new( 'percent' )->numParams( $percentage )
				);
				$rowElements[] = $this->language->formatNum( $numAnswers );
			}
			$tableCellContentByRow[] = $rowElements;
		}

		$noResponseRowElements = [
			$this->msgFormatter->format( MessageValue::new( 'campaignevents-details-stats-no-response' ) )
		];
		$noResponseMin = max(
			$totalParticipants - $knownAnswersNum - ( self::MIN_ANSWERS_PER_OPTION - 1 ) * $answersBelowThreshold,
			0
		);
		$noResponseMax = $totalParticipants - $knownAnswersNum;
		if ( $noResponseMin === $noResponseMax ) {
			// No answers below threshold, we can just show this as a number.
			$noResponsePercentage = round( $noResponseMin / $totalParticipants * 100, 1 );
			$noResponseRowElements[] = $this->msgFormatter->format(
				MessageValue::new( 'percent' )->numParams( $noResponsePercentage )
			);
			$noResponseRowElements[] = $this->language->formatNum( $noResponseMin );
		} else {
			// Show it as a range.
			$noResponsePercentageMin = round( $noResponseMin / $totalParticipants * 100, 1 );
			$noResponsePercentageMax = round( $noResponseMax / $totalParticipants * 100, 1 );
			$noResponseRowElements[] = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-details-stats-range-percentage' )
					->numParams( $noResponsePercentageMin, $noResponsePercentageMax )
			);
			$noResponseRowElements[] = $this->msgFormatter->format(
				MessageValue::new( 'campaignevents-details-stats-range-number' )
					->numParams( $noResponseMin, $noResponseMax )
			);
		}

		$tableCellContentByRow[] = $noResponseRowElements;

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
