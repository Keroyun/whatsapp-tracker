<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Link generator
 * ---------------------------------------------------------------------- */

function awm_generator_page() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		return;
	}
	$approved = awm_get_approved_numbers();
	?>
	<div class="wrap awm-wrap">
		<h1>WhatsApp Link Generator</h1>
		<p class="awm-muted">Generate a standard <code>wa.me</code> destination plus source-tagged shortcode/HTML for use-case attribution. The visible WhatsApp URL stays clean; tracking source is stored on the website CTA, not added to WhatsApp.</p>
		<div class="awm-two">
			<div class="awm-card">
				<table class="form-table">
					<tr><th><label for="awm-approved-select">Approved Use Case</label></th><td><select id="awm-approved-select"><option value="">Custom number</option><?php foreach ( $approved as $item ) : ?><option value="<?php echo esc_attr( $item['number'] ); ?>" data-source="<?php echo esc_attr( $item['label'] ); ?>"><?php echo esc_html( $item['label'] . ' — ' . $item['number'] ); ?></option><?php endforeach; ?></select><p class="description">The same phone number can appear more than once with different labels.</p></td></tr>
					<tr><th><label for="awm-phone">Phone</label></th><td><input class="regular-text" id="awm-phone" placeholder="12025550100"></td></tr>
					<tr><th><label for="awm-source">Source / Use Case</label></th><td><input class="regular-text" id="awm-source" placeholder="Customer Support"><p class="description">For server-side source attribution, this should match a configured label under Settings for the selected number.</p></td></tr>
					<tr><th><label for="awm-message">Prefilled Message</label></th><td><textarea class="large-text" rows="5" id="awm-message" placeholder="Hi, I would like to know more..."></textarea></td></tr>
					<tr><th><label for="awm-title">Link Text</label></th><td><input class="regular-text" id="awm-title" value="Chat on WhatsApp"></td></tr>
				</table>
			</div>
			<div class="awm-card">
				<h2>Generated WhatsApp URL</h2><div class="awm-code" id="awm-generated-url">Enter a phone number.</div><p><button class="button" type="button" data-copy="awm-generated-url" data-copy-label="Copy URL">Copy URL</button></p>
				<p class="description"><strong>Important:</strong> the URL alone identifies the destination, not the use-case when one number has multiple labels.</p>
				<h2>Source-Tagged Shortcode</h2><div class="awm-code" id="awm-generated-shortcode">—</div><p><button class="button" type="button" data-copy="awm-generated-shortcode" data-copy-label="Copy Shortcode">Copy Shortcode</button></p>
				<h2>Source-Tagged HTML</h2><div class="awm-code" id="awm-generated-html">—</div><p><button class="button" type="button" data-copy="awm-generated-html" data-copy-label="Copy HTML">Copy HTML</button></p>
				<p class="description">If your page builder uses its own button element, keep the normal WhatsApp URL and add a custom HTML attribute: <code>data-awm-source="Your Label"</code>.</p>
			</div>
		</div>
	</div>
	<?php
}
