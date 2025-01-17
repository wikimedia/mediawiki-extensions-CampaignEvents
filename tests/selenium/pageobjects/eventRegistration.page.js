'use strict';

const Page = require( 'wdio-mediawiki/Page' ),
	Api = require( 'wdio-mediawiki/Api' );

class EventRegistrationPage extends Page {

	constructor() {
		super();
		this.d = new Date();
		this.startDefault = {
			day: this.d.getDate() + 2,
			year: this.d.getFullYear()
		};
		this.endDefault = {
			day: this.d.getDate() + 2,
			year: this.d.getFullYear() + 2
		};
	}

	get editRegistration() {
		return $( '[value="Edit registration"]' );
	}

	get enableRegistration() {
		return $( '[value="Enable registration"]' );
	}

	get eventPage() {
		return $( '[name="wpEventPage"]' );
	}

	get generalError() {
		return $( '[role=alert]' );
	}

	get meetingTypeSelector() {
		return $( '#mw-input-wpEventMeetingType div[role="radiogroup"]' );
	}

	get startDateInput() {
		return $( '#mw-input-wpEventStart' ).$( '[size="2"]' );
	}

	get startYearInput() {
		return $( '#mw-input-wpEventStart' ).$( '[size="4"]' );
	}

	get endDateInput() {
		return $( '#mw-input-wpEventEnd' ).$( '[size="2"]' );
	}

	get endYearInput() {
		return $( '#mw-input-wpEventEnd' ).$( '[size="4"]' );
	}

	get organizersInput() {
		// Note, this needs to target the <input> inside the infused version of the field.
		return $( '.ext-campaignevents-organizers-multiselect-input .oo-ui-menuTagMultiselectWidget input' );
	}

	get body() {
		return $( 'body' );
	}

	async open() {
		await super.openTitle( 'Special:EnableEventRegistration' );
	}

	loseFocus() {
		return this.body.click();
	}

	async createEventPage( event ) {
		const bot = await Api.bot();
		await bot.edit( event, '', '' );
	}

	async selectMeetingType( meetingType ) {
		if ( meetingType === 'online' ) {
			await this.meetingTypeSelector.$( 'label:nth-of-type(1)' ).click();
		} else if ( meetingType === 'inperson' ) {
			await this.meetingTypeSelector.$( 'label:nth-of-type(2)' ).click();
		} else if ( meetingType === 'hybrid' ) {
			await this.meetingTypeSelector.$( 'label:nth-of-type(3)' ).click();
		}
	}

	/**
	 * @param {Object} date With 'year' and 'day' as properties
	 */
	async setStartDate( date ) {
		await this.startYearInput.setValue( ( date.year ).toString() );
		await this.loseFocus();
		await this.startDateInput.setValue( ( date.day ).toString() );
		await this.loseFocus();
	}

	/**
	 * @param {Object} date With 'year' and 'day' as properties
	 */
	async setEndDate( date ) {
		await this.endYearInput.setValue( ( date.year ).toString() );
		await this.loseFocus();
		await this.endDateInput.setValue( ( date.day ).toString() );
		await this.loseFocus();
	}

	/**
	 * @param {string} organizer to be added to event
	 */
	async addOrganizer( organizer ) {
		await this.organizersInput.setValue( organizer );
		const menuItem = await $( `.oo-ui-menuSelectWidget [id='${ organizer }']` );
		await menuItem.waitForDisplayed();
		await menuItem.click();
	}

	/**
	 * Enable an event.
	 *
	 * Pass in an an event, start date and end date, and an event will be created
	 *
	 * @param {string} event a namespaced string beginning with 'Event:'
	 * example: 'Event:Test'
	 * @param {Object} start the day and year to start the event
	 * example: {day: 15, year: 2023}
	 * @param {Object} end the day and year to end the event
	 * example: {day: 15, year: 2024}
	 */
	async enableEvent( event, start = this.startDefault, end = this.endDefault ) {
		await this.open();
		await this.eventPage.setValue( event );
		await this.setStartDate( start );
		await this.setEndDate( end );
		await this.enableRegistration.click();
	}

	/**
	 * Edit an event.
	 *
	 * Pass in an an event, start date and end date, and an event will be created
	 *
	 * @param {number} id id of the event to be edited
	 * example: 22
	 * @param {string} event a namespaced string beginning with 'Event:'
	 * example: 'Event:Test'
	 * @param {Object} start the day and year to start the event
	 * example: {day: 15, year: 2023}
	 * @param {Object} end the day and year to end the event
	 * example: {day: 15, year: 2024}
	 * @param {string} meetingType choose from 'inperson', 'hybrid', or 'online'
	 * example: 'inperson'
	 */
	async editEvent( {
		id,
		event,
		start,
		end,
		meetingType,
		organizer
	} ) {
		await super.openTitle( `Special:EditEventRegistration/${ id }` );

		if ( event ) {
			await this.eventPage.setValue( event );
		}
		if ( start ) {
			await this.setStartDate( start );
		}
		if ( end ) {
			await this.setEndDate( end );
		}
		if ( meetingType ) {
			await this.selectMeetingType( meetingType );
		}
		if ( organizer ) {
			await this.addOrganizer( organizer );
		}

		await this.editRegistration.click();
	}
}

module.exports = new EventRegistrationPage();
