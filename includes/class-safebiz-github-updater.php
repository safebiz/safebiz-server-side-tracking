<?php
/**
 * GitHub Auto-Updater pentru plugin WordPress
 *
 * Verifica GitHub Releases pentru versiuni noi si integreaza cu WordPress
 * native update mechanism (Dashboard -> Updates / Plugins -> Update Available).
 *
 * Usage in main plugin file:
 *   require_once __DIR__ . '/includes/class-safebiz-github-updater.php';
 *   new SafeBiz_GitHub_Updater([
 *       'plugin_file' => __FILE__,
 *       'github_repo' => 'safebiz/safebiz-server-side-tracking',
 *       'plugin_slug' => 'safebiz-server-side-tracking',
 *       'access_token' => '', // empty pentru public repo
 *   ]);
 *
 * GitHub workflow:
 *   1. Push cod la main branch
 *   2. Create Release pe GitHub cu tag `vX.Y.Z` (ex: v1.0.3)
 *   3. WordPress detecteaza update via daily cron + arata notice
 *   4. User click "Update Now" -> WP descarca zip-ul release-ului automat
 *
 * @package SafeBiz_Server_Side_Tracking
 * @version 1.0.2
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('SafeBiz_GitHub_Updater') ) :

class SafeBiz_GitHub_Updater {

    private $plugin_file;       // Absolute path to main plugin file
    private $plugin_slug;       // Plugin slug (folder name)
    private $plugin_basename;   // e.g., 'safebiz-server-side-tracking/safebiz-server-side-tracking.php'
    private $github_repo;       // e.g., 'safebiz/safebiz-server-side-tracking'
    private $access_token;      // Optional GitHub PAT for private repos
    private $plugin_data;       // Cached plugin header data
    private $github_response;   // Cached GitHub API response
    private $cache_key;
    private $cache_seconds = 21600; // 6 hours

    public function __construct($config) {
        $this->plugin_file    = $config['plugin_file'];
        $this->github_repo    = $config['github_repo'];
        $this->plugin_slug    = $config['plugin_slug'];
        $this->access_token   = ! empty($config['access_token']) ? $config['access_token'] : '';
        $this->plugin_basename = plugin_basename($this->plugin_file);
        $this->cache_key      = 'safebiz_ghupdate_' . md5($this->github_repo);

        // Hooks WP native update flow
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api',                            [$this, 'plugin_details_modal'], 10, 3);
        add_filter('upgrader_source_selection',              [$this, 'fix_source_folder_name'], 10, 4);
        add_action('upgrader_process_complete',              [$this, 'clear_cache'], 10, 2);
    }

    /**
     * Get current installed plugin version from header.
     */
    private function get_plugin_data() {
        if ( ! function_exists('get_plugin_data') ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! $this->plugin_data ) {
            $this->plugin_data = get_plugin_data($this->plugin_file, false, false);
        }
        return $this->plugin_data;
    }

    /**
     * Query GitHub Releases API (cached).
     */
    private function get_latest_release() {
        $cached = get_transient($this->cache_key);
        if ( $cached !== false ) return $cached;

        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $args = [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'SafeBiz-WP-Updater',
            ],
        ];
        if ( ! empty($this->access_token) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        }

        $response = wp_remote_get($url, $args);

        if ( is_wp_error($response) ) {
            error_log('[SafeBiz-GHUpdater] WP_Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ( $code !== 200 ) {
            error_log('[SafeBiz-GHUpdater] HTTP ' . $code . ' from GitHub API');
            // Cache fail short-term to avoid hammering API on persistent errors
            set_transient($this->cache_key, false, HOUR_IN_SECONDS);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ( ! is_array($body) || empty($body['tag_name']) ) {
            return false;
        }

        // Normalize: tag "v1.0.3" or "1.0.3" -> "1.0.3"
        $version = ltrim($body['tag_name'], 'vV');

        $release = [
            'version'      => $version,
            'tag_name'     => $body['tag_name'],
            'zipball_url'  => $body['zipball_url'] ?? '',
            'html_url'     => $body['html_url'] ?? '',
            'body'         => $body['body'] ?? '',
            'published_at' => $body['published_at'] ?? '',
            'assets'       => $body['assets'] ?? [],
        ];

        set_transient($this->cache_key, $release, $this->cache_seconds);
        return $release;
    }

    /**
     * Get download URL: prefer first .zip asset (release artifact), fallback la zipball.
     */
    private function get_download_url($release) {
        if ( ! empty($release['assets']) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( ! empty($asset['browser_download_url']) && substr($asset['name'], -4) === '.zip' ) {
                    return $asset['browser_download_url'];
                }
            }
        }
        return $release['zipball_url'];
    }

    /**
     * Hook: inject update info in WP transient if GitHub has newer version.
     */
    public function inject_update($transient) {
        if ( ! is_object($transient) ) return $transient;
        if ( empty($transient->checked) ) return $transient;

        $release = $this->get_latest_release();
        if ( ! $release ) return $transient;

        $current_version = $this->get_plugin_data()['Version'] ?? '0.0.0';

        if ( version_compare($release['version'], $current_version, '>') ) {
            $obj = new stdClass();
            $obj->slug         = $this->plugin_slug;
            $obj->plugin       = $this->plugin_basename;
            $obj->new_version  = $release['version'];
            $obj->url          = $release['html_url'];
            $obj->package      = $this->get_download_url($release);
            $obj->tested       = get_bloginfo('version');
            $obj->requires_php = $this->get_plugin_data()['RequiresPHP'] ?? '7.4';
            $obj->compatibility = new stdClass();

            $transient->response[$this->plugin_basename] = $obj;
        }

        return $transient;
    }

    /**
     * Hook: provide plugin details for "View details" modal in WP admin.
     */
    public function plugin_details_modal($result, $action, $args) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( empty($args->slug) || $args->slug !== $this->plugin_slug ) return $result;

        $release = $this->get_latest_release();
        if ( ! $release ) return $result;

        $plugin_data = $this->get_plugin_data();

        $info = new stdClass();
        $info->name           = $plugin_data['Name'] ?? $this->plugin_slug;
        $info->slug           = $this->plugin_slug;
        $info->version        = $release['version'];
        $info->author         = $plugin_data['AuthorName'] ?? $plugin_data['Author'] ?? '';
        $info->author_profile = $plugin_data['AuthorURI'] ?? '';
        $info->homepage       = $plugin_data['PluginURI'] ?? $release['html_url'];
        $info->requires       = $plugin_data['RequiresWP'] ?? '6.0';
        $info->tested         = get_bloginfo('version');
        $info->requires_php   = $plugin_data['RequiresPHP'] ?? '7.4';
        $info->last_updated   = $release['published_at'];
        $info->download_link  = $this->get_download_url($release);

        $info->sections = [
            'description' => wpautop(esc_html($plugin_data['Description'] ?? '')),
            'changelog'   => wpautop(esc_html($release['body'])),
        ];

        return $info;
    }

    /**
     * Hook: fix folder name after unzip from GitHub.
     *
     * GitHub zip-uri (zipball_url) au folder ca "owner-repo-{sha7}".
     * WP asteapta folderul exact ca plugin_slug. Redenumim post-extract.
     */
    public function fix_source_folder_name($source, $remote_source, $upgrader, $hook_extra = null) {
        global $wp_filesystem;

        if ( ! is_object($upgrader) || ! isset($upgrader->skin) ) return $source;
        // Only intervene for our plugin
        $plugin = isset($hook_extra['plugin']) ? $hook_extra['plugin'] : '';
        if ( $plugin && $plugin !== $this->plugin_basename ) return $source;

        $source_folder_name = basename(rtrim($source, '/\\'));
        if ( $source_folder_name === $this->plugin_slug ) return $source;

        // Only rename if folder name looks like GitHub-generated (owner-repo-SHA)
        $expected_prefix = str_replace('/', '-', $this->github_repo) . '-';
        if ( strpos($source_folder_name, $expected_prefix) !== 0 ) return $source;

        $new_source = trailingslashit($remote_source) . $this->plugin_slug;
        if ( $wp_filesystem && $wp_filesystem->move($source, $new_source) ) {
            return trailingslashit($new_source);
        }
        return $source;
    }

    /**
     * Hook: clear cache after successful update.
     */
    public function clear_cache($upgrader, $hook_extra) {
        if ( ! is_array($hook_extra) ) return;
        if ( ($hook_extra['action'] ?? '') !== 'update' ) return;
        if ( ($hook_extra['type'] ?? '') !== 'plugin' ) return;
        if ( empty($hook_extra['plugins']) || ! in_array($this->plugin_basename, (array) $hook_extra['plugins'], true) ) return;

        delete_transient($this->cache_key);
    }

    /**
     * Force check (e.g., from admin action) — clears cache.
     */
    public function force_check() {
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
    }
}

endif;
