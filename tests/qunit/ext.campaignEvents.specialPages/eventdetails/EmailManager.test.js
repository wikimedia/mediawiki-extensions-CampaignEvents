'use strict';

let emailManager;

QUnit.module(
	'ext.campaignEvents.specialPages/eventdetails/EmailManager.js',
	QUnit.newMwEnvironment( {
		before: () => {
			const fixtureHTML = `
			<div id="ext-campaignevents-eventdetails-tabs" class="oo-ui-layout oo-ui-menuLayout oo-ui-menuLayout-static oo-ui-menuLayout-top oo-ui-menuLayout-showMenu oo-ui-indexLayout" data-ooui="{&quot;_&quot;:&quot;OO.ui.IndexLayout&quot;,&quot;expanded&quot;:false,&quot;menuPanel&quot;:{&quot;tag&quot;:&quot;ooui-php-800&quot;},&quot;contentPanel&quot;:{&quot;tag&quot;:&quot;ooui-php-801&quot;},&quot;autoFocus&quot;:false,&quot;tabPanels&quot;:{&quot;EventDetailsPanel&quot;:{&quot;tag&quot;:&quot;EventDetailsPanel&quot;},&quot;ParticipantsPanel&quot;:{&quot;tag&quot;:&quot;ParticipantsPanel&quot;},&quot;EmailPanel&quot;:{&quot;tag&quot;:&quot;EmailPanel&quot;}},&quot;tabSelectWidget&quot;:{&quot;tag&quot;:&quot;ooui-php-802&quot;}}">
				<div aria-hidden="false" class="oo-ui-menuLayout-menu">
					<div id="ooui-php-800" class="oo-ui-layout oo-ui-panelLayout oo-ui-indexLayout-tabPanel" data-ooui="{&quot;_&quot;:&quot;OO.ui.PanelLayout&quot;,&quot;preserveContent&quot;:false,&quot;expanded&quot;:false}">
						<div role="tablist" aria-multiselectable="false" tabindex="0" id="ooui-php-802" class="oo-ui-selectWidget oo-ui-selectWidget-unpressed oo-ui-widget oo-ui-widget-enabled oo-ui-tabSelectWidget oo-ui-tabSelectWidget-frameless" data-ooui="{&quot;_&quot;:&quot;OO.ui.TabSelectWidget&quot;,&quot;framed&quot;:false,&quot;items&quot;:[{&quot;tag&quot;:&quot;ooui-php-803&quot;},{&quot;tag&quot;:&quot;ooui-php-804&quot;},{&quot;tag&quot;:&quot;ooui-php-805&quot;}]}">
							<div aria-selected="false" role="tab" id="ooui-php-803" class="oo-ui-widget oo-ui-widget-enabled oo-ui-labelElement oo-ui-optionWidget oo-ui-tabOptionWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.TabOptionWidget&quot;,&quot;href&quot;:&quot;#EventDetailsPanel&quot;,&quot;label&quot;:&quot;Event details&quot;,&quot;data&quot;:&quot;EventDetailsPanel&quot;}">
								<a href="#EventDetailsPanel" class="oo-ui-labelElement-label">Event details</a>
							</div>
							<div aria-selected="false" role="tab" id="ooui-php-804" class="oo-ui-widget oo-ui-widget-enabled oo-ui-labelElement oo-ui-optionWidget oo-ui-tabOptionWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.TabOptionWidget&quot;,&quot;href&quot;:&quot;#ParticipantsPanel&quot;,&quot;label&quot;:&quot;Participants&quot;,&quot;data&quot;:&quot;ParticipantsPanel&quot;}">
								<a href="#ParticipantsPanel" class="oo-ui-labelElement-label">Participants</a>
							</div>
							<div aria-selected="true" role="tab" id="ooui-php-805" class="oo-ui-widget oo-ui-widget-enabled oo-ui-labelElement oo-ui-optionWidget oo-ui-tabOptionWidget oo-ui-optionWidget-selected" data-ooui="{&quot;_&quot;:&quot;OO.ui.TabOptionWidget&quot;,&quot;href&quot;:&quot;#EmailPanel&quot;,&quot;selected&quot;:true,&quot;label&quot;:&quot;Message&quot;,&quot;data&quot;:&quot;EmailPanel&quot;}">
								<a href="#EmailPanel" class="oo-ui-labelElement-label">Message</a>
							</div>
						</div>
					</div>
				</div>
				<div class="oo-ui-menuLayout-content">
					<div id="ooui-php-801" class="oo-ui-layout oo-ui-panelLayout oo-ui-stackLayout oo-ui-indexLayout-stackLayout" data-ooui="{&quot;_&quot;:&quot;OO.ui.StackLayout&quot;,&quot;preserveContent&quot;:false,&quot;expanded&quot;:false,&quot;items&quot;:[{&quot;tag&quot;:&quot;EventDetailsPanel&quot;},{&quot;tag&quot;:&quot;ParticipantsPanel&quot;},{&quot;tag&quot;:&quot;EmailPanel&quot;}]}">
						<div id="EventDetailsPanel" role="tabpanel" aria-hidden="true" class="oo-ui-layout oo-ui-panelLayout oo-ui-panelLayout-scrollable oo-ui-tabPanelLayout oo-ui-element-hidden" data-ooui="{&quot;_&quot;:&quot;OO.ui.TabPanelLayout&quot;,&quot;name&quot;:&quot;EventDetailsPanel&quot;,&quot;label&quot;:&quot;Event details&quot;,&quot;tabItemConfig&quot;:{&quot;href&quot;:&quot;#EventDetailsPanel&quot;},&quot;scrollable&quot;:true,&quot;expanded&quot;:false}"></div>
						<div id="ParticipantsPanel" role="tabpanel" aria-hidden="true" class="oo-ui-layout oo-ui-panelLayout oo-ui-panelLayout-scrollable oo-ui-tabPanelLayout oo-ui-element-hidden" data-ooui="{&quot;_&quot;:&quot;OO.ui.TabPanelLayout&quot;,&quot;name&quot;:&quot;ParticipantsPanel&quot;,&quot;label&quot;:&quot;Participants&quot;,&quot;tabItemConfig&quot;:{&quot;href&quot;:&quot;#ParticipantsPanel&quot;},&quot;scrollable&quot;:true,&quot;expanded&quot;:false}"></div>
						<div id="EmailPanel" role="tabpanel" class="oo-ui-layout oo-ui-panelLayout oo-ui-panelLayout-scrollable oo-ui-tabPanelLayout oo-ui-tabPanelLayout-active" data-ooui="{&quot;_&quot;:&quot;OO.ui.TabPanelLayout&quot;,&quot;name&quot;:&quot;EmailPanel&quot;,&quot;label&quot;:&quot;Message&quot;,&quot;tabItemConfig&quot;:{&quot;href&quot;:&quot;#EmailPanel&quot;},&quot;scrollable&quot;:true,&quot;expanded&quot;:false}"></div>
					</div>
				</div>
			</div>
			<div class="ext-campaignevents-eventdetails-email-recipient-list"></div>
			<span id="ooui-php-900" class="ext-campaignevents-details-email-recipients-link oo-ui-widget oo-ui-widget-enabled oo-ui-buttonElement oo-ui-buttonElement-frameless oo-ui-labelElement oo-ui-flaggedElement-progressive oo-ui-buttonWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.ButtonWidget&quot;,&quot;rel&quot;:[&quot;nofollow&quot;],&quot;framed&quot;:false,&quot;label&quot;:&quot;Add recipients&quot;,&quot;flags&quot;:[&quot;progressive&quot;],&quot;classes&quot;:[&quot;ext-campaignevents-details-email-recipients-link&quot;]}">
				<a role="button" tabindex="0" rel="nofollow" class="oo-ui-buttonElement-button">
					<span class="oo-ui-iconElement-icon oo-ui-iconElement-noIcon oo-ui-image-progressive"></span>
					<span class="oo-ui-labelElement-label">Add recipients</span>
					<span class="oo-ui-indicatorElement-indicator oo-ui-indicatorElement-noIndicator oo-ui-image-progressive"></span>
				</a>
			</span>
			<div id="ooui-php-901" class="ext-campaignevents-details-email-notification oo-ui-element-hidden oo-ui-layout oo-ui-fieldLayout oo-ui-fieldLayout-align-left" data-ooui="{&quot;_&quot;:&quot;OO.ui.FieldLayout&quot;,&quot;fieldWidget&quot;:{&quot;tag&quot;:&quot;ooui-php-902&quot;},&quot;$overlay&quot;:true,&quot;classes&quot;:[&quot;ext-campaignevents-details-email-notification&quot;,&quot;oo-ui-element-hidden&quot;]}">
				<div class="oo-ui-fieldLayout-body">
					<span class="oo-ui-fieldLayout-header">
						<label id="ooui-php-903" class="oo-ui-labelElement-label"></label>
					</span>
					<div class="oo-ui-fieldLayout-field">
						<div aria-live="polite" aria-labelledby="ooui-php-903" id="ooui-php-902" class="oo-ui-widget oo-ui-widget-enabled oo-ui-flaggedElement-warning oo-ui-iconElement oo-ui-messageWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.MessageWidget&quot;,&quot;type&quot;:&quot;warning&quot;,&quot;inline&quot;:true,&quot;showClose&quot;:false,&quot;icon&quot;:&quot;alert&quot;,&quot;flags&quot;:[&quot;warning&quot;]}">
							<span class="oo-ui-iconElement-icon oo-ui-icon-alert oo-ui-image-warning"></span>
							<span class="oo-ui-labelElement-label"></span>
						</div>
					</div>
				</div>
			</div>
			<div id="ooui-php-1000" class="ext-campaignevents-details-email-subject oo-ui-widget oo-ui-widget-enabled oo-ui-inputWidget oo-ui-textInputWidget oo-ui-textInputWidget-type-text oo-ui-textInputWidget-php" data-ooui="{&quot;_&quot;:&quot;OO.ui.TextInputWidget&quot;,&quot;placeholder&quot;:&quot;The subject of your email message&quot;,&quot;inputId&quot;:&quot;ooui-php-1001&quot;,&quot;required&quot;:false,&quot;classes&quot;:[&quot;ext-campaignevents-details-email-subject&quot;]}">
				<input type="text" tabindex="0" value="" placeholder="The subject of your email message" id="ooui-php-1001" class="oo-ui-inputWidget-input">
				<span class="oo-ui-iconElement-icon oo-ui-iconElement-noIcon"></span>
				<span class="oo-ui-indicatorElement-indicator oo-ui-indicatorElement-noIndicator"></span>
			</div>
			<div id="ooui-php-1002" class="ext-campaignevents-details-email-message oo-ui-widget oo-ui-widget-enabled oo-ui-inputWidget oo-ui-textInputWidget oo-ui-textInputWidget-type-text oo-ui-textInputWidget-php" data-ooui="{&quot;_&quot;:&quot;OO.ui.MultilineTextInputWidget&quot;,&quot;rows&quot;:17,&quot;placeholder&quot;:&quot;The plaintext content of your email message, this must be at least 10 characters&quot;,&quot;maxLength&quot;:2000,&quot;minLength&quot;:10,&quot;inputId&quot;:&quot;ooui-php-1003&quot;,&quot;required&quot;:false,&quot;classes&quot;:[&quot;ext-campaignevents-details-email-message&quot;]}">
				<textarea tabindex="0" placeholder="The plaintext content of your email message, this must be at least 10 characters" maxlength="2000" minlength="10" rows="17" id="ooui-php-1003" class="oo-ui-inputWidget-input"></textarea>
				<span class="oo-ui-iconElement-icon oo-ui-iconElement-noIcon"></span>
				<span class="oo-ui-indicatorElement-indicator oo-ui-indicatorElement-noIndicator"></span>
			</div>
			<span id="ooui-php-1004" class="ext-campaignevents-details-email-ccme oo-ui-widget oo-ui-widget-enabled oo-ui-inputWidget oo-ui-checkboxInputWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.CheckboxInputWidget&quot;,&quot;inputId&quot;:&quot;ooui-php-1005&quot;,&quot;required&quot;:false,&quot;classes&quot;:[&quot;ext-campaignevents-details-email-ccme&quot;]}"><input type="checkbox" tabindex="0" value="" id="ooui-php-1005" class="oo-ui-inputWidget-input">
				<span class="oo-ui-checkboxInputWidget-checkIcon oo-ui-widget oo-ui-widget-enabled oo-ui-iconElement-icon oo-ui-icon-check oo-ui-iconElement oo-ui-labelElement-invisible oo-ui-iconWidget oo-ui-image-invert"></span>
			</span>
			<span aria-disabled="true" aria-labelledby="ooui-php-1006" id="ooui-php-1007" class="ext-campaignevents-details-email-button oo-ui-widget oo-ui-widget-disabled oo-ui-buttonElement oo-ui-buttonElement-framed oo-ui-labelElement oo-ui-flaggedElement-primary oo-ui-flaggedElement-progressive oo-ui-buttonWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.ButtonWidget&quot;,&quot;rel&quot;:[&quot;nofollow&quot;],&quot;disabled&quot;:true,&quot;label&quot;:&quot;Send email&quot;,&quot;flags&quot;:[&quot;primary&quot;,&quot;progressive&quot;],&quot;classes&quot;:[&quot;ext-campaignevents-details-email-button&quot;]}">
				<a role="button" tabindex="-1" aria-disabled="true" rel="nofollow" class="oo-ui-buttonElement-button">
					<span class="oo-ui-iconElement-icon oo-ui-iconElement-noIcon oo-ui-image-invert"></span>
					<span class="oo-ui-labelElement-label">Send email</span>
					<span class="oo-ui-indicatorElement-indicator oo-ui-indicatorElement-noIndicator oo-ui-image-invert"></span>
				</a>
			</span>
			`;
			$( '#qunit-fixture' ).html( fixtureHTML );
			// Defer initialization because the constructor tries to infuse certain nodes.
			emailManager = require( '../../../../resources/ext.campaignEvents.specialPages/eventdetails/EmailManager.js' );
		}
	} ),
	() => {
		const participantsManager = require( '../../../../resources/ext.campaignEvents.specialPages/eventdetails/ParticipantsManager.js' );
		participantsManager.viewerHasEmail = true;
		participantsManager.selectAllParticipantsCheckbox = new OO.ui.CheckboxInputWidget( {} );

		const makeEnvironmentWithTotalParticipants = ( total ) => QUnit.newMwEnvironment( {
			before: () => {
				participantsManager.participantsTotal = total;
			}
		} );
		const makeCheckbox = ( userID, isSelected, isValidRecipient ) => {
			const username = `User-${ userID }`;
			return new OO.ui.CheckboxInputWidget( {
				selected: isSelected,
				name: 'event-details-participants-checkboxes',
				value: String( userID ),
				classes: [
					'ext-campaignevents-event-details-participants-checkboxes'
				],
				data: {
					canReceiveEmail: isValidRecipient,
					username: username,
					userPageLink: { path: `/wiki/User:${ username }`, title: 'User:${ username }', classes: '' }
				}
			} );
		};
		const setupParticipantsManager = ( userData, checkedBoxes, selectedIDs, isInverted ) => {
			// NOTE: We assume that the test has already initialized this field...
			const totalParticipants = participantsManager.participantsTotal;
			participantsManager.participantCheckboxes = [];
			for ( const userID in userData ) {
				const isSelected = checkedBoxes.includes( parseInt( userID ) );
				participantsManager.participantCheckboxes.push( makeCheckbox( userID, isSelected, userData[ userID ] ) );
			}
			participantsManager.selectedParticipantIDs = selectedIDs;
			if ( isInverted ) {
				participantsManager.selectAllParticipantsCheckbox.setSelected( true, true );
				participantsManager.selectedParticipantsAmount = totalParticipants - selectedIDs.length;
			} else {
				participantsManager.selectAllParticipantsCheckbox.setSelected(
					selectedIDs === null || selectedIDs.length === totalParticipants,
					true
				);
				participantsManager.selectedParticipantsAmount = selectedIDs === null ? totalParticipants : selectedIDs.length;
			}
			participantsManager.isSelectionInverted = isInverted;
			participantsManager.emit( 'change' );
		};

		const assertRecipientsMsg = ( assert, msg, ...params ) => {
			let expectedText = `(${ msg }`;
			if ( params.length ) {
				expectedText += ': ' + params.join( ', ' );
			}
			expectedText += ')';
			assert.strictEqual( emailManager.$recipientsListElement.text(), expectedText, 'Recipients message' );
		};
		const assertWarning = ( assert, msg ) => {
			if ( msg !== null ) {
				assert.strictEqual( emailManager.resultMessage.getLabel(), `(${ msg })`, 'Warning message' );
			} else {
				assert.strictEqual( emailManager.resultMessage.getLabel(), null, 'No warning expected' );
			}
		};

		QUnit.module(
			'Manual selection, more users visible',
			makeEnvironmentWithTotalParticipants( 10000 ),
			() => {
				QUnit.test( 'All selected are invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: false, 2: false, 3: false, 4: false, 5: true, 6: true },
						[ 1, 2, 3, 4 ],
						[ 1, 2, 3, 4 ],
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-count', 4 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '1 valid, 3 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: false, 3: false, 4: false, 5: true, 6: true },
						[ 1, 2, 3, 4 ],
						[ 1, 2, 3, 4 ],
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-count', 4 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '2 valid, 2 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: false, 4: false, 5: true, 6: true },
						[ 1, 2, 3, 4 ],
						[ 1, 2, 3, 4 ],
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-count', 4 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '3 valid, 1 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: true, 4: false, 5: true, 6: true },
						[ 1, 2, 3, 4 ],
						[ 1, 2, 3, 4 ],
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-count', 4 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'All selected are valid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: true, 4: true, 5: true, 6: true },
						[ 1, 2, 3, 4 ],
						[ 1, 2, 3, 4 ],
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-count', 4 );
					assertWarning( assert, null );
				} );
			}
		);
		QUnit.module(
			'Manual selection of all users',
			makeEnvironmentWithTotalParticipants( 4 ),
			() => {
				QUnit.test( 'All selected are invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: false, 2: false, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '1 valid, 3 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: false, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '2 valid, 2 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '3 valid, 1 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: true, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'All selected are valid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: true, 4: true },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, null );
				} );
			}
		);
		QUnit.module(
			'All selected, only some are visible',
			makeEnvironmentWithTotalParticipants( 10000 ),
			() => {
				QUnit.test( 'All visible selected are invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: false, 2: false, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '1 valid, 3 invalid (visible)', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: false, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '2 valid, 2 invalid (visible)', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '3 valid, 1 invalid (visible)', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: true, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'All visible selected are valid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: true, 4: true },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					// TODO: This should warn (soft version).
					assertWarning( assert, null );
				} );
			}
		);
		QUnit.module(
			'All selected, all visible',
			makeEnvironmentWithTotalParticipants( 4 ),
			() => {
				QUnit.test( 'All selected are invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: false, 2: false, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '1 valid, 3 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: false, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '2 valid, 2 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: false, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( '3 valid, 1 invalid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: true, 4: false },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'All selected are valid', async ( assert ) => {
					setupParticipantsManager(
						{ 1: true, 2: true, 3: true, 4: true },
						[ 1, 2, 3, 4 ],
						null,
						false
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-all' );
					assertWarning( assert, null );
				} );
			}
		);

		const halfValidUserData = { 1: false, 2: false, 3: false, 4: true, 5: true, 6: true };
		QUnit.module(
			'All selected with exceptions, only some (6) visible, 3 valid 3 invalid',
			makeEnvironmentWithTotalParticipants( 10000 ),
			() => {
				QUnit.test( 'Deselect 1 invalid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 2, 3, 4, 5, 6 ],
						[ 1 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except', 'User-1' );
					// TODO: This should warn (hard version).
					assertWarning( assert, null );
				} );
				QUnit.test( 'Deselect 2 invalid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 3, 4, 5, 6 ],
						[ 1, 2 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 2 );
					// TODO: This should warn (hard version).
					assertWarning( assert, null );
				} );
				QUnit.test( 'Deselect 3 invalid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 4, 5, 6 ],
						[ 1, 2, 3 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 3 );
					// TODO: This should warn (soft version).
					assertWarning( assert, null );
				} );

				QUnit.test( 'Deselect 1 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 1, 2, 3, 5, 6 ],
						[ 4 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except', 'User-4' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 2 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 1, 2, 3, 6 ],
						[ 4, 5 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 2 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 3 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 1, 2, 3 ],
						[ 4, 5, 6 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 3 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );

				QUnit.test( 'Deselect 1 invalid, 1 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 2, 3, 5, 6 ],
						[ 1, 4 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 2 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 1 invalid, 2 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 2, 3, 6 ],
						[ 1, 4, 5 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 3 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 1 invalid, 3 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 2, 3 ],
						[ 1, 4, 5, 6 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 4 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );

				QUnit.test( 'Deselect 2 invalid, 1 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 3, 5, 6 ],
						[ 1, 2, 4 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 3 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 2 invalid, 2 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 3, 6 ],
						[ 1, 2, 4, 5 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 4 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 2 invalid, 3 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 3 ],
						[ 1, 2, 4, 5, 6 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 5 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );

				QUnit.test( 'Deselect 3 invalid, 1 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 5, 6 ],
						[ 1, 2, 3, 4 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 4 );
					// TODO: This should be a soft warning.
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 3 invalid, 2 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 6 ],
						[ 1, 2, 3, 4, 5 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 5 );
					// TODO: This should be a soft warning.
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
			}
		);
		QUnit.module(
			'All selected with exceptions, all (6) visible, 3 valid 3 invalid',
			makeEnvironmentWithTotalParticipants( 6 ),
			() => {
				QUnit.test( 'Deselect 1 invalid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 2, 3, 4, 5, 6 ],
						[ 1 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except', 'User-1' );
					// TODO: This should warn (hard version).
					assertWarning( assert, null );
				} );
				QUnit.test( 'Deselect 2 invalid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 3, 4, 5, 6 ],
						[ 1, 2 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 2 );
					// TODO: This should warn (hard version).
					assertWarning( assert, null );
				} );
				QUnit.test( 'Deselect 3 invalid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 4, 5, 6 ],
						[ 1, 2, 3 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 3 );
					assertWarning( assert, null );
				} );

				QUnit.test( 'Deselect 1 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 1, 2, 3, 5, 6 ],
						[ 4 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except', 'User-4' );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 2 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 1, 2, 3, 6 ],
						[ 4, 5 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 2 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 3 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 1, 2, 3 ],
						[ 4, 5, 6 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 3 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );

				QUnit.test( 'Deselect 1 invalid, 1 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 2, 3, 5, 6 ],
						[ 1, 4 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 2 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 1 invalid, 2 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 2, 3, 6 ],
						[ 1, 4, 5 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 3 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 1 invalid, 3 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 2, 3 ],
						[ 1, 4, 5, 6 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 4 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );

				QUnit.test( 'Deselect 2 invalid, 1 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 3, 5, 6 ],
						[ 1, 2, 4 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 3 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 2 invalid, 2 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 3, 6 ],
						[ 1, 2, 4, 5 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 4 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 2 invalid, 3 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 3 ],
						[ 1, 2, 4, 5, 6 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 5 );
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );

				QUnit.test( 'Deselect 3 invalid, 1 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 5, 6 ],
						[ 1, 2, 3, 4 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 4 );
					// TODO: This should not warn
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
				QUnit.test( 'Deselect 3 invalid, 2 valid', async ( assert ) => {
					setupParticipantsManager(
						halfValidUserData,
						[ 6 ],
						[ 1, 2, 3, 4, 5 ],
						true
					);
					assertRecipientsMsg( assert, 'campaignevents-email-participants-except-count', 5 );
					// TODO: This should not warn
					assertWarning( assert, 'campaignevents-email-participants-missing-address' );
				} );
			}
		);
	}
);
