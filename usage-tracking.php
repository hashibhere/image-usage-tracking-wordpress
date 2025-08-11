<?php
/**
 * Plugin Name: Usage Tracking
 * Description: Tracks media usage across all posts/pages with view counts, scrollable table, sticky columns, and improved layout.
 * Version: 1.6
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

// ----------------------------------------
// FRONTEND: Track media loads
// ----------------------------------------
function ut_enqueue_tracking_script() {
    if (is_singular()) {
        global $post;

        $media_ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $found = [];

        foreach ($media_ids as $media_id) {
            $url = wp_get_attachment_url($media_id);
            if ($url && strpos($post->post_content, $url) !== false) {
                $found[] = $url;
            }
        }

        if (!empty($found)) {
            echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    var mediaFiles = ' . json_encode($found) . ';
                    mediaFiles.forEach(function(file) {
                        fetch("' . admin_url('admin-ajax.php') . '?action=ut_track_usage", {
                            method: "POST",
                            headers: {"Content-Type": "application/x-www-form-urlencoded"},
                            body: "file=" + encodeURIComponent(file) + "&post_id=' . get_the_ID() . '"
                        });
                    });
                });
            </script>';
        }
    }
}
add_action('wp_footer', 'ut_enqueue_tracking_script');

// ----------------------------------------
// BACKEND: Record view count
// ----------------------------------------
function ut_track_usage() {
    global $wpdb;
    $table = $wpdb->prefix . 'ut_usage';
    $file = esc_url_raw($_POST['file']);
    $post_id = intval($_POST['post_id']);

    if (!$file || !$post_id) wp_die();

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE file_url = %s AND post_id = %d",
        $file, $post_id
    ));

    if ($exists) {
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET load_count = load_count + 1 WHERE file_url = %s AND post_id = %d",
            $file, $post_id
        ));
    } else {
        $wpdb->insert($table, [
            'file_url' => $file,
            'post_id' => $post_id,
            'load_count' => 1
        ]);
    }

    wp_die();
}
add_action('wp_ajax_ut_track_usage', 'ut_track_usage');
add_action('wp_ajax_nopriv_ut_track_usage', 'ut_track_usage');

// ----------------------------------------
// INSTALLATION: Create DB table
// ----------------------------------------
function ut_install() {
    global $wpdb;
    $table = $wpdb->prefix . 'ut_usage';
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $sql = "CREATE TABLE $table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        file_url TEXT,
        post_id BIGINT,
        load_count INT
    ) $charset_collate;";
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'ut_install');

// ----------------------------------------
// ADMIN MENU: Add page
// ----------------------------------------
function ut_register_admin_menu() {
    add_media_page('Usage Tracking', 'Usage Tracking', 'manage_options', 'usage-tracking', 'ut_render_admin_page');
}
add_action('admin_menu', 'ut_register_admin_menu');

// ----------------------------------------
// ADMIN PAGE: Display usage tracking table
// ----------------------------------------
function ut_render_admin_page() {
    global $wpdb;

    $media_files = get_posts(['post_type' => 'attachment', 'numberposts' => -1]);
    $posts = get_posts(['post_type' => ['post', 'page'], 'numberposts' => -1]);

    $usage_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ut_usage");
    $usage = [];
    foreach ($usage_rows as $row) {
        $usage[$row->file_url][$row->post_id] = $row->load_count;
    }

    echo '<div class="wrap"><h1>Media Usage Tracking</h1>';

    echo '<style>
    .ut-wrapper {
        width: 100%;
        overflow-x: auto;
        padding-bottom: 1rem;
    }
    .ut-table {
        border-collapse: collapse;
        min-width: 2000px;
        width: max-content;
    }
    .ut-table th,
    .ut-table td {
        white-space: nowrap;
        padding: 12px 16px;
        border: 1px solid #ccc;
        background: #fff;
        text-align: center;
        vertical-align: middle;
        font-size: 14px;
    }
    .ut-table th {
        font-weight: bold;
        background: #f0f0f0;
    }

    /* Sticky first 2 columns */
    .ut-table th.sticky,
    .ut-table td.sticky {
        position: sticky;
        background: #fafafa;
        z-index: 5;
    }

    .ut-table th.col-1, .ut-table td.col-1 { left: 0; width: 60px; z-index: 10; }
    .ut-table th.col-2, .ut-table td.col-2 { left: 60px; width: 220px; }

    .ut-table td img {
        display: block;
        margin: 0 auto 8px;
        height: 100px;
    }
    @media (min-width: 768px) {
        .ut-table td img {
            height: 200px;
        }
    }

    .media-title {
        font-size: 13px;
        margin-top: 5px;
        font-weight: 600;
        color: #333;
    }
    </style>';

    echo '<div class="ut-wrapper"><table class="ut-table">';
    echo '<thead><tr>';
    echo '<th class="sticky col-1">Slno.</th>';
    echo '<th class="sticky col-2">Media</th>';
    $counter = 1;
    foreach ($posts as $p) {
        echo '<th>Page ' . $counter++ . '</th>';
    }
    echo '</tr></thead><tbody>';

    $sl = 1;
    foreach ($media_files as $media) {
        $file_url = wp_get_attachment_url($media->ID);
        $media_title = esc_html($media->post_title);
        $media_img = '<img src="' . esc_url($file_url) . '" alt="' . $media_title . '">';
        $media_block = $media_img . '<div class="media-title">' . $media_title . '</div>';

        echo '<tr>';
        echo '<td class="sticky col-1">' . $sl++ . '</td>';
        echo '<td class="sticky col-2">' . $media_block . '</td>';

        foreach ($posts as $p) {
            $slug = untrailingslashit(parse_url(get_permalink($p->ID), PHP_URL_PATH));
            $load_count = $usage[$file_url][$p->ID] ?? 0;
            if (strpos($p->post_content, $file_url) !== false) {
                echo '<td><a href="' . esc_url(get_permalink($p->ID)) . '" target="_blank">' . esc_html($slug) . '</a><br>(' . $load_count . ')</td>';
            } else {
                echo '<td>-</td>';
            }
        }

        echo '</tr>';
    }

    echo '</tbody></table></div></div>';
}
