( function () {
	'use strict';

	// Check if we should run at all
	if ( !mw.user.isNamed() ) {
		return;
	}

	const Vue = require( 'vue' );

	/**
	 * Mounts the contributions actions app
	 */
	function mountContributionsActionsApp() {
		const ContributionsActionsApp = require( './components/ContributionsActionsApp.vue' );
		const contributionsActionsAppContainer = document.createElement( 'div' );
		contributionsActionsAppContainer.id = 'ext-campaignevents-contributions-actions-vue-root';
		document.body.append( contributionsActionsAppContainer );

		Vue.createMwApp( ContributionsActionsApp )
			.mount( contributionsActionsAppContainer );
	}
	function mountContributionsAddDialog() {
		const AddContributionDialog = require( './components/AddContributionDialog.vue' ),
			contributionTableHeader = document.querySelector( '.ext-campaignevents-contributions-table .cdx-table__header' ),
			addContributionAppContainer = document.createElement( 'div' );

		contributionTableHeader.appendChild( addContributionAppContainer );
		addContributionAppContainer.id = 'ext-campaignevents-contributions-add-vue-root';
		Vue.createMwApp( AddContributionDialog )
			.mount( addContributionAppContainer );
	}

	$( mountContributionsActionsApp );
	$( mountContributionsAddDialog );

}() );
