<template>
	<div class="ext-campaignevents-event-details-worklist-actions">
		<a
			v-if="worklistPageUrl"
			class="ext-campaignevents-event-details-worklist-page-link"
			:href="worklistPageUrl"
		>
			{{ $i18n( 'campaignevents-event-details-worklist-view-page' ).text() }}
		</a>
		<add-worklist-article-dialog v-if="canAddArticles"></add-worklist-article-dialog>
	</div>
</template>

<script>
const { defineComponent } = require( 'vue' );
const AddWorklistArticleDialog = require( './AddWorklistArticleDialog.vue' );

module.exports = exports = defineComponent( {
	name: 'WorklistApp',
	components: { AddWorklistArticleDialog },
	setup() {
		// Only logged-in (named) users may add articles, mirroring the backend permission.
		const canAddArticles = mw.user.isNamed();
		// URL of the worklist wiki page, shown as a link when the page already exists.
		const worklistPageUrl = mw.config.get( 'wgCampaignEventsWorklistPageUrl' ) || '';
		return { canAddArticles, worklistPageUrl };
	}
} );
</script>
