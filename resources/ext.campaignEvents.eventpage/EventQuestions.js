( function () {
	'use strict';

	function EventQuestions( eventQuestionsData ) {
		this.questions = {};
		if ( mw.config.get( 'wgCampaignEventsEnableParticipantQuestions' ) ) {
			this.eventQuestions = eventQuestionsData;
			this.addQuestions();
		}
	}

	EventQuestions.prototype.addQuestions = function () {
		for ( var questionKey in this.eventQuestions ) {
			switch ( this.eventQuestions[ questionKey ].type ) {
				case 'radio':
					this.addQuestionRadioType( questionKey, this.eventQuestions[ questionKey ] );
					break;
				case 'select':
					this.addQuestionSelectType( questionKey, this.eventQuestions[ questionKey ] );
					break;
				case 'text':
					this.addQuestionTextType( questionKey, this.eventQuestions[ questionKey ] );
					break;
				default:
					throw new Error( 'Unsupported field type ' + this.eventQuestions[ questionKey ].type );
			}

			if ( this.eventQuestions[ questionKey ][ 'hide-if' ] ) {
				var hideIfCondition = this.eventQuestions[ questionKey ][ 'hide-if' ][ 0 ],
					hideIfQuestionKey = this.eventQuestions[ questionKey ][ 'hide-if' ][ 1 ],
					hideIfValue = this.eventQuestions[ questionKey ][ 'hide-if' ][ 2 ];
				if ( this.eventQuestions[ hideIfQuestionKey ].type === 'select' ) {
					this.selectTypeHideIfListener(
						hideIfCondition,
						hideIfQuestionKey,
						questionKey,
						hideIfValue
					);
				} else {
					throw new Error( 'Unsupported field type for hide event listener' + this.eventQuestions[ questionKey ].type );
				}
			}
		}
	};

	/**
	 * @param {string} questionKey
	 * @param {Object} question
	 */
	EventQuestions.prototype.addQuestionRadioType = function ( questionKey, question ) {
		var options = [];
		for ( var optionMessage in question[ 'options-messages' ] ) {
			options.push(
				new OO.ui.RadioOptionWidget( {
					data: question[ 'options-messages' ][ optionMessage ].value,
					label: question[ 'options-messages' ][ optionMessage ].message
				} )
			);
		}

		options[ 0 ].setSelected( true );
		this.questions[ questionKey ] = new OO.ui.FieldLayout(
			new OO.ui.RadioSelectWidget( {
				items: options
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
		var options = [];
		for ( var optionMessage in question[ 'options-messages' ] ) {
			options.push(
				{
					data: question[ 'options-messages' ][ optionMessage ].value,
					label: question[ 'options-messages' ][ optionMessage ].message
				}
			);
		}

		this.questions[ questionKey ] = new OO.ui.FieldLayout(
			new OO.ui.DropdownInputWidget( {
				options: options
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
		this.questions[ questionKey ] = new OO.ui.FieldLayout(
			new OO.ui.TextInputWidget( {
				placeholder: question[ 'placeholder-message' ] ? question[ 'placeholder-message' ] : ''
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
		var that = this;
		this.questions[ questionKey ].toggle( false );
		this.questions[ questionListener ].fieldWidget.on( 'change', function ( val ) {
			if ( condition === '!==' ) {
				if ( val !== conditionValue ) {
					that.questions[ questionKey ].toggle( false );
				} else {
					that.questions[ questionKey ].toggle( true );
				}
			} else {
				throw new Error( 'Unexpected hide-if condition ' + condition );
			}
		} );
	};

	module.exports = EventQuestions;
}() );
