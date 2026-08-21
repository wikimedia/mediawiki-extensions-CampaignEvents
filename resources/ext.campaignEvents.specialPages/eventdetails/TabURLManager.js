( function () {
	'use strict';

	const TAB_PARAM = 'tab';

	/**
	 * Keeps the tab parameter of the URL in sync with the tab that is currently open, so that the
	 * URL can be shared as a direct link to it, and so that switching tabs can be undone with the
	 * browser's back button.
	 *
	 * The tab the page was opened with must be selected before calling this, so that the state the
	 * page was opened with does not end up in the browser history.
	 *
	 * @param {OO.ui.IndexLayout} tabLayout
	 */
	function connect( tabLayout ) {
		// The tab the page was opened with, which is the default one when the URL doesn't request
		// any. Keeps the URL of a freshly opened page untouched, and is what navigating back to
		// such a URL returns to.
		const initialTab = tabLayout.getCurrentTabPanelName();
		let isNavigatingHistory = false;

		tabLayout.on( 'set', ( tabPanel ) => {
			if ( isNavigatingHistory ) {
				return;
			}
			const tabName = tabPanel.getName();
			if ( tabName === initialTab && mw.util.getParamValue( TAB_PARAM ) === null ) {
				return;
			}
			const url = new URL( window.location.href );
			url.searchParams.set( TAB_PARAM, tabName );
			window.history.pushState( null, '', url.toString() );
		} );

		window.addEventListener( 'popstate', () => {
			const tabName = mw.util.getParamValue( TAB_PARAM ) || initialTab;
			if ( !tabLayout.getTabPanel( tabName ) ) {
				return;
			}
			isNavigatingHistory = true;
			try {
				tabLayout.setTabPanel( tabName );
			} finally {
				// Without this, a failed navigation would leave the URL out of sync with the
				// tabs for as long as the page is open.
				isNavigatingHistory = false;
			}
		} );
	}

	module.exports = { connect };
}() );
