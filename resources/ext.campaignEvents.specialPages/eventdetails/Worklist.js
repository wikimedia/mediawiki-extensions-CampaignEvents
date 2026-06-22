( function () {
	'use strict';

	// eslint-disable-next-line no-jquery/no-global-selector
	if ( !mw.user.isNamed() || !$( '#WorklistPanel' ).length ) {
		return;
	}

	const Vue = require( 'vue' );

	/**
	 * Mounts the worklist actions app, which handles removing articles from the worklist.
	 */
	function mountWorklistActionsApp() {
		const WorklistActionsApp = require( './components/WorklistActionsApp.vue' );
		const worklistActionsAppContainer = document.createElement( 'div' );
		worklistActionsAppContainer.id = 'ext-campaignevents-worklist-actions-vue-root';
		document.body.append( worklistActionsAppContainer );

		Vue.createMwApp( WorklistActionsApp )
			.mount( worklistActionsAppContainer );
	}

	/**
	 * Mounts the worklist header controls (view-page link + add-article dialog) into the worklist
	 * table header.
	 */
	function mountWorklistApp() {
		const WorklistApp = require( './components/WorklistApp.vue' ),
			worklistTableHeader = document.querySelector(
				'.ext-campaignevents-worklist-table .cdx-table__header'
			),
			container = document.createElement( 'div' );

		worklistTableHeader.appendChild( container );
		container.id = 'ext-campaignevents-worklist-app';
		Vue.createMwApp( WorklistApp ).mount( container );
	}

	$( mountWorklistActionsApp );
	$( mountWorklistApp );

}() );
