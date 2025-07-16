<?php
/*
Plugin Name: NoLeemits WP Troubleshooter
Description: Logs memory usage and plugin activity during shutdown to help identify performance issues. Includes an admin dashboard for viewing, exporting, and clearing logs.
Version: 1.1
Author: Stephen Lee Hernandez
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly;

class NoLeemits_WP_Troubleshooter {
    const LOG_FILE = WP_CONTENT_DIR . '/debug-memory.log';

    public function __construct() {
        add_action('shutdown', [$this, 'log_shutdown_info'], PHP_INT_MAX);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_nlt_export_logs', [$this, 'export_logs']);
        add_action('admin_post_nlt_clear_logs', [$this, 'clear_logs']);
    }

    public function log_shutdown_info() {
        if (!current_user_can('manage_options')) return;

        $page     = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $user     = wp_get_current_user();
        $plugins  = get_option('active_plugins');
        $limit    = ini_get('memory_limit');
        $limit_mb = wp_convert_hr_to_bytes($limit) / 1024 / 1024;
        $peak     = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $percent  = round(($peak / $limit_mb) * 100, 2);

        $log = [
            'timestamp'      => current_time('mysql'),
            'uri'            => $page,
            'user'           => $user->user_login ?? 'guest',
            'memory_usage'   => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory'    => $peak,
            'memory_limit'   => $limit,
            'memory_percent' => $percent,
            'plugins'        => $plugins,
            'method'         => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'is_ajax'        => (defined('DOING_AJAX') && DOING_AJAX),
            'referrer'       => $_SERVER['HTTP_REFERER'] ?? '',
            'warning'        => ($percent > 90 ? 'High memory usage' : '')
        ];

        file_put_contents(self::LOG_FILE, json_encode($log, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    public function register_admin_page() {
        add_management_page(
            'WP Troubleshooter Logs',
            'Troubleshooter Logs',
            'manage_options',
            'nlt_logs',
            [$this, 'render_logs_page']
        );
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $logs = file_exists(self::LOG_FILE) ? file(self::LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $parsed_logs = array_map(fn($l) => json_decode($l, true), $logs);

        echo '<div class="wrap"><h1>WP Troubleshooter Logs</h1>';
        echo '<p>Total Entries: ' . count($parsed_logs) . '</p>';

        echo '<style>
            .nlt-warning-high { background: #ffe5e5; }
            .nlt-warning-medium { background: #fff5cc; }
            .nlt-tooltip-icon { cursor: help; color: #777; }
            .nlt-sidebar { position: fixed; top: 50px; right: 0; width: 400px; background: #fff; height: 100%; overflow-y: auto; border-left: 1px solid #ccc; padding: 15px; display: none; z-index: 9999; }
            .widefat thead th { position: sticky; top: 32px; background: #f1f1f1; z-index: 10; box-shadow: inset 0 -1px 0 #ccc; }
        </style>';

        echo '<div id="nlt-plugin-sidebar" class="nlt-sidebar"></div>';

        echo '<table class="widefat fixed striped">
            <thead><tr>
                <th>Date</th><th>URI</th><th>User</th><th>Memory <span class="nlt-tooltip-icon" title="Memory usage at the end of the request.">ℹ️</span></th><th>Peak <span class="nlt-tooltip-icon" title="Highest memory used during the request.">ℹ️</span></th><th>% <span class="nlt-tooltip-icon" title="% of the PHP memory_limit used.">ℹ️</span></th><th>Warning</th><th>Plugins</th>
            </tr></thead><tbody>';

        foreach ($parsed_logs as $index => $log) {
            $row_class = '';
            if ($log['memory_percent'] >= 90) {
                $row_class = 'nlt-warning-high';
            } elseif ($log['memory_percent'] >= 75) {
                $row_class = 'nlt-warning-medium';
            }

            echo "<tr class=\"$row_class\">";
            echo '<td>' . esc_html($log['timestamp']) . '</td>';
            echo '<td>' . esc_html($log['uri']) . '</td>';
            echo '<td>' . esc_html($log['user']) . '</td>';
            echo '<td>' . esc_html($log['memory_usage']) . ' MB</td>';
            echo '<td>' . esc_html($log['peak_memory']) . ' MB</td>';
            echo '<td>' . esc_html($log['memory_percent']) . '%</td>';
            echo '<td>' . esc_html($log['warning']) . '</td>';
            echo '<td><button class="button view-plugins" data-index="' . esc_attr($index) . '">View Plugins (' . count($log['plugins']) . ')</button></td>';
            echo '</tr>';
        }

        echo '</tbody></table><br />';

        $export_url = admin_url('admin-post.php?action=nlt_export_logs');
        $clear_url  = admin_url('admin-post.php?action=nlt_clear_logs&_wpnonce=' . wp_create_nonce('nlt_clear_logs'));

        echo '<a class="button button-primary" href="' . esc_url($export_url) . '">Export JSON</a> ';
        echo '<a class="button" href="' . esc_url($clear_url) . '" onclick="return confirm(\'Clear all logs?\')">Clear Logs</a>';

        echo '<script>
    const logs = ' . json_encode($parsed_logs) . ';
    document.querySelectorAll(".view-plugins").forEach(btn => {
        btn.addEventListener("click", e => {
            const i = btn.dataset.index;
            const sidebar = document.getElementById("nlt-plugin-sidebar");
            const plugins = logs[i].plugins || [];
            sidebar.innerHTML = "<h2>Plugins (" + plugins.length + ")</h2><ul>" +
                plugins.map(function(p) { return "<li>" + p + "</li>"; }).join("") + "</ul>" +
                "<p><button class=\\"button\\" onclick=\\"document.getElementById(\'nlt-plugin-sidebar\').style.display=\'none\'\\">Close</button></p>";
            sidebar.style.display = "block";
        });
    });
</script>';

        echo '</div>';
    }

    public function export_logs() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $file = self::LOG_FILE;
        if (!file_exists($file)) wp_die('No logs available.');

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="debug-memory-' . date('Ymd_His') . '.json"');
        readfile($file);
        exit;
    }

    public function clear_logs() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'nlt_clear_logs')) {
            wp_die('Unauthorized');
        }
        file_put_contents(self::LOG_FILE, '');
        wp_redirect(admin_url('tools.php?page=nlt_logs&logs_cleared=1'));
        exit;
    }
}

new NoLeemits_WP_Troubleshooter();
