<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use BadMethodCallException;
use LogicException;
use UnexpectedValueException;

class EventQuestionsRegistry {
	public const SERVICE_NAME = 'CampaignEventsEventQuestionsRegistry';

	public const RADIO_BUTTON_QUESTION_TYPE = 'radio';
	public const SELECT_QUESTION_TYPE = 'select';
	public const FREE_TEXT_QUESTION_TYPE = 'text';

	public const MULTIPLE_CHOICE_TYPES = [
		self::RADIO_BUTTON_QUESTION_TYPE,
		self::SELECT_QUESTION_TYPE
	];

	/**
	 * @var bool Determines whether Wikimedia-specific questions should be shown. In the future, this might be
	 * replaced with a hook for adding custom questions.
	 */
	private bool $wikimediaQuestionsEnabled;

	/** @var array|null Question overrides used in test. Null means no override. */
	private ?array $testOverrides = null;

	/**
	 * @param bool $wikimediaQuestionsEnabled
	 */
	public function __construct( bool $wikimediaQuestionsEnabled ) {
		$this->wikimediaQuestionsEnabled = $wikimediaQuestionsEnabled;
	}

	/**
	 * Returns the internal registry with all the question. Each entry in the registry must have the following
	 * properties:
	 *  - name (string): Readable identifier of the question, can be used in forms and APIs (potentially prefixed)
	 *  - db-id (integer): Identifier of the question in the database
	 *  - wikimedia (bool): Whether the question is Wikimedia-specific
	 *  - pii (bool): Whether the question is considered PII
	 *  - stats-label-message (string): Key of a message that should be used when introducing the response statistics
	 *      for this question.
	 *  - non-pii-label-message (string): Key of a message that should be used when introducing the response of
	 *      non-pii responses
	 *  - questionData (array): User-facing properties of the question, with the following keys:
	 *    - type (string, required): Type of the question, must be one of the self::*_QUESTION_TYPE constants
	 *    - label-message (string, required): i18n key for the question label
	 *    - options-messages (array, optional): For multiple-choice questions, the list of possible answers.
	 *      NOTE: For multipe-choice questions, the option 0 must be a placeholder value to let the
	 *      user skip the question.
	 *  - otherOptions (array): List of fields to show conditionally if the parent field has a certain value. Currently,
	 *    this has the following requirements:
	 *      - the parent question must be a multiple-choice question
	 *      - the key of each 'other' element must correspond to the value that the parent question should have in order
	 *        for the 'other' field to be shown
	 *      - the 'other' field must be a free-text field
	 *    Each 'other' option has the following keys:
	 *      - type (string, required): Must be set to self::FREE_TEXT_QUESTION_TYPE, and is only required for robustness
	 *      - placeholder-message (string, required): Placeholder for the text field, required in place of the label
	 * @return array[]
	 */
	private function getQuestions(): array {
		if ( $this->testOverrides !== null ) {
			return $this->testOverrides;
		}

		$questions = [
			[
				'name' => 'gender',
				'db-id' => 1,
				'wikimedia' => false,
				'pii' => true,
				'stats-label-message' => 'campaignevents-register-question-gender-stats-label',
				'organizer-label-message' => 'campaignevents-register-question-gender-organizer-label',
				'questionData' => [
					'type' => self::RADIO_BUTTON_QUESTION_TYPE,
					'label-message' => 'campaignevents-register-question-gender',
					'options-messages' => [
						'campaignevents-register-question-gender-option-not-say' => 0,
						'campaignevents-register-question-gender-option-man' => 1,
						'campaignevents-register-question-gender-option-woman' => 2,
						'campaignevents-register-question-gender-option-agender' => 3,
						'campaignevents-register-question-gender-option-nonbinary' => 4,
						'campaignevents-register-question-gender-option-other' => 5,
					],
				],
			],
			[
				'name' => 'age',
				'db-id' => 2,
				'wikimedia' => false,
				'pii' => true,
				'stats-label-message' => 'campaignevents-register-question-age-stats-label',
				'organizer-label-message' => 'campaignevents-register-question-age-organizer-label',
				'questionData' => [
					'type' => self::SELECT_QUESTION_TYPE,
					'label-message' => 'campaignevents-register-question-age',
					'options-messages' => [
						'campaignevents-register-question-age-placeholder' => 0,
						'campaignevents-register-question-age-option-under-25' => 1,
						'campaignevents-register-question-age-option-25-34' => 2,
						'campaignevents-register-question-age-option-35-44' => 3,
						'campaignevents-register-question-age-option-45-54' => 4,
						'campaignevents-register-question-age-option-55-64' => 5,
						'campaignevents-register-question-age-option-65-74' => 6,
						'campaignevents-register-question-age-option-75-84' => 7,
						'campaignevents-register-question-age-option-85-plus' => 8,
					],
				],
			],
			[
				'name' => 'profession',
				'db-id' => 3,
				'wikimedia' => false,
				'pii' => true,
				'stats-label-message' => 'campaignevents-register-question-profession-stats-label',
				'organizer-label-message' => 'campaignevents-register-question-profession-organizer-label',
				'questionData' => [
					'type' => self::SELECT_QUESTION_TYPE,
					'label-message' => 'campaignevents-register-question-profession',
					'options-messages' => [
						'campaignevents-register-question-profession-placeholder' => 0,
						'campaignevents-register-question-profession-option-artist-creative' => 1,
						'campaignevents-register-question-profession-option-educator' => 2,
						'campaignevents-register-question-profession-option-librarian' => 3,
						'campaignevents-register-question-profession-option-mass-media' => 4,
						'campaignevents-register-question-profession-option-museum-archive' => 5,
						'campaignevents-register-question-profession-option-nonprofit' => 6,
						'campaignevents-register-question-profession-option-researcher' => 7,
						'campaignevents-register-question-profession-option-software-engineer' => 8,
						'campaignevents-register-question-profession-option-student' => 9,
						'campaignevents-register-question-profession-option-other' => 10,
					],
				],
			],
			[
				'name' => 'confidence',
				'db-id' => 4,
				'wikimedia' => true,
				'pii' => false,
				'stats-label-message' => 'campaignevents-register-question-confidence-stats-label',
				'non-pii-label-message' => 'campaignevents-individual-stats-label-message-confidence',
				'organizer-label-message' => 'campaignevents-register-question-confidence-organizer-label',
				'questionData' => [
					'type' => self::RADIO_BUTTON_QUESTION_TYPE,
					'label-message' => 'campaignevents-register-question-confidence-contributing',
					'options-messages' => [
						'campaignevents-register-question-confidence-contributing-not-say' => 0,
						'campaignevents-register-question-confidence-contributing-option-none' => 1,
						'campaignevents-register-question-confidence-contributing-option-some-not-confident' => 2,
						'campaignevents-register-question-confidence-contributing-option-some-confident' => 3,
						'campaignevents-register-question-confidence-contributing-option-confident' => 4,
					],
				],
			],
			[
				'name' => 'affiliate',
				'db-id' => 5,
				'wikimedia' => true,
				'pii' => false,
				'stats-label-message' => 'campaignevents-register-question-affiliate-stats-label',
				'non-pii-label-message' => 'campaignevents-individual-stats-label-message-affiliate',
				'organizer-label-message' => 'campaignevents-register-question-affiliate-organizer-label',
				'questionData' => [
					'type' => self::SELECT_QUESTION_TYPE,
					'label-message' => 'campaignevents-register-question-affiliate',
					'options-messages' => [
						'campaignevents-register-question-affiliate-placeholder' => 0,
						'campaignevents-register-question-affiliate-option-affiliate' => 1,
						'campaignevents-register-question-affiliate-option-none' => 2,
					],
				],
				'otherOptions' => [
					1 => [
						'type' => self::FREE_TEXT_QUESTION_TYPE,
						'placeholder-message' => 'campaignevents-register-question-affiliate-details-placeholder',
					],
				],
			],
		];

		return array_filter(
			$questions,
			fn ( array $question ): bool => !$question['wikimedia'] || $this->wikimediaQuestionsEnabled
		);
	}

	/**
	 * @codeCoverageIgnore
	 * @return array[]
	 */
	public function getQuestionsForTesting(): array {
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new BadMethodCallException( 'This method can only be used in tests' );
		}
		return $this->getQuestions();
	}

	/**
	 * @codeCoverageIgnore
	 * @param array[] $questions
	 */
	public function overrideQuestionsForTesting( array $questions ): void {
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new BadMethodCallException( 'This method can only be used in tests' );
		}
		$this->testOverrides = $questions;
	}

	/**
	 * Returns the questions corresponding to the given question database IDs, in the format accepted by HTMLForm.
	 * Each "child" question is given the CSS class `ext-campaignevents-participant-question-other-option`, which can
	 * be used to style the child field.
	 *
	 * @note This method ignores any IDs not corresponding to known questions.
	 *
	 * @param int[] $questionIDs
	 * @param Answer[] $curAnswers Current user answers, used for the default values
	 * @return array[]
	 */
	public function getQuestionsForHTMLForm( array $questionIDs, array $curAnswers ): array {
		$curAnswersByID = [];
		foreach ( $curAnswers as $answer ) {
			$curAnswersByID[$answer->getQuestionDBID()] = $answer;
		}
		$fields = [];
		foreach ( $this->getQuestions() as $question ) {
			$questionID = $question['db-id'];
			if ( !in_array( $questionID, $questionIDs, true ) ) {
				continue;
			}
			$curAnswer = $curAnswersByID[$questionID] ?? null;
			$fieldName = 'Question' . ucfirst( $question['name'] );
			$fields[$fieldName] = $question[ 'questionData' ];
			if ( $curAnswer ) {
				$type = $question['questionData']['type'];
				if ( $type === self::FREE_TEXT_QUESTION_TYPE ) {
					$default = $curAnswer->getText();
				} elseif ( in_array( $type, self::MULTIPLE_CHOICE_TYPES, true ) ) {
					$default = $curAnswer->getOption();
				} else {
					throw new UnexpectedValueException( "Unhandled question type $type" );
				}
				$fields[$fieldName]['default'] = $default;
			}
			foreach ( $question[ 'otherOptions' ] ?? [] as $showIfVal => $optionData ) {
				$optionName = $fieldName . '_Other' . '_' . $showIfVal;
				$optionData['hide-if'] = [ '!==', $fieldName, (string)$showIfVal ];
				$optionData['cssclass'] = 'ext-campaignevents-participant-question-other-option';
				$fields[$optionName] = $optionData;
				if ( $curAnswer && $curAnswer->getOption() === $showIfVal ) {
					$fields[$optionName]['default'] = $curAnswer->getText();
				}
			}
		}
		return $fields;
	}

	/**
	 * Parses an array of form field values from an HTMLForm that was built using getQuestionsForHTMLForm(),
	 * and returns an array of answers to store.
	 *
	 * @param array $formData As given by HTMLForm
	 * @param int[] $enabledQuestionIDs Enabled question for the event, should match the value passed to
	 *   getQuestionsForHTMLForm().
	 * @return Answer[]
	 * @throws InvalidAnswerDataException
	 */
	public function extractUserAnswersHTMLForm( array $formData, array $enabledQuestionIDs ): array {
		$answers = [];
		foreach ( $this->getQuestions() as $question ) {
			if ( !in_array( $question['db-id'], $enabledQuestionIDs, true ) ) {
				continue;
			}
			$answer = $this->newAnswerFromHTMLForm( $question, $formData );
			if ( $answer ) {
				$answers[] = $answer;
			}
		}
		return $answers;
	}

	/**
	 * @param array $questionSpec Must be an entry in the registry
	 * @param array $formData
	 * @return Answer|null
	 * @throws InvalidAnswerDataException
	 */
	private function newAnswerFromHTMLForm( array $questionSpec, array $formData ): ?Answer {
		$fieldName = 'Question' . ucfirst( $questionSpec['name'] );
		if ( !isset( $formData[$fieldName] ) ) {
			return null;
		}
		$type = $questionSpec['questionData']['type'];
		$ansValue = $formData[$fieldName];
		if ( $this->isPlaceholderValue( $type, $ansValue ) ) {
			return null;
		}
		if ( $type === self::FREE_TEXT_QUESTION_TYPE ) {
			if ( !is_string( $ansValue ) ) {
				throw new InvalidAnswerDataException( $fieldName );
			}
			$ansOption = null;
			$ansText = $ansValue;
		} elseif ( in_array( $type, self::MULTIPLE_CHOICE_TYPES, true ) ) {
			// Note, HTMLForm always uses strings for field values
			if ( !is_numeric( $ansValue ) ) {
				throw new InvalidAnswerDataException( $fieldName );
			}
			$ansValue = (int)$ansValue;
			if ( !in_array( $ansValue, $questionSpec['questionData']['options-messages'], true ) ) {
				throw new InvalidAnswerDataException( $fieldName );
			}
			$ansOption = $ansValue;
			$ansText = null;
			$otherOptionName = $fieldName . '_Other' . '_' . $ansValue;
			if ( isset( $questionSpec['otherOptions'][$ansValue] ) && isset( $formData[$otherOptionName] ) ) {
				$answerOther = $formData[$otherOptionName];
				if ( $answerOther !== '' ) {
					if ( !is_string( $answerOther ) ) {
						throw new InvalidAnswerDataException( $otherOptionName );
					}
					$ansText = $answerOther;
				}
			}
		} else {
			throw new UnexpectedValueException( "Unhandled question type $type" );
		}

		return new Answer( $questionSpec['db-id'], $ansOption, $ansText );
	}

	/**
	 * @param string $questionType
	 * @param mixed $value
	 * @return bool
	 */
	private function isPlaceholderValue( string $questionType, $value ): bool {
		switch ( $questionType ) {
			case self::RADIO_BUTTON_QUESTION_TYPE:
			case self::SELECT_QUESTION_TYPE:
				return $value === 0 || $value === '0';
			case self::FREE_TEXT_QUESTION_TYPE:
				return $value === '';
			default:
				throw new LogicException( 'Unhandled question type' );
		}
	}

	/**
	 * Returns the questions corresponding to the given question database IDs, in a format suitable for the API.
	 *
	 * @note This method ignores any IDs not corresponding to known questions.
	 *
	 * @param int[]|null $questionIDs Only include questions with these IDs, or null to include all questions
	 * @return array[] The keys are question IDs, and the values are arrays with the following keys: `name`, `type`,
	 *   `label-message`. `options-messages` is also present if the question is multiple choice. `other-option` is
	 *    also set if the question has additional options. This is an array where the keys are possible values for
	 *    the parent question, and the values are arrays with `type` and `label-message`.
	 */
	public function getQuestionsForAPI( array $questionIDs = null ): array {
		$ret = [];
		foreach ( $this->getQuestions() as $question ) {
			$questionID = $question['db-id'];
			if ( $questionIDs !== null && !in_array( $questionID, $questionIDs, true ) ) {
				continue;
			}
			$questionData = $question['questionData'];
			$ret[$questionID] = [
				'name' => $question['name'],
				'type' => $questionData['type'],
				'label-message' => $questionData['label-message'],
			];
			if ( isset( $questionData['options-messages'] ) ) {
				$ret[$questionID]['options-messages'] = $questionData['options-messages'];
			}
			$otherOptions = [];
			foreach ( $question[ 'otherOptions' ] ?? [] as $showIfVal => $optionData ) {
				$otherOptions[$showIfVal] = [
					'type' => $optionData['type'],
					'label-message' => $optionData['placeholder-message'],
				];
			}
			if ( $otherOptions ) {
				$ret[$questionID]['other-options'] = $otherOptions;
			}
		}
		return $ret;
	}

	/**
	 * Formats a list of answers for use in API (GET) requests. This is the same format expected by
	 * extractUserQuestionsAPI().
	 *
	 * @param Answer[] $answers
	 * @return array[]
	 */
	public function formatAnswersForAPI( array $answers ): array {
		$answersByID = [];
		foreach ( $answers as $answer ) {
			$answersByID[$answer->getQuestionDBID()] = $answer;
		}
		$ret = [];
		foreach ( $this->getQuestions() as $question ) {
			$questionID = $question['db-id'];
			if ( !isset( $answersByID[$questionID] ) ) {
				continue;
			}
			$answer = $answersByID[$questionID];
			$option = $answer->getOption();
			$formattedAnswer = [
				'value' => $option,
			];
			if ( $answer->getText() !== null ) {
				$formattedAnswer['other'] = $answer->getText();
			}
			$ret[$question['name']] = $formattedAnswer;
		}
		return $ret;
	}

	/**
	 * Extracts user answers given in the API format returned by formatAnswersForAPI().
	 *
	 * @param array[] $data
	 * @param int[] $enabledQuestionIDs
	 * @return Answer[]
	 * @throws InvalidAnswerDataException If an answer's value is malformed
	 */
	public function extractUserAnswersAPI( array $data, array $enabledQuestionIDs ): array {
		$answers = [];
		foreach ( $this->getQuestions() as $question ) {
			if ( !in_array( $question['db-id'], $enabledQuestionIDs, true ) ) {
				continue;
			}
			$questionName = $question['name'];
			if ( !isset( $data[$questionName] ) ) {
				continue;
			}
			$answer = $this->newAnswerFromAPI( $question, $questionName, $data[$questionName] );
			if ( $answer ) {
				$answers[] = $answer;
			}
		}
		return $answers;
	}

	/**
	 * @param array $questionSpec Must be an entry in the registry
	 * @param string $questionName
	 * @param array $answerData
	 * @return Answer|null
	 * @throws InvalidAnswerDataException If an answer's value is malformed
	 */
	private function newAnswerFromAPI( array $questionSpec, string $questionName, array $answerData ): ?Answer {
		$type = $questionSpec['questionData']['type'];
		$ansValue = $answerData['value'];
		if ( $this->isPlaceholderValue( $type, $ansValue ) ) {
			return null;
		}
		if ( $type === self::FREE_TEXT_QUESTION_TYPE ) {
			if ( !is_string( $ansValue ) ) {
				throw new InvalidAnswerDataException( $questionName );
			}
			$ansOption = null;
			$ansText = $ansValue;
		} elseif ( in_array( $type, self::MULTIPLE_CHOICE_TYPES, true ) ) {
			if ( !is_int( $ansValue ) ) {
				throw new InvalidAnswerDataException( $questionName );
			}
			if ( !in_array( $ansValue, $questionSpec['questionData']['options-messages'], true ) ) {
				throw new InvalidAnswerDataException( $questionName );
			}
			$ansOption = $ansValue;
			$ansText = null;
			if ( isset( $questionSpec['otherOptions'][$ansValue] ) && isset( $answerData['other'] ) ) {
				$answerOther = $answerData['other'];
				if ( $answerOther !== '' ) {
					if ( !is_string( $answerOther ) ) {
						throw new InvalidAnswerDataException( $questionName );
					}
					$ansText = $answerData['other'];
				}
			}
		} else {
			throw new UnexpectedValueException( "Unhandled question type $type" );
		}

		return new Answer( $questionSpec['db-id'], $ansOption, $ansText );
	}

	/**
	 * Returns message keys to use as labels for each question in the form shown to organizers when they enable
	 * or edit an event registation. Labels for PII and non-PII questions are provided separately. Question labels
	 * are mapped to question IDs.
	 *
	 * @return string[][]
	 * @phan-return array{pii:array<string,int>,non-pii:array<string,int>}
	 */
	public function getQuestionLabelsForOrganizerForm(): array {
		$ret = [ 'pii' => [], 'non-pii' => [] ];
		foreach ( $this->getQuestions() as $question ) {
			$key = $question['pii'] ? 'pii' : 'non-pii';
			$questionMsg = $question['organizer-label-message'];
			$ret[$key][$questionMsg] = $question['db-id'];
		}
		return $ret;
	}

	/**
	 * Returns the key of a message to be used when introducing stats for the given question.
	 *
	 * @param int $questionID
	 * @return string
	 */
	public function getQuestionLabelForStats( int $questionID ): string {
		foreach ( $this->getQuestions() as $question ) {
			if ( $question['db-id'] === $questionID ) {
				return $question['stats-label-message'];
			}
		}
		throw new UnknownQuestionException( "Unknown question with ID $questionID" );
	}

	/**
	 * @param int $questionID
	 * @return array<int,string> Map of [ answer ID => message key ]
	 */
	public function getQuestionOptionsForStats( int $questionID ): array {
		foreach ( $this->getQuestions() as $question ) {
			if ( $question['db-id'] === $questionID ) {
				$questionData = $question['questionData'];
				if ( !in_array( $questionData['type'], self::MULTIPLE_CHOICE_TYPES, true ) ) {
					throw new LogicException( 'Not implemented' );
				}
				$options = array_flip( $questionData['options-messages'] );
				unset( $options[0] );
				return $options;
			}
		}
		throw new UnknownQuestionException( "Unknown question with ID $questionID" );
	}

	/**
	 * @param int $questionID
	 * @param int $optionID
	 * @return string
	 */
	public function getQuestionOptionMessageByID( int $questionID, int $optionID ): string {
		foreach ( $this->getQuestions() as $question ) {
			if ( $question['db-id'] === $questionID ) {
				$optionMessages = array_flip( $question['questionData']['options-messages'] );
				if ( !isset( $optionMessages[ $optionID ] ) ) {
					throw new UnknownQuestionOptionException( "Unknown option with ID $optionID" );
				}
				return $optionMessages[ $optionID ];
			}
		}
		throw new UnknownQuestionException( "Unknown question with ID $questionID" );
	}

	/**
	 * @return string[]
	 */
	public function getAvailableQuestionNames(): array {
		return array_column( $this->getQuestions(), 'name' );
	}

	/**
	 * Given a question name, returns the corresponding database ID.
	 *
	 * @param string $name
	 * @return int
	 * @throws UnknownQuestionException
	 */
	public function nameToDBID( string $name ): int {
		foreach ( $this->getQuestions() as $question ) {
			if ( $question['name'] === $name ) {
				return $question['db-id'];
			}
		}
		throw new UnknownQuestionException( "Unknown question name $name" );
	}

	/**
	 * Given a question database ID, returns its name.
	 *
	 * @param int $dbID
	 * @return string
	 * @throws UnknownQuestionException
	 */
	public function dbIDToName( int $dbID ): string {
		foreach ( $this->getQuestions() as $question ) {
			if ( $question['db-id'] === $dbID ) {
				return $question['name'];
			}
		}
		throw new UnknownQuestionException( "Unknown question DB ID $dbID" );
	}

	/**
	 * Returns non PII questions labels.
	 *
	 * @param array $eventQuestions
	 * @return array
	 */
	public function getNonPIIQuestionLabels( array $eventQuestions ): array {
		$nonPIIquestionLabels = [];
		foreach ( $this->getQuestions() as $question ) {
			if ( in_array( $question[ 'db-id' ], $eventQuestions, true ) && $question['pii'] === false ) {
				$nonPIIquestionLabels[] = $question['non-pii-label-message'];
			}
		}
		return $nonPIIquestionLabels;
	}

	/**
	 * Returns non PII questions IDs.
	 *
	 * @param array $eventQuestions
	 * @return array
	 */
	public function getNonPIIQuestionIDs( array $eventQuestions ): array {
		$nonPIIquestionIDs = [];
		foreach ( $this->getQuestions() as $question ) {
			if ( in_array( $question[ 'db-id' ], $eventQuestions, true ) && $question['pii'] === false ) {
				$nonPIIquestionIDs[] = $question['db-id'];
			}
		}
		return $nonPIIquestionIDs;
	}
}
