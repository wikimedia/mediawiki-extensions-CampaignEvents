<template>
	<div>
		<delete-contribution-dialog
			v-model:open="isDialogOpen"
			:event-name="eventName"
			@confirm-delete="onConfirmDelete"
			@cancel="onCancel"
		></delete-contribution-dialog>
	</div>
</template>

<script>
const { defineComponent, ref, onMounted, onBeforeUnmount } = require( 'vue' );
const DeleteContributionDialog = require( './DeleteContributionDialog.vue' );

module.exports = exports = defineComponent( {
	name: 'ContributionsActionsApp',
	components: { DeleteContributionDialog },
	setup() {
		const isDialogOpen = ref( false );
		let currentContribID = null;

		const eventName = mw.config.get( 'wgCampaignEventsEventName' ) || '';

		function openDeleteDialog( contribID ) {
			currentContribID = contribID;
			isDialogOpen.value = true;
		}

		function delegatedClickHandler( e ) {
			const target = e.target.closest( '.ext-campaignevents-delete-contribution-btn' );
			if ( !target ) {
				return;
			}
			const contribId = target.getAttribute( 'data-contrib-id' );
			openDeleteDialog( contribId );
		}

		onMounted( () => {
			document.addEventListener( 'click', delegatedClickHandler );
		} );

		onBeforeUnmount( () => {
			document.removeEventListener( 'click', delegatedClickHandler );
		} );

		function onConfirmDelete() {
			if ( !currentContribID ) {
				return;
			}

			const api = new mw.Rest();
			api.delete( '/campaignevents/v0/event_contributions/' + currentContribID, {
				token: mw.user.tokens.get( 'csrfToken' )
			} ).then( () => {
				mw.notify( mw.msg( 'campaignevents-event-details-contributions-delete-success' ), {
					type: 'success'
				} );

				removeContributionRow( currentContribID );
			} ).catch( () => {
				mw.notify( mw.msg( 'campaignevents-event-details-contributions-delete-error' ), {
					type: 'error'
				} );
			} ).then( () => {
				isDialogOpen.value = false;
				currentContribID = null;
			} );
		}

		function onCancel() {
			isDialogOpen.value = false;
			currentContribID = null;
		}

		function removeContributionRow( contribID ) {
			// Remove the row with a fade effect using jQuery
			// eslint-disable-next-line no-jquery/no-fade
			$( `[data-contrib-id="${ contribID }"]` ).closest( 'tr' ).fadeOut();
		}

		return {
			isDialogOpen,
			eventName,
			// Exposed for testing purposes
			// eslint-disable-next-line vue/no-unused-properties
			openDeleteDialog,
			onConfirmDelete,
			onCancel
		};
	}
} );
</script>
