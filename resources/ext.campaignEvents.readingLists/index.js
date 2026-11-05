/**
 * CampaignEvents features on Special:Saved: an impact chip above the title of each card,
 * and a view count below its description.
 *
 * ReadingListsHandler adds this module to the special page. All the code of the features is in
 * CampaignEvents. The ReadingLists extension has no code for them.
 *
 * ReadingLists shows its cards with a Vue app, after the page loads, and gives no event when
 * the cards are there. Thus this module watches the DOM of the page. It uses only selectors from
 * core and from the documented markup of the Codex Card, not the classes of ReadingLists. A
 * change to them is a breaking change in a Codex release, not a silent change in ReadingLists.
 *
 * Only pages of this wiki get signals. The card of a page on another wiki gets nothing, because
 * the browser cannot get the wiki ID of another host.
 */
const { createMwApp, Fragment, h, ref, shallowRef, Teleport } = require( 'vue' );
const { ImpactChip, ViewCount, useQuality, usePageData } =
	require( 'ext.campaignEvents.cardElements' );

/** The content of the page, from core. The module only loads on Special:Saved. */
const ROOT = '#mw-content-text';

/** A card: a Codex card with a URL is an `<a>` element. */
const CARD = 'a.cdx-card[href]';

/** The title of the card, in the Codex card. The chip goes above it. */
const CARD_TITLE = '.cdx-card__text__title';

/** The description of the card, in the Codex card. The view count goes below it. */
const CARD_DESCRIPTION = '.cdx-card__text__description';

/** The REST path of the quality endpoint for any pages on one wiki. */
const QUALITY_PATH = '/campaignevents/v0/pages/quality';

/** Time to wait after the last DOM change, in ms, so that one render gives one update. */
const SETTLE_TIME = 100;

/**
 * The prefixed title of a page on this wiki, from the URL of the page.
 *
 * ReadingLists links each card to the canonical URL of the page. A page on another wiki has a
 * URL on another host, so this gives null for it.
 *
 * @param {string} href
 * @return {string|null} Null for a page on another wiki, or a URL that is not a page
 */
function localTitleFromUrl( href ) {
	const url = new URL( href, location.href );
	const hosts = [ location.host, new URL( mw.config.get( 'wgCanonicalServer' ), location.href ).host ];
	if ( !hosts.includes( url.host ) ) {
		return null;
	}
	let text = url.searchParams.get( 'title' );
	if ( !text ) {
		const prefix = mw.config.get( 'wgArticlePath' ).replace( '$1', '' );
		if ( !url.pathname.startsWith( prefix ) ) {
			return null;
		}
		try {
			text = decodeURIComponent( url.pathname.slice( prefix.length ) );
		} catch ( e ) {
			// A malformed escape sequence. Skip this card, not all of them.
			return null;
		}
	}
	const title = mw.Title.newFromText( text );
	return title ? title.getPrefixedText() : null;
}

/**
 * The cards on screen that are pages of this wiki, in DOM order.
 *
 * @param {HTMLElement} root
 * @return {Array<{link: HTMLElement, article: Object}>} Each article has the fields that
 *  useQuality() and usePageData() need
 */
function readCards( root ) {
	const wiki = mw.config.get( 'wgWikiID' );
	const found = [];
	Array.from( root.querySelectorAll( CARD ) ).forEach( ( link ) => {
		const title = localTitleFromUrl( link.getAttribute( 'href' ) );
		if ( title ) {
			// A page of this wiki, so usePageData() reads it from the local API.
			found.push( { link, article: { wiki, title, isLocal: true, apiUrl: null } } );
		}
	} );
	return found;
}

/**
 * Put an element directly before or after a reference node. Change the DOM only when the
 * element is not there already, because each change calls update() again.
 *
 * @param {HTMLElement} host
 * @param {Node} reference
 * @param {boolean} after
 */
function placeBeside( host, reference, after ) {
	if ( after ) {
		if ( reference.nextSibling !== host ) {
			reference.parentNode.insertBefore( host, reference.nextSibling );
		}
	} else if ( host.nextSibling !== reference ) {
		reference.parentNode.insertBefore( host, reference );
	}
}

/**
 * Keeps an impact chip and a view count in each card that is a page of this wiki.
 *
 * There is one Vue app. It is mounted on an element that is never added to the page, and it
 * teleports each element of a card into an element that this module adds to the card. When a
 * card goes away, its teleports go away from the list, and Vue removes its elements.
 *
 * @param {HTMLElement} root
 */
function init( root ) {
	const articles = ref( [] );
	const { qualityFor } = useQuality( QUALITY_PATH, articles );
	const { pageDataFor } = usePageData( articles );

	/**
	 * The elements that this module adds to each card. Each has:
	 * - name: the key of its teleport
	 * - hostClass: the class of the element that this module adds to the card for it
	 * - place: puts the host in the card text. Called with the title, the description or null,
	 *   and the host.
	 * - render: gives the vnode of the element for an article. The render functions read the
	 *   composables, so each element shows when its data arrives. ImpactChip and ViewCount show
	 *   nothing when there is no data.
	 */
	const elements = [
		{
			name: 'impact',
			hostClass: 'ext-campaignevents-readinglists-impact',
			// Above the title.
			place: ( title, description, host ) => placeBeside( host, title, false ),
			render: ( article ) => h( ImpactChip, {
				class: 'ext-campaignevents-readinglists-impact__chip',
				band: qualityFor( article ).band
			} )
		},
		{
			name: 'views',
			hostClass: 'ext-campaignevents-readinglists-views',
			// Below the description, or below the title when the card has no description.
			place: ( title, description, host ) => placeBeside( host, description || title, true ),
			render: ( article ) => {
				const views = pageDataFor( article ).views;
				return h( ViewCount, {
					class: 'ext-campaignevents-readinglists-views__count',
					count: views ? views.count : null
				} );
			}
		}
	];

	/**
	 * The cards that have elements, in DOM order. Each card has the fields key, link, hosts and
	 * article.
	 */
	const cards = shallowRef( [] );

	/**
	 * The elements of this module in each card, keyed on the card link.
	 *
	 * @type {WeakMap<HTMLElement, {key: number, hosts: Object<string, HTMLElement>}>}
	 */
	const entries = new WeakMap();
	let nextKey = 0;

	createMwApp( {
		render: () => cards.value.map( ( card ) => h(
			Fragment,
			{ key: card.key },
			elements.map( ( element ) => h(
				Teleport,
				{ key: element.name, to: card.hosts[ element.name ] },
				[ element.render( card.article ) ]
			) )
		) )
	} ).mount( document.createElement( 'div' ) );

	/**
	 * Removes the elements of this module from a card.
	 *
	 * @param {{hosts: Object<string, HTMLElement>}} entry
	 */
	function removeHosts( entry ) {
		Object.keys( entry.hosts ).forEach( ( name ) => entry.hosts[ name ].remove() );
	}

	/**
	 * The elements of this module in the card, in their places. Makes them when necessary.
	 *
	 * @param {HTMLElement} link
	 * @return {{key: number, hosts: Object<string, HTMLElement>}|null} Null when the card has no
	 *  title
	 */
	function hostsFor( link ) {
		const title = link.querySelector( CARD_TITLE );
		if ( !title ) {
			// Not the card that this module knows. Add nothing.
			return null;
		}
		let entry = entries.get( link );
		const inCard = ( element ) => link.contains( entry.hosts[ element.name ] );
		if ( entry && !elements.every( inCard ) ) {
			// ReadingLists rendered the text of the card again and removed an element. Make all
			// the elements again, with a new key, so that Vue mounts them again.
			removeHosts( entry );
			entry = null;
		}
		if ( !entry ) {
			entry = { key: nextKey++, hosts: {} };
			elements.forEach( ( element ) => {
				const host = document.createElement( 'span' );
				host.className = element.hostClass;
				entry.hosts[ element.name ] = host;
			} );
			entries.set( link, entry );
		}
		// ReadingLists can render the text of the card again, or add a part of the card after
		// an element, for example the description. Put the elements in their places again.
		const description = link.querySelector( CARD_DESCRIPTION );
		elements.forEach(
			( element ) => element.place( title, description, entry.hosts[ element.name ] )
		);
		return entry;
	}

	/**
	 * @param {Array<{key: number, article: Object}>} list
	 * @return {string} The same for two lists with the same cards and pages, in the same order
	 */
	const signature = ( list ) => list.map( ( card ) => card.key + '|' + card.article.title ).join( '\n' );

	function update() {
		const next = [];
		readCards( root ).forEach( ( { link, article } ) => {
			const entry = hostsFor( link );
			if ( entry ) {
				next.push( { key: entry.key, link, hosts: entry.hosts, article } );
			}
		} );

		// Remove the elements of a card that no longer gets them: for example, the card now
		// shows a page on another wiki.
		const links = new Set( next.map( ( card ) => card.link ) );
		cards.value.forEach( ( card ) => {
			if ( !links.has( card.link ) ) {
				removeHosts( card );
				entries.delete( card.link );
			}
		} );

		// Change the refs only when the cards change: the app also changes the DOM for other
		// things, for example a dialog or a menu.
		if ( signature( next ) !== signature( cards.value ) ) {
			cards.value = next;
		}
		const found = next.map( ( card ) => card.article );
		const titles = ( list ) => list.map( ( article ) => article.title ).join( '\n' );
		if ( found.length && titles( found ) !== titles( articles.value ) ) {
			articles.value = found;
		}
	}

	// ReadingLists adds the cards after its API requests. It also changes them when the reader
	// goes to another list, because the router changes the view without a page load. A card
	// can also show another page, with a new link. The elements of this module also change the
	// DOM, but then the cards are the same, and update() changes nothing.
	new MutationObserver( mw.util.debounce( update, SETTLE_TIME ) ).observe( root, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'href' ]
	} );
	update();
}

const contentRoot = document.querySelector( ROOT );
if ( contentRoot ) {
	init( contentRoot );
}

module.exports = { init };
