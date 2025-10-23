import Page from 'wdio-mediawiki/Page';

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
		return $( '.mw-htmlform-submit-buttons button[value="Edit registration"]' );
	}

	get enableRegistration() {
		return $( '.mw-htmlform-submit-buttons button[value="Enable registration"]' );
	}

	get eventPage() {
		return $( 'input[name="wpEventPage"]' );
	}

	get generalError() {
		return $( '.mw-htmlform-ooui-header-errors .oo-ui-messageWidget' );
	}

	get participationOptionsSelector() {
		return $( '#mw-input-wpParticipationOptions div[role="radiogroup"]' );
	}

	get startDateInput() {
		return $( '#mw-input-wpEventStart.ext-campaignevents-time-input-enhanced' ).$( '[size="2"]' );
	}

	get startYearInput() {
		return $( '#mw-input-wpEventStart.ext-campaignevents-time-input-enhanced' ).$( '[size="4"]' );
	}

	get endDateInput() {
		return $( '#mw-input-wpEventEnd.ext-campaignevents-time-input-enhanced' ).$( '[size="2"]' );
	}

	get endYearInput() {
		return $( '#mw-input-wpEventEnd.ext-campaignevents-time-input-enhanced' ).$( '[size="4"]' );
	}

	get organizersInput() {
		// Target the <input> inside the infused AND enhanced version of the field.
		return $( '.ext-campaignevents-organizers-input-enhanced .oo-ui-menuTagMultiselectWidget input' );
	}

	get typesInput() {
		return $( '.ext-campaignevents-edit-eventtypes-input .oo-ui-tagMultiselectWidget-content input' );
	}

	get countrySelector() {
		return $( '#mw-input-wpEventMeetingCountryCode' );
	}

	get hiddenCountrySelector() {
		return $( 'select[name="wpEventMeetingCountryCode"]' );
	}

	get header() {
		return $( 'h1' );
	}

	async open() {
		await super.openTitle( 'Special:EnableEventRegistration' );
	}

	async loseFocus() {
		await this.header.click();
	}

	async selectCountry( countryCode ) {
		await this.countrySelector.click();
		const countryText = await this.hiddenCountrySelector.$( `option[value="${ countryCode }` )
			.getHTML( { includeSelectorTag: false } );
		const menuItem = await $( `.oo-ui-menuOptionWidget=${ countryText }` );
		await this.chooseMenuOption( menuItem );
	}

	async selectParticipationOptions( participationOptions, countryCode ) {
		if ( participationOptions === 'online' ) {
			await this.participationOptionsSelector.$( 'label:nth-of-type(1)' ).click();
			return;
		}

		if ( !countryCode ) {
			throw new Error( `Country code is required for participation option ${ participationOptions }` );
		}
		const optionIdx = participationOptions === 'inperson' ? 2 : 3;
		await this.participationOptionsSelector.$( `label:nth-of-type(${ optionIdx })` ).click();
		await this.selectCountry( countryCode );
	}

	/**
	 * @param {Object} date With 'year' and 'day' as properties
	 */
	async setStartDate( date ) {
		await this.startYearInput.click();
		await browser.keys( date.year.toString() );
		await this.startDateInput.click();
		await browser.keys( date.day.toString() );
		await this.loseFocus();
	}

	/**
	 * @param {Object} date With 'year' and 'day' as properties
	 */
	async setEndDate( date ) {
		await this.endYearInput.click();
		await browser.keys( date.year.toString() );
		await this.endDateInput.click();
		await browser.keys( date.day.toString() );
		await this.loseFocus();
	}

	async chooseMenuOption( menuItem ) {
		await menuItem.waitForDisplayed();
		await menuItem.waitForClickable();
		await menuItem.moveTo();
		await menuItem.waitUntil( async function () {
			const classes = await this.getAttribute( 'class' );
			return classes.includes( 'oo-ui-optionWidget-highlighted' );
		} );
		await menuItem.click();
	}

	/**
	 * @param {string} organizer to be added to event
	 */
	async addOrganizer( organizer ) {
		await this.organizersInput.setValue( organizer );
		const menuItem = await $( `.oo-ui-menuSelectWidget [id='${ organizer }']` );
		await this.chooseMenuOption( menuItem );
	}

	/**
	 * @param {string[]} types
	 */
	async addTypes( types ) {
		await this.typesInput.click();
		for ( const type of types ) {
			// Assumes English as interface language, as well as a correspondence between IDs and
			// localized names that is not guaranteed to remain there.
			const typeName = ( type.charAt( 0 ).toUpperCase() + type.slice( 1 ) ).replace( '-', ' ' );
			// Brittle selector, but there isn't much we can do because the OOUI menu has no
			// field-specific identifiers.
			const menuItem = await $( `.oo-ui-menuOptionWidget=${ typeName }` );
			await this.chooseMenuOption( menuItem );
		}
	}

	/**
	 * Wait until the OOUI form has been infused, to make sure we interact with JS widgets only.
	 *
	 * @return {Promise<void>}
	 */
	async waitForFormInfusion() {
		await browser.waitUntil(
			// Infusion empties the data-ooui attribute, so wait until the attribute becomes empty
			// on all elements with auto-infusion.
			() => browser.execute( () => $( '.mw-htmlform-autoinfuse[data-ooui!=""]' ).length === 0 ),
			{ timeoutMsg: 'Form fields weren\'t infused' }
		);
	}

	/**
	 * Enable an event.
	 *
	 * Pass in an an event, start date and end date, and an event will be created
	 *
	 * @param {string} eventPage Prefixed title of the event page, such as 'Event:Test'
	 * @param {Object} start the day and year to start the event, e.g. {day: 15, year: 2023}
	 * @param {Object} end the day and year to end the event, e.g. {day: 15, year: 2024}
	 */
	async enableEvent( eventPage, start = this.startDefault, end = this.endDefault ) {
		await this.open();
		await this.waitForFormInfusion();
		await this.eventPage.setValue( eventPage );
		await this.setStartDate( start );
		await this.setEndDate( end );
		await this.addTypes( [ 'editing-event' ] );
		await this.enableRegistration.click();
	}

	/**
	 * Edit an event.
	 *
	 * Pass in an an event, start date and end date, and an event will be created
	 *
	 * @param {number} id id of the event to be edited
	 * @param {string} eventPage Prefixed title of the event page, such as 'Event:Test'
	 * @param {Object} start the day and year to start the event, e.g. {day: 15, year: 2023}
	 * @param {Object} end the day and year to end the event, e.g. {day: 15, year: 2024}
	 * @param {string} participationOptions choose from 'inperson', 'hybrid', or 'online'
	 * @param {string} countryCode
	 * @param {string} organizer Username of a user to add as organizer
	 */
	async editEvent( {
		id,
		eventPage,
		start,
		end,
		participationOptions,
		countryCode,
		organizer
	} ) {
		await super.openTitle( `Special:EditEventRegistration/${ id }` );
		await this.waitForFormInfusion();

		if ( eventPage ) {
			await this.eventPage.setValue( eventPage );
		}
		if ( start ) {
			await this.setStartDate( start );
		}
		if ( end ) {
			await this.setEndDate( end );
		}
		if ( participationOptions ) {
			await this.selectParticipationOptions( participationOptions, countryCode );
		}
		if ( organizer ) {
			await this.addOrganizer( organizer );
		}

		await this.editRegistration.click();
	}
}

export default new EventRegistrationPage();
