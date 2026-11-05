<template>
	<span
		v-if="text"
		class="ext-campaignevents-article-signals"
		dir="auto"
	>
		<!-- `dir="auto"` because a signal is in the language of the reader, which may run the
			other way from the card around it. -->
		<!-- A line of text, not a row of boxes, so that a long need wraps in the card. The
			lightbulb tells the same as the words, so it has no label, and Codex hides it from
			screen readers. -->
		<cdx-icon
			class="ext-campaignevents-article-signals__icon"
			:icon="cdxIconLightbulbOutline"
			size="small"
		></cdx-icon>{{ text }}
	</span>
</template>

<script>
const { computed, defineComponent } = require( 'vue' );
const { CdxIcon } = require( './codex.js' );
const { cdxIconLightbulbOutline } = require( './icons.json' );

/**
 * What an article most needs, with a lightbulb icon. For example: "Needs more citations".
 *
 * The card gives the signals: the quality loader makes them from the per-element scores of
 * the article. The component shows nothing when there are no signals: while they load, when
 * the article has no weak elements, or when the request fails. It sets no layout: the card
 * puts it where it must be.
 */
// @vue/component
module.exports = exports = defineComponent( {
	name: 'ArticleSignals',
	components: { CdxIcon },
	props: {
		/** The signals, as texts in the language of the reader. Most important first. */
		signals: {
			type: Array,
			default: () => []
		}
	},
	setup( props ) {
		// Only the most important signal, with no count of the others (T443285). The reader
		// cannot open a list of the others, so a count would tell them nothing they can use.
		const text = computed( () => props.signals[ 0 ] || '' );

		return {
			text,
			cdxIconLightbulbOutline
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.ext-campaignevents-article-signals {
	color: @color-subtle;
}

// Two classes, so that this rule is stronger than the Codex rule for a small icon.
.ext-campaignevents-article-signals .ext-campaignevents-article-signals__icon {
	// Codex gives a small icon the size of the medium font, in all text. On the smaller text of
	// a card, the icon then has space around it. Here, the icon has the size of the text.
	width: 1em;
	height: 1em;
	margin-right: @spacing-12;
	vertical-align: text-bottom;
	color: @color-subtle;
}
</style>
