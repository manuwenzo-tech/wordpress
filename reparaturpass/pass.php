<?php
namespace ReparaturPass;

defined('ABSPATH') || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Your Repair Pass', 'reparaturpass'); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(add_query_arg('ver', VERSION, asset('style.css'))); ?>">
    <script defer src="<?php echo esc_url(add_query_arg('ver', VERSION, asset('customer.js'))); ?>"></script>
</head>
<body class="rp-public">
<main class="rp rp-pass"
    data-endpoint="<?php echo esc_url(rest_url('reparaturpass/v1/pass/RP_ID')); ?>"
    data-error="<?php esc_attr_e('This repair link is unavailable.', 'reparaturpass'); ?>"
    data-retry="<?php esc_attr_e('Could not load the repair pass. Please try again.', 'reparaturpass'); ?>">
    <header class="rp-brand">ReparaturPass</header>
    <p class="rp-reference" data-reference></p>
    <h1><?php esc_html_e('Your Repair Pass', 'reparaturpass'); ?></h1>
    <p data-message role="status"><?php esc_html_e('Loading repair status…', 'reparaturpass'); ?></p>
    <div data-progress hidden>
        <div class="rp-illustration">
            <span class="rp-bike" aria-hidden="true">🚲</span>
            <strong data-stage></strong>
            <progress max="4" value="0" aria-label="<?php esc_attr_e('Repair progress', 'reparaturpass'); ?>"></progress>
        </div>
        <ol class="rp-timeline" aria-label="<?php esc_attr_e('Repair stages', 'reparaturpass'); ?>"></ol>
        <section data-summary hidden>
            <h2><?php esc_html_e('Repair summary', 'reparaturpass'); ?></h2>
            <p class="rp-lines"></p>
        </section>
    </div>
    <button class="rp-button" type="button" data-refresh hidden><?php esc_html_e('Refresh status', 'reparaturpass'); ?></button>
    <noscript><p><?php esc_html_e('Enable JavaScript to open your repair pass.', 'reparaturpass'); ?></p></noscript>
</main>
</body>
</html>
