'use strict';

const Page = require( 'wdio-mediawiki/Page' );

class MyEventsPage extends Page {

	get manageEventMenuButton() {
		return $( '.ext-campaignevents-events-table-cell-manage .oo-ui-buttonMenuSelectWidget' );
	}

	get firstRegistrationNameCell() {
		return $( '.TablePager_col_event_name' );
	}

	get deleteRegistrationButton() {
		return this.manageEventMenuButton.$( '*=Delete event' );
	}

	get notification() {
		return $( '.mw-notification' );
	}

	get firstEvent() {
		return $( '.ext-campaignevents-events-table-eventpage-link' );
	}

	get deleteConfirmationButton() {
		return $( '.oo-ui-messageDialog' ).$( '.oo-ui-buttonElement-button=Delete' );
	}

	get filterMenu() {
		return $( '.ext-campaignevents-myevents-filter-widget > .oo-ui-buttonElement' );
	}

	get eventStatusFilter() {
		return $( '#mw-input-wpStatus' );
	}

	get openEventsFilter() {
		return $( '.oo-ui-defaultOverlay .oo-ui-selectWidget' ).$( '.oo-ui-optionWidget=Open events' );
	}

	get eventNameFilter() {
		return $( 'input[name="wpSearch"]' );
	}

	get filtersSubmitButton() {
		return $( '#ext-campaignevents-myevents-form button[type="submit"]' );
	}

	async open() {
		await super.openTitle( 'Special:MyEvents' );
	}

	async filterByOpenRegistrations() {
		await this.filterMenu.click();
		await this.eventStatusFilter.click();
		await this.openEventsFilter.click();
		await this.filtersSubmitButton.click();
	}

	async filterByName( name ) {
		await this.eventNameFilter.setValue( name );
		await this.filtersSubmitButton.click();
	}

	async deleteFirstRegistration() {
		await this.manageEventMenuButton.click();
		await this.deleteRegistrationButton.click();
		// Wait for the dialog actions to become functional (T382143)
		await browser.waitUntil(
			() => browser.execute(
				() => $( '.ext-campaignevents-myevents-delete-confirm-dialog.oo-ui-window' )
					.hasClass( 'oo-ui-window-ready' )
			),
			{ timeoutMsg: 'Dialog is not ready' }
		);
		await this.deleteConfirmationButton.click();
	}
}

module.exports = new MyEventsPage();
