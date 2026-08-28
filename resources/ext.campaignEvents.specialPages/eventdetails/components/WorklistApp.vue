<template>
	<div class="ext-campaignevents-worklist-view">
		<div class="ext-campaignevents-worklist-toolbar">
			<add-worklist-article-dialog
				v-if="canAddArticles"
				@added="reload"
			></add-worklist-article-dialog>
			<a
				v-if="historyUrl"
				class="ext-campaignevents-worklist-toolbar__history"
				:class="historyButtonClasses"
				:href="historyUrl"
				:aria-label="historyLabel"
				:title="historyLabel"
			>
				<cdx-icon :icon="cdxIconHistory"></cdx-icon>
			</a>
		</div>

		<cdx-search-input
			v-model="searchTerm"
			class="ext-campaignevents-worklist-search"
			:placeholder="searchPlaceholder"
			:aria-label="searchPlaceholder"
			clearable
		></cdx-search-input>

		<cdx-message v-if="errorMessage" type="error">
			{{ errorMessage }}
		</cdx-message>

		<div
			v-else-if="showSkeleton"
			class="ext-campaignevents-worklist-skeleton"
			role="status"
			:aria-label="$i18n( 'campaignevents-event-details-worklist-loading' ).text()"
		>
			<div class="ext-campaignevents-worklist-cards" aria-hidden="true">
				<div
					v-for="n in skeletonCards"
					:key="n"
					class="ext-campaignevents-worklist-skeleton-card"
				></div>
			</div>
		</div>

		<p v-else-if="isEmpty" class="ext-campaignevents-worklist-empty-state">
			{{ emptyStateMessage }}
		</p>

		<ul
			v-else
			class="ext-campaignevents-worklist-cards"
			:aria-busy="isLoading"
		>
			<worklist-article-card
				v-for="article in visibleArticles"
				:key="article.wiki + '|' + article.title"
				:article="article"
				:can-remove="canRemoveArticles"
				@remove="onRemoveRequested"
			></worklist-article-card>
		</ul>

		<nav
			v-if="showPagination"
			class="ext-campaignevents-worklist-pagination"
			:aria-label="paginationLabel"
		>
			<cdx-button
				class="ext-campaignevents-worklist-pagination__prev"
				:disabled="isLoading || !hasPreviousPage"
				:aria-label="previousLabel"
				:title="previousLabel"
				@click="previousPage"
			>
				<cdx-icon :icon="cdxIconPrevious"></cdx-icon>
			</cdx-button>

			<ul class="ext-campaignevents-worklist-pagination__list">
				<li
					v-for="( item, index ) in pageItems"
					:key="index"
					class="ext-campaignevents-worklist-pagination__item"
				>
					<!-- The gap stands for pages that are not offered; it names nothing a reader
						needs, so it is left out of the accessibility tree. -->
					<span
						v-if="item === ELLIPSIS"
						class="ext-campaignevents-worklist-pagination__ellipsis"
						aria-hidden="true"
					>{{ ellipsisText }}</span>
					<cdx-button
						v-else
						class="ext-campaignevents-worklist-pagination__page"
						:class="{
							'ext-campaignevents-worklist-pagination__page--current':
								item === currentPage
						}"
						weight="quiet"
						:disabled="isLoading"
						:aria-current="item === currentPage ? 'page' : undefined"
						@click="goToPage( item )"
					>
						{{ formatNumber( item ) }}
					</cdx-button>
				</li>
			</ul>

			<cdx-button
				class="ext-campaignevents-worklist-pagination__next"
				:disabled="isLoading || !hasNextPage"
				:aria-label="nextLabel"
				:title="nextLabel"
				@click="nextPage"
			>
				<cdx-icon :icon="cdxIconNext"></cdx-icon>
			</cdx-button>
		</nav>

		<!-- Below the list, per the task's acceptance criteria. Empty for an event on another
			wiki, where the worklist subpage cannot be resolved locally. -->
		<a
			v-if="worklistPageUrl"
			class="ext-campaignevents-worklist-page-link"
			:href="worklistPageUrl"
		>{{ $i18n( 'campaignevents-event-details-worklist-view-page' ).text() }}</a>

		<remove-worklist-article-dialog
			v-model:open="isRemoveDialogOpen"
			:pending="isRemoving"
			@confirm-delete="confirmRemove"
			@cancel="cancelRemove"
		></remove-worklist-article-dialog>
	</div>
</template>

<script>
const { defineComponent, ref, computed, watch, onMounted } = require( 'vue' );
const { CdxButton, CdxIcon, CdxMessage, CdxSearchInput } = require( '../../../codex.js' );
const { cdxIconHistory, cdxIconNext, cdxIconPrevious } = require( '../../../icons.json' );
const AddWorklistArticleDialog = require( './AddWorklistArticleDialog.vue' );
const RemoveWorklistArticleDialog = require( './RemoveWorklistArticleDialog.vue' );
const WorklistArticleCard = require( './WorklistArticleCard.vue' );
const worklistPages = require( '../worklistPages.js' );

// Enough cards to make paging rare on a wide screen without a long first load. The grid takes
// as many columns as the viewport allows, so no page size can promise a full last row.
const ARTICLES_PER_PAGE = 24;
// The fewest pages offered either side of the current one before a gap is drawn instead; a run
// anchored to the first or last page shows more than this.
const PAGE_SIBLINGS = 1;
/** Marks a run of pages that is not offered, rather than a page number. */
const ELLIPSIS = 'ellipsis';
const SKELETON_CARDS = 6;

// The history control navigates, so it has to be a link. Codex has no button that renders as one,
// so it is an anchor carrying the button classes; `--fake-button--enabled` stands in for the
// `:enabled` state that the styles key off and only a real <button> can have.
const HISTORY_BUTTON_CLASSES = [
	'cdx-button',
	'cdx-button--fake-button',
	'cdx-button--fake-button--enabled',
	'cdx-button--icon-only'
];

/**
 * @param {number} from
 * @param {number} to
 * @return {number[]}
 */
function range( from, to ) {
	const pages = [];
	for ( let page = from; page <= to; page++ ) {
		pages.push( page );
	}
	return pages;
}

/**
 * The page numbers to offer, with `ELLIPSIS` where a run of them is left out.
 *
 * Every result holds the same number of items, so that the controls do not change width
 * underneath the pointer. Once there are more pages than fit, the run offered is either centred
 * on the current page, with a gap and an end page on each side, or — near either end, where a gap
 * would hide no more than the page it replaces — anchored to that end, with a single gap and the
 * far end page. An anchored run therefore reaches further than PAGE_SIBLINGS, which is what keeps
 * the count the same.
 *
 * @param {number} currentPage
 * @param {number} totalPages
 * @return {Array<number|string>}
 */
function buildPageItems( currentPage, totalPages ) {
	// First and last page, the current page and its siblings, and a gap on each side.
	const maxVisible = PAGE_SIBLINGS * 2 + 5;
	if ( totalPages <= maxVisible ) {
		return range( 1, totalPages );
	}

	// An anchored run gives up one of those items to its single gap and one to the far end page.
	const anchoredRun = maxVisible - 2;
	const left = Math.max( currentPage - PAGE_SIBLINGS, 1 );
	const right = Math.min( currentPage + PAGE_SIBLINGS, totalPages );
	// A gap is only worth drawing where it hides more than the page it replaces.
	const gapOnLeft = left > 2;
	const gapOnRight = right < totalPages - 1;

	if ( !gapOnLeft ) {
		return range( 1, anchoredRun ).concat( [ ELLIPSIS, totalPages ] );
	}
	if ( !gapOnRight ) {
		return [ 1, ELLIPSIS ].concat( range( totalPages - anchoredRun + 1, totalPages ) );
	}
	return [ 1, ELLIPSIS ].concat( range( left, right ), [ ELLIPSIS, totalPages ] );
}

/**
 * The card presentation of an event's worklist, which replaces the placeholder cards the server
 * renders. This is what a reader with JavaScript gets; the server-rendered table is the fallback
 * for readers without it.
 */
// @vue/component
module.exports = exports = defineComponent( {
	name: 'WorklistApp',
	components: {
		AddWorklistArticleDialog,
		RemoveWorklistArticleDialog,
		WorklistArticleCard,
		CdxButton,
		CdxIcon,
		CdxMessage,
		CdxSearchInput
	},
	setup() {
		// Every article matching the current search. The server decides which those are; splitting
		// them into pages is this component's job, so that paging costs no request.
		const articles = ref( [] );
		const currentPage = ref( 1 );
		const isLoading = ref( true );
		const errorMessage = ref( '' );

		const searchTerm = ref( '' );

		const isRemoveDialogOpen = ref( false );
		const isRemoving = ref( false );
		const articleToRemove = ref( null );

		// Only named users may add or remove articles, mirroring the backend permission. The
		// backend re-checks, so this only decides what is worth showing.
		const canAddArticles = mw.user.isNamed();
		const canRemoveArticles = mw.user.isNamed();

		// Identifies the most recent request, so that a slow response cannot overwrite the results
		// of a newer one; paging quickly is enough to get two requests in flight.
		let latestRequestId = 0;

		// The placeholders stand in for a list that is not on screen yet. Once there are cards to
		// show, a refresh leaves them in place rather than replacing the list with placeholders on
		// every keystroke; the list is marked busy instead.
		const showSkeleton = computed( () => isLoading.value && !articles.value.length );
		// Filtering happens here rather than on the server: paging client-side means the whole
		// worklist is already in hand, so a request per keystroke would fetch what we have.
		const matchingArticles = computed( () => {
			const needle = searchTerm.value.trim().toLowerCase();
			if ( !needle ) {
				return articles.value;
			}
			return articles.value.filter(
				( article ) => article.title.toLowerCase().includes( needle )
			);
		} );
		const isEmpty = computed( () => !matchingArticles.value.length );
		const totalPages = computed(
			() => Math.max( 1, Math.ceil( matchingArticles.value.length / ARTICLES_PER_PAGE ) )
		);
		const visibleArticles = computed( () => {
			const start = ( currentPage.value - 1 ) * ARTICLES_PER_PAGE;
			return matchingArticles.value.slice( start, start + ARTICLES_PER_PAGE );
		} );
		// One page of results needs no controls to leave it.
		const showPagination = computed(
			() => !errorMessage.value && !showSkeleton.value && totalPages.value > 1
		);
		const hasPreviousPage = computed( () => currentPage.value > 1 );
		const hasNextPage = computed( () => currentPage.value < totalPages.value );
		const pageItems = computed(
			() => buildPageItems( currentPage.value, totalPages.value )
		);
		// An empty list means something different once the reader has filtered it.
		const emptyStateMessage = computed( () => mw.msg(
			searchTerm.value.trim() ?
				'campaignevents-event-details-worklist-search-no-results' :
				'campaignevents-worklist-empty-state'
		) );

		/**
		 * Read the whole worklist, keeping the reader on the page they are on where that page
		 * still exists. Searching and paging both work on what this returns.
		 */
		function load() {
			const requestId = ++latestRequestId;
			isLoading.value = true;
			errorMessage.value = '';
			worklistPages.fetchPages().then( ( response ) => {
				if ( requestId !== latestRequestId ) {
					return;
				}
				articles.value = response.pages;
				// Removing an article can empty the last page; fall back to the one before it
				// rather than showing a page that is no longer there.
				currentPage.value = Math.min( currentPage.value, totalPages.value );
				isLoading.value = false;
			}, ( err, errObj ) => {
				if ( requestId !== latestRequestId ) {
					return;
				}
				isLoading.value = false;
				errorMessage.value = mw.msg(
					'campaignevents-event-details-worklist-load-error',
					worklistPages.errorText( errObj )
				);
			} );
		}

		/** Read the worklist again from the first page, after the set of articles has changed. */
		function reload() {
			currentPage.value = 1;
			load();
		}

		// A new term reshuffles the list, so the reader starts at its first page again.
		watch( searchTerm, () => {
			currentPage.value = 1;
		} );

		function nextPage() {
			currentPage.value = Math.min( currentPage.value + 1, totalPages.value );
		}

		function previousPage() {
			currentPage.value = Math.max( currentPage.value - 1, 1 );
		}

		function goToPage( page ) {
			currentPage.value = Math.min( Math.max( page, 1 ), totalPages.value );
		}

		function onRemoveRequested( article ) {
			articleToRemove.value = article;
			isRemoveDialogOpen.value = true;
		}

		function confirmRemove() {
			// Ignore extra confirm clicks while a request is already in flight.
			if ( !articleToRemove.value || isRemoving.value ) {
				return;
			}
			isRemoving.value = true;
			const article = articleToRemove.value;
			worklistPages.removeArticle( article.wiki, article.title ).then( () => {
				mw.notify( mw.msg( 'campaignevents-worklist-remove-success' ), { type: 'success' } );
				isRemoving.value = false;
				isRemoveDialogOpen.value = false;
				articleToRemove.value = null;
				// Re-read rather than splicing the article out, so that the page count and the
				// articles that move up to fill the gap are the server's answer, not a guess.
				load();
			}, ( err, errObj ) => {
				mw.notify(
					mw.msg(
						'campaignevents-worklist-remove-error',
						worklistPages.errorText( errObj )
					),
					{ type: 'error' }
				);
				// Leave the dialog open and re-enable its confirm button so the user can retry.
				isRemoving.value = false;
			} );
		}

		function cancelRemove() {
			isRemoveDialogOpen.value = false;
			articleToRemove.value = null;
			isRemoving.value = false;
		}

		onMounted( reload );

		return {
			visibleArticles,
			isEmpty,
			isLoading,
			errorMessage,
			searchTerm,
			currentPage,
			pageItems,
			ELLIPSIS,
			showSkeleton,
			showPagination,
			emptyStateMessage,
			hasPreviousPage,
			hasNextPage,
			isRemoveDialogOpen,
			isRemoving,
			canAddArticles,
			canRemoveArticles,
			worklistPageUrl: mw.config.get( 'wgCampaignEventsWorklistPageUrl' ) || '',
			historyUrl: mw.config.get( 'wgCampaignEventsWorklistPageHistoryUrl' ) || '',
			historyLabel: mw.msg( 'campaignevents-event-details-worklist-history-button-label' ),
			historyButtonClasses: HISTORY_BUTTON_CLASSES,
			searchPlaceholder: mw.msg( 'campaignevents-event-details-worklist-search-placeholder' ),
			paginationLabel: mw.msg( 'campaignevents-event-details-worklist-pagination-label' ),
			ellipsisText: mw.msg( 'ellipsis' ),
			previousLabel: mw.msg( 'campaignevents-event-details-worklist-previous-page' ),
			nextLabel: mw.msg( 'campaignevents-event-details-worklist-next-page' ),
			// Page numbers belong to the controls rather than to the worklist, so they take the
			// digits of the reader's language.
			formatNumber: ( page ) => String( mw.language.convertNumber( page ) ),
			skeletonCards: SKELETON_CARDS,
			cdxIconHistory,
			cdxIconPrevious,
			cdxIconNext,
			nextPage,
			previousPage,
			goToPage,
			onRemoveRequested,
			confirmRemove,
			cancelRemove,
			reload
		};
	}
} );
</script>
