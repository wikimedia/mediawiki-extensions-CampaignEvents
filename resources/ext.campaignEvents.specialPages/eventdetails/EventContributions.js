( function () {
	'use strict';

	// Check if we should run at all
	if ( !mw.user.isNamed() ) {
		return;
	}

	/**
	 * Mounts the contributions actions app
	 */
	function mountContributionsActionsApp() {
		const Vue = require( 'vue' );
		const ContributionsActionsApp = require( './components/ContributionsActionsApp.vue' );

		const appContainer = document.createElement( 'div' );
		appContainer.id = 'ext-campaignevents-contributions-actions-vue-root';
		document.body.append( appContainer );

		Vue.createMwApp( ContributionsActionsApp )
			.mount( appContainer );
	}

	$( mountContributionsActionsApp );

}() );
