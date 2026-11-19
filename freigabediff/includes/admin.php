<?php
namespace FreigabeDiff;

defined('ABSPATH') || exit;

function url(array $args = []): string {
    return add_query_arg(['page' => 'freigabediff'] + $args, admin_url('admin.php'));
}

function value(array $source, string $key): string {
    return isset($source[$key]) && is_string($source[$key]) ? wp_unslash($source[$key]) : '';
}

function form_fields(string $operation, int $id): void {
    ?>
    <input type="hidden" name="action" value="freigabediff">
    <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
    <input type="hidden" name="record" value="<?php echo esc_attr((string) $id); ?>">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce("freigabediff-{$operation}-{$id}")); ?>">
    <?php
}

add_action('admin_menu', static function (): void {
    $screen_id = add_menu_page('FreigabeDiff', 'FreigabeDiff', 'edit_pages', 'freigabediff',
        __NAMESPACE__ . '\\dashboard', 'dashicons-controls-repeat');
    add_action('admin_enqueue_scripts', static function (string $screen) use ($screen_id): void {
        if ($screen === $screen_id) {
            wp_enqueue_style('freigabediff', plugins_url('../assets/admin.css', __FILE__), [], VERSION);
        }
    });
});

add_filter('page_row_actions', static function (array $actions, \WP_Post $page): array {
    if (is_live_page($page) && current_user_can('edit_post', $page->ID)) {
        $actions['freigabediff'] = sprintf('<a href="%s">%s</a>',
            esc_url(url(['proposal_for' => $page->ID])), esc_html__('Propose a change', 'freigabediff'));
    }
    return $actions;
}, 10, 2);

add_action('admin_post_freigabediff', static function (): void {
    $operation = value($_POST, 'operation');
    $id = absint(value($_POST, 'record'));
    if (!current_user_can('edit_pages') || !in_array($operation, ['submit', 'review', 'publish'], true)) {
        wp_die(esc_html__('You cannot perform this action.', 'freigabediff'), '', ['response' => 403]);
    }
    check_admin_referer("freigabediff-{$operation}-{$id}");
    if ($operation === 'publish' && value($_POST, 'decision') === 'changes') {
        $operation = 'changes';
    }
    $redirect = [];

    if ($operation === 'submit') {
        [$notice, $review_id] = submit($id, value($_POST, 'baseline'), value($_POST, 'content'));
        $redirect = $review_id ? ['review_id' => $review_id] : ['proposal_for' => $id];
    } else {
        $proposal = review_snapshot($id);
        if (!$proposal || !can_review((int) $proposal->page_id)) {
            wp_die(esc_html__('You cannot review this page.', 'freigabediff'), '', ['response' => 403]);
        }
        $redirect = ['review_id' => $id];
        $note = sanitize_textarea_field(value($_POST, 'note'));
        if (strlen($note) > 8000) {
            $notice = 'note_length';
        } elseif ($operation === 'publish') {
            $notice = publish($id, $note);
        } elseif ($operation === 'review') {
            $notice = transition($id, 'proposed', ['state' => 'review']) ? 'started' : 'resolved';
        } elseif ($note === '') {
            $notice = 'note_required';
        } else {
            $notice = transition($id, 'review', [
                'state' => 'changes',
                'reviewer_id' => get_current_user_id(),
                'review_note' => $note,
                'decided_at' => current_time('mysql', true),
            ]) ? 'changes' : 'resolved';
        }
    }

    wp_safe_redirect(url($redirect + ['fd_notice' => $notice]));
    exit;
});

function state_label(string $state): string {
    return match ($state) {
        'proposed' => __('Proposed', 'freigabediff'),
        'review' => __('In review', 'freigabediff'),
        'published' => __('Published', 'freigabediff'),
        'changes' => __('Changes requested', 'freigabediff'),
        default => __('Unknown', 'freigabediff'),
    };
}

function dashboard(): void {
    if (!current_user_can('edit_pages')) {
        return;
    }
    $review_id = absint(value($_GET, 'review_id'));
    $page_id = absint(value($_GET, 'proposal_for'));
    $messages = [
        'submitted' => __('Proposal submitted.', 'freigabediff'),
        'started' => __('Review started.', 'freigabediff'),
        'published' => __('The proposed content is now live.', 'freigabediff'),
        'changes' => __('Changes requested. The live page is unchanged.', 'freigabediff'),
        'stale' => __('The live page changed. Create a fresh proposal from the current page.', 'freigabediff'),
        'unchanged' => __('Change the page content before submitting.', 'freigabediff'),
        'too_large' => __('This page exceeds the 250 KB review limit.', 'freigabediff'),
        'note_length' => __('The review note exceeds the 8 KB limit.', 'freigabediff'),
        'note_required' => __('Add a note explaining the requested changes.', 'freigabediff'),
        'resolved' => __('The proposal status has changed. Review its current state.', 'freigabediff'),
        'failed' => __('The operation could not be saved.', 'freigabediff'),
        'database' => __('Publishing requires MySQL or MariaDB with InnoDB tables.', 'freigabediff'),
        'forbidden' => __('You cannot change this page.', 'freigabediff'),
    ];
    $notice = sanitize_key(value($_GET, 'fd_notice'));
    ?>
    <div class="wrap fd">
        <header class="fd-brand"><span aria-hidden="true">±</span><h1>FreigabeDiff</h1></header>
        <?php if (isset($messages[$notice])) : ?>
            <p class="fd-notice" role="status"><?php echo esc_html($messages[$notice]); ?></p>
        <?php endif; ?>
        <?php if ($review_id || $page_id) : ?>
            <p><a href="<?php echo esc_url(url()); ?>"><?php esc_html_e('All proposals', 'freigabediff'); ?></a></p>
        <?php endif; ?>
        <?php
        if ($review_id) {
            render_review($review_id);
        } elseif ($page_id) {
            render_submission($page_id);
        } else {
            render_queue();
        }
        ?>
    </div>
    <?php
}

function render_queue(): void {
    global $wpdb;
    $user = get_current_user_id();
    $proposals = $wpdb->get_results($wpdb->prepare(
        'SELECT id, page_id, author_id, state FROM %i WHERE author_id = %d OR %d = 1 ORDER BY id DESC LIMIT 50',
        table(), $user, (int) current_user_can('publish_pages')
    ));
    ?>
    <section class="fd-card">
        <h2><?php esc_html_e('Recent proposals', 'freigabediff'); ?></h2>
        <p><?php esc_html_e('Open a published page in the Pages list and choose “Propose a change” to begin.', 'freigabediff'); ?>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>"><?php esc_html_e('Open Pages', 'freigabediff'); ?></a></p>
        <ul class="fd-list">
            <?php foreach ($proposals as $proposal) :
                $page_id = (int) $proposal->page_id;
                if (!current_user_can('edit_post', $page_id) || ((int) $proposal->author_id !== $user && !can_review($page_id))) {
                    continue;
                }
                ?>
                <li><a href="<?php echo esc_url(url(['review_id' => $proposal->id])); ?>">
                    <?php echo esc_html(get_the_title($page_id) . ' · #' . $proposal->id); ?>
                </a><span class="fd-badge"><?php echo esc_html(state_label($proposal->state)); ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
}

function render_submission(int $id): void {
    $page = page_snapshot($id);
    if (!is_live_page($page) || !current_user_can('edit_post', $id)) {
        echo '<p>' . esc_html__('This page is unavailable for proposals.', 'freigabediff') . '</p>';
        return;
    }
    ?>
    <section class="fd-card">
        <h2><?php echo esc_html($page->post_title); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php form_fields('submit', $id); ?>
            <input type="hidden" name="baseline" value="<?php echo esc_attr(fingerprint($page)); ?>">
            <label for="fd-content"><?php esc_html_e('Proposed page content (HTML or block markup)', 'freigabediff'); ?></label>
            <textarea id="fd-content" name="content" rows="14" spellcheck="false"><?php echo esc_textarea($page->post_content); ?></textarea>
            <button class="fd-action"><?php esc_html_e('Submit proposal', 'freigabediff'); ?></button>
        </form>
    </section>
    <?php
}

function render_review(int $id): void {
    $proposal = review_snapshot($id);
    $page_id = $proposal ? (int) $proposal->page_id : 0;
    $can_review = $page_id && can_review($page_id);
    if (!$proposal || !current_user_can('edit_post', $page_id) || (!$can_review && (int) $proposal->author_id !== get_current_user_id())) {
        echo '<p>' . esc_html__('This proposal is unavailable.', 'freigabediff') . '</p>';
        return;
    }

    $page = page_snapshot($page_id);
    $pending = in_array($proposal->state, ['proposed', 'review'], true);
    $stale = $pending && (!is_live_page($page) || !hash_equals($proposal->base_hash, fingerprint($page)));
    $base = $proposal->base_content;
    $draft = $proposal->proposed_content;

    if ($stale && is_live_page($page)) {
        $base = $page->post_content;
    }
    $show_diff = substr_count($base, "\n") + substr_count($draft, "\n") <= 400
        && max(strlen($base), strlen($draft)) <= 35000;
    $diff = $show_diff ? wp_text_diff($base, $draft, ['show_split_view' => true]) : '';
    ?>
    <div class="fd-workspace">
        <section class="fd-card">
            <p class="fd-reference"><?php echo esc_html(sprintf(__('Page proposal / FD-%d', 'freigabediff'), $id)); ?></p>
            <h2><?php echo esc_html($page ? $page->post_title : __('Unavailable page', 'freigabediff')); ?></h2>
            <div class="fd-compare">
                <h3><?php esc_html_e('Revision comparison', 'freigabediff'); ?></h3>
                <p><?php echo esc_html($stale && is_live_page($page) ? __('Current live content and proposed revision', 'freigabediff') : __('Original content and proposed revision', 'freigabediff')); ?></p>
                <?php if ($diff !== '') :
                    echo wp_kses($diff, [
                        'table' => ['class' => true], 'colgroup' => ['class' => true],
                        'col' => ['class' => true], 'thead' => [], 'tbody' => [],
                        'tr' => [], 'th' => ['scope' => true], 'td' => ['class' => true, 'colspan' => true],
                        'del' => [], 'ins' => [], 'span' => ['class' => true],
                    ]);
                else : ?>
                    <div class="fd-snapshots"><pre><?php echo esc_html($base); ?></pre><pre><?php echo esc_html($draft); ?></pre></div>
                <?php endif; ?>
            </div>
        </section>
        <aside class="fd-card">
            <h2><?php esc_html_e('Change inspector', 'freigabediff'); ?></h2>
            <p><span class="fd-badge"><?php echo esc_html(state_label($proposal->state)); ?></span></p>
            <?php if ($stale) : ?>
                <p class="fd-notice"><?php esc_html_e('Approval paused. The live page has changed or is unavailable.', 'freigabediff'); ?></p>
                <?php if (is_live_page($page)) : ?>
                    <a href="<?php echo esc_url(url(['proposal_for' => $page_id])); ?>"><?php esc_html_e('Start a fresh proposal', 'freigabediff'); ?></a>
                    <details>
                        <summary><?php esc_html_e('Copy the original proposal', 'freigabediff'); ?></summary>
                        <pre><?php echo esc_html($draft); ?></pre>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($proposal->review_note !== '') : ?>
                <h3><?php esc_html_e('Review note', 'freigabediff'); ?></h3>
                <p class="fd-note"><?php echo esc_html($proposal->review_note); ?></p>
            <?php endif; ?>
            <?php if ($can_review && $pending) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php form_fields($proposal->state === 'proposed' ? 'review' : 'publish', $id); ?>
                    <?php if ($proposal->state === 'proposed') : ?>
                        <button class="fd-action"><?php esc_html_e('Start review', 'freigabediff'); ?></button>
                    <?php else : ?>
                        <label for="fd-note"><?php esc_html_e('Review note', 'freigabediff'); ?></label>
                        <textarea id="fd-note" name="note" rows="3" maxlength="8000"></textarea>
                        <button class="fd-action" <?php disabled($stale); ?>><?php esc_html_e('Approve and publish', 'freigabediff'); ?></button>
                        <button class="fd-secondary" name="decision" value="changes"><?php esc_html_e('Request changes', 'freigabediff'); ?></button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </aside>
    </div>
    <?php
}
