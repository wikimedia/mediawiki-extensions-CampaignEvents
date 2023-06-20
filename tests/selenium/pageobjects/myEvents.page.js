'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class MyEventsPage extends Page {

	get manageEventMenuButton() { return $( '.ext-campaignevents-eventspager-cell-manage .oo-ui-icon-ellipsis' ); }
	get closeRegistrationButton() { return $( '.ext-campaignevents-eventspager-cell-manage' ).$( '*=Close' ); }
	get deleteRegistrationButton() { return $( '.ext-campaignevents-eventspager-cell-manage' ).$( '*=Delete' ); }
	get notification() { return $( '.mw-notification' ); }
	get firstEvent() { return $( '.ext-campaignevents-eventspager-eventpage-link' ); }
	get deleteConfirmationButton() { return $( '.oo-ui-messageDialog' ).$( '.oo-ui-buttonElement-button=Delete' ); }
	get filterMenu() { return $( '.ext-campaignevents-myevents-filter-widget > .oo-ui-buttonElement' ); }
	get eventStatusFilter() { return $( '#mw-input-wpStatus' ); }
	get openEventsFilter() { return $( '.oo-ui-defaultOverlay .oo-ui-selectWidget' ).$( '.oo-ui-optionWidget=Open events' ); }
	get filtersSubmitButton() { return $( '#ext-campaignevents-myevents-form button[type="submit"]' ); }

	open() {
		super.openTitle( 'Special:MyEvents' );
	}

	async filterByOpenRegistrations() {
		await this.filterMenu.click();
		await this.eventStatusFilter.click();
		await this.openEventsFilter.click();
		await this.filtersSubmitButton.click();
	}

	async closeFirstRegistration() {
		await this.manageEventMenuButton.click();
		await this.closeRegistrationButton.click();
	}

	async deleteFirstRegistration() {
		await this.manageEventMenuButton.click();
		await this.deleteRegistrationButton.click();
		await this.deleteConfirmationButton.click();
	}
}

module.exports = new MyEventsPage();
