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

		mw.hook( 'postEdit' ).add( () => {
			// Open the dialog after each edit (e.g., in case of consecutive
			// VE edits without reloading the page)
			isOpen.value = true;
		} );

		function onAssociateEdit( eventID ) {
			const curWikiID = mw.config.get( 'wgDBname' ),
				revID = mw.config.get( 'wgRevisionId' );
			new mw.Rest().put(
				`/campaignevents/v0/event_registration/${ eventID }/edits/${ curWikiID }/${ revID }`,
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
