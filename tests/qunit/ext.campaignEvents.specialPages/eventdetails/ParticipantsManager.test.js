'use strict';

const testConfig = {
	wgCampaignEventsEventID: 999999,
	wgCampaignEventsShowParticipantCheckboxes: true,
	wgCampaignEventsShowPrivateParticipants: true,
	wgCampaignEventsLastParticipantID: 3,
	wgCampaignEventsCurUserCentralID: 987654321,
	wgCampaignEventsViewerHasEmail: true,
	wgCampaignEventsNonPIIQuestionIDs: [],
	wgCampaignEventsEventDetailsParticipantsTotal: 10
};

QUnit.module(
	'ext.campaignEvents.specialPages/eventdetails/ParticipantsManager.js',
	QUnit.newMwEnvironment( { config: testConfig } ),
	() => {
		const participantsManager = require( '../../../../resources/ext.campaignEvents.specialPages/eventdetails/ParticipantsManager.js' );

		const setupFixture = () => {
			let oouiAutoID = 1000;
			const makeCheckbox = ( username, userID ) => {
				const checkboxID = oouiAutoID++;
				return `
					<td class="ext-campaignevents-eventdetails-user-row-checkbox">
						<div class="oo-ui-layout oo-ui-fieldLayout oo-ui-fieldLayout-align-left">
							<div class="oo-ui-fieldLayout-body">
								<span class="oo-ui-fieldLayout-header">
									<label title="${ username }" for="ooui-php-${ checkboxID }" class="oo-ui-labelElement-label oo-ui-labelElement-invisible">${ username }</label>
								</span>
								<span class="oo-ui-fieldLayout-field">
									<span id="ooui-php-${ oouiAutoID++ }" class="ext-campaignevents-event-details-participants-checkboxes oo-ui-widget oo-ui-widget-enabled oo-ui-inputWidget oo-ui-checkboxInputWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.CheckboxInputWidget&quot;,&quot;name&quot;:&quot;event-details-participants-checkboxes&quot;,&quot;value&quot;:&quot;${ userID }&quot;,&quot;inputId&quot;:&quot;ooui-php-${ checkboxID }&quot;,&quot;required&quot;:false,&quot;data&quot;:{&quot;canReceiveEmail&quot;:true,&quot;username&quot;:&quot;${ username }&quot;,&quot;userId&quot;:${ userID },&quot;userPageLink&quot;:{&quot;path&quot;:&quot;\\/wiki\\/User:${ username }&quot;,&quot;title&quot;:&quot;User:${ username }&quot;,&quot;classes&quot;:&quot;mw-userlink&quot;}},&quot;classes&quot;:[&quot;ext-campaignevents-event-details-participants-checkboxes&quot;]}">
										<input type="checkbox" tabindex="0" name="event-details-participants-checkboxes" value="${ userID }" id="ooui-php-${ checkboxID }" class="oo-ui-inputWidget-input">
										<span class="oo-ui-checkboxInputWidget-checkIcon oo-ui-widget oo-ui-widget-enabled oo-ui-iconElement-icon oo-ui-icon-check oo-ui-iconElement oo-ui-labelElement-invisible oo-ui-iconWidget oo-ui-image-invert"></span>
									</span>
								</span>
							</div>
						</div>
					</td>`;
			};

			const fixtureHTML = `
					<span id="ooui-php-35" class="ext-campaignevents-eventdetails-participants-count-button oo-ui-widget oo-ui-widget-enabled oo-ui-buttonElement oo-ui-buttonElement-frameless oo-ui-iconElement oo-ui-labelElement oo-ui-flaggedElement-progressive oo-ui-buttonWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.ButtonWidget&quot;,&quot;rel&quot;:[&quot;nofollow&quot;],&quot;framed&quot;:false,&quot;icon&quot;:&quot;close&quot;,&quot;label&quot;:&quot;0 out of 0 selected&quot;,&quot;title&quot;:&quot;Deselect&quot;,&quot;flags&quot;:[&quot;progressive&quot;],&quot;classes&quot;:[&quot;ext-campaignevents-eventdetails-participants-count-button&quot;]}"><a role="button" title="Deselect" tabindex="0" rel="nofollow" class="oo-ui-buttonElement-button"><span class="oo-ui-iconElement-icon oo-ui-icon-close oo-ui-image-progressive"></span><span class="oo-ui-labelElement-label">0 out of 0 selected</span><span class="oo-ui-indicatorElement-indicator oo-ui-indicatorElement-noIndicator oo-ui-image-progressive"></span></a></span>
					<div class="ext-campaignevents-eventdetails-participants-container">
						<table class="ext-campaignevents-eventdetails-participants-table">
							<thead>
								<tr>
									<th class="ext-campaignevents-eventdetails-participants-selectall-checkbox-cell">
										<div id="ooui-php-36" class="ext-campaignevents-event-details-select-all-participant-checkbox-field oo-ui-layout oo-ui-fieldLayout oo-ui-fieldLayout-align-inline" data-ooui="{&quot;_&quot;:&quot;OO.ui.FieldLayout&quot;,&quot;fieldWidget&quot;:{&quot;tag&quot;:&quot;ooui-php-37&quot;},&quot;align&quot;:&quot;inline&quot;,&quot;$overlay&quot;:true,&quot;label&quot;:&quot;Select all participants&quot;,&quot;invisibleLabel&quot;:true,&quot;title&quot;:&quot;Select all participants&quot;,&quot;classes&quot;:[&quot;ext-campaignevents-event-details-select-all-participant-checkbox-field&quot;]}">
											<div class="oo-ui-fieldLayout-body">
												<span class="oo-ui-fieldLayout-field">
													<span id="ooui-php-37" class="oo-ui-widget oo-ui-widget-enabled oo-ui-inputWidget oo-ui-checkboxInputWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.CheckboxInputWidget&quot;,&quot;name&quot;:&quot;event-details-select-all-participants&quot;,&quot;inputId&quot;:&quot;ooui-php-1&quot;,&quot;required&quot;:false}"><input type="checkbox" tabindex="0" name="event-details-select-all-participants" value="" id="ooui-php-1" class="oo-ui-inputWidget-input">
														<span class="oo-ui-checkboxInputWidget-checkIcon oo-ui-widget oo-ui-widget-enabled oo-ui-iconElement-icon oo-ui-icon-check oo-ui-iconElement oo-ui-labelElement-invisible oo-ui-iconWidget oo-ui-image-invert"></span>
													</span>
												</span>
												<span class="oo-ui-fieldLayout-header">
													<label title="Select all participants" for="ooui-php-1" class="oo-ui-labelElement-label oo-ui-labelElement-invisible">Select all participants</label>
											</span>
												</div>
										</div>
									</th>
								</tr>
							</thead>
							<tbody>
								<tr class="ext-campaignevents-details-user-row">${ makeCheckbox( 'User1', 1 ) }</tr>
								<tr class="ext-campaignevents-details-user-row">${ makeCheckbox( 'User2', 2 ) }</tr>
								<tr class="ext-campaignevents-details-user-row">${ makeCheckbox( 'User3', 3 ) }</tr>
							</tbody>
						</table>
					</div>
				`;
			$( '#qunit-fixture' ).html( fixtureHTML );
		};

		const waitForEventPropagation = async function () {
			return new Promise( ( r ) => {
				setTimeout( r );
			} );
		};

		const clickCheckboxForUserIDs = async ( ...ids ) => {
			for ( const id of ids ) {
				const $el = $( `input[name="event-details-participants-checkboxes"][value="${ id }"]` );
				if ( $el.length !== 1 ) {
					throw new Error( `Invalid user ID given (got ${ $el.length } elements)` );
				}
				$el.trigger( 'click' );
				await waitForEventPropagation();
			}
		};
		const clickSelectAll = async () => {
			$( 'input[name="event-details-select-all-participants"]' ).trigger( 'click' );
			await waitForEventPropagation();
		};
		const clickDeselectAll = async () => {
			// Note, jQuery's `.trigger( 'click' )` doesn't work because it does not set the `which` property, and
			// it gets lost at some point even when set explicitly.
			$( '.ext-campaignevents-eventdetails-participants-count-button > a' )[ 0 ].click();
			await waitForEventPropagation();
		};
		const triggerParticipantLoad = async () => {
			$( '.ext-campaignevents-eventdetails-participants-container' ).trigger( 'scroll' );
			// First wait for the `scroll` event listener to run
			await waitForEventPropagation();
			// Then wait for the `.then()` callback attached to the API request to run
			await waitForEventPropagation();
		};
		const countCheckedVisibleUsers = () => $( 'input[name="event-details-participants-checkboxes"]:checked' ).length;

		const makeServerRespondWithUsers = ( server, userIDs ) => {
			const response = [];
			for ( const id of userIDs ) {
				/* eslint-disable camelcase */
				response.push( {
					participant_id: id,
					user_id: id,
					user_name: `User${ id }`,
					user_page: { path: '#', title: `User${ id }`, classes: 'mw-userLink' },
					user_registered_at_formatted: '2025-01-01T12:00:00Z',
					user_is_valid_recipient: true
				} );
				/* eslint-enable camelcase */
			}
			server.respondImmediately = true;
			server.respondWith(
				'GET',
				/\/campaignevents\/v0\/event_registration\/\d+\/participants/,
				[
					200,
					{ 'Content-Type': 'application/json' },
					JSON.stringify( response )
				]
			);
		};

		const assertClassState = ( assert, expectedAmount, expectedUserIDs, expectedInverted, baseMsg ) => {
			assert.strictEqual(
				participantsManager.selectedParticipantsAmount,
				expectedAmount,
				`${ baseMsg }, amount of selected users`
			);
			assert.deepEqual(
				participantsManager.selectedParticipantIDs,
				expectedUserIDs,
				`${ baseMsg }, list of selected IDs`
			);
			assert.strictEqual(
				participantsManager.isSelectionInverted,
				expectedInverted,
				`${ baseMsg }, whether selection is inverted`
			);
		};
		const assertVisibleUsers = ( assert, userIDs ) => {
			const $boxes = $( 'input[name="event-details-participants-checkboxes"]' ),
				visibleIDs = $boxes.map( function () {
					return parseInt( this.value );
				} ).get();

			assert.deepEqual( visibleIDs, userIDs, 'List of visible users should match' );
			assert.strictEqual(
				participantsManager.lastParticipantID,
				userIDs.at( -1 ),
				'Last loaded participant ID should belong to the last listed user.'
			);
		};

		QUnit.test( 'Initially, no participants are selected', ( assert ) => {
			setupFixture();
			participantsManager.init();

			assertVisibleUsers( assert, [ 1, 2, 3 ] );
			assert.strictEqual( countCheckedVisibleUsers(), 0, 'No checkboxes are checked' );
			assertClassState( assert, 0, [], false, 'Initial state' );
		} );

		QUnit.module(
			'"Select all" and "deselect all"',
			QUnit.newMwEnvironment( {
				config: {
					...testConfig,
					wgCampaignEventsEventDetailsParticipantsTotal: 6
				},
				beforeEach: function () {
					setupFixture();
					this.server = this.sandbox.useFakeServer();
					makeServerRespondWithUsers( this.server, [ 4, 5, 6 ] );
					participantsManager.init();
				}
			} ),
			() => {
				QUnit.test( 'Work on the initial group of checkboxes', async ( assert ) => {
					assertVisibleUsers( assert, [ 1, 2, 3 ] );

					await clickSelectAll();
					assert.strictEqual(
						countCheckedVisibleUsers(),
						3,
						'All checkboxes should be checked after "select all" is clicked'
					);

					await clickDeselectAll();
					assert.strictEqual(
						countCheckedVisibleUsers(),
						0,
						'No checkboxes should be checked after "deselect all" is clicked'
					);
				} );

				QUnit.test( 'Work on dynamically-loaded checkboxes', async ( assert ) => {
					await triggerParticipantLoad();
					assertVisibleUsers( assert, [ 1, 2, 3, 4, 5, 6 ] );

					await clickSelectAll();
					assert.strictEqual(
						countCheckedVisibleUsers(),
						6,
						'All checkboxes should be checked after "select all" is clicked'
					);

					await clickDeselectAll();
					assert.strictEqual(
						countCheckedVisibleUsers(),
						0,
						'No checkboxes should be checked after "deselect all" is clicked'
					);
				} );
			}
		);

		QUnit.module(
			'Checkboxes selection - partial list of users',
			QUnit.newMwEnvironment( {
				config: testConfig,
				beforeEach: () => {
					setupFixture();
					participantsManager.init();
				}
			} ),
			() => {
				QUnit.test( 'Manual selection and deselection', async ( assert ) => {
					await clickCheckboxForUserIDs( 1 );
					assertClassState(
						assert,
						1,
						[ 1 ],
						false,
						'After first selection'
					);

					await clickCheckboxForUserIDs( 2 );
					assertClassState(
						assert,
						2,
						[ 1, 2 ],
						false,
						'After second selection'
					);

					await clickCheckboxForUserIDs( 3 );
					assertClassState(
						assert,
						3,
						[ 1, 2, 3 ],
						false,
						'After the last visible user has been selected'
					);

					await clickCheckboxForUserIDs( 1 );
					assertClassState(
						assert,
						2,
						[ 2, 3 ],
						false,
						'After first deselection'
					);

					await clickCheckboxForUserIDs( 3 );
					assertClassState(
						assert,
						1,
						[ 2 ],
						false,
						'After second deselection'
					);

					await clickCheckboxForUserIDs( 2 );
					assertClassState(
						assert,
						0,
						[],
						false,
						'After final deselection'
					);
				} );

				QUnit.test( 'Use "select all", then deselect manually', async ( assert ) => {
					await clickSelectAll();
					assertClassState(
						assert,
						10,
						null,
						false,
						'After clicking "select all"'
					);

					await clickCheckboxForUserIDs( 2 );
					assertClassState(
						assert,
						9,
						[ 2 ],
						true,
						'After deselecting user #2'
					);

					await clickCheckboxForUserIDs( 1, 3 );
					assertClassState(
						assert,
						7,
						[ 2, 1, 3 ],
						true,
						'After deselecting all 3 visible users'
					);
				} );

				QUnit.test( 'Use "select all", then deselect all', async ( assert ) => {
					await clickSelectAll();
					assertClassState(
						assert,
						10,
						null,
						false,
						'After clicking "select all"'
					);

					await clickDeselectAll();
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all'
					);
				} );

				QUnit.test( 'Select manually, then deselect all', async ( assert ) => {
					await clickCheckboxForUserIDs( 1, 2, 3 );
					assertClassState(
						assert,
						3,
						[ 1, 2, 3 ],
						false,
						'After manual selection'
					);

					await clickDeselectAll();
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all'
					);
				} );
			}
		);

		QUnit.module(
			'Checkboxes selection - full list of users',
			QUnit.newMwEnvironment( {
				config: {
					...testConfig,
					wgCampaignEventsEventDetailsParticipantsTotal: 3
				},
				beforeEach: () => {
					setupFixture();
					participantsManager.init();
				}
			} ),
			() => {
				QUnit.test( 'Manual selection and deselection', async ( assert ) => {
					await clickCheckboxForUserIDs( 1 );
					assertClassState(
						assert,
						1,
						[ 1 ],
						false,
						'After first selection'
					);

					await clickCheckboxForUserIDs( 2 );
					assertClassState(
						assert,
						2,
						[ 1, 2 ],
						false,
						'After second selection'
					);

					await clickCheckboxForUserIDs( 3 );
					assertClassState(
						assert,
						3,
						null,
						false,
						'After all checkboxes have been checked'
					);

					await clickCheckboxForUserIDs( 1 );
					assertClassState(
						assert,
						2,
						[ 1 ],
						true,
						'After first deselection'
					);

					await clickCheckboxForUserIDs( 3 );
					assertClassState(
						assert,
						1,
						[ 1, 3 ],
						true,
						'After second deselection'
					);

					await clickCheckboxForUserIDs( 2 );
					assertClassState(
						assert,
						0,
						[],
						false,
						'After final deselection'
					);
				} );

				QUnit.test( 'Use "select all", then deselect manually', async ( assert ) => {
					await clickSelectAll();
					assertClassState(
						assert,
						3,
						null,
						false,
						'After clicking "select all"'
					);

					await clickCheckboxForUserIDs( 2 );
					assertClassState(
						assert,
						2,
						[ 2 ],
						true,
						'After deselecting user #2'
					);

					await clickCheckboxForUserIDs( 1, 3 );
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all 3 users'
					);
				} );

				QUnit.test( 'Use "select all", then deselect all', async ( assert ) => {
					await clickSelectAll();
					assertClassState(
						assert,
						3,
						null,
						false,
						'After clicking "select all"'
					);

					await clickDeselectAll();
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all'
					);
				} );

				QUnit.test( 'Select manually, then deselect all', async ( assert ) => {
					await clickCheckboxForUserIDs( 1, 2, 3 );
					assertClassState(
						assert,
						3,
						null,
						false,
						'After all checkboxes have been checked'
					);

					await clickDeselectAll();
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all'
					);
				} );
			}
		);

		const testInitialStateOfNewCheckboxes = ( totalParticipants ) => {
			QUnit.test( 'If no users are selected, new users are not loaded as selected', async ( assert ) => {
				await triggerParticipantLoad();
				assertVisibleUsers( assert, [ 1, 2, 3, 4, 5, 6 ] );
				assert.strictEqual( countCheckedVisibleUsers(), 0, 'No visible users should be checked' );
				assertClassState( assert, 0, [], false, 'After loading participants' );
			} );

			QUnit.test( 'If all visible users are selected, new users are not loaded as selected', async ( assert ) => {
				await clickCheckboxForUserIDs( 1, 2, 3 );

				await triggerParticipantLoad();
				assertVisibleUsers( assert, [ 1, 2, 3, 4, 5, 6 ] );
				assert.strictEqual( countCheckedVisibleUsers(), 3, 'Only the initially-selected users should be selected' );
				assertClassState( assert, 3, [ 1, 2, 3 ], false, 'After loading participants' );
			} );

			QUnit.test( 'If "select all" is checked, new users are loaded as selected', async ( assert ) => {
				await clickSelectAll();

				await triggerParticipantLoad();
				assertVisibleUsers( assert, [ 1, 2, 3, 4, 5, 6 ] );
				assert.strictEqual( countCheckedVisibleUsers(), 6, 'All users should be selected' );
				assertClassState( assert, totalParticipants, null, false, 'After loading participants' );
			} );

			QUnit.test( 'If "select all" is checked but someone has been deselected, new users are loaded as selected', async ( assert ) => {
				await clickSelectAll();
				await clickCheckboxForUserIDs( 2 );

				await triggerParticipantLoad();
				assertVisibleUsers( assert, [ 1, 2, 3, 4, 5, 6 ] );
				assert.strictEqual( countCheckedVisibleUsers(), 5, 'All but one user should be selected' );
				assertClassState( assert, totalParticipants - 1, [ 2 ], true, 'After loading participants' );
			} );
		};

		QUnit.module(
			'Checkboxes selection - start with partial list of users and load more (but not all)',
			QUnit.newMwEnvironment( {
				config: testConfig,
				beforeEach: function () {
					setupFixture();
					this.server = this.sandbox.useFakeServer();
					makeServerRespondWithUsers( this.server, [ 4, 5, 6 ] );
					participantsManager.init();
				}
			} ),
			() => {
				testInitialStateOfNewCheckboxes( 10 );

				QUnit.test( 'Manual selection and deselection', async ( assert ) => {
					await triggerParticipantLoad();

					await clickCheckboxForUserIDs( 1 );
					assertClassState(
						assert,
						1,
						[ 1 ],
						false,
						'After selecting a checkbox from the initial group'
					);

					await clickCheckboxForUserIDs( 6 );
					assertClassState(
						assert,
						2,
						[ 1, 6 ],
						false,
						'After selecting a checkbox from the second group'
					);

					await clickCheckboxForUserIDs( 2, 3, 4, 5 );
					assertClassState(
						assert,
						6,
						[ 1, 6, 2, 3, 4, 5 ],
						false,
						'After selecting all visible checkboxes'
					);

					await clickCheckboxForUserIDs( 5 );
					assertClassState(
						assert,
						5,
						[ 1, 6, 2, 3, 4 ],
						false,
						'After deselecting one from the second group'
					);

					await clickCheckboxForUserIDs( 1, 2, 4, 6 );
					assertClassState(
						assert,
						1,
						[ 3 ],
						false,
						'After deselecting all but one from the initial group'
					);

					await clickCheckboxForUserIDs( 3 );
					assertClassState(
						assert,
						0,
						[],
						false,
						'After final deselection'
					);
				} );

				QUnit.test( 'Use "select all", then deselect manually', async ( assert ) => {
					await triggerParticipantLoad();

					await clickSelectAll();
					assertClassState(
						assert,
						10,
						null,
						false,
						'After clicking "select all"'
					);

					await clickCheckboxForUserIDs( 2 );
					assertClassState(
						assert,
						9,
						[ 2 ],
						true,
						'After deselecting user from the first group'
					);

					await clickCheckboxForUserIDs( 5 );
					assertClassState(
						assert,
						8,
						[ 2, 5 ],
						true,
						'After deselecting user from the second group'
					);

					await clickCheckboxForUserIDs( 1, 3, 4, 6 );
					assertClassState(
						assert,
						4,
						[ 2, 5, 1, 3, 4, 6 ],
						true,
						'After deselecting all visible users'
					);
				} );

				QUnit.test( 'Use "select all", then deselect all', async ( assert ) => {
					await triggerParticipantLoad();

					await clickSelectAll();
					assertClassState(
						assert,
						10,
						null,
						false,
						'After clicking "select all"'
					);

					await clickDeselectAll();
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all'
					);
				} );

				QUnit.test( 'Select manually, then deselect all', async ( assert ) => {
					await triggerParticipantLoad();

					await clickCheckboxForUserIDs( 4, 5, 6 );
					assertClassState(
						assert,
						3,
						[ 4, 5, 6 ],
						false,
						'After selecting all checkboxes from the second group'
					);

					await clickCheckboxForUserIDs( 1, 2, 3 );
					assertClassState(
						assert,
						6,
						[ 4, 5, 6, 1, 2, 3 ],
						false,
						'After all checkboxes have been checked'
					);

					await clickDeselectAll();
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all'
					);
				} );
			}
		);

		QUnit.module(
			'Checkboxes selection - start with partial list of users and load full list',
			QUnit.newMwEnvironment( {
				config: {
					...testConfig,
					wgCampaignEventsEventDetailsParticipantsTotal: 6
				},
				beforeEach: function () {
					setupFixture();
					this.server = this.sandbox.useFakeServer();
					makeServerRespondWithUsers( this.server, [ 4, 5, 6 ] );
					participantsManager.init();
				}
			} ),
			() => {
				testInitialStateOfNewCheckboxes( 6 );

				QUnit.test( 'Manual selection and deselection', async ( assert ) => {
					await triggerParticipantLoad();

					await clickCheckboxForUserIDs( 1 );
					assertClassState(
						assert,
						1,
						[ 1 ],
						false,
						'After selecting a checkbox from the initial group'
					);

					await clickCheckboxForUserIDs( 6 );
					assertClassState(
						assert,
						2,
						[ 1, 6 ],
						false,
						'After selecting a checkbox from the second group'
					);

					await clickCheckboxForUserIDs( 2, 3, 4, 5 );
					assertClassState(
						assert,
						6,
						null,
						false,
						'After selecting all visible checkboxes'
					);

					await clickCheckboxForUserIDs( 5 );
					assertClassState(
						assert,
						5,
						[ 5 ],
						true,
						'After deselecting one from the second group'
					);

					await clickCheckboxForUserIDs( 1, 2, 4, 6 );
					assertClassState(
						assert,
						1,
						[ 5, 1, 2, 4, 6 ],
						true,
						'After deselecting all but one from the initial group'
					);

					await clickCheckboxForUserIDs( 3 );
					assertClassState(
						assert,
						0,
						[],
						false,
						'After final deselection'
					);
				} );

				QUnit.test( 'Use "select all", then deselect manually', async ( assert ) => {
					await triggerParticipantLoad();

					await clickSelectAll();
					assertClassState(
						assert,
						6,
						null,
						false,
						'After clicking "select all"'
					);

					await clickCheckboxForUserIDs( 2 );
					assertClassState(
						assert,
						5,
						[ 2 ],
						true,
						'After deselecting user from the first group'
					);

					await clickCheckboxForUserIDs( 5 );
					assertClassState(
						assert,
						4,
						[ 2, 5 ],
						true,
						'After deselecting user from the second group'
					);

					await clickCheckboxForUserIDs( 1, 3, 4, 6 );
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all visible users'
					);
				} );

				QUnit.test( 'Use "select all", then deselect all', async ( assert ) => {
					await triggerParticipantLoad();

					await clickSelectAll();
					assertClassState(
						assert,
						6,
						null,
						false,
						'After clicking "select all"'
					);

					await clickDeselectAll();
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all'
					);
				} );

				QUnit.test( 'Select manually, then deselect all', async ( assert ) => {
					await triggerParticipantLoad();

					await clickCheckboxForUserIDs( 4, 5, 6 );
					assertClassState(
						assert,
						3,
						[ 4, 5, 6 ],
						false,
						'After selecting all checkboxes from the second group'
					);

					await clickCheckboxForUserIDs( 1, 2, 3 );
					assertClassState(
						assert,
						6,
						null,
						false,
						'After all checkboxes have been checked'
					);

					await clickDeselectAll();
					assertClassState(
						assert,
						0,
						[],
						false,
						'After deselecting all'
					);
				} );
			}
		);
	}
);
