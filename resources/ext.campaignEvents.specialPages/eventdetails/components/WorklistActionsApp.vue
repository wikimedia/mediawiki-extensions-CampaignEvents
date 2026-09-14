<template>
	<div>
		<remove-worklist-article-dialog
			v-model:open="isDialogOpen"
			:pending="isDeleting"
			@confirm-delete="onConfirmDelete"
			@cancel="onCancel"
		></remove-worklist-article-dialog>
	</div>
</template>

<script>
const { defineComponent, ref, onMounted, onBeforeUnmount } = require( 'vue' );
const RemoveWorklistArticleDialog = require( './RemoveWorklistArticleDialog.vue' );
const worklistPages = require( '../worklistPages.js' );

module.exports = exports = defineComponent( {
	name: 'WorklistActionsApp',
	components: { RemoveWorklistArticleDialog },
	setup() {
		const isDialogOpen = ref( false );
		// Disables the dialog's confirm button while a removal request is in flight, so a
		// double-click cannot fire two removal requests for the same article.
		const isDeleting = ref( false );
		let currentArticle = null;

		function openDeleteDialog( wiki, title ) {
			currentArticle = { wiki, title };
			isDialogOpen.value = true;
		}

		function delegatedClickHandler( e ) {
			const target = e.target.closest( '.ext-campaignevents-delete-worklist-page-btn' );
			if ( !target ) {
				return;
			}
			const wiki = target.getAttribute( 'data-wiki' );
			const title = target.getAttribute( 'data-title' );
			openDeleteDialog( wiki, title );
		}

		onMounted( () => {
			document.addEventListener( 'click', delegatedClickHandler );
		} );

		onBeforeUnmount( () => {
			document.removeEventListener( 'click', delegatedClickHandler );
		} );

		function onConfirmDelete() {
			// Ignore extra confirm clicks while a request is already in flight.
			if ( !currentArticle || isDeleting.value ) {
				return;
			}
			isDeleting.value = true;

			const article = currentArticle;
			worklistPages.removeArticle( article.wiki, article.title ).then( () => {
				mw.notify( mw.msg( 'campaignevents-worklist-remove-success' ), {
					type: 'success'
				} );

				removeArticleRow( article );
				isDialogOpen.value = false;
				currentArticle = null;
				isDeleting.value = false;
			}, ( err, errObj ) => {
				// Show the API's real error message (content language, per T269492), like
				// AddContributionDialog.vue, instead of a generic "please try again".
				mw.notify( mw.msg( 'campaignevents-worklist-remove-error',
					worklistPages.errorText( errObj ) ), {
					type: 'error'
				} );
				// Keep the dialog open and re-enable the confirm button so the user can retry.
				isDeleting.value = false;
			} );
		}

		function onCancel() {
			isDialogOpen.value = false;
			currentArticle = null;
			isDeleting.value = false;
		}

		function removeArticleRow( article ) {
			// Remove the row with a fade effect using jQuery
			// eslint-disable-next-line no-jquery/no-fade
			$( `[data-wiki="${ article.wiki }"][data-title="${ article.title }"]` )
				.closest( 'tr' ).fadeOut();
		}

		return {
			isDialogOpen,
			isDeleting,
			// Exposed for testing purposes
			// eslint-disable-next-line vue/no-unused-properties
			openDeleteDialog,
			onConfirmDelete,
			onCancel
		};
	}
} );
</script>
