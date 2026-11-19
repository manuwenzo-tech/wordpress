<?php
/**
 * Plugin Name: ReparaturPass
 * Description: Track workshop repairs and share a QR-linked repair pass with customers.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: reparaturpass
 */

namespace ReparaturPass;

defined('ABSPATH') || exit;

const CAPABILITY = 'manage_reparaturpass';
const VERSION = '1.0.0';

function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'reparaturpass';
}

function activate(bool $network_wide = false): void {
    if ($network_wide) {
        wp_die(esc_html__('Activate ReparaturPass individually on each site.', 'reparaturpass'));
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        item varchar(160) NOT NULL,
        issue text NOT NULL,
        staff_note text NOT NULL,
        repair_summary text NOT NULL,
        stage tinyint unsigned NOT NULL DEFAULT 0,
        secret char(64) NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id)
    ) {$charset};");

    $administrator = get_role('administrator');
    if ($administrator) {
        $administrator->add_cap(CAPABILITY);
    }
    add_role('reparaturpass_staff', __('Repair staff', 'reparaturpass'), [
        'read' => true,
        CAPABILITY => true,
    ]);
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');

function stages(): array {
    return [
        __('Received', 'reparaturpass'),
        __('In repair', 'reparaturpass'),
        __('Ready for pickup', 'reparaturpass'),
        __('Picked up', 'reparaturpass'),
    ];
}

function repair(int $id): ?object {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', table(), $id));
}

function token(object $row): string {
    return hash_hmac('sha256', $row->id . ':' . $row->secret, wp_salt('auth'));
}

function customer_url(object $row): string {
    return add_query_arg('reparaturpass', '1', home_url('/')) . '#rp/' . $row->id . '/' . token($row);
}

function asset(string $file): string {
    return plugins_url('assets/' . $file, __FILE__);
}

function input(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? wp_unslash($value) : '';
}

function dashboard_url(array $args = []): string {
    return add_query_arg(['page' => 'reparaturpass'] + $args, admin_url('admin.php'));
}

add_action('admin_menu', static function (): void {
    $hook = add_menu_page('ReparaturPass', 'ReparaturPass', CAPABILITY, 'reparaturpass',
        __NAMESPACE__ . '\\dashboard', 'dashicons-hammer');
    add_action('admin_enqueue_scripts', static function ($screen) use ($hook): void {
        if ($screen !== $hook) {
            return;
        }
        wp_enqueue_style('reparaturpass', asset('style.css'), [], VERSION);
        wp_enqueue_script('reparaturpass-qr', asset('qrcode.js'), [], '2.0.4', true);
        wp_enqueue_script('reparaturpass-workshop', asset('workshop.js'), ['reparaturpass-qr'], VERSION, true);
    });
});

add_action('admin_post_reparaturpass_write', static function (): void {
    if (!current_user_can(CAPABILITY)) {
        wp_die(esc_html__('You do not have permission to manage repairs.', 'reparaturpass'), '', ['response' => 403]);
    }
    check_admin_referer('reparaturpass_write');
    global $wpdb;

    $id = absint(input('repair_id'));
    $operation = input('operation');
    $notice = 'failed';
    if ($operation === 'create') {
        $item = sanitize_text_field(input('item'));
        if ($item === '' || wp_html_excerpt($item, 160) !== $item) {
            $notice = 'item';
        } elseif ($wpdb->insert(table(), [
            'item' => $item,
            'issue' => sanitize_textarea_field(input('issue')),
            'staff_note' => sanitize_textarea_field(input('staff_note')),
            'repair_summary' => '',
            'secret' => bin2hex(random_bytes(32)),
            'updated_at' => current_time('mysql', true),
        ])) {
            $id = (int) $wpdb->insert_id;
            $notice = 'created';
        }
    } elseif ($operation === 'advance') {
        $expected = filter_var(input('expected'), FILTER_VALIDATE_INT);
        if ($expected !== false && $expected >= 0 && $expected < count(stages()) - 1) {
            $summary = sanitize_textarea_field(input('repair_summary'));
            if ($expected === 1 && $summary === '') {
                $notice = 'summary';
            } else {
                // Compare the submitted stage in the same statement as the update.
                $data = ['stage' => $expected + 1, 'updated_at' => current_time('mysql', true)];
                if ($expected === 1) {
                    $data['repair_summary'] = $summary;
                }
                $changed = $wpdb->update(table(), $data, ['id' => $id, 'stage' => $expected], null, ['%d', '%d']);
                $notice = $changed === 1 ? 'advanced' : ($changed === 0 ? 'stale' : 'failed');
            }
        }
    } elseif ($operation === 'note' && repair($id)) {
        $changed = $wpdb->update(table(), ['staff_note' => sanitize_textarea_field(input('staff_note'))], ['id' => $id], ['%s'], ['%d']);
        $notice = $changed === false ? 'failed' : 'saved';
    } elseif ($operation === 'rotate') {
        $changed = $wpdb->update(table(), ['secret' => bin2hex(random_bytes(32))], ['id' => $id], ['%s'], ['%d']);
        $notice = $changed === 1 ? 'rotated' : 'failed';
    }

    wp_safe_redirect(dashboard_url(['repair' => $id, 'rp_notice' => $notice]));
    exit;
});

add_action('rest_api_init', static function (): void {
    register_rest_route('reparaturpass/v1', '/pass/(?P<id>[1-9][0-9]{0,17})', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\customer_pass',
    ]);
});

function customer_pass(\WP_REST_Request $request): \WP_REST_Response {
    $key = $request->get_json_params()['key'] ?? null;
    $row = is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key)
        ? repair((int) $request->get_url_params()['id'])
        : null;

    if (!$row || !hash_equals(token($row), $key)) {
        $response = new \WP_REST_Response(['message' => __('This repair link is unavailable.', 'reparaturpass')], 404);
    } else {
        $step = (int) $row->stage;
        $response = new \WP_REST_Response([
            'reference' => 'RP-' . $row->id,
            'stage' => $step,
            'stages' => stages(),
            'summary' => $step >= 2 ? $row->repair_summary : '',
        ]);
    }
    $response->header('Cache-Control', 'private, no-store, max-age=0');
    return $response;
}

add_action('template_redirect', static function (): void {
    if (($_GET['reparaturpass'] ?? '') !== '1') {
        return;
    }

    nocache_headers();
    status_header(200);
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    require __DIR__ . '/pass.php';
    exit;
}, 0);

require __DIR__ . '/workshop.php';
