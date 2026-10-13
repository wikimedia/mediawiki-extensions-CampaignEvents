<template>
	<cdx-dialog
		:title="$i18n( 'campaignevents-worklist-remove-confirm-title' ).text()"
		:use-close-button="true"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="$emit( 'confirm-delete' )"
		@default="$emit( 'cancel' )"
	>
		<div
			v-if="isPrivateParticipant"
			class="ext-campaignevents-worklist-remove-private-warning-container"
		>
			<cdx-message
				class="ext-campaignevents-worklist-remove-private-warning"
				type="warning"
			>
				{{ $i18n( 'campaignevents-worklist-private-participant-warning' ).text() }}
			</cdx-message>
		</div>
		<p>{{ $i18n( 'campaignevents-worklist-remove-confirm-body' ).text() }}</p>
	</cdx-dialog>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxDialog, CdxMessage } = require( '../../../codex.js' );

module.exports = exports = defineComponent( {
	name: 'RemoveWorklistArticleDialog',
	components: { CdxDialog, CdxMessage },
	props: {
		// Disables the confirm button while a removal request is in flight.
		pending: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'confirm-delete', 'cancel' ],
	setup( props ) {
		const primaryAction = computed( () => ( {
			label: mw.msg( 'campaignevents-worklist-remove-confirm-action' ),
			actionType: 'destructive',
			disabled: props.pending
		} ) );
		const defaultAction = {
			label: mw.msg( 'campaignevents-worklist-remove-confirm-cancel' )
		};
		// Removing an article edits the public worklist page, which reveals a private
		// participant's username, so warn them before they confirm.
		const isPrivateParticipant = !!mw.config.get( 'wgCampaignEventsWorklistIsPrivateParticipant' );

		return {
			primaryAction,
			defaultAction,
			isPrivateParticipant
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.ext-campaignevents-worklist-remove-private-warning-container {
	margin-bottom: @spacing-100;
}
</style>
