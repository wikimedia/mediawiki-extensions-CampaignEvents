'use strict';

const Page = require( 'wdio-mediawiki/Page' ),
	Api = require( 'wdio-mediawiki/Api' );

class EnableEventRegistrationPage extends Page {

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

	get enableRegistration() { return $( '[value="Enable registration"]' ); }
	get eventPage() { return $( '[name="wpEventPage"]' ); }
	get generalError() { return $( '[role=alert]' ); }
	get startDateInput() { return $( '#mw-input-wpEventStart' ).$( '[size="2"]' ); }
	get startYearInput() { return $( '#mw-input-wpEventStart' ).$( '[size="4"]' ); }
	get endDateInput() { return $( '#mw-input-wpEventEnd' ).$( '[size="2"]' ); }
	get endYearInput() { return $( '#mw-input-wpEventEnd' ).$( '[size="4"]' ); }
	get feedback() { return $( '#mw-content-text' ); }
	get body() { return $( 'body' ); }

	open() {
		super.openTitle( 'Special:EnableEventRegistration' );
	}

	getTestString( prefix = '' ) {
		return prefix + Date.now().toString() + '-Iñtërnâtiônàlizætiøn';
	}

	loseFocus() {
		return this.body.click();
	}

	async createEvent( event ) {
		const bot = await Api.bot();
		await bot.edit( event, '', '' );
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
		this.open();
		await this.eventPage.setValue( event );
		await this.startYearInput.setValue( ( start.year ).toString() );
		await this.startDateInput.setValue( ( start.day ).toString() );
		await this.endDateInput.setValue( ( end.day ).toString() );
		await this.endYearInput.setValue( ( end.year ).toString() );
		await this.loseFocus();
		await this.enableRegistration.click();
	}
}

module.exports = new EnableEventRegistrationPage();
