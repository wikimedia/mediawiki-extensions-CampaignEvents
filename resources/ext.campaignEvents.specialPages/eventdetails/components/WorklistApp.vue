<template>
	<div class="ext-campaignevents-worklist-view">
		<div class="ext-campaignevents-worklist-toolbar">
			<add-worklist-article-dialog
				v-if="canAddArticles"
				@added="firstPage"
			></add-worklist-article-dialog>
			<a
				v-if="historyUrl"
				role="button"
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
				v-for="article in visiblePages"
				:key="article.wiki + '|' + article.title"
				:article="article"
				:can-remove="canRemoveArticles"
				:added="article.timestampFormatted"
				:added-at="article.timestamp"
				:views="viewsFor( article )"
				:image="imageFor( article )"
				:description="descriptionFor( article )"
				:signals="signalsFor( article )"
				:impact-band="bandFor( article )"
				@remove="onRemoveRequested"
			></worklist-article-card>
		</ul>

		<nav
			v-if="showPagination"
			class="ext-campaignevents-worklist-pagination"
			:aria-label="paginationLabel"
		>
			<cdx-button
				class="ext-campaignevents-worklist-pagination__first"
				weight="quiet"
				:disabled="isLoading || !hasFirstPage"
				:aria-label="$i18n( 'campaignevents-event-details-worklist-first-page' ).text()"
				:title="$i18n( 'campaignevents-event-details-worklist-first-page' ).text()"
				@click="firstPage"
			>
				<cdx-icon :icon="cdxIconMoveFirst"></cdx-icon>
			</cdx-button>
			<cdx-button
				class="ext-campaignevents-worklist-pagination__prev"
				weight="quiet"
				:disabled="isLoading || previousPageOffset === null"
				:aria-label="$i18n( 'campaignevents-event-details-worklist-previous-page' ).text()"
				:title="$i18n( 'campaignevents-event-details-worklist-previous-page' ).text()"
				@click="previousPage"
			>
				<cdx-icon :icon="cdxIconPrevious"></cdx-icon>
			</cdx-button>
			<cdx-button
				class="ext-campaignevents-worklist-pagination__next"
				weight="quiet"
				:disabled="isLoading || nextPageOffset === null"
				:aria-label="$i18n( 'campaignevents-event-details-worklist-next-page' ).text()"
				:title="$i18n( 'campaignevents-event-details-worklist-next-page' ).text()"
				@click="nextPage"
			>
				<cdx-icon :icon="cdxIconNext"></cdx-icon>
			</cdx-button>
			<cdx-button
				class="ext-campaignevents-worklist-pagination__last"
				weight="quiet"
				:disabled="isLoading || !hasLastPage"
				:aria-label="$i18n( 'campaignevents-event-details-worklist-last-page' ).text()"
				:title="$i18n( 'campaignevents-event-details-worklist-last-page' ).text()"
				@click="lastPage"
			>
				<cdx-icon :icon="cdxIconMoveLast"></cdx-icon>
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
const { cdxIconHistory, cdxIconMoveFirst, cdxIconPrevious, cdxIconNext, cdxIconMoveLast } = require( '../../../icons.json' );
const AddWorklistArticleDialog = require( './AddWorklistArticleDialog.vue' );
const RemoveWorklistArticleDialog = require( './RemoveWorklistArticleDialog.vue' );
const WorklistArticleCard = require( './WorklistArticleCard.vue' );
const worklistPages = require( '../worklistPages.js' );
const worklistPageData = require( '../WorklistPageData.js' );
const worklistQuality = require( '../WorklistQuality.js' );

// Enough cards to make paging rare on a wide screen without a long first load. The grid takes
// as many columns as the viewport allows, so no page size can promise a full last row.
const ARTICLES_PER_PAGE = 24;
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

// How long to wait (in ms) before sending a new request when the user changes the search input.
const SEARCH_DEBOUNCE_TIME = 500;

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
		const visiblePages = ref( [] );
		// Per-article data held against `wiki|title`. A ref rather than a plain object so that
		// filling one in after its card has rendered redraws that card.
		const pageData = ref( new Map() );
		const firstLoadDone = ref( false );
		const isLoading = ref( true );
		const errorMessage = ref( '' );

		const searchTerm = ref( '' );

		const isPaginatingBackwards = ref( false );
		const hasFirstPage = ref( false );
		const previousPageOffset = ref( null );
		const curPageOffset = ref( null );
		const nextPageOffset = ref( null );
		const hasLastPage = ref( false );

		// Quality held against `wiki|title`. A ref rather than a plain object so that filling one
		// in after its card has rendered redraws that card.
		const quality = ref( new Map() );

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
		const showSkeleton = computed( () => isLoading.value && !firstLoadDone.value );

		const isEmpty = computed( () => !visiblePages.value.length );
		// One page of results needs no controls to leave it.
		const showPagination = computed(
			() => !errorMessage.value && !showSkeleton.value &&
				( previousPageOffset.value !== null || nextPageOffset.value !== null )
		);

		// An empty list means something different once the reader has filtered it.
		const emptyStateMessage = computed( () => mw.msg(
			searchTerm.value.trim() ?
				'campaignevents-event-details-worklist-search-no-results' :
				'campaignevents-worklist-empty-state'
		) );

		/**
		 * @param {string|null} offset
		 * @param {boolean} backwards
		 * @return {jQuery.Promise}
		 */
		function loadResultPage( offset, backwards ) {
			const requestId = ++latestRequestId;
			isLoading.value = true;
			errorMessage.value = '';

			const needle = searchTerm.value.trim().toLowerCase() || null;
			return worklistPages.fetchPages(
				ARTICLES_PER_PAGE, offset, backwards, needle
			).then( ( response ) => {
				if ( requestId !== latestRequestId ) {
					return;
				}

				visiblePages.value = response.pages;
				hasFirstPage.value = 'first' in response.paginationOffsets;
				previousPageOffset.value = response.paginationOffsets.prev || null;
				curPageOffset.value = offset;
				nextPageOffset.value = response.paginationOffsets.next || null;
				hasLastPage.value = 'last' in response.paginationOffsets;
				isPaginatingBackwards.value = backwards;

				firstLoadDone.value = true;
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

		watch( searchTerm, mw.util.debounce( firstPage, SEARCH_DEBOUNCE_TIME ) );

		/**
		 * Fill in the quality of the articles now on screen.
		 *
		 * A second request, made once the cards are rendered: a quality score is real work for
		 * the model behind it, and the list is what the reader is waiting for. Only the visible
		 * articles are asked about, so paging or searching costs one small request rather than
		 * one for the whole worklist.
		 */
		function loadQuality() {
			const showing = visiblePages.value;
			if ( !showing.length ) {
				return;
			}
			worklistQuality.fetchQuality( showing ).then( ( known ) => {
				// A new Map, so that Vue sees the change and redraws the cards.
				quality.value = new Map( known );
			}, () => {
				// The card is complete without it, so a failure leaves the cards as they are
				// rather than being surfaced to the reader.
			} );
		}

		watch( visiblePages, loadQuality );

		/**
		 * @param {Object} article
		 * @return {string[]}
		 */
		function signalsFor( article ) {
			const known = quality.value.get( article.wiki + '|' + article.title );
			return known ? known.signals : [];
		}

		/**
		 * @param {Object} article
		 * @return {string|null}
		 */
		function bandFor( article ) {
			const known = quality.value.get( article.wiki + '|' + article.title );
			return known ? known.band : null;
		}

		function firstPage() {
			loadResultPage( null, false );
		}

		function previousPage() {
			loadResultPage( previousPageOffset.value, true );
		}

		function nextPage() {
			loadResultPage( nextPageOffset.value, false );
		}

		function lastPage() {
			loadResultPage( null, true );
		}

		/**
		 * Fill in the per-article data for the articles now on screen.
		 *
		 * A request per wiki, made once the cards are rendered. Only the articles on screen are
		 * asked about: a worklist can hold thousands, and the reader is only looking at a page
		 * of them. Whatever brings an article into view — paging, filtering, a reload — is a
		 * reason to ask for what it still lacks.
		 */
		function loadPageData() {
			const showing = visiblePages.value;
			if ( !showing.length ) {
				return;
			}
			worklistPageData.fetchPageData( showing ).then( ( known ) => {
				// A new Map, so that Vue sees the change and redraws the cards.
				pageData.value = new Map( known );
			}, () => {
				// The card is complete without it, so a failure leaves the cards as they are
				// rather than being surfaced to the reader.
			} );
		}

		watch( visiblePages, loadPageData );

		/**
		 * @param {Object} article
		 * @return {?Object}
		 */
		function viewsFor( article ) {
			const known = pageData.value.get( article.wiki + '|' + article.title );
			return known ? known.views : null;
		}

		/**
		 * @param {Object} article
		 * @return {?string}
		 */
		function descriptionFor( article ) {
			const known = pageData.value.get( article.wiki + '|' + article.title );
			return known ? known.description : null;
		}

		/**
		 * @param {Object} article
		 * @return {?Object}
		 */
		function imageFor( article ) {
			const known = pageData.value.get( article.wiki + '|' + article.title );
			return known ? known.image : null;
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
				// Reload the current page, to update contents and pagination status.
				loadResultPage( curPageOffset.value, isPaginatingBackwards.value )
					.then( () => {
						if ( curPageOffset.value && !visiblePages.value.length ) {
							// This could happen if removal empties the last (respectively first)
							// page when paginating forwards (resp. backwards), as well as in case
							// of offset mismatch between the pager implementations. So, reload the
							// new last (resp. first) page without offset.
							loadResultPage( null, !isPaginatingBackwards.value );
						}
					} );
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

		onMounted( firstPage );

		return {
			visiblePages,
			viewsFor,
			imageFor,
			descriptionFor,
			signalsFor,
			bandFor,
			isEmpty,
			isLoading,
			errorMessage,
			searchTerm,
			showSkeleton,
			showPagination,
			emptyStateMessage,
			hasFirstPage,
			previousPageOffset,
			nextPageOffset,
			hasLastPage,
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
			skeletonCards: SKELETON_CARDS,
			cdxIconHistory,
			cdxIconMoveFirst,
			cdxIconPrevious,
			cdxIconNext,
			cdxIconMoveLast,
			firstPage,
			previousPage,
			nextPage,
			lastPage,
			onRemoveRequested,
			confirmRemove,
			cancelRemove
		};
	}
} );
</script>
