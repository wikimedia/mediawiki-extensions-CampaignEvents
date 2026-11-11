<template>
	<span v-if="text" class="ext-campaignevents-view-count">
		<!-- A line of text, not a box, so that the count is on the same baseline as the text
			after it, and wraps with it. -->
		<!-- The figure has no words, so the icon tells what the figure counts. The icon has a
			label, or a screen reader reads "20k" and nothing tells what it counts. -->
		<cdx-icon
			class="ext-campaignevents-view-count__icon"
			:icon="cdxIconChartLine"
			:icon-label="label"
			size="small"
		></cdx-icon>{{ text }}
	</span>
</template>

<script>
const { computed, defineComponent } = require( 'vue' );
const { CdxIcon } = require( './codex.js' );
const { cdxIconChartLine } = require( './icons.json' );

/** Counts of 1,000 and more are shortened. The suffix is a message, not a letter in code. */
const THOUSAND = 1000;
const MILLION = 1000000;

/**
 * The view count as the reader sees it: shortened from 1,000, in the digits of the reader.
 *
 * @param {number} count
 * @return {string}
 */
function shorten( count ) {
	if ( count < THOUSAND ) {
		return mw.language.convertNumber( count );
	}
	// The unit comes from the rounded figure, not from the count. Thus a count that rounds up
	// to the next unit shows in that unit: 999,600 shows as 1M, not 1000k.
	const thousands = Math.round( count / THOUSAND );
	if ( thousands < THOUSAND ) {
		return mw.msg(
			'campaignevents-card-views-thousands',
			mw.language.convertNumber( thousands )
		);
	}
	return mw.msg(
		'campaignevents-card-views-millions',
		mw.language.convertNumber( Math.round( count / MILLION ) )
	);
}

/**
 * How often an article is read, as a short figure with a chart icon.
 *
 * The card gives the count. The component shows nothing when there is no count: while the
 * count loads, when the wiki has no count, or when the request fails. It sets no layout: the
 * card puts it where it must be.
 */
// @vue/component
module.exports = exports = defineComponent( {
	name: 'ViewCount',
	components: { CdxIcon },
	props: {
		/** Number of views, or null for none. */
		count: {
			type: Number,
			default: null
		}
	},
	setup( props ) {
		const text = computed(
			() => typeof props.count === 'number' && isFinite( props.count ) ?
				shorten( props.count ) :
				''
		);

		return {
			text,
			label: mw.msg( 'campaignevents-card-views-label' ),
			cdxIconChartLine
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.ext-campaignevents-view-count {
	color: @color-subtle;
}

// Two classes, so that this rule is stronger than the Codex rule for a small icon.
.ext-campaignevents-view-count .ext-campaignevents-view-count__icon {
	// Codex gives a small icon the size of the medium font, in all text. On the smaller text of
	// a card, the icon then has space around it. Here, the icon has the size of the text.
	width: 1em;
	height: 1em;
	margin-right: @spacing-12;
	vertical-align: text-bottom;
	color: @color-subtle;
}
</style>
