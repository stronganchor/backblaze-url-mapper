<?php
/**
 * Plugin Name: Backblaze Folder URL Mapper
 * Description: Map specific local upload folders to Backblaze B2 URLs at runtime (safe version: no automatic DB migration).
 * Version:     1.1.4
 * Author:      Strong Anchor Tech
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bb_url_mapper_normalize_local_prefix( $local ) {
    $local = trim( (string) $local );

    if ( $local === '' ) {
        return $local;
    }

    if ( $local[0] !== '/' ) {
        $local = '/' . ltrim( $local, '/' );
    }

    if ( substr( $local, -1 ) !== '/' ) {
        $local .= '/';
    }

    return $local;
}

function bb_url_mapper_get_mappings() {
    $mappings = get_option( 'bb_url_mapper_mappings', array() );
    if ( ! is_array( $mappings ) ) {
        $mappings = array();
    }

    foreach ( $mappings as $index => $mapping ) {
        if ( ! is_array( $mapping ) ) {
            unset( $mappings[ $index ] );
            continue;
        }

        $local  = isset( $mapping['local_prefix'] ) ? trim( (string) $mapping['local_prefix'] ) : '';
        $remote = isset( $mapping['remote_base'] ) ? trim( (string) $mapping['remote_base'] ) : '';

        if ( $local === '' || $remote === '' ) {
            unset( $mappings[ $index ] );
            continue;
        }

        $local  = bb_url_mapper_normalize_local_prefix( $local );
        $remote = esc_url_raw( $remote );

        if ( $remote === '' ) {
            unset( $mappings[ $index ] );
            continue;
        }

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

function bb_url_mapper_get_replacements() {
    $mappings = bb_url_mapper_get_mappings();
    if ( empty( $mappings ) ) {
        return array();
    }

    $home = home_url();
    $site = site_url();

    $bases = array_unique( array_filter( array(
        set_url_scheme( $home, 'http' ),
        set_url_scheme( $home, 'https' ),
        set_url_scheme( $site, 'http' ),
        set_url_scheme( $site, 'https' ),
    ) ) );

    $pairs = array();

    foreach ( $mappings as $mapping ) {
        $local_prefix = $mapping['local_prefix'];
        $remote_base  = $mapping['remote_base'];

        // Absolute URLs (home + site URL variants).
        foreach ( $bases as $base ) {
            $pairs[] = array(
                'search'  => rtrim( $base, '/' ) . $local_prefix,
                'replace' => $remote_base,
            );
        }

        // Relative paths stored in LL Tools meta.
        $pairs[] = array(
            'search'  => $local_prefix,
            'replace' => $remote_base,
        );

        $relative = ltrim( $local_prefix, '/' );
        if ( $relative !== $local_prefix ) {
            $pairs[] = array(
                'search'  => $relative,
                'replace' => $remote_base,
            );
        }
    }

    return $pairs;
}

function bb_url_mapper_replace_in_string( $string ) {
    if ( ! is_string( $string ) || $string === '' ) {
        return $string;
    }

    $pairs = bb_url_mapper_get_replacements();
    if ( empty( $pairs ) ) {
        return $string;
    }

    foreach ( $pairs as $pair ) {
        $string = str_replace( $pair['search'], $pair['replace'], $string );
    }

    return $string;
}

function bb_url_mapper_deep_replace( $value, $depth = 0 ) {
    if ( $depth > 10 ) {
        return $value;
    }

    if ( is_string( $value ) ) {
        return bb_url_mapper_replace_in_string( $value );
    }

    if ( is_array( $value ) ) {
        foreach ( $value as $k => $v ) {
            $value[ $k ] = bb_url_mapper_deep_replace( $v, $depth + 1 );
        }
        return $value;
    }

    if ( is_object( $value ) ) {
        foreach ( get_object_vars( $value ) as $k => $v ) {
            $value->$k = bb_url_mapper_deep_replace( $v, $depth + 1 );
        }
        return $value;
    }

    return $value;
}

function bb_url_mapper_filter_content( $content ) {
    return bb_url_mapper_replace_in_string( $content );
}

function bb_url_mapper_filter_url( $url ) {
    return bb_url_mapper_replace_in_string( $url );
}

function bb_url_mapper_filter_image_src( $image, $attachment_id = 0, $size = '', $icon = false ) {
    if ( is_array( $image ) && isset( $image[0] ) && is_string( $image[0] ) ) {
        $image[0] = bb_url_mapper_replace_in_string( $image[0] );
    }
    return $image;
}

function bb_url_mapper_filter_srcset( $sources ) {
    if ( ! is_array( $sources ) ) {
        return $sources;
    }

    foreach ( $sources as $width => $source ) {
        if ( isset( $source['url'] ) && is_string( $source['url'] ) ) {
            $sources[ $width ]['url'] = bb_url_mapper_replace_in_string( $source['url'] );
        }
    }

    return $sources;
}

function bb_url_mapper_get_meta_keys_whitelist() {
    $defaults = array( 'word_audio_file', 'audio_file_path' );
    $keys = get_option( 'bb_url_mapper_meta_keys', $defaults );

    if ( is_string( $keys ) ) {
        $keys = preg_split( '/\r\n|\r|\n/', $keys );
    }

    if ( ! is_array( $keys ) ) {
        $keys = array();
    }

    $keys = array_filter( array_map( 'sanitize_key', $keys ) );

    if ( empty( $keys ) ) {
        $keys = $defaults;
    }

    // Ensure LL Tools CPT audio meta is always covered.
    $keys = array_values( array_unique( array_merge( $defaults, $keys ) ) );

    return $keys;
}

/**
 * Read-time meta mapping (SAFE): only runs for whitelisted meta keys.
 */
function bb_url_mapper_filter_post_meta( $value, $object_id, $meta_key, $single ) {
    if ( ! is_string( $meta_key ) || $meta_key === '' ) {
        return $value;
    }

    $whitelist = bb_url_mapper_get_meta_keys_whitelist();
    if ( ! in_array( sanitize_key( $meta_key ), $whitelist, true ) ) {
        return $value;
    }

    // If core already loaded a non-null value, just post-process it.
    if ( $value !== null ) {
        return bb_url_mapper_deep_replace( $value );
    }

    // Otherwise let core load it, but we still need to return non-null to apply mapping.
    // Use get_post_meta (which will recurse back here) is dangerous, so query directly.
    global $wpdb;

    if ( $single ) {
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
                $object_id,
                $meta_key
            )
        );

        if ( $raw === null ) {
            return null;
        }

        return bb_url_mapper_deep_replace( maybe_unserialize( $raw ) );
    }

    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
            $object_id,
            $meta_key
        )
    );

    if ( empty( $rows ) ) {
        return null;
    }

    foreach ( $rows as $i => $row ) {
        $rows[ $i ] = bb_url_mapper_deep_replace( maybe_unserialize( $row ) );
    }

    return $rows;
}

function bb_url_mapper_register_filters() {
    add_filter( 'the_content', 'bb_url_mapper_filter_content', 20 );
    add_filter( 'widget_text', 'bb_url_mapper_filter_content', 20 );
    add_filter( 'widget_block_content', 'bb_url_mapper_filter_content', 20 );

    add_filter( 'wp_get_attachment_url', 'bb_url_mapper_filter_url', 20, 1 );
    add_filter( 'post_thumbnail_html', 'bb_url_mapper_filter_content', 20, 1 );
    add_filter( 'wp_get_attachment_image_src', 'bb_url_mapper_filter_image_src', 20, 4 );
    add_filter( 'wp_calculate_image_srcset', 'bb_url_mapper_filter_srcset', 20, 5 );

    // IMPORTANT: only affects whitelisted keys (defaults to word_audio_file).
    add_filter( 'get_post_metadata', 'bb_url_mapper_filter_post_meta', 20, 4 );
}
add_action( 'init', 'bb_url_mapper_register_filters' );

/**
 * Settings page (mappings + meta-key whitelist).
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

function bb_url_mapper_save_settings_from_post() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( ! isset( $_POST['bb_url_mapper_nonce'] ) || ! wp_verify_nonce( $_POST['bb_url_mapper_nonce'], 'bb_url_mapper_save' ) ) {
        return;
    }

    // Save mappings.
    $new_mappings = array();
    if ( isset( $_POST['bb_mappings'] ) && is_array( $_POST['bb_mappings'] ) ) {
        $raw_mappings = wp_unslash( $_POST['bb_mappings'] );
        foreach ( $raw_mappings as $mapping ) {
            if ( ! is_array( $mapping ) ) {
                continue;
            }

            $local  = isset( $mapping['local_prefix'] ) ? trim( (string) $mapping['local_prefix'] ) : '';
            $remote = isset( $mapping['remote_base'] ) ? trim( (string) $mapping['remote_base'] ) : '';

            if ( $local === '' || $remote === '' ) {
                continue;
            }

            $local  = bb_url_mapper_normalize_local_prefix( sanitize_text_field( $local ) );
            $remote = esc_url_raw( $remote );
            if ( $remote === '' ) {
                continue;
            }
            if ( substr( $remote, -1 ) !== '/' ) {
                $remote .= '/';
            }

            $new_mappings[] = array(
                'local_prefix' => $local,
                'remote_base'  => $remote,
            );
        }
    }
    update_option( 'bb_url_mapper_mappings', $new_mappings );

    // Save meta-key whitelist (textarea: one per line).
    $meta_keys_raw = '';
    if ( isset( $_POST['bb_meta_keys'] ) ) {
        $meta_keys_raw = (string) wp_unslash( $_POST['bb_meta_keys'] );
    }
    $meta_keys = preg_split( '/\r\n|\r|\n/', $meta_keys_raw );
    $meta_keys = array_filter( array_map( 'sanitize_key', (array) $meta_keys ) );
    if ( empty( $meta_keys ) ) {
        $meta_keys = array( 'word_audio_file' );
    }
    update_option( 'bb_url_mapper_meta_keys', $meta_keys );
}

function bb_url_mapper_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $notice = '';

    if ( isset( $_POST['bb_url_mapper_submit'] ) ) {
        bb_url_mapper_save_settings_from_post();
        $notice = '<div class="updated"><p>Settings saved.</p></div>';
    }

    $mappings = bb_url_mapper_get_mappings();
    $mappings[] = array( 'local_prefix' => '', 'remote_base' => '' );

    $meta_keys = bb_url_mapper_get_meta_keys_whitelist();
    $meta_keys_text = implode( "\n", $meta_keys );

    $upload_dir = wp_get_upload_dir();

    ?>
    <div class="wrap">
        <h1>Backblaze Folder URL Mapper (Safe)</h1>
        <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

        <p>
            This version maps URLs at runtime only. It does <strong>not</strong> automatically rewrite the database.
            The meta mapping runs only for the meta keys you list below (default: <code>word_audio_file</code>).
        </p>

        <form method="post">
            <?php wp_nonce_field( 'bb_url_mapper_save', 'bb_url_mapper_nonce' ); ?>

            <h2>Folder mappings</h2>
            <p>Example upload base URL on this site: <code><?php echo esc_html( $upload_dir['baseurl'] ); ?></code></p>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:35%;">Local folder prefix (relative to site)</th>
                        <th style="width:55%;">Remote base URL</th>
                        <th style="width:10%;">Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $mappings as $index => $mapping ) : ?>
                    <tr>
                        <td>
                            <input type="text" name="bb_mappings[<?php echo (int) $index; ?>][local_prefix]" value="<?php echo esc_attr( $mapping['local_prefix'] ); ?>" class="regular-text" placeholder="/wp-content/uploads/2025/" />
                        </td>
                        <td>
                            <input type="text" name="bb_mappings[<?php echo (int) $index; ?>][remote_base]" value="<?php echo esc_attr( $mapping['remote_base'] ); ?>" class="large-text" placeholder="https://f002.backblazeb2.com/file/your-bucket/wp-content/uploads/2025/" />
                        </td>
                        <td><em>Empty row ignored.</em></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Meta keys to rewrite at read-time</h2>
            <p>One meta key per line. Keep this tight to avoid unintended side-effects.</p>
            <textarea name="bb_meta_keys" rows="6" style="width:100%;max-width:900px;"><?php echo esc_textarea( $meta_keys_text ); ?></textarea>

            <p class="submit">
                <button type="submit" name="bb_url_mapper_submit" class="button-primary">Save Settings</button>
            </p>
        </form>
    </div>
    <?php
}
