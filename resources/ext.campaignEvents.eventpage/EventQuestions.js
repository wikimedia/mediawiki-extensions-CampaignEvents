( function () {
	'use strict';

	function EventQuestions( eventQuestionsData ) {
		this.questionFields = {};
		this.questionList = eventQuestionsData.questions;
		this.prevAnswers = eventQuestionsData.answers;
		this.emptyDefaultsByType = {
			radio: 0,
			select: 0,
			text: ''
		};
		this.addQuestions();
	}

	EventQuestions.prototype.hasQuestions = function () {
		return Object.keys( this.questionList ).length > 0;
	};

	EventQuestions.prototype.addQuestions = function () {
		for ( var questionName in this.questionList ) {
			var questionData = this.questionList[ questionName ],
				prevAnswerData = this.prevAnswers[ questionName ],
				prevAnswer = typeof prevAnswerData !== 'undefined' ? prevAnswerData.value : null;

			var field = this.getQuestionField( questionData, prevAnswer ),
				curFieldsData = { main: field, other: {} };

			if ( questionData[ 'other-options' ] ) {
				for ( var showIfVal in questionData[ 'other-options' ] ) {
					var otherOptionData = questionData[ 'other-options' ][ showIfVal ],
						prevOtherAns = String( showIfVal ) === String( prevAnswer ) ?
							prevAnswerData.other :
							null;

					var otherOptionField = this.getQuestionField( otherOptionData, prevOtherAns );
					otherOptionField.$element.addClass( 'ext-campaignevents-question-other-option' );
					this.makeFieldConditionallyVisible( otherOptionField, field, showIfVal );
					curFieldsData.other[ showIfVal ] = otherOptionField;
				}
			}

			this.questionFields[ questionName ] = curFieldsData;
		}
	};

	EventQuestions.prototype.getQuestionFields = function () {
		var fields = [];
		for ( var questionName in this.questionFields ) {
			var fieldData = this.questionFields[ questionName ];
			fields.push( fieldData.main );
			if ( fieldData.other ) {
				for ( var otherKey in fieldData.other ) {
					fields.push( fieldData.other[ otherKey ] );
				}
			}
		}
		return fields;
	};

	EventQuestions.prototype.getParticipantAnswers = function () {
		var answers = {};
		for ( var questionName in this.questionFields ) {
			var fieldData = this.questionFields[ questionName ],
				questionField = fieldData.main.getField(),
				ansVal = questionField.getValue();

			if (
				questionField instanceof OO.ui.RadioSelectInputWidget ||
				questionField instanceof OO.ui.DropdownInputWidget
			) {
				// getValue always returns a string for these field types.
				ansVal = parseInt( ansVal );
			}

			var curAnswer = { value: ansVal };
			if ( fieldData.other && fieldData.other[ ansVal ] ) {
				curAnswer.other = fieldData.other[ ansVal ].getField().getValue();
			}
			answers[ questionName ] = curAnswer;
		}

		return answers;
	};

	EventQuestions.prototype.resetToDefault = function () {
		for ( var questionName in this.questionFields ) {
			var questionSpec = this.questionList[ questionName ],
				fieldData = this.questionFields[ questionName ],
				questionField = fieldData.main.getField();

			questionField.setValue( this.emptyDefaultsByType[ questionSpec.type ] );
			if ( fieldData.other ) {
				for ( var otherKey in fieldData.other ) {
					var otherType = questionSpec[ 'other-options' ][ otherKey ].type;
					fieldData.other[ otherKey ].getField().setValue(
						this.emptyDefaultsByType[ otherType ]
					);
				}
			}
		}
	};

	/**
	 * @param {Object} questionData
	 * @param {string|number|null} defaultValue
	 * @return {OO.ui.FieldLayout}
	 */
	EventQuestions.prototype.getQuestionField = function ( questionData, defaultValue ) {
		switch ( questionData.type ) {
			case 'radio':
				return this.getRadioField( questionData, defaultValue );
			case 'select':
				return this.getSelectField( questionData, defaultValue );
			case 'text':
				return this.getTextField( questionData, defaultValue );
			default:
				throw new Error( 'Unsupported field type ' + questionData.type );
		}
	};

	/**
	 * @param {Object} questionData
	 * @param {string|number|null} defaultValue
	 * @return {OO.ui.FieldLayout}
	 */
	EventQuestions.prototype.getRadioField = function ( questionData, defaultValue ) {
		var options = [];
		for ( var optionMessage in questionData.options ) {
			options.push(
				{
					data: questionData.options[ optionMessage ].value,
					label: questionData.options[ optionMessage ].message
				}
			);
		}

		return new OO.ui.FieldLayout(
			new OO.ui.RadioSelectInputWidget( {
				options: options,
				value: defaultValue || this.emptyDefaultsByType.radio
			} ),
			{
				label: questionData.label,
				align: 'top',
				classes: [ 'ext-campaingevents-question-radio-button' ]
			}
		);
	};

	/**
	 * @param {Object} questionData
	 * @param {string|number|null} defaultValue
	 * @return {OO.ui.FieldLayout}
	 */
	EventQuestions.prototype.getSelectField = function ( questionData, defaultValue ) {
		var options = [];

		for ( var optionMessage in questionData.options ) {
			options.push(
				{
					data: questionData.options[ optionMessage ].value,
					label: questionData.options[ optionMessage ].message
				}
			);
		}

		return new OO.ui.FieldLayout(
			new OO.ui.DropdownInputWidget( {
				options: options,
				value: defaultValue || this.emptyDefaultsByType.select
			} ),
			{
				label: questionData.label,
				align: 'top',
				classes: [ 'ext-campaignevents-dropdown-question' ]
			}
		);
	};

	/**
	 * @param {Object} questionData
	 * @param {string|number|null} defaultValue
	 * @return {OO.ui.FieldLayout}
	 */
	EventQuestions.prototype.getTextField = function ( questionData, defaultValue ) {
		return new OO.ui.FieldLayout(
			new OO.ui.TextInputWidget( {
				placeholder: questionData.placeholder || '',
				value: defaultValue || this.emptyDefaultsByType.text
			} ),
			{
				label: questionData.label || '',
				align: 'top'
			}
		);
	};

	/**
	 * @param {OO.ui.FieldLayout} field
	 * @param {OO.ui.FieldLayout} parentField
	 * @param {string|number} showIfVal
	 */
	EventQuestions.prototype.makeFieldConditionallyVisible = function (
		field,
		parentField,
		showIfVal
	) {
		var parentWidget = parentField.getField();
		function visibilityUpdater( val ) {
			var shouldBeShown = String( val ) === String( showIfVal );
			field.toggle( shouldBeShown );
		}
		parentWidget.on( 'change', visibilityUpdater );
		visibilityUpdater( parentWidget.getValue() );
	};

	module.exports = EventQuestions;
}() );
