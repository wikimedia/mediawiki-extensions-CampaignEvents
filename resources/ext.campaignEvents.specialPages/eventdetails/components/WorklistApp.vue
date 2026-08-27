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

		<cdx-message v-if="errorMessage" type="error">
			{{ errorMessage }}
		</cdx-message>

		<div
			v-else-if="isLoading"
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

		<p v-else-if="!articles.length" class="ext-campaignevents-worklist-empty-state">
			{{ $i18n( 'campaignevents-worklist-empty-state' ).text() }}
		</p>

		<ul v-else class="ext-campaignevents-worklist-cards">
			<worklist-article-card
				v-for="article in articles"
				:key="article.wiki + '|' + article.title"
				:article="article"
				:can-remove="canRemoveArticles"
				@remove="onRemoveRequested"
			></worklist-article-card>
		</ul>

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
const { defineComponent, ref, onMounted } = require( 'vue' );
const { CdxIcon, CdxMessage } = require( '../../../codex.js' );
const { cdxIconHistory } = require( '../../../icons.json' );
const AddWorklistArticleDialog = require( './AddWorklistArticleDialog.vue' );
const RemoveWorklistArticleDialog = require( './RemoveWorklistArticleDialog.vue' );
const WorklistArticleCard = require( './WorklistArticleCard.vue' );
const worklistPages = require( '../worklistPages.js' );

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
		CdxIcon,
		CdxMessage
	},
	setup() {
		const articles = ref( [] );
		const isLoading = ref( true );
		const errorMessage = ref( '' );

		const isRemoveDialogOpen = ref( false );
		const isRemoving = ref( false );
		const articleToRemove = ref( null );

		// Only named users may add or remove articles, mirroring the backend permission. The
		// backend re-checks, so this only decides what is worth showing.
		const canAddArticles = mw.user.isNamed();
		const canRemoveArticles = mw.user.isNamed();

		// Identifies the most recent request, so that a slow response cannot overwrite the results
		// of a newer one; a reload while one is in flight is enough to get two.
		let latestRequestId = 0;

		/** Read the worklist, replacing whatever is on screen. */
		function load() {
			const requestId = ++latestRequestId;
			isLoading.value = true;
			errorMessage.value = '';
			worklistPages.fetchPages().then( ( response ) => {
				if ( requestId !== latestRequestId ) {
					return;
				}
				articles.value = response.pages;
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

		/** Read the worklist again, after it has been changed. */
		function reload() {
			load();
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
				// Re-read the list rather than splicing the article out, so that what is shown is
				// what the server has.
				reload();
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
			articles,
			isLoading,
			errorMessage,
			isRemoveDialogOpen,
			isRemoving,
			canAddArticles,
			canRemoveArticles,
			worklistPageUrl: mw.config.get( 'wgCampaignEventsWorklistPageUrl' ) || '',
			historyUrl: mw.config.get( 'wgCampaignEventsWorklistPageHistoryUrl' ) || '',
			historyLabel: mw.msg( 'campaignevents-event-details-worklist-history-button-label' ),
			historyButtonClasses: HISTORY_BUTTON_CLASSES,
			skeletonCards: SKELETON_CARDS,
			cdxIconHistory,
			onRemoveRequested,
			confirmRemove,
			cancelRemove,
			reload
		};
	}
} );
</script>
