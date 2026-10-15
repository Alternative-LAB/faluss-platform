<?php

function wp_slash($value) { return $value; }

// Reuse the canonical Studio transaction fixture and its compatibility assertions.
ob_start();
require __DIR__ . '/link-hotfix-behavior-test.php';
ob_end_clean();

$wpdb->blocks = $canonical_blocks;
$wpdb->identity = $canonical_identity;
foreach ($wpdb->blocks as &$fixture_block) {
    $fixture_block['payload'] = str_replace('example.test', 'example.com', $fixture_block['payload']);
}
unset($fixture_block);
$wpdb->identity['external_links'] = str_replace('example.test', 'example.com', $wpdb->identity['external_links']);
$wpdb->blocks[0]['payload'] = wp_json_encode(array('label' => 'Libre', 'url' => 'https://example.com/free', 'attachment_id' => 77, 'visibility' => 'all'));
$baseline = array($wpdb->card, $wpdb->blocks, $wpdb->identity);
$reset_import = static function () use ($wpdb, $baseline): void {
    list($wpdb->card, $wpdb->blocks, $wpdb->identity) = $baseline;
};
$sorted_rows = static function () use ($wpdb): array {
    $rows = $wpdb->blocks;
    usort($rows, static fn (array $left, array $right): int => (int) $left['sort_order'] <=> (int) $right['sort_order']);
    return $rows;
};
$assert_no_write = static function (array $payload, string $message) use ($wpdb, $run): void {
    $before = $wpdb->digest();
    $writes = $wpdb->write_count;
    $result = $run('import_links', $payload);
    fl_hotfix_assert(!$result['ok'] && $before === $wpdb->digest() && $writes === $wpdb->write_count, $message);
};

$rows = array(
    array('label' => 'Premier importé', 'url' => 'https://example.com/new-first', 'order' => 20),
    array('label' => 'Doublon existant', 'url' => 'https://EXAMPLE.com/free', 'order' => 30),
    array('label' => 'Dangereux', 'url' => 'javascript:alert(1)', 'order' => 40),
    array('label' => 'Même candidat', 'url' => 'https://EXAMPLE.com:443/new-first', 'order' => 50),
    array('label' => 'Second importé', 'url' => 'https://example.com/new-second', 'order' => 10),
);
$before_card = $wpdb->card;
$before_identity = $wpdb->identity;
$before_blocks = $wpdb->blocks;
$initial_version = $version();
$result = $run('import_links', array('rows' => $rows), $initial_version, $collection_a);
fl_hotfix_assert($result['ok'] && 'imported' === $result['code'], 'A mixed batch must import its valid unique subset.');
fl_hotfix_assert(2 === $result['imported'], 'The report must count only the two inserted links.');
$statuses = array_count_values(array_column($result['report'], 'status'));
fl_hotfix_assert(5 === count($result['report']) && 2 === ($statuses['ready'] ?? 0) && 2 === ($statuses['duplicate'] ?? 0) && 1 === ($statuses['invalid'] ?? 0), 'The server must independently report every valid, duplicate and invalid candidate.');
fl_hotfix_assert($before_card === $wpdb->card, 'Import must preserve cover, appearance and every stored card preference.');
$after_identity = $wpdb->identity;
unset($before_identity['external_links'], $after_identity['external_links']);
fl_hotfix_assert($before_identity === $after_identity, 'Import must preserve the identity, avatar, publication and profile fields.');

$existing_ids = array_column($before_blocks, 'block_id');
$ordered = $sorted_rows();
$remaining_existing = array_values(array_filter($ordered, static fn (array $block): bool => in_array($block['block_id'], $existing_ids, true)));
fl_hotfix_assert($existing_ids === array_column($remaining_existing, 'block_id'), 'Import must preserve the relative order of all pre-existing blocks.');
foreach ($before_blocks as $index => $block) {
    $after = $remaining_existing[$index];
    unset($block['sort_order'], $after['sort_order'], $block['updated_at'], $after['updated_at']);
    fl_hotfix_assert($block === $after, 'Import must not overwrite existing UUIDs, payloads, media, visibility or collections.');
}
$new_blocks = array_values(array_filter($ordered, static fn (array $block): bool => !in_array($block['block_id'], $existing_ids, true)));
fl_hotfix_assert(2 === count($new_blocks) && array('Second importé', 'Premier importé') === array_map(static fn (array $block): string => json_decode($block['payload'], true)['label'], $new_blocks), 'Candidate order must govern the imported links.');
$first_collection = array_search($collection_a, array_column($ordered, 'block_id'), true);
foreach ($new_blocks as $block) {
    fl_hotfix_assert(array_search($block['block_id'], array_column($ordered, 'block_id'), true) < $first_collection, 'New links must remain outside every existing collection, including the active collection.');
}
fl_hotfix_assert($collection_a === $result['state']['active_collection'] && 1 === count($result['state']['collections'][0]['links']), 'The active collection and its exact membership must survive the import.');
$projected = json_decode($wpdb->identity['external_links'], true);
fl_hotfix_assert(array('Libre', 'Second importé', 'Premier importé', 'Dans A') === array_column($projected, 'label'), 'Identity must receive the same complete canonical links in their committed order.');
fl_hotfix_assert($initial_version !== $result['state']['version'] && $version() === $result['state']['version'], 'The success state must expose the new canonical aggregate version.');

$after_import = $wpdb->digest();
$writes_after_import = $wpdb->write_count;
$replay = $run('import_links', array('rows' => $rows), $initial_version);
fl_hotfix_assert(!$replay['ok'] && 409 === $replay['status'] && 'stale_version' === $replay['code'] && $after_import === $wpdb->digest() && $writes_after_import === $wpdb->write_count, 'A replay or concurrent import using the old version must fail without a write.');
$assert_no_write(array('rows' => $rows), 'Reimporting the committed candidates must find no new valid link and perform no write.');

$reset_import();
$before_parse = $wpdb->digest();
$request = $request_method->invoke(null, array('mutation' => 'import_links', 'aggregate_version' => $version(), 'rows' => $rows));
fl_hotfix_assert(is_array($request) && 'import_links' === $request['mutation'] && $rows === $request['payload']['rows'], 'The closed Studio request must accept only the targeted import rows.');
$forged = $request_method->invoke(null, array('mutation' => 'import_links', 'aggregate_version' => $version(), 'rows' => $rows, 'content_blocks' => array()));
fl_hotfix_assert(is_wp_error($forged) && $before_parse === $wpdb->digest(), 'An import request must reject a forged full-form block stream before persistence.');
foreach (array('block_id' => $link_a, 'attachment_id' => 77, 'visibility' => 'private', 'status' => 'ready') as $field => $value) {
    $forged_row = array('label' => 'Ne pas modifier', 'url' => 'https://example.com/forged', 'order' => 1, $field => $value);
    $assert_no_write(array('rows' => array($forged_row)), 'Import must reject forged row field ' . $field . ' without modifying existing data.');
}
foreach (array(
    array(),
    array('rows' => array()),
    array('rows' => 'not-an-array'),
    array('rows' => array(array('label' => 'Invalide', 'url' => 'javascript:alert(1)', 'order' => 1))),
    array('rows' => array(array('label' => 'Déjà présent', 'url' => 'https://example.com/free', 'order' => 1))),
) as $invalid_payload) {
    $assert_no_write($invalid_payload, 'An empty, malformed, wholly invalid or duplicate batch must write nothing.');
}

for ($number = count($wpdb->blocks) + 1; $number <= 31; ++$number) {
    $wpdb->blocks[] = array('id' => $number, 'faluss_id' => $member, 'block_id' => sprintf('88888888-8888-4888-8888-%012d', $number), 'sort_order' => $number, 'block_type' => 'link', 'payload' => wp_json_encode(array('label' => 'Existant ' . $number, 'url' => 'https://example.com/existing-' . $number)));
}
$assert_no_write(array('rows' => array($rows[0], $rows[4])), 'A batch that exceeds the remaining 32-block capacity must fail entirely without a write.');
$at_limit = $run('import_links', array('rows' => array($rows[0])));
fl_hotfix_assert($at_limit['ok'] && 32 === count($wpdb->blocks) && 1 === $at_limit['imported'], 'Import must allow filling exactly the final available block slot.');
$assert_no_write(array('rows' => array($rows[4])), 'A full 32-block stream must reject an additional import without a write.');

foreach (array('transaction_started', 'before_block_insert', 'before_projection', 'after_projection', 'before_commit') as $stage) {
    $reset_import();
    $before = $wpdb->digest();
    $fl_hotfix_fail_stage = $stage;
    $failed = $run('import_links', array('rows' => array($rows[0], $rows[4])));
    $fl_hotfix_fail_stage = '';
    fl_hotfix_assert(!$failed['ok'] && $before === $wpdb->digest(), 'An injected import failure must roll back blocks, order, card and Identity at ' . $stage . '.');
}
foreach (array('fail_projection', 'fail_commit') as $failure) {
    $reset_import();
    $before = $wpdb->digest();
    $wpdb->{$failure} = true;
    $failed = $run('import_links', array('rows' => array($rows[0], $rows[4])));
    $wpdb->{$failure} = false;
    fl_hotfix_assert(!$failed['ok'] && $before === $wpdb->digest(), 'A database ' . $failure . ' must roll back the complete import aggregate.');
}

echo "Link import validation, add-only persistence, capacity, conflicts and rollback: OK\n";
