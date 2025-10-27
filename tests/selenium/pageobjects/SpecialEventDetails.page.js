import Page from 'wdio-mediawiki/Page';

class SpecialEventDetails extends Page {
	get organizersList() {
		return $( '.ext-campaignevents-eventdetails-organizers-list' );
	}

	async open( eventID ) {
		await super.openTitle( `Special:EventDetails/${ eventID }` );
	}
}

export default new SpecialEventDetails();
