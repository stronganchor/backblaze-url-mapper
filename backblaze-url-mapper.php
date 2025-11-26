<?php
/**
 * Plugin Name: Backblaze Folder URL Mapper
 * Description: Map specific local upload folders to Backblaze B2 URLs without changing the database.
 * Version:     1.0.0
 * Author:      Strong Anchor Tech
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get mappings from the options table.
 *
 * Each mapping is an array:
 * [
 *   'local_prefix' => '/wp-content/uploads/2023/',
 *   'remote_base'  => 'https://f002.backblazeb2.com/file/funwithaview-wp-content/wp-content/uploads/2023/'
 * ]
 *
 * Local prefix is relative to the site root (starts with /).
 * Remote base is a full URL, typically ending with /.
 */
function bb_url_mapper_get_mappings() {
    $mappings = get_option( 'bb_url_mapper_mappings', array() );
    if ( ! is_array( $mappings ) ) {
        $mappings = array();
    }

    // Normalize structure.
    foreach ( $mappings as $index => $mapping ) {
        if ( ! is_array( $mapping ) ) {
            unset( $mappings[ $index ] );
            continue;
        }
        $local  = isset( $mapping['local_prefix'] ) ? trim( $mapping['local_prefix'] ) : '';
        $remote = isset( $mapping['remote_base'] ) ? trim( $mapping['remote_base'] ) : '';

        if ( $local === '' || $remote === '' ) {
            unset( $mappings[ $index ] );
            continue;
        }

        // Ensure local prefix starts with a slash.
        if ( $local[0] !== '/' ) {
            $local = '/' . ltrim( $local, '/' );
        }

        // Ensure remote base has a trailing slash.
        if ( substr( $remote, -1 ) !== '/' ) {
            $remote .= '/';
        }

        $mappings[ $index ] = array(
            'local_prefix' => $local,
            'remote_base'  => $remote,
        );
    }

    return array_values( $mappings );
}

/**
 * Save mappings from POST.
 */
function bb_url_mapper_save_mappings_from_post() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_POST['bb_url_mapper_nonce'] ) || ! wp_verify_nonce( $_POST['bb_url_mapper_nonce'], 'bb_url_mapper_save' ) ) {
        return;
    }

    if ( ! isset( $_POST['bb_mappings'] ) || ! is_array( $_POST['bb_mappings'] ) ) {
        return;
    }

    $raw_mappings = wp_unslash( $_POST['bb_mappings'] );
    $new_mappings = array();

    foreach ( $raw_mappings as $mapping ) {
        if ( ! is_array( $mapping ) ) {
            continue;
        }

        $local  = isset( $mapping['local_prefix'] ) ? trim( $mapping['local_prefix'] ) : '';
        $remote = isset( $mapping['remote_base'] ) ? trim( $mapping['remote_base'] ) : '';

        if ( $local === '' || $remote === '' ) {
            continue;
        }

        // Basic sanitization.
        $local  = sanitize_text_field( $local );
        $remote = esc_url_raw( $remote );

        if ( $remote === '' ) {
            continue;
        }

        $new_mappings[] = array(
            'local_prefix' => $local,
            'remote_base'  => $remote,
        );
    }

    update_option( 'bb_url_mapper_mappings', $new_mappings );
}

/**
 * Replace local URLs with Backblaze URLs in a string.
 */
function bb_url_mapper_replace_in_string( $string ) {
    if ( ! is_string( $string ) || $string === '' ) {
        return $string;
    }

    $mappings = bb_url_mapper_get_mappings();
    if ( empty( $mappings ) ) {
        return $string;
    }

    $home = home_url();
    $home_no_scheme_http  = set_url_scheme( $home, 'http' );
    $home_no_scheme_https = set_url_scheme( $home, 'https' );

    foreach ( $mappings as $mapping ) {
        $local_prefix = $mapping['local_prefix'];
        $remote_base  = $mapping['remote_base'];

        // Build full local URL prefixes (http and https).
        $local_http  = rtrim( $home_no_scheme_http, '/' ) . $local_prefix;
        $local_https = rtrim( $home_no_scheme_https, '/' ) . $local_prefix;

        // Ensure remote base ends with a slash (already done in getter but be safe).
        if ( substr( $remote_base, -1 ) !== '/' ) {
            $remote_base .= '/';
        }

        // Replace both http and https variants.
        $string = str_replace( $local_http, $remote_base, $string );
        $string = str_replace( $local_https, $remote_base, $string );
    }

    return $string;
}

/**
 * Filter for content-like strings.
 */
function bb_url_mapper_filter_content( $content ) {
    return bb_url_mapper_replace_in_string( $content );
}

/**
 * Filter for simple URL strings.
 */
function bb_url_mapper_filter_url( $url ) {
    return bb_url_mapper_replace_in_string( $url );
}

/**
 * Filter for image src arrays.
 *
 * $image is array( url, width, height, is_intermediate ).
 */
function bb_url_mapper_filter_image_src( $image, $attachment_id = 0, $size = '', $icon = false ) {
    if ( is_array( $image ) && isset( $image[0] ) && is_string( $image[0] ) ) {
        $image[0] = bb_url_mapper_replace_in_string( $image[0] );
    }
    return $image;
}

/**
 * Register filters.
 */
function bb_url_mapper_register_filters() {
    // Main content.
    add_filter( 'the_content', 'bb_url_mapper_filter_content', 20 );
    add_filter( 'widget_text', 'bb_url_mapper_filter_content', 20 );
    add_filter( 'widget_block_content', 'bb_url_mapper_filter_content', 20 );

    // Attachment URLs.
    add_filter( 'wp_get_attachment_url', 'bb_url_mapper_filter_url', 20, 1 );
    add_filter( 'post_thumbnail_html', 'bb_url_mapper_filter_content', 20, 1 );

    // Image src/srcset.
    add_filter( 'wp_get_attachment_image_src', 'bb_url_mapper_filter_image_src', 20, 4 );
    add_filter( 'wp_calculate_image_srcset', 'bb_url_mapper_filter_url', 20, 1 );
}
add_action( 'init', 'bb_url_mapper_register_filters' );

/**
 * Add settings page.
 */
function bb_url_mapper_add_admin_menu() {
    add_options_page(
        'Backblaze URL Mapper',
        'Backblaze URL Mapper',
        'manage_options',
        'bb-url-mapper',
        'bb_url_mapper_render_settings_page'
    );
}
add_action( 'admin_menu', 'bb_url_mapper_add_admin_menu' );

/**
 * Render settings page.
 */
function bb_url_mapper_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['bb_url_mapper_submit'] ) ) {
        bb_url_mapper_save_mappings_from_post();
        echo '<div class="updated"><p>Mappings saved.</p></div>';
    }

    $mappings = bb_url_mapper_get_mappings();

    // Add one empty row for convenience.
    $mappings[] = array(
        'local_prefix' => '',
        'remote_base'  => '',
    );

    $upload_dir = wp_get_upload_dir();
    $suggested_local_2023 = '/wp-content/uploads/2023/';
    ?>
    <div class="wrap">
        <h1>Backblaze Folder URL Mapper</h1>
        <p>
            Map local upload folders to Backblaze (or other remote) URLs without modifying the database.
            Any URL starting with a local prefix will be rewritten to the remote base URL at runtime.
        </p>

        <h2>Example for this site</h2>
        <p>
            If your local files live at:<br>
            <code><?php echo esc_html( $upload_dir['baseurl'] . '/2023/' ); ?></code><br>
            and your Backblaze URLs look like:<br>
            <code>https://f002.backblazeb2.com/file/funwithaview-wp-content/wp-content/uploads/2023/</code><br>
            then use:
        </p>
        <ul>
            <li><strong>Local folder prefix:</strong> <code><?php echo esc_html( $suggested_local_2023 ); ?></code></li>
            <li><strong>Remote base URL:</strong> <code>https://f002.backblazeb2.com/file/funwithaview-wp-content/wp-content/uploads/2023/</code></li>
        </ul>

        <form method="post">
            <?php wp_nonce_field( 'bb_url_mapper_save', 'bb_url_mapper_nonce' ); ?>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:35%;">Local folder prefix (relative to site)</th>
                        <th style="width:55%;">Remote base URL (Backblaze or other)</th>
                        <th style="width:10%;">Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $mappings as $index => $mapping ) : ?>
                    <tr>
                        <td>
                            <input type="text"
                                   name="bb_mappings[<?php echo (int) $index; ?>][local_prefix]"
                                   value="<?php echo esc_attr( $mapping['local_prefix'] ); ?>"
                                   class="regular-text"
                                   placeholder="/wp-content/uploads/2023/" />
                        </td>
                        <td>
                            <input type="text"
                                   name="bb_mappings[<?php echo (int) $index; ?>][remote_base]"
                                   value="<?php echo esc_attr( $mapping['remote_base'] ); ?>"
                                   class="large-text"
                                   placeholder="https://f002.backblazeb2.com/file/your-bucket/wp-content/uploads/2023/" />
                        </td>
                        <td>
                            <em>Leave both fields empty to ignore row.</em>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" name="bb_url_mapper_submit" class="button-primary">
                    Save Mappings
                </button>
            </p>
        </form>

        <h2>How it works</h2>
        <ol>
            <li>For each mapping, the plugin builds the full local URL using your <code>home_url()</code> and the local prefix.</li>
            <li>On output, it replaces occurrences of that local URL with the remote base URL.</li>
            <li>Only matching folders are rewritten (e.g. <code>/wp-content/uploads/2023/</code>), so newer folders remain local.</li>
        </ol>

        <p><strong>Note:</strong> This does not change the database. Disable the plugin and URLs revert to their original local values.</p>
    </div>
    <?php
}
