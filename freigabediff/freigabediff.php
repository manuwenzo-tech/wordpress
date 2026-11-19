<?php
/**
 * Plugin Name: FreigabeDiff
 * Description: Review proposed edits to published pages before applying them.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: freigabediff
 */

namespace FreigabeDiff;

defined('ABSPATH') || exit;

const VERSION = '1.0.0';

require __DIR__ . '/includes/workflow.php';
require __DIR__ . '/includes/admin.php';

register_activation_hook(__FILE__, __NAMESPACE__ . '\\install');
