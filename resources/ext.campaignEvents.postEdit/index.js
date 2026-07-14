( function () {
	const associateEdit = require( './associateEdit.js' );
	const notifyAssociationSuccess = require( './notifyAssociationSuccess.js' );

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

	/**
	 * Handles client-side edits that don't reload the page: VisualEditor, and edits from modules
	 * loaded manually such as for Wikibase. Each edit is evaluated independently — a single
	 * worklist match is auto-associated (and we keep listening so further edits are handled too),
	 * otherwise the association dialog, or failing that the discovery dialog, is mounted and takes
	 * over from here.
	 *
	 * @param {number|null} handledRevisionId The revision already handled server-side on this
	 *   reload, if any; its edit signal is ignored so the same edit isn't handled twice.
	 */
	function setupClientEditHandler( handledRevisionId ) {
		// Not in the NS_EVENT namespace (T406672)
		if ( mw.config.get( 'wgNamespaceNumber' ) === 1728 ) {
			return;
		}

		let lastHandledRevisionId = handledRevisionId;

		function stopListening() {
			mw.hook( 'postEdit' ).remove( onPostEdit );
			mw.hook( 'wikibase.statement.saved' ).remove( onWikibaseEdit );
			mw.hook( 'wikibase.statement.removed' ).remove( onWikibaseEdit );
		}

		async function handleEdit( revisionId ) {
			if ( revisionId === lastHandledRevisionId ) {
				// Already handled (the reload's own edit, or a duplicate signal).
				return;
			}
			// Set before any await, so a repeated signal for the same edit is ignored.
			lastHandledRevisionId = revisionId;

			const userEvents = await new mw.Rest().get(
				'/campaignevents/v0/participant/self/events_for_edit?title=' +
					encodeURIComponent( mw.config.get( 'wgPageName' ) )
			);

			// Exactly one candidate event has this page on its worklist: auto-associate and show a
			// confirmation, mirroring the server-side reload path. Keep listening so each further
			// in-place edit is auto-associated too.
			const autoAssociable = userEvents.filter( ( event ) => event.autoAssociable );
			if ( autoAssociable.length === 1 ) {
				const event = autoAssociable[ 0 ];
				await associateEdit( event.id, revisionId );
				notifyAssociationSuccess( event.id, event.name );
				return;
			}

			// Otherwise a dialog is shown. It mounts a persistent app that handles subsequent
			// edits itself, so stop listening here to avoid double-handling.
			stopListening();

			mw.config.set( 'wgCampaignEventsEventsForAssociation', userEvents );
			if ( userEvents.length ) {
				mountAssociationApp();
				return;
			}

			// The association dialog takes precedence, so the two never appear together (T431571).
			const discoveryEvents = await new mw.Rest().get(
				'/campaignevents/v0/event_discovery/discoverable_events?page=' +
					encodeURIComponent( mw.config.get( 'wgPageName' ) )
			);
			if ( discoveryEvents.length ) {
				mw.config.set( 'wgCampaignEventsDiscoveryEvents', discoveryEvents );
				mountDiscoveryApp();
			}
		}

		// VE updates wgRevisionId on save; Wikibase does not, so take the revision from the hook.
		function onPostEdit() {
			handleEdit( mw.config.get( 'wgRevisionId' ) );
		}
		// Both wikibase hooks pass the new revision ID as their last argument.
		function onWikibaseEdit( ...args ) {
			handleEdit( args[ args.length - 1 ] );
		}

		mw.hook( 'postEdit' ).add( onPostEdit );
		mw.hook( 'wikibase.statement.saved' ).add( onWikibaseEdit );
		mw.hook( 'wikibase.statement.removed' ).add( onWikibaseEdit );
	}

	// These vars are set server-side (PostEditHandler) on a full post-edit reload and are mutually
	// exclusive. For auto-association the client edit handler is still registered so that further
	// in-place edits are handled, guarded against re-handling this reload's own edit.
	const autoAssociatedEvent = mw.config.get( 'wgCampaignEventsAutoAssociatedEvent' );
	if ( autoAssociatedEvent ) {
		notifyAssociationSuccess( autoAssociatedEvent.id, autoAssociatedEvent.name );
		setupClientEditHandler( mw.config.get( 'wgRevisionId' ) );
		return;
	}

	if ( mw.config.get( 'wgCampaignEventsDiscoveryEvents' ) ) {
		mountDiscoveryApp();
		return;
	}

	if ( mw.config.get( 'wgCampaignEventsEventsForAssociation' ) ) {
		// Variable set server-side in PostEditHandler: the page was just reloaded after a
		// source-editor edit, so mount the app immediately (App.vue then registers its own hooks
		// to handle subsequent in-place edits).
		mountAssociationApp();
	} else {
		// Module loaded as a VE plugin, or manually (e.g. for Wikibase): no server-side signal, so
		// handle edits on the client as they happen.
		setupClientEditHandler( null );
	}
}() );
