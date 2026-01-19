<template>
	<edit-association-dialog
		v-model:open="isOpen"
		@associate-edit="onAssociateEdit"
		@primary="isOpen = false"
		@default="isOpen = false"
	></edit-association-dialog>
</template>

<script>
const { defineComponent, ref } = require( 'vue' );
const EditAssociationDialog = require( './EditAssociationDialog.vue' );

module.exports = exports = defineComponent( {
	name: 'App',
	components: { EditAssociationDialog },
	setup() {
		const isOpen = ref( true );
		let revisionID = mw.config.get( 'wgRevisionId' );

		mw.hook( 'postEdit' ).add( () => {
			// Open the dialog after each edit (e.g., in case of consecutive
			// VE edits without reloading the page)
			isOpen.value = true;
			// Refresh revision ID
			revisionID = mw.config.get( 'wgRevisionId' );
		} );

		// Wikibase edits do not fire the postEdit hook, see T344984.
		// So, implement partial handling here (T411829).
		mw.hook( 'wikibase.statement.saved' ).add( ( entityID, guid, oldStatement, savedStatement, newRevID ) => {
			isOpen.value = true;
			revisionID = newRevID;
		} );
		mw.hook( 'wikibase.statement.removed' ).add( ( entityID, guid, newRevID ) => {
			isOpen.value = true;
			revisionID = newRevID;
		} );

		function onAssociateEdit( eventID ) {
			const curWikiID = mw.config.get( 'wgDBname' );
			new mw.Rest().put(
				`/campaignevents/v0/event_registration/${ eventID }/edits/${ curWikiID }/${ revisionID }`,
				{ token: mw.user.tokens.get( 'csrfToken' ) }
			);
			isOpen.value = false;
		}

		return {
			isOpen,
			onAssociateEdit
		};
	}
} );
</script>
