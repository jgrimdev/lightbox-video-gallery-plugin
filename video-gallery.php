<?php
/*
Plugin Name: Lightbox Video Gallery
Description: A simple lightbox video gallery plugin for WordPress.
Version: 1.2
Author: jgrimdev
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

// Register Custom Post Type
function vg_register_post_type() {
    $labels = array(
        'name' => 'Videos',
        'singular_name' => 'Video',
        'menu_name' => 'Lightbox Video Gallery',
        'name_admin_bar' => 'Video',
        'add_new' => 'Add New',
        'add_new_item' => 'Add New Video',
        'new_item' => 'New Video',
        'edit_item' => 'Edit Video',
        'view_item' => 'View Video',
        'all_items' => 'All Videos',
        'search_items' => 'Search Videos',
        'not_found' => 'No videos found.',
        'not_found_in_trash' => 'No videos found in Trash.',
    );

    $args = array(
        'labels' => $labels,
        'public' => true,
        'has_archive' => true,
        'supports' => array('title', 'editor', 'thumbnail', 'page-attributes'), // Přidat 'page-attributes'
        'menu_position' => 5,
        'menu_icon' => 'dashicons-video-alt3',
        'taxonomies' => array('category'),
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'hierarchical' => false, // Může být true nebo false, podle potřeby
    );

    register_post_type('video', $args);
}
add_action('init', 'vg_register_post_type');



// Add Meta Box for Video URL
function vg_add_meta_box() {
    add_meta_box('vg_meta', 'Video URL', 'vg_meta_box_callback', 'video', 'normal', 'high');
}
add_action('add_meta_boxes', 'vg_add_meta_box');

function vg_meta_box_callback($post) {
    wp_nonce_field('vg_save_meta_box_data', 'vg_meta_box_nonce');

    $value = get_post_meta($post->ID, '_video_url', true);

    echo '<label for="vg_video_url">URL:</label>';
    echo '<input type="text" id="vg_video_url" name="vg_video_url" value="' . esc_attr($value) . '" size="25" />';
}

// Save Meta Box Data
function vg_save_meta_box_data($post_id) {
    if (!isset($_POST['vg_meta_box_nonce'])) {
        return;
    }

    if (!wp_verify_nonce($_POST['vg_meta_box_nonce'], 'vg_save_meta_box_data')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (!isset($_POST['vg_video_url'])) {
        return;
    }

    $video_url = sanitize_text_field($_POST['vg_video_url']);
    update_post_meta($post_id, '_video_url', $video_url);
}
add_action('save_post', 'vg_save_meta_box_data');

// Get Video Thumbnail
function vg_get_video_thumbnail($url) {
    $video_id = '';
    $thumbnail_url = '';

    // Detect YouTube video
    if (preg_match('/youtube\.com\/watch\?v=([^\&\?\/]+)/', $url, $id) || 
        preg_match('/youtube\.com\/embed\/([^\&\?\/]+)/', $url, $id) || 
        preg_match('/youtube\.com\/v\/([^\&\?\/]+)/', $url, $id) || 
        preg_match('/youtu\.be\/([^\&\?\/]+)/', $url, $id)) {
        $video_id = $id[1];
        $thumbnail_url = 'https://img.youtube.com/vi/' . $video_id . '/mqdefault.jpg';
    } 
    // Detect Vimeo video
    elseif (preg_match('/vimeo\.com\/(\d+)/', $url, $id) || preg_match('/player\.vimeo\.com\/video\/(\d+)/', $url, $id)) {
        $video_id = $id[1];
        $vimeo_response = wp_remote_get("https://vimeo.com/api/v2/video/$video_id.php");
        if (is_array($vimeo_response) && !is_wp_error($vimeo_response)) {
            $vimeo_body = wp_remote_retrieve_body($vimeo_response);
            $vimeo_data = unserialize($vimeo_body);
            if (isset($vimeo_data[0]['thumbnail_large'])) {
                $thumbnail_url = $vimeo_data[0]['thumbnail_large'];
            }
        }
    }

    return $thumbnail_url ? $thumbnail_url : '';
}
function vg_group_by_category($query) {
    global $pagenow;
    $post_type = 'video'; 

    if (is_admin() && $pagenow == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] == $post_type) {
        // Přidáme nonce ověření
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'vg_group_by_category_nonce')) {
            $query->set('orderby', array('category' => 'ASC', 'menu_order' => 'ASC'));
            $query->set('order', 'ASC');
        }
    }
}
add_action('pre_get_posts', 'vg_group_by_category');
function vg_add_admin_nonce($url) {
    if (strpos($url, 'post_type=video') !== false) {
        $url = add_query_arg('_wpnonce', wp_create_nonce('vg_group_by_category_nonce'), $url);
    }
    return $url;
}
add_filter('admin_url', 'vg_add_admin_nonce');



// Add "Duplicate" link to post row actions
function vg_add_duplicate_link_row_action($actions, $post) {
    if ('video' === $post->post_type) {
        $actions['duplicate'] = '<a href="' . wp_nonce_url('admin.php?action=vg_duplicate_video&post=' . $post->ID, 'vg_duplicate_video_nonce') . '" title="' . esc_attr__('Duplicate this item', 'text-domain') . '">' . __('Duplicate') . '</a>';
    }
    return $actions;
}
add_filter('post_row_actions', 'vg_add_duplicate_link_row_action', 10, 2);

// Handle duplication action
function vg_duplicate_video_action() {
    if (isset($_GET['action']) && $_GET['action'] === 'vg_duplicate_video' && isset($_GET['post']) && wp_verify_nonce($_GET['_wpnonce'], 'vg_duplicate_video_nonce')) {
        $post_id = absint($_GET['post']);
        $post = get_post($post_id);

        if ($post && 'video' === $post->post_type) {
            $new_post_id = wp_insert_post(array(
                'post_title' => $post->post_title . ' (Copy)',
                'post_content' => $post->post_content,
                'post_status' => $post->post_status,
                'post_type' => $post->post_type,
            ));

            // Duplicate meta fields
            $video_url = get_post_meta($post_id, '_video_url', true);
            update_post_meta($new_post_id, '_video_url', $video_url);

            // Redirect to edit screen of the new duplicated post
            if (!is_wp_error($new_post_id)) {
                wp_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
                exit;
            }
        }
    }
}
add_action('admin_init', 'vg_duplicate_video_action');

function vg_shortcode($atts) {
    $atts = shortcode_atts(array(
        'category' => '',
    ), $atts, 'video_gallery');

    $args = array(
        'post_type' => 'video',
        'posts_per_page' => -1,
        'orderby' => 'menu_order',
        'order' => 'ASC', // nebo 'DESC' pro opačné řazení
    );

    if (!empty($atts['category'])) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => $atts['category'],
            ),
        );
    }

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $output = '<div class="video-gallery">';
        while ($query->have_posts()) {
            $query->the_post();
            $video_url = get_post_meta(get_the_ID(), '_video_url', true);
            $thumbnail_url = vg_get_video_thumbnail($video_url);
            $output .= '<div class="video-item">';
            $output .= '<a href="' . esc_url($video_url) . '" class="video-link">';
            $output .= '<div class="video-thumbnail">';
            $output .= '<img src="' . esc_url($thumbnail_url) . '" alt="' . get_the_title() . '" />';
            $output .= '</div>';
            $output .= '<div class="video-title">' . get_the_title() . '</div>';
            $output .= '</a>';
            $output .= '</div>';
        }
        $output .= '</div>';
        wp_reset_postdata();
    } else {
        $output = 'No videos found.';
    }

    return $output;
}
add_shortcode('video_gallery', 'vg_shortcode');




// Add CSS and JS for Magnific Popup
function vg_enqueue_styles_scripts() {
    wp_enqueue_style('vg_magnific_popup_css', plugins_url('assets/magnific-popup.css', __FILE__));
    wp_enqueue_script('jquery');
    wp_enqueue_script('vg_magnific_popup_js', plugins_url('assets/jquery.magnific-popup.min.js', __FILE__), array('jquery'), null, true);
    wp_enqueue_script('vg_custom_js', plugins_url('assets/custom.js', __FILE__), array('jquery', 'vg_magnific_popup_js'), null, true);
    wp_enqueue_style('vg_video_gallery_css', plugins_url('assets/video-gallery.css', __FILE__));
}
add_action('wp_enqueue_scripts', 'vg_enqueue_styles_scripts');
