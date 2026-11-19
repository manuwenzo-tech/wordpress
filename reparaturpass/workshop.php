<?php
namespace ReparaturPass;

defined('ABSPATH') || exit;

function form_fields(string $operation, int $id = 0): void {
    ?>
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('reparaturpass_write')); ?>">
    <input type="hidden" name="action" value="reparaturpass_write">
    <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
    <input type="hidden" name="repair_id" value="<?php echo esc_attr((string) $id); ?>">
    <?php
}

function dashboard(): void {
    if (!current_user_can(CAPABILITY)) {
        return;
    }

    global $wpdb;
    $id = isset($_GET['repair']) && is_string($_GET['repair']) ? absint($_GET['repair']) : 0;
    $row = $id ? repair($id) : null;
    $page = isset($_GET['rp_page']) && is_string($_GET['rp_page']) ? max(1, min(1000000, absint($_GET['rp_page']))) : 1;
    $repairs = $wpdb->get_results($wpdb->prepare(
        'SELECT id, item, stage FROM %i ORDER BY id DESC LIMIT 21 OFFSET %d', table(), ($page - 1) * 20
    ));
    $labels = stages();
    $post_url = admin_url('admin-post.php');
    $notices = [
        'created' => __('Repair created.', 'reparaturpass'),
        'advanced' => __('Repair status updated.', 'reparaturpass'),
        'saved' => __('Workshop note saved.', 'reparaturpass'),
        'rotated' => __('New customer link generated.', 'reparaturpass'),
        'failed' => __('The repair could not be saved.', 'reparaturpass'),
        'summary' => __('Add a repair summary before marking the item ready for pickup.', 'reparaturpass'),
        'stale' => __('This repair was already updated. Review its current status before trying again.', 'reparaturpass'),
        'item' => __('Enter an item name of up to 160 characters.', 'reparaturpass'),
    ];
    $notice = isset($_GET['rp_notice']) && is_string($_GET['rp_notice']) ? sanitize_key($_GET['rp_notice']) : '';
    ?>
    <div class="wrap rp">
        <h1>ReparaturPass</h1>
        <?php if (isset($notices[$notice])) : ?>
            <p class="rp-notice" role="status"><?php echo esc_html($notices[$notice]); ?></p>
        <?php endif; ?>
        <div class="rp-layout">
            <section class="rp-card">
                <h2><?php esc_html_e('Workshop repairs', 'reparaturpass'); ?></h2>
                <?php if ($repairs) : ?>
                    <ul class="rp-list">
                        <?php foreach (array_slice($repairs, 0, 20) as $entry) : ?>
                            <li><a href="<?php echo esc_url(dashboard_url(['repair' => $entry->id, 'rp_page' => $page])); ?>" <?php if ($id === (int) $entry->id) echo 'aria-current="page"'; ?>><?php echo esc_html($entry->item); ?></a>
                                <span><?php echo esc_html($labels[(int) $entry->stage]); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p><?php esc_html_e('No repairs on this page.', 'reparaturpass'); ?></p>
                <?php endif; ?>
                <?php if ($page > 1) : ?>
                    <a href="<?php echo esc_url(dashboard_url(['rp_page' => $page - 1])); ?>"><?php esc_html_e('Previous page', 'reparaturpass'); ?></a>
                <?php endif; ?>
                <?php if (count($repairs) > 20) : ?>
                    <a href="<?php echo esc_url(dashboard_url(['rp_page' => $page + 1])); ?>"><?php esc_html_e('More repairs', 'reparaturpass'); ?></a>
                <?php endif; ?>
                <h2><?php esc_html_e('New repair', 'reparaturpass'); ?></h2>
                <form method="post" action="<?php echo esc_url($post_url); ?>">
                    <?php form_fields('create'); ?>
                    <label><?php esc_html_e('Item name', 'reparaturpass'); ?><input name="item" maxlength="160" required></label>
                    <label><?php esc_html_e('Reported issue', 'reparaturpass'); ?><textarea name="issue" rows="2"></textarea></label>
                    <label><?php esc_html_e('Internal workshop note', 'reparaturpass'); ?><textarea name="staff_note" rows="2"></textarea></label>
                    <button class="rp-button"><?php esc_html_e('Create repair', 'reparaturpass'); ?></button>
                </form>
            </section>
            <?php if ($row) :
                $step = (int) $row->stage;
                $url = customer_url($row);
                ?>
                <section class="rp-card">
                    <p class="rp-reference"><?php echo esc_html('WORKSHOP / RP-' . $row->id); ?></p>
                    <h2><?php echo esc_html($row->item); ?></h2>
                    <?php if ($row->issue !== '') : ?><p><?php echo esc_html($row->issue); ?></p><?php endif; ?>
                    <div class="rp-illustration">
                        <span class="rp-bike" aria-hidden="true">🚲</span>
                        <strong><?php echo esc_html($labels[$step]); ?></strong>
                        <progress value="<?php echo esc_attr((string) ($step + 1)); ?>" max="4" aria-label="<?php esc_attr_e('Repair progress', 'reparaturpass'); ?>"></progress>
                    </div>
                    <?php if ($step < count($labels) - 1) : ?>
                        <form method="post" action="<?php echo esc_url($post_url); ?>">
                            <?php form_fields('advance', $id); ?>
                            <input type="hidden" name="expected" value="<?php echo esc_attr((string) $step); ?>">
                            <?php if ($step === 1) : ?>
                                <label><?php esc_html_e('Customer-facing repair summary', 'reparaturpass'); ?><textarea name="repair_summary" required></textarea></label>
                            <?php endif; ?>
                            <button class="rp-button"><?php echo esc_html(sprintf(__('Set status to %s', 'reparaturpass'), $labels[$step + 1])); ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($step >= 2) : ?>
                        <h3><?php esc_html_e('Repair summary', 'reparaturpass'); ?></h3>
                        <p class="rp-lines"><?php echo esc_html($row->repair_summary); ?></p>
                    <?php endif; ?>
                    <details>
                        <summary><?php esc_html_e('Internal workshop note', 'reparaturpass'); ?></summary>
                        <form method="post" action="<?php echo esc_url($post_url); ?>">
                            <?php form_fields('note', $id); ?>
                            <label><?php esc_html_e('Staff only', 'reparaturpass'); ?><textarea name="staff_note" rows="3"><?php echo esc_textarea($row->staff_note); ?></textarea></label>
                            <button class="button"><?php esc_html_e('Save note', 'reparaturpass'); ?></button>
                        </form>
                    </details>
                    <div class="rp-qr-print">
                        <h3><?php esc_html_e('Customer QR pass', 'reparaturpass'); ?> · <?php echo esc_html('RP-' . $row->id); ?></h3>
                        <div data-rp-qr data-url="<?php echo esc_url($url); ?>"></div>
                        <p><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open customer pass', 'reparaturpass'); ?></a></p>
                        <button class="button" type="button" data-rp-print hidden><?php esc_html_e('Print QR pass', 'reparaturpass'); ?></button>
                    </div>
                    <form method="post" action="<?php echo esc_url($post_url); ?>">
                        <?php form_fields('rotate', $id); ?>
                        <button class="button"><?php esc_html_e('Replace customer link', 'reparaturpass'); ?></button>
                        <p class="description"><?php esc_html_e('Replacing the link disables the previous QR pass.', 'reparaturpass'); ?></p>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
