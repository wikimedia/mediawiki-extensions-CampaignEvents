( function () {
	/**
	 * Mounts the contribution-association app. Precondition: there is at least one event
	 * in wgCampaignEventsEventsForAssociation.
	 */
	function mountAssociationApp() {
		const Vue = require( 'vue' );
		const App = require( './components/App.vue' );

		const appContainer = document.createElement( 'div' );
		appContainer.id = 'ext-campaignevents-postedit-vue-root';
		document.body.append( appContainer );

		Vue.createMwApp( App )
			.mount( appContainer );
	}

	/**
	 * Mounts the event-discovery app. Precondition: wgCampaignEventsDiscoveryEvents is set.
	 */
	function mountDiscoveryApp() {
		const Vue = require( 'vue' );
		const App = require( './components/EventDiscoveryApp.vue' );

		const appContainer = document.createElement( 'div' );
		appContainer.id = 'ext-campaignevents-eventdiscovery-vue-root';
		document.body.append( appContainer );

		Vue.createMwApp( App )
			.mount( appContainer );
	}

	// Event discovery is exposed server-side (PostEditHandler) only when no association dialog
	// applies, so the two never appear together. It is only set on a full post-edit reload.
	if ( mw.config.get( 'wgCampaignEventsDiscoveryEvents' ) ) {
		mountDiscoveryApp();
		return;
	}

	if ( mw.config.get( 'wgCampaignEventsEventsForAssociation' ) ) {
		// Variable set server-side in PostEditHandler. Means the page was just reloaded after an
		// edit (source editor), so mount the app immediately. Note, the server-side hook handler
		// guarantees that there is at least one event.
		mountAssociationApp();
	} else {
		// Module loaded as a VE plugin (or potentially manually, e.g. for Wikibase). Mount the app
		// after the actual edit, lazy-loading the list of events...
		// Not in the NS_EVENT namespace, though (T406672)
		if ( mw.config.get( 'wgNamespaceNumber' ) === 1728 ) {
			return;
		}
		const lazyMount = async () => {
			// Remove the handlers first so we only run once, even if a hook fires again while
			// the requests below are in flight.
			mw.hook( 'postEdit' ).remove( lazyMount );
			mw.hook( 'wikibase.statement.saved' ).remove( lazyMount );
			mw.hook( 'wikibase.statement.removed' ).remove( lazyMount );

			const userEvents = await new mw.Rest().get( '/campaignevents/v0/participant/self/events_for_edit' );
			mw.config.set( 'wgCampaignEventsEventsForAssociation', userEvents );
			if ( userEvents.length ) {
				mountAssociationApp();
				return;
			}

			// No association dialog applies, so fall through to event discovery (the association
			// dialog takes precedence, so the two never appear together, T431571). VE saves in
			// place, so the server-side hook never ran: fetch the discoverable events for this page
			// (which also records the once-per-user promotion) and mount if there are any.
			const discoveryEvents = await new mw.Rest().get(
				'/campaignevents/v0/event_discovery/discoverable_events?page=' +
					encodeURIComponent( mw.config.get( 'wgPageName' ) )
			);
			if ( discoveryEvents.length ) {
				mw.config.set( 'wgCampaignEventsDiscoveryEvents', discoveryEvents );
				mountDiscoveryApp();
			}
		};
		mw.hook( 'postEdit' ).add( lazyMount );
		mw.hook( 'wikibase.statement.saved' ).add( lazyMount );
		mw.hook( 'wikibase.statement.removed' ).add( lazyMount );
	}
}() );
