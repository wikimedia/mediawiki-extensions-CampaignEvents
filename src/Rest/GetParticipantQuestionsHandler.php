<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Language\Language;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class GetParticipantQuestionsHandler extends Handler {
	private EventQuestionsRegistry $eventQuestionsRegistry;
	private IMessageFormatterFactory $messageFormatterFactory;
	private Language $contentLanguage;

	/**
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param Language $contentLanguage
	 */
	public function __construct(
		EventQuestionsRegistry $eventQuestionsRegistry,
		IMessageFormatterFactory $messageFormatterFactory,
		Language $contentLanguage
	) {
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->contentLanguage = $contentLanguage;
	}

	/**
	 * @return Response
	 */
	public function execute(): Response {
		$params = $this->getValidatedParams();
		$questionIDs = $params['question_ids'] ?? null;
		$questions = $this->eventQuestionsRegistry->getQuestionsForAPI( $questionIDs );

		// FIXME: use appropriate language when T269492 is resolved.
		$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $this->contentLanguage->getCode() );

		$response = [];
		foreach ( $questions as $questionID => $question ) {
			$curQuestionData = [
				'name' => $question['name'],
				'type' => $question['type'],
				'label' => $msgFormatter->format( MessageValue::new( $question['label-message'] ) ),
			];
			if ( isset( $question['options-messages'] ) ) {
				$curQuestionData['options'] = [];
				foreach ( $question['options-messages'] as $msgKey => $val ) {
					$optionText = $msgFormatter->format( MessageValue::new( $msgKey ) );
					$curQuestionData['options'][$optionText] = $val;
				}
			}
			if ( isset( $question['other-options'] ) ) {
				$curQuestionData['other-options'] = [];
				foreach ( $question['other-options'] as $showIfVal => $optionData ) {
					$optionRespData = [
						'type' => $optionData['type'],
						'label' => $msgFormatter->format( MessageValue::new( $optionData['label-message'] ) )
					];
					$curQuestionData['other-options'][$showIfVal] = $optionRespData;
				}
			}
			$response[$questionID] = $curQuestionData;
		}

		return $this->getResponseFactory()->createJson( $response );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return [
			'question_ids' => [
				static::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true
			],
		];
	}
}
