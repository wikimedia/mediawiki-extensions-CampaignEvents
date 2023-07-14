( function () {
	'use strict';

	function EventQuestions( eventQuestionsData ) {
		this.questionFields = {};
		if ( mw.config.get( 'wgCampaignEventsEnableParticipantQuestions' ) ) {
			this.questionList = eventQuestionsData;
			this.addQuestions();
		}
	}

	EventQuestions.prototype.addQuestions = function () {
		for ( var questionKey in this.questionList ) {
			switch ( this.questionList[ questionKey ].type ) {
				case 'radio':
					this.addQuestionRadioType( questionKey, this.questionList[ questionKey ] );
					break;
				case 'select':
					this.addQuestionSelectType( questionKey, this.questionList[ questionKey ] );
					break;
				case 'text':
					this.addQuestionTextType( questionKey, this.questionList[ questionKey ] );
					break;
				default:
					throw new Error( 'Unsupported field type ' + this.questionList[ questionKey ].type );
			}

			if ( this.questionList[ questionKey ][ 'hide-if' ] ) {
				var hideIfCondition = this.questionList[ questionKey ][ 'hide-if' ][ 0 ],
					hideIfQuestionKey = this.questionList[ questionKey ][ 'hide-if' ][ 1 ],
					hideIfValue = this.questionList[ questionKey ][ 'hide-if' ][ 2 ];
				if ( this.questionList[ hideIfQuestionKey ].type === 'select' ) {
					this.selectTypeHideIfListener(
						hideIfCondition,
						hideIfQuestionKey,
						questionKey,
						hideIfValue
					);
				} else {
					throw new Error( 'Unsupported field type for hide event listener' + this.questionList[ questionKey ].type );
				}
			}
		}
	};

	EventQuestions.prototype.getQuestionFields = function () {
		var that = this;
		return Object.keys( this.questionFields ).map( function ( q ) {
			return that.questionFields[ q ];
		} );
	};

	EventQuestions.prototype.getParticipantAnswers = function () {
		var answers = {};
		for ( var questionId in this.questionFields ) {
			var questionField = this.questionFields[ questionId ].getField(),
				questionName = questionId.replace( 'Question', '' ).toLowerCase();

			if ( questionField instanceof OO.ui.RadioSelectInputWidget ) {
				answers[ questionName ] = {
					value: parseInt( questionField.getValue() )
				};
			} else if ( questionField instanceof OO.ui.DropdownInputWidget ) {
				answers[ questionName ] = { value: parseInt( questionField.getValue() ) };
			} else if ( questionField instanceof OO.ui.TextInputWidget ) {
				var questionOther = questionId.split( '_' );
				if ( questionOther.length === 3 && questionOther[ 1 ] === 'Other' ) {
					var questionOtherName =
						questionOther[ 0 ].replace( 'Question', '' ).toLowerCase();
					if ( answers[ questionOtherName ].value === parseInt( questionOther[ 2 ] ) ) {
						answers[ questionOtherName ].other = questionField.getValue();
					}
				} else {
					answers[ questionName ] = { value: questionField.getValue() };
				}
			} else {
				throw new Error( 'Unexpected question type' );
			}
		}

		return answers;
	};

	/**
	 * @param {string} questionKey
	 * @param {Object} question
	 */
	EventQuestions.prototype.addQuestionRadioType = function ( questionKey, question ) {
		var options = [],
			defaultValue = question.default || 0;
		for ( var optionMessage in question[ 'options-messages' ] ) {
			options.push(
				{
					data: question[ 'options-messages' ][ optionMessage ].value,
					label: question[ 'options-messages' ][ optionMessage ].message
				}
			);
		}

		this.questionFields[ questionKey ] = new OO.ui.FieldLayout(
			new OO.ui.RadioSelectInputWidget( {
				options: options,
				value: defaultValue
			} ),
			{
				label: question[ 'label-message' ],
				align: 'top',
				classes: [ 'ext-campaingevents-question-radio-button' ]
			}
		);
	};

	/**
	 * @param {string} questionKey
	 * @param {Object} question
	 */
	EventQuestions.prototype.addQuestionSelectType = function ( questionKey, question ) {
		var options = [],
			defaultValue = question.default || 0;

		for ( var optionMessage in question[ 'options-messages' ] ) {
			options.push(
				{
					data: question[ 'options-messages' ][ optionMessage ].value,
					label: question[ 'options-messages' ][ optionMessage ].message
				}
			);
		}

		this.questionFields[ questionKey ] = new OO.ui.FieldLayout(
			new OO.ui.DropdownInputWidget( {
				options: options,
				value: defaultValue
			} ),
			{
				label: question[ 'label-message' ],
				align: 'top',
				classes: [ 'ext-campaignevents-dropdown-question' ]
			}
		);
	};

	/**
	 * @param {string} questionKey
	 * @param {Object} question
	 */
	EventQuestions.prototype.addQuestionTextType = function ( questionKey, question ) {
		var defaultValue = question.default || '';
		this.questionFields[ questionKey ] = new OO.ui.FieldLayout(
			new OO.ui.TextInputWidget( {
				placeholder: question[ 'placeholder-message' ] ? question[ 'placeholder-message' ] : '',
				value: defaultValue
			} ),
			// there is only on class, it is in EventQuestionsRegistry.php
			/* eslint-disable-next-line */
			{
				classes: question.cssclass ? [ question.cssclass ] : [],
				label: question[ 'label-message' ] ? question[ 'label-message' ] : '',
				align: 'top'
			}
		);
	};

	/**
	 * @param {string} condition
	 * @param {string} questionListener
	 * @param {string} questionKey
	 * @param {string} conditionValue
	 */
	EventQuestions.prototype.selectTypeHideIfListener = function (
		condition,
		questionListener,
		questionKey,
		conditionValue
	) {
		var that = this,
			questionField = this.questionFields[ questionListener ].getField();
		function selectOnChange( val ) {
			if ( condition === '!==' ) {
				if ( String( val ) !== String( conditionValue ) ) {
					that.questionFields[ questionKey ].toggle( false );
				} else {
					that.questionFields[ questionKey ].toggle( true );
				}
			} else {
				throw new Error( 'Unexpected hide-if condition ' + condition );
			}
		}
		questionField.on( 'change', selectOnChange );
		selectOnChange( questionField.getValue() );
	};

	module.exports = EventQuestions;
}() );
