<template>
	<cdx-info-chip v-if="label" class="ext-campaignevents-impact-chip">
		{{ label }}
	</cdx-info-chip>
</template>

<script>
const { computed, defineComponent } = require( 'vue' );
const { CdxInfoChip } = require( './codex.js' );

/**
 * The message for each impact band.
 *
 * All the bands look the same. The words show the difference. A colour or a weight for each
 * band would rank one article above another, on a page that lists work to do.
 */
const MESSAGES = {
	// Messages used here:
	// * campaignevents-event-details-worklist-card-impact-high
	// * campaignevents-event-details-worklist-card-impact-medium
	// * campaignevents-event-details-worklist-card-impact-low
	high: 'campaignevents-event-details-worklist-card-impact-high',
	medium: 'campaignevents-event-details-worklist-card-impact-medium',
	low: 'campaignevents-event-details-worklist-card-impact-low'
};

/**
 * How much an article can gain from edits, as a chip.
 *
 * The card gives the band. The chip shows nothing when there is no band: while the band
 * loads, when there is no score, or when the request fails. It sets no layout: the card puts
 * it where it must be.
 */
// @vue/component
module.exports = exports = defineComponent( {
	name: 'ImpactChip',
	components: { CdxInfoChip },
	props: {
		/** Impact band: 'high', 'medium' or 'low', or null for none. */
		band: {
			type: String,
			default: null
		}
	},
	setup( props ) {
		const label = computed( () => {
			const message = MESSAGES[ props.band ];
			return message ? mw.msg( message ) : '';
		} );

		return { label };
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

// Two classes, so that this rule is stronger than the Codex rule for the chip background.
.cdx-info-chip.ext-campaignevents-impact-chip {
	// Outlined on the base background, as in the design, not the filled grey of Codex.
	background-color: @background-color-base;
}
</style>
