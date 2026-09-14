( function () {
	'use strict';

	// eslint-disable-next-line no-jquery/no-global-selector
	if ( !mw.user.isNamed() || !$( '#WorklistPanel' ).length ) {
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
		const WorklistTableControls = require( './components/WorklistTableControls.vue' ),
			worklistTableHeader = document.querySelector(
				'.ext-campaignevents-worklist-table .cdx-table__header'
			),
			container = document.createElement( 'div' );

		worklistTableHeader.appendChild( container );
		container.id = 'ext-campaignevents-worklist-app';
		Vue.createMwApp( WorklistTableControls ).mount( container );
	}

	$( mountTableViewActions );
	$( mountTableViewControls );

}() );
