( function () {
	/**
	 * Mounts the app with the dialog. Precondition: there is at least one event
	 * in wgCampaignEventsEventsForAssociation.
	 */
	function mountApp() {
		const Vue = require( 'vue' );
		const App = require( './components/App.vue' );

		const appContainter = document.createElement( 'div' );
		appContainter.id = 'ext-campaignevents-postedit-vue-root';
		document.body.append( appContainter );

		Vue.createMwApp( App )
			.mount( appContainter );
	}

	if ( mw.config.get( 'wgCampaignEventsEventsForAssociation' ) ) {
		// Variable set server-side in PostEditHandler. Means the page was just reloaded after an
		// edit (source editor), so mount the app immediately. Note, the server-side hook handler
		// guarantees that there is at least one event.
		mountApp();
	} else {
		// Module loaded as a VE plugin (or potentially manually, e.g. for Wikibase). Mount the app
		// after the actual edit, lazy-loading the list of events...
		// Not in the NS_EVENT namespace, though (T406672)
		if ( mw.config.get( 'wgNamespaceNumber' ) === 1728 ) {
			return;
		}
		const lazyMount = async () => {
			const userEvents = await new mw.Rest().get( '/campaignevents/v0/participant/self/events_for_edit' );
			mw.config.set( 'wgCampaignEventsEventsForAssociation', userEvents );
			if ( userEvents.length ) {
				mountApp();
			}
			// Remove the handler to make sure we only mount the app once.
			mw.hook( 'postEdit' ).remove( lazyMount );
			mw.hook( 'wikibase.statement.saved' ).remove( lazyMount );
			mw.hook( 'wikibase.statement.removed' ).remove( lazyMount );
		};
		mw.hook( 'postEdit' ).add( lazyMount );
		mw.hook( 'wikibase.statement.saved' ).add( lazyMount );
		mw.hook( 'wikibase.statement.removed' ).add( lazyMount );
	}
}() );
