<?php
$post_id = 183;
$raw = get_post_meta($post_id, '_pbsg_steps_json', true);
$steps = json_decode($raw, true);

// Snapshot original for revert
update_post_meta($post_id, '_pbsg_steps_json_debug_backup', $raw);

// Set step 1's branch.tutorial_url to SharePoint (resource_mode: shared)
$steps[1]['branch']['tutorial_type'] = 'url';
$steps[1]['branch']['tutorial_url'] = 'https://upeica.sharepoint.com/sites/myUPEI';
// Force view-time re-resolution by clearing saved flags; template's resolve_flags will re-check
unset($steps[1]['branch']['tutorial_embeddable']);
unset($steps[1]['branch']['tutorial_is_document_url']);

update_post_meta($post_id, '_pbsg_steps_json', wp_json_encode($steps));

// Dump what we just saved
$saved = get_post_meta($post_id, '_pbsg_steps_json', true);
$s = json_decode($saved, true);
echo "step 1 branch: " . json_encode($s[1]['branch'], JSON_PRETTY_PRINT) . "\n";
