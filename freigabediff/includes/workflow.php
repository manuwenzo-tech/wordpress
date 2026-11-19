<?php
namespace FreigabeDiff;

defined('ABSPATH') || exit;

function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'freigabediff';
}

function install(bool $network_wide): void {
    if ($network_wide) {
        wp_die(esc_html__('Activate FreigabeDiff individually on each site.', 'freigabediff'));
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        page_id bigint(20) unsigned NOT NULL,
        author_id bigint(20) unsigned NOT NULL,
        reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
        base_hash char(64) NOT NULL,
        base_content longtext NOT NULL,
        proposed_content longtext NOT NULL,
        review_note text NOT NULL,
        state varchar(20) NOT NULL DEFAULT 'proposed',
        created_at datetime NOT NULL,
        decided_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY page_id (page_id),
        KEY author_id (author_id)
    ) ENGINE=InnoDB {$charset};");
}

function page_snapshot(int $id, bool $lock = false): ?object {
    global $wpdb;
    $query = 'SELECT ID, post_type, post_status, post_title, post_excerpt, post_content, post_modified_gmt FROM %i WHERE ID = %d';
    if ($lock) {
        $query .= ' FOR UPDATE';
    }
    return $wpdb->get_row($wpdb->prepare($query, $wpdb->posts, $id));
}

function review_snapshot(int $id, bool $lock = false): ?object {
    global $wpdb;
    $query = 'SELECT * FROM %i WHERE id = %d';
    if ($lock) {
        $query .= ' FOR UPDATE';
    }
    return $wpdb->get_row($wpdb->prepare($query, table(), $id));
}

function is_live_page(?object $page): bool {
    return $page && $page->post_type === 'page' && $page->post_status === 'publish';
}

function fingerprint(object $page): string {
    return hash('sha256', serialize([
        $page->post_title, $page->post_excerpt, $page->post_content, $page->post_modified_gmt,
    ]));
}

function can_review(int $page_id): bool {
    return current_user_can('publish_pages') && current_user_can('edit_post', $page_id);
}

function submit(int $page_id, string $baseline, string $content): array {
    global $wpdb;
    $page = page_snapshot($page_id);
    if (!is_live_page($page) || !current_user_can('edit_post', $page_id)) {
        return ['forbidden', 0];
    }
    if (!hash_equals(fingerprint($page), $baseline)) {
        return ['stale', 0];
    }
    // Apply the same capability boundary WordPress uses for post HTML.
    $content = current_user_can('unfiltered_html') ? $content : wp_kses_post($content);
    if ($content === $page->post_content) {
        return ['unchanged', 0];
    }
    if (strlen($content) > 250000 || strlen($page->post_content) > 250000) {
        return ['too_large', 0];
    }
    $inserted = $wpdb->insert(table(), [
        'page_id' => $page_id,
        'author_id' => get_current_user_id(),
        'base_hash' => fingerprint($page),
        'base_content' => $page->post_content,
        'proposed_content' => $content,
        'review_note' => '',
        'created_at' => current_time('mysql', true),
    ]);
    return $inserted === 1 ? ['submitted', (int) $wpdb->insert_id] : ['failed', 0];
}

function transition(int $id, string $from, array $data): bool {
    global $wpdb;
    return $wpdb->update(table(), $data, ['id' => $id, 'state' => $from]) === 1;
}

function supports_transactions(): bool {
    global $wpdb;
    $names = [$wpdb->posts, $wpdb->postmeta, table()];
    $engines = $wpdb->get_col($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)',
        ...$names
    ));
    return count($engines) === count($names) && count(array_filter($engines, static fn ($engine) => strtolower((string) $engine) === 'innodb')) === count($names);
}

function publish(int $id, string $note): string {
    global $wpdb;
    $proposal = review_snapshot($id);
    if (!$proposal || !can_review((int) $proposal->page_id)) {
        return 'forbidden';
    }
    if (!supports_transactions()) {
        return 'database';
    }

    $page_id = (int) $proposal->page_id;
    if ($wpdb->query('START TRANSACTION') === false) {
        return 'failed';
    }
    $committed = false;
    $attempted_update = false;
    try {
        // Lock the live page first so competing approvals serialize on the same row.
        $page = page_snapshot($page_id, true);
        $proposal = review_snapshot($id, true);

        if (!$proposal || (int) $proposal->page_id !== $page_id || $proposal->state !== 'review') {
            return 'resolved';
        }
        if (!is_live_page($page) || !hash_equals($proposal->base_hash, fingerprint($page))) {
            return 'stale';
        }
        if (!transition($id, 'review', [
            'state' => 'published',
            'reviewer_id' => get_current_user_id(),
            'review_note' => $note,
            'decided_at' => current_time('mysql', true),
        ])) {
            return 'failed';
        }

        $attempted_update = true;
        wp_cache_delete($page_id, 'posts');
        $previous = wp_save_post_revision($page_id);
        if (is_wp_error($previous)) {
            return 'failed';
        }
        $saved = wp_update_post(wp_slash([
            'ID' => $page_id,
            'post_content' => $proposal->proposed_content,
        ]), true);
        $published = page_snapshot($page_id);
        if (is_wp_error($saved) || $saved !== $page_id || !is_live_page($published)
            || $published->post_content !== $proposal->proposed_content) {
            return 'failed';
        }

        $committed = $wpdb->query('COMMIT') !== false;
        return $committed ? 'published' : 'failed';
    } finally {
        if (!$committed) {
            $wpdb->query('ROLLBACK');
        }
        if ($attempted_update) {
            clean_post_cache($page_id);
        }
    }
}
