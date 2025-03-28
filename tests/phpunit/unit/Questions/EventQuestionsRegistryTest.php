<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Tests\Unit\Questions;

use Generator;
use MediaWiki\Extension\CampaignEvents\Questions\Answer;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\InvalidAnswerDataException;
use MediaWiki\Extension\CampaignEvents\Questions\UnknownQuestionException;
use MediaWiki\Extension\CampaignEvents\Questions\UnknownQuestionOptionException;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry
 */
class EventQuestionsRegistryTest extends MediaWikiUnitTestCase {
	private const QUESTION_OVERRIDES = [
		[
			'name' => 'testradio',
			'db-id' => 1,
			'wikimedia' => false,
			'pii' => true,
			'stats-label-message' => 'question-1-stats-label',
			'organizer-label-message' => 'question-1-organizer-label',
			'questionData' => [
				'type' => EventQuestionsRegistry::RADIO_BUTTON_QUESTION_TYPE,
				'label-message' => 'question-1-label',
				'options-messages' => [
					'question-1-option-0' => 0,
					'question-1-option-1' => 1,
					'question-1-option-2' => 2,
				],
			],
		],
		[
			'name' => 'testselect',
			'db-id' => 2,
			'wikimedia' => false,
			'pii' => false,
			'stats-label-message' => 'question-2-stats-label',
			'organizer-label-message' => 'question-2-organizer-label',
			'questionData' => [
				'type' => EventQuestionsRegistry::SELECT_QUESTION_TYPE,
				'label-message' => 'question-2-label',
				'options-messages' => [
					'question-2-option-0' => 0,
					'question-2-option-1' => 1,
					'question-2-option-2' => 2,
				],
			],
		],
		[
			'name' => 'testother',
			'db-id' => 3,
			'wikimedia' => false,
			'pii' => false,
			'stats-label-message' => 'question-3-stats-label',
			'organizer-label-message' => 'question-3-organizer-label',
			'questionData' => [
				'type' => EventQuestionsRegistry::SELECT_QUESTION_TYPE,
				'label-message' => 'question-3-label',
				'options-messages' => [
					'question-3-option-0' => 0,
					'question-3-option-1' => 1,
					'question-3-option-2' => 2,
				],
			],
			'otherOptions' => [
				1 => [
					'type' => EventQuestionsRegistry::FREE_TEXT_QUESTION_TYPE,
					'placeholder-message' => 'question-3-placeholder',
				],
			],
		],
	];

	private const QUESTION_OVERRIDES_API = [
		1 => [
			'name' => 'testradio',
			'type' => EventQuestionsRegistry::RADIO_BUTTON_QUESTION_TYPE,
			'label-message' => 'question-1-label',
			'options-messages' => [
				'question-1-option-0' => 0,
				'question-1-option-1' => 1,
				'question-1-option-2' => 2,
			],
		],
		2 => [
			'name' => 'testselect',
			'type' => EventQuestionsRegistry::SELECT_QUESTION_TYPE,
			'label-message' => 'question-2-label',
			'options-messages' => [
				'question-2-option-0' => 0,
				'question-2-option-1' => 1,
				'question-2-option-2' => 2,
			],
		],
		3 => [
			'name' => 'testother',
			'type' => EventQuestionsRegistry::SELECT_QUESTION_TYPE,
			'label-message' => 'question-3-label',
			'options-messages' => [
				'question-3-option-0' => 0,
				'question-3-option-1' => 1,
				'question-3-option-2' => 2,
			],
			'other-options' => [
				1 => [
					'type' => EventQuestionsRegistry::FREE_TEXT_QUESTION_TYPE,
					'label-message' => 'question-3-placeholder',
				],
			],
		],
	];

	private function getRegistry(): EventQuestionsRegistry {
		return new EventQuestionsRegistry( true );
	}

	/**
	 * @covers ::getQuestions
	 */
	public function testRegistryStructure(): void {
		$questions = $this->getRegistry()->getQuestionsForTesting();
		$seenNames = [];
		$seenDBIDs = [];
		foreach ( $questions as $questionDescriptor ) {
			$this->assertIsArray( $questions, 'Question descriptor should be an array' );
			$this->assertArrayHasKey( 'name', $questionDescriptor, 'Questions should have a name' );
			$name = $questionDescriptor['name'];
			$this->assertIsString( $name, 'Question names should be strings' );
			$this->assertArrayNotHasKey( $name, $seenNames, 'Duplicated question name' );
			$seenNames[$name] = true;
			$this->assertArrayHasKey( 'db-id', $questionDescriptor, 'Questions should have a DB ID' );
			$dbID = $questionDescriptor['db-id'];
			$this->assertIsInt( $dbID, 'Question DB IDs should be integers' );
			$this->assertArrayNotHasKey( $dbID, $seenDBIDs, 'Duplicated question DB ID' );
			$seenDBIDs[$dbID] = true;
			$this->assertArrayHasKey(
				'wikimedia',
				$questionDescriptor,
				'Questions should specify whether they are Wikimedia-specific'
			);
			$this->assertIsBool(
				$questionDescriptor['wikimedia'],
				'Values for the "wikimedia" key should be booleans'
			);
			$this->assertArrayHasKey( 'pii', $questionDescriptor, 'Questions should specify whether they are PII' );
			$this->assertIsBool( $questionDescriptor['pii'], 'Values for the "pii" key should be booleans' );
			$this->assertArrayHasKey(
				'stats-label-message',
				$questionDescriptor,
				'Questions should specify a label message for stats'
			);
			$this->assertIsString(
				$questionDescriptor['stats-label-message'],
				'"stats-label-message" should be a string'
			);
			$this->assertArrayHasKey(
				'organizer-label-message',
				$questionDescriptor,
				'Questions should specify a label message for the organizer form'
			);
			$this->assertIsString(
				$questionDescriptor['organizer-label-message'],
				'"organizer-label-message" should be a string'
			);
			$this->assertArrayHasKey( 'questionData', $questionDescriptor, 'Questions should have data' );
			$questionData = $questionDescriptor['questionData'];
			if ( !$questionDescriptor['pii'] ) {
				$this->assertIsString(
					$questionDescriptor[ 'non-pii-label-message' ],
					'"non-pii-label-message" should be a string'
				);
			}
			$this->assertArrayHasKey( 'type', $questionData, 'Questions should have a type' );
			$questionType = $questionData['type'];
			if ( in_array( $questionType, EventQuestionsRegistry::MULTIPLE_CHOICE_TYPES, true ) ) {
				$this->assertArrayHasKey(
					'options-messages',
					$questionData,
					'Multiple-choice questions must have options-messages'
				);
				$this->assertContains(
					0,
					$questionData['options-messages'],
					'There must be a placeholder option with value 0'
				);
			}
			$this->assertArrayHasKey( 'label-message', $questionData, 'Questions should have a label' );
			if ( isset( $questionDescriptor['otherOptions'] ) ) {
				$this->assertIsArray( $questionDescriptor['otherOptions'], 'otherOptions should be an array' );
				$this->assertContains(
					$questionType,
					EventQuestionsRegistry::MULTIPLE_CHOICE_TYPES,
					'Only multiple choice questions can have other options'
				);
				foreach ( $questionDescriptor['otherOptions'] as $key => $val ) {
					$this->assertIsInt( $key, 'otherOptions should use parent values as keys' );
					$this->assertContains(
						$key,
						$questionData['options-messages'],
						'otherOptions keys must be possible values of the parent field'
					);
					$this->assertIsArray( $val, 'Each option in otherOptions should be an array' );
					$this->assertSame(
						EventQuestionsRegistry::FREE_TEXT_QUESTION_TYPE,
							$val['type'] ?? null,
						'Each option in otherOptions should be explicitly defined as free text'
					);
					$this->assertArrayHasKey(
						'placeholder-message',
						$val,
						'Each option in otherOptions should have a placeholder'
					);
				}
			}
		}
	}

	/**
	 * @covers ::getQuestionsForHTMLForm
	 */
	public function testGetQuestionsForHTMLForm(): void {
		$registry = $this->getRegistry();
		$availableQuestionIDs = array_column( $registry->getQuestionsForTesting(), 'db-id' );
		$htmlFormQuestions = $this->getRegistry()->getQuestionsForHTMLForm( $availableQuestionIDs, [] );
		foreach ( $htmlFormQuestions as $key => $descriptor ) {
			$this->assertIsString( $key, 'HTMLForm keys should be strings (field names)' );
			$this->assertIsArray( $descriptor, 'HTMLForm descriptors should be arrays' );
			// TODO: We may want to check that $descriptor is valid (e.g., by calling
			// HTMLForm::loadInputFromParameters), but the code is still heavily reliant on global state.
		}
	}

	/**
	 * @covers ::extractUserAnswersHTMLForm
	 * @covers ::newAnswerFromHTMLForm
	 * @covers ::isPlaceholderValue
	 * @dataProvider provideExtractUserAnswersHTMLForm
	 */
	public function testExtractUserAnswersHTMLForm( array $formData, array $enabledQuestions, array $expectedAnswers ) {
		$this->assertEquals(
			$expectedAnswers,
			$this->getRegistry()->extractUserAnswersHTMLForm( $formData, $enabledQuestions )
		);
	}

	public static function provideExtractUserAnswersHTMLForm(): Generator {
		yield 'Empty form data' => [ [], [ 1, 2, 3 ], [] ];
		yield 'No answers in form data' => [ [ 'notaquestion' => '42' ], [ 1 ], [] ];
		yield 'Form data contains answer to question not enabled' => [ [ 'QuestionAge' => '2' ], [], [] ];
		yield 'Placeholder radio' => [ [ 'QuestionGender' => '0' ], [ 1 ], [] ];
		yield 'Placeholder select' => [ [ 'QuestionAge' => '0' ], [ 2 ], [] ];
		yield 'Simple answer with no other value' => [
			[ 'QuestionGender' => '1' ],
			[ 1 ],
			[ new Answer( 1, 1, null ) ]
		];
		yield 'No value provided for otherOption' => [
			[ 'QuestionAffiliate' => '1' ],
			[ 5 ],
			[ new Answer( 5, 1, null ) ]
		];
		yield 'otherOption provided for wrong value' => [
			[ 'QuestionAffiliate' => '2', 'QuestionAffiliate_Other_2' => 'Foo' ],
			[ 5 ],
			[ new Answer( 5, 2, null ) ]
		];
		yield 'Placeholder provided for otherOption' => [
			[ 'QuestionAffiliate' => '1', 'QuestionAffiliate_Other_1' => '' ],
			[ 5 ],
			[ new Answer( 5, 1, null ) ]
		];
		yield 'Answer with other value' => [
			[ 'QuestionAffiliate' => '1', 'QuestionAffiliate_Other_1' => 'some-affiliate' ],
			[ 5 ],
			[ new Answer( 5, 1, 'some-affiliate' ) ]
		];
		yield 'Multiple answer types' => [
			[
				'QuestionGender' => '3',
				'QuestionAge' => '5',
				'QuestionAffiliate' => '1',
				'QuestionAffiliate_Other_1' => 'some-affiliate'
			],
			[ 1, 2, 3, 4, 5 ],
			[
				new Answer( 1, 3, null ),
				new Answer( 2, 5, null ),
				new Answer( 5, 1, 'some-affiliate' )
			]
		];
	}

	/**
	 * @covers ::extractUserAnswersHTMLForm
	 * @covers ::newAnswerFromHTMLForm
	 * @dataProvider provideExtractUserAnswersHTMLForm__error
	 *
	 */
	public function testExtractUserAnswersHTMLForm__error( array $formData ) {
		$this->expectException( InvalidAnswerDataException::class );
		$this->getRegistry()->extractUserAnswersHTMLForm( $formData, [ 1, 2, 3, 4, 5 ] );
	}

	public static function provideExtractUserAnswersHTMLForm__error(): Generator {
		yield 'Radio wrong answer type' => [
			[ 'QuestionGender' => 'foo' ],
		];
		yield 'Non-existing radio option' => [
			[ 'QuestionGender' => 10000 ],
		];
		yield 'Select wrong answer type' => [
			[ 'QuestionAge' => 'foo' ],
		];
		yield 'Non-existing select option' => [
			[ 'QuestionAge' => 100000 ],
		];
		yield 'otherOption wrong type' => [
			[ 'QuestionAffiliate' => 1, 'QuestionAffiliate_Other_1' => true ],
		];
	}

	/**
	 * @covers ::getQuestionsForHTMLForm
	 * @covers ::extractUserAnswersHTMLForm
	 * @covers ::newAnswerFromHTMLForm
	 */
	public function testHTMLFormRoundtrip() {
		$registry = $this->getRegistry();
		$enabledQuestions = [ 1, 2, 3, 4, 5 ];
		$htmlFormDescriptor = $registry->getQuestionsForHTMLForm( $enabledQuestions, [] );
		$reqData = [];
		foreach ( $htmlFormDescriptor as $name => $field ) {
			switch ( $field['type'] ) {
				case 'radio':
				case 'select':
					// Note: this assumes that the affiliate question only uses 'otherOption' when the value is 1
					$val = 1;
					break;
				case 'text':
					$val = 'foo';
					break;
				default:
					$this->fail( "Unhandled field type " . $field['type'] );
			}
			$reqData[$name] = $val;
		}
		$parsedAnswers = $registry->extractUserAnswersHTMLForm( $reqData, $enabledQuestions );
		$expected = [
			new Answer( 1, 1, null ),
			new Answer( 2, 1, null ),
			new Answer( 3, 1, null ),
			new Answer( 4, 1, null ),
			new Answer( 5, 1, 'foo' ),
		];

		$this->assertEquals( $expected, $parsedAnswers );
	}

	/**
	 * @covers ::getQuestionsForAPI
	 * @dataProvider provideGetQuestionsForAPI
	 */
	public function testGetQuestionsForAPI( ?array $questionIDs, array $expected ) {
		$registry = $this->getRegistry();
		$registry->overrideQuestionsForTesting( self::QUESTION_OVERRIDES );
		$this->assertSame( $expected, $registry->getQuestionsForAPI( $questionIDs ) );
	}

	public static function provideGetQuestionsForAPI(): Generator {
		yield 'No filter' => [
			null,
			self::QUESTION_OVERRIDES_API
		];
		yield 'Empty filter' => [
			[],
			[]
		];
		yield 'Filter by single question' => [
			[ 1 ],
			[ 1 => self::QUESTION_OVERRIDES_API[1] ]
		];
		yield 'Filter by multiple questions' => [
			[ 1, 2 ],
			[ 1 => self::QUESTION_OVERRIDES_API[1], 2 => self::QUESTION_OVERRIDES_API[2] ]
		];
	}

	/**
	 * @covers ::formatAnswersForAPI
	 * @dataProvider provideFormatAnswersForAPI
	 */
	public function testFormatAnswersForAPI( array $answers, array $expected ) {
		$this->assertSame( $expected, $this->getRegistry()->formatAnswersForAPI( $answers, range( 1, 5 ) ) );
	}

	public static function provideFormatAnswersForAPI(): Generator {
		yield 'No answers' => [ [], [] ];
		yield 'Unrecognized question' => [
			[ new Answer( 10000000, 1, 'foo' ) ],
			[],
		];
		yield 'Various types of answers' => [
			[
				new Answer( 1, 3, null ),
				new Answer( 2, 5, null ),
				new Answer( 5, 1, 'some-affiliate' )
			],
			[
				'gender' => [
					'value' => 3,
				],
				'age' => [
					'value' => 5,
				],
				'affiliate' => [
					'value' => 1,
					'other' => 'some-affiliate',
				],
			],
		];
	}

	/**
	 * @covers ::extractUserAnswersAPI
	 * @covers ::newAnswerFromAPI
	 * @dataProvider provideExtractUserQuestionsAPI
	 *
	 */
	public function testExtractUserQuestionsAPI( array $data, array $enabledQuestions, array $expected ) {
		$this->assertEquals(
			$expected,
			$this->getRegistry()->extractUserAnswersAPI( $data, $enabledQuestions )
		);
	}

	public static function provideExtractUserQuestionsAPI(): Generator {
		yield 'No answers' => [ [], [ 1, 2, 3 ], [] ];
		yield 'Unrecognized answer' => [
			[ 'notaquestion' => [ 'value' => 42 ] ],
			[ 1 ],
			[]
		];
		yield 'Contains answer to question not enabled' => [
			[ 'age' => [ 'value' => 2 ] ],
			[],
			[]
		];
		yield 'Placeholder radio' => [
			[ 'gender' => [ 'value' => 0 ] ],
			[ 1 ],
			[]
		];
		yield 'Placeholder select' => [
			[ 'age' => [ 'value' => 0 ] ],
			[ 2 ],
			[]
		];
		yield 'Simple answer with no other value' => [
			[ 'gender' => [ 'value' => 1 ] ],
			[ 1 ],
			[ new Answer( 1, 1, null ) ]
		];
		yield 'No value provided for otherOption' => [
			[ 'affiliate' => [ 'value' => 1 ] ],
			[ 5 ],
			[ new Answer( 5, 1, null ) ]
		];
		yield 'otherOption provided for wrong value' => [
			[ 'affiliate' => [ 'value' => 2, 'other' => 'foo' ] ],
			[ 5 ],
			[ new Answer( 5, 2, null ) ]
		];
		yield 'Placeholder provided for otherOption' => [
			[ 'affiliate' => [ 'value' => 1, 'other' => '' ] ],
			[ 5 ],
			[ new Answer( 5, 1, null ) ]
		];
		yield 'Answer with other value' => [
			[ 'affiliate' => [ 'value' => 1, 'other' => 'some-affiliate' ] ],
			[ 5 ],
			[ new Answer( 5, 1, 'some-affiliate' ) ]
		];
		yield 'Multiple answer types' => [
			[
				'gender' => [ 'value' => 3 ],
				'age' => [ 'value' => 5 ],
				'affiliate' => [ 'value' => 1, 'other' => 'some-affiliate' ],
			],
			[ 1, 2, 3, 4, 5 ],
			[
				new Answer( 1, 3, null ),
				new Answer( 2, 5, null ),
				new Answer( 5, 1, 'some-affiliate' )
			]
		];
	}

	/**
	 * @covers ::extractUserAnswersAPI
	 * @covers ::newAnswerFromAPI
	 * @dataProvider provideExtractUserQuestionsAPI__error
	 *
	 */
	public function testExtractUserQuestionsAPI__error( array $data ) {
		$this->expectException( InvalidAnswerDataException::class );
		$this->getRegistry()->extractUserAnswersAPI( $data, [ 1, 2, 3, 4, 5 ] );
	}

	public static function provideExtractUserQuestionsAPI__error(): Generator {
		yield 'Radio wrong answer type' => [
			[ 'gender' => [ 'value' => 'foo' ] ],
		];
		yield 'Non-existing radio option' => [
			[ 'gender' => [ 'value' => 10000 ] ],
		];
		yield 'Select wrong answer type' => [
			[ 'age' => [ 'value' => 'foo' ] ],
		];
		yield 'Non-existing select option' => [
			[ 'age' => [ 'value' => 100000 ] ],
		];
		yield 'otherOption wrong type' => [
			[ 'affiliate' => [ 'value' => 1, 'other' => true ] ],
		];
	}

	/**
	 * @covers ::formatAnswersForAPI
	 * @covers ::extractUserAnswersAPI
	 */
	public function testAPIRoundtrip() {
		$answers = [
			new Answer( 1, 1, null ),
			new Answer( 2, 2, null ),
			new Answer( 5, 1, 'foo' ),
		];
		$registry = $this->getRegistry();
		$this->assertEquals(
			$answers,
			$registry->extractUserAnswersAPI(
				$registry->formatAnswersForAPI( $answers, [ 1, 2, 5 ] ),
				[ 1, 2, 5 ]
			)
		);
	}

	/**
	 * @covers ::getAvailableQuestionNames
	 */
	public function testGetAvailableQuestionNames() {
		$expected = [
			'gender',
			'age',
			'profession',
			'confidence',
			'affiliate',
		];
		$this->assertSame( $expected, $this->getRegistry()->getAvailableQuestionNames() );
	}

	/**
	 * @covers ::nameToDBID
	 * @dataProvider provideNameToDBID
	 */
	public function testNameToDBID( string $name, ?int $expected ) {
		if ( $expected === null ) {
			$this->expectException( UnknownQuestionException::class );
		}
		$actual = $this->getRegistry()->nameToDBID( $name );
		if ( $expected !== null ) {
			$this->assertSame( $expected, $actual );
		}
	}

	public static function provideNameToDBID(): Generator {
		yield 'Valid' => [ 'age', 2 ];
		yield 'Invalid' => [ 'this-is-definitely-invalid', null ];
	}

	/**
	 * @covers ::nameToDBID
	 * @dataProvider provideDbIDToName
	 */
	public function testDbIDToName( int $dbID, ?string $expected ) {
		if ( $expected === null ) {
			$this->expectException( UnknownQuestionException::class );
		}
		$actual = $this->getRegistry()->dbIDToName( $dbID );
		if ( $expected !== null ) {
			$this->assertSame( $expected, $actual );
		}
	}

	public static function provideDbIDToName(): Generator {
		yield 'Valid' => [ 2, 'age' ];
		yield 'Invalid' => [ -142365, null ];
	}

	/**
	 * @covers ::getQuestionLabelsForOrganizerForm
	 */
	public function testGetQuestionLabelsForOrganizerForm() {
		$registry = $this->getRegistry();
		$labels = $registry->getQuestionLabelsForOrganizerForm();
		$this->assertArrayHasKey( 'pii', $labels, 'Real registry' );
		$this->assertArrayHasKey( 'non-pii', $labels, 'Real registry' );

		$registry->overrideQuestionsForTesting( [] );
		$this->assertArrayHasKey( 'pii', $labels, 'Empty registry' );
		$this->assertArrayHasKey( 'non-pii', $labels, 'Empty registry' );
	}

	/**
	 * @covers ::getQuestionOptionMessageByID
	 * @dataProvider provideGetQuestionOptionMessageByID
	 */
	public function testGetQuestionOptionMessageByID(
		int $questionID,
		int $optionID,
		?string $expected,
		?string $exception = null
	) {
		if ( $exception !== null ) {
			$this->expectException( $exception );
		}
		$actual = $this->getRegistry()->getQuestionOptionMessageByID( $questionID, $optionID );
		if ( $expected !== null ) {
			$this->assertSame( $expected, $actual );
		}
	}

	public static function provideGetQuestionOptionMessageByID(): Generator {
		yield 'Valid question and option' => [
			5,
			1,
			'campaignevents-register-question-affiliate-option-affiliate'
		];
		yield 'Invalid question' => [ 12, 2, null, UnknownQuestionException::class ];
		yield 'Invalid option' => [ 5, 3, null, UnknownQuestionOptionException::class ];
	}

	/**
	 * @covers ::getNonPIIQuestionIDs
	 */
	public function testGetNonPIIQuestionIDs() {
		$nonPIIQuestionIDs = $this->getRegistry()->getNonPIIQuestionIDs(
			[ 1, 2, 3, 4, 5 ]
		);
		$this->assertSame( [ 4, 5 ], $nonPIIQuestionIDs );
	}

	/**
	 * @covers ::getNonPIIQuestionLabels
	 */
	public function testGetNonPIIQuestionLabels() {
		$nonPIIQuestionLabels = $this->getRegistry()->getNonPIIQuestionLabels(
			[ 1, 2, 3, 4, 5 ]
		);
		$this->assertSame(
			[
				'campaignevents-individual-stats-label-message-confidence',
				'campaignevents-individual-stats-label-message-affiliate'
			],
			$nonPIIQuestionLabels
		);
	}

	/**
	 * @param int[] $enabledQuestions
	 * @param Answer[] $userAnswers
	 * @param int[] $expected
	 * @covers ::getParticipantQuestionsToShow
	 * @dataProvider provideParticipantQuestionsToShow
	 */
	public function testGetParticipantQuestionsToShow( array $enabledQuestions, array $userAnswers, array $expected ) {
		$this->assertSame(
			$expected,
			EventQuestionsRegistry::getParticipantQuestionsToShow( $enabledQuestions, $userAnswers )
		);
	}

	public static function provideParticipantQuestionsToShow() {
		$ans = static function ( int $id ): Answer {
			return new Answer( $id, 42, null );
		};
		return [
			'Nothing enabled, no answers' => [ [], [], [] ],
			'Some questions enabled, no answers' => [ [ 1, 2, 3 ], [], [ 1, 2, 3 ] ],
			'Nothing enabled, some answers' => [ [], [ $ans( 1 ), $ans( 2 ), $ans( 3 ) ], [ 1, 2, 3 ] ],
			'Enabled same as answers' => [ [ 1, 2, 3 ], [ $ans( 1 ), $ans( 2 ), $ans( 3 ) ], [ 1, 2, 3 ] ],
			'Some of enabled have no answer' => [ [ 1, 2, 3 ], [ $ans( 1 ), $ans( 2 ) ], [ 1, 2, 3 ] ],
			'Some answered are not enabled' => [ [ 1 ], [ $ans( 1 ), $ans( 2 ), $ans( 3 ) ], [ 1, 2, 3 ] ],
		];
	}
}
