( function () {
	'use strict';

	// eslint-disable-next-line no-jquery/no-global-selector
	if ( !$( '#WorklistPanel' ).length ) {
		return;
	}

	const Vue = require( 'vue' );

	/**
	 * Handles removing an article from the worklist table, by a delegated click on the buttons the
	 * server renders.
	 */
	function mountTableViewActions() {
		const WorklistActionsApp = require( './components/WorklistActionsApp.vue' );
		const worklistActionsAppContainer = document.createElement( 'div' );
		worklistActionsAppContainer.id = 'ext-campaignevents-worklist-actions-vue-root';
		document.body.append( worklistActionsAppContainer );

		Vue.createMwApp( WorklistActionsApp )
			.mount( worklistActionsAppContainer );
	}

	/**
	 * Mounts the controls for the worklist table (view-page link + add-article dialog) into its
	 * header.
	 */
	function mountTableViewControls() {
		const worklistTableHeader = document.querySelector(
			'.ext-campaignevents-worklist-table .cdx-table__header'
		);
		if ( !worklistTableHeader ) {
			return;
		}
		const WorklistTableControls = require( './components/WorklistTableControls.vue' ),
			container = document.createElement( 'div' );

		worklistTableHeader.appendChild( container );
		container.id = 'ext-campaignevents-worklist-app';
		Vue.createMwApp( WorklistTableControls ).mount( container );
	}

	/**
	 * Mounts the card view, which renders the worklist itself: the toolbar and the article cards.
	 * The server renders placeholder cards inside the mount point, which mounting replaces.
	 *
	 * @return {boolean} Whether the card view was rendered, and so mounted
	 */
	function mountWorklistApp() {
		const container = document.querySelector( '.ext-campaignevents-worklist-app' );
		if ( !container ) {
			return false;
		}
		const WorklistApp = require( './components/WorklistApp.vue' );
		Vue.createMwApp( WorklistApp ).mount( container );
		return true;
	}

	$( () => {
		// The server renders one view or the other, depending on the card view feature flag and
		// the requested view, so mount whichever is actually on the page. The table's controls
		// are for editing, which only a named user may do; the cards are read-only until one of
		// their own controls is used, so anonymous readers get them too.
		if ( mountWorklistApp() ) {
			return;
		}
		if ( mw.user.isNamed() ) {
			mountTableViewActions();
			mountTableViewControls();
		}
	} );

}() );
