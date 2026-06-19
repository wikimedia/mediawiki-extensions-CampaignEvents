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
	 * Mounts the "add article" dialog into the worklist table header.
	 */
	function mountWorklistAddDialog() {
		const AddWorklistArticleDialog = require( './components/AddWorklistArticleDialog.vue' ),
			worklistTableHeader = document.querySelector(
				'.ext-campaignevents-worklist-table .cdx-table__header'
			),
			addWorklistAppContainer = document.createElement( 'div' );

		worklistTableHeader.appendChild( addWorklistAppContainer );
		addWorklistAppContainer.id = 'ext-campaignevents-worklist-add-vue-root';
		Vue.createMwApp( AddWorklistArticleDialog )
			.mount( addWorklistAppContainer );
	}

	$( mountWorklistActionsApp );
	$( mountWorklistAddDialog );

}() );
