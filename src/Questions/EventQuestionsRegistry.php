<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Questions;

use BadMethodCallException;

class EventQuestionsRegistry {
	public const SERVICE_NAME = 'CampaignEventsEventQuestionsRegistry';

	public const RADIO_BUTTON_QUESTION_TYPE = 'radio';
	public const SELECT_QUESTION_TYPE = 'select';
	public const FREE_TEXT_QUESTION_TYPE = 'text';

	/**
	 * @var bool Determines whether Wikimedia-specific questions should be shown. In the future, this might be
	 * replaced with a hook for adding custom questions.
	 */
	private bool $wikimediaQuestionsEnabled;

	/**
	 * @param bool $wikimediaQuestionsEnabled
	 */
	public function __construct( bool $wikimediaQuestionsEnabled ) {
		$this->wikimediaQuestionsEnabled = $wikimediaQuestionsEnabled;
	}

	/**
	 * @return array[]
	 */
	private function getQuestions(): array {
		$questions = [
			[
				'name' => 'gender',
				'db-id' => 1,
				'wikimedia' => false,
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
				'questionData' => [
					'type' => self::SELECT_QUESTION_TYPE,
					'label-message' => 'campaignevents-register-question-age',
					'options-messages' => [
						'campaignevents-register-question-age-placeholder' => 0,
						'campaignevents-register-question-age-option-under-18' => 1,
						'campaignevents-register-question-age-option-18-24' => 2,
						'campaignevents-register-question-age-option-25-34' => 3,
						'campaignevents-register-question-age-option-35-44' => 4,
						'campaignevents-register-question-age-option-45-54' => 5,
						'campaignevents-register-question-age-option-55-64' => 6,
						'campaignevents-register-question-age-option-65-74' => 7,
						'campaignevents-register-question-age-option-75-84' => 8,
						'campaignevents-register-question-age-option-85-plus' => 9,
					],
				],
			],
			[
				'name' => 'profession',
				'db-id' => 3,
				'wikimedia' => false,
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
				'questionData' => [
					'type' => self::SELECT_QUESTION_TYPE,
					'label-message' => 'campaignevents-register-question-affiliate',
					'options-messages' => [
						'campaignevents-register-question-affiliate-placeholder' => 0,
						'campaignevents-register-question-affiliate-option-none' => 1,
						'campaignevents-register-question-affiliate-option-affiliate' => 2,
						'campaignevents-register-question-affiliate-option-chapter' => 3,
						'campaignevents-register-question-affiliate-option-user-group' => 4,
						'campaignevents-register-question-affiliate-option-organizing-partner' => 5,
					],
				],
				'otherOptions' => [
					// TBD: for now this is just a free text option
					// but we might change it later to be a dropdown list of options
					// Other options above also may have a dropdown list of options in the future
					// that is why this "otherOptions" is an array
					'name' => [
						'type' => self::FREE_TEXT_QUESTION_TYPE,
						'cssclass' => 'ext-campaignevents-participant-question-affiliate-name',
						'placeholder-message' => 'campaignevents-register-question-affiliate-details-placeholder',
						'hide-if-parent' => [ '!==', '2' ],
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
	 * Returns the defined questions in the format accepted by HTMLForm.
	 *
	 * @return array[]
	 */
	public function getQuestionsForHTMLForm(): array {
		$fields = [];
		foreach ( $this->getQuestions() as $question ) {
			$fieldName = 'Question' . ucfirst( $question['name'] );
			$fields[$fieldName] = $question[ 'questionData' ];
			foreach ( $question[ 'otherOptions' ] ?? [] as $optionName => $optionData ) {
				$optionName = $fieldName . '_' . ucfirst( $optionName );
				if ( isset( $optionData['hide-if-parent'] ) ) {
					$optionData['hide-if'] = [
						$optionData['hide-if-parent'][0],
						$fieldName,
						$optionData['hide-if-parent'][1]
					];
					unset( $optionData['hide-if-parent'] );
				}
				$fields[$optionName] = $optionData;
			}
		}
		return $fields;
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
}
