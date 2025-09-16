<?php
/**
 * Plugin Name: CF7 → Bloomreach (Async, Consent-Safe)
 * Description: Sends CF7 submissions to Bloomreach asynchronously. Pushes consent only if the customer doesn't already have it.
 * Version: 1.0.0
 * Author: Steve O'Rourke
 */

if (!defined('ABSPATH')) exit;


/**
 * (Optional) Ensure Markdown parser exists for PUC GitHub release notes.
 * Safe to keep; harmless if vendor already loads it.
 */
if ( ! class_exists('Parsedown') ) {
    foreach ([
        __DIR__ . '/inc/plugin-update-checker/vendor/Parsedown.php',
        __DIR__ . '/inc/plugin-update-checker/vendor/ParsedownModern.php',
        __DIR__ . '/inc/plugin-update-checker/vendor/erusev/parsedown/Parsedown.php',
    ] as $pd) {
        if ( is_readable($pd) ) { require_once $pd; break; }
    }
}

/**
 * Plugin Update Checker bootstrap (robust for v5+)
 */
$__puc_loader = __DIR__ . '/inc/plugin-update-checker/plugin-update-checker.php';

if ( file_exists($__puc_loader) ) {
    require_once $__puc_loader;

    // Try latest namespaces first, then fallback
    $factoryClass = null;
    foreach ([
        '\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory',
        '\YahnisElsts\PluginUpdateChecker\v5p6\PucFactory',
        '\YahnisElsts\PluginUpdateChecker\v5\PucFactory',
        'Puc_v5_Factory',
    ] as $candidate) {
        if ( class_exists($candidate) ) { $factoryClass = $candidate; break; }
    }

    if ( $factoryClass ) {
        $updateChecker = $factoryClass::buildUpdateChecker(
            'https://github.com/infoitteam/bloomreach-contact-forms',
            __FILE__,
            'bloomreach-contact-forms' // plugin folder slug
        );
        $updateChecker->setBranch('main'); // track main branch
    }
}


} else {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>CF7 → Bloomreach:</strong> Missing <code>inc/plugin-update-checker/plugin-update-checker.php</code>. Reinstall the plugin.</p></div>';
    });
    error_log('CF7→BR: plugin-update-checker.php missing at ' . $__puc_loader);
}



class CF7_Bloomreach_Async {
    const OPT = 'cf7_br_settings';
    const CRON_HOOK = 'cf7_br_send_job';
    const TRANSIENT_PREFIX = 'cf7_br_consent_'; // email|consent_key → bool

    public function __construct() {
        // Admin settings
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', function() {
            add_options_page('CF7 → Bloomreach', 'CF7 → Bloomreach', 'manage_options', 'cf7-br', [$this, 'settings_page']);
        });

        // Hook CF7 submit
        add_action('wpcf7_mail_sent', [$this, 'on_cf7_submit'], 10, 1);

        // Cron consumer
        add_action(self::CRON_HOOK, [$this, 'process_job'], 10, 1);
    }

    /** SETTINGS **/
    public function register_settings() {
        register_setting(self::OPT, self::OPT, function($in) {
            $out = [
                'token'       => sanitize_text_field($in['token'] ?? ''),
                'project'     => sanitize_text_field($in['project'] ?? ''), // optional
                'api_base'    => esc_url_raw($in['api_base'] ?? 'https://api.exponea.com'), // adjust if needed
                'forms'       => is_array($in['forms'] ?? []) ? array_map(function($row){
                    return [
                        'form_id'     => absint($row['form_id'] ?? 0),
                        'event_type'  => sanitize_key($row['event_type'] ?? 'cf7_submit'),
                        'consent_key' => sanitize_key($row['consent_key'] ?? ''), // e.g. marketing_email
                        'email_field' => sanitize_key($row['email_field'] ?? 'your-email'),
                        // optional extra fields map (cf7_name_field => br_property_key)
                        'map'         => array_filter(array_map('sanitize_text_field', $row['map'] ?? [])),
                    ];
                }, $in['forms']) : [],
                'consent_cache_minutes' => max(1, absint($in['consent_cache_minutes'] ?? 60)),
                'timeout'     => max(3, absint($in['timeout'] ?? 5)),
            ];
            return $out;
        });
    }

    public function settings_page() {
        $s = get_option(self::OPT, []);
        ?>
        <div class="wrap">
            <h1>CF7 → Bloomreach</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT); ?>
                <table class="form-table">
                    <tr><th>Bloomreach Token</th>
                        <td><input type="text" name="<?php echo self::OPT; ?>[token]" value="<?php echo esc_attr($s['token'] ?? ''); ?>" size="60" /></td></tr>
                    <tr><th>Project (optional)</th>
                        <td><input type="text" name="<?php echo self::OPT; ?>[project]" value="<?php echo esc_attr($s['project'] ?? ''); ?>" size="60" /></td></tr>
                    <tr><th>API Base</th>
                        <td><input type="text" name="<?php echo self::OPT; ?>[api_base]" value="<?php echo esc_attr($s['api_base'] ?? 'https://api.exponea.com'); ?>" size="60" /></td></tr>
                    <tr><th>HTTP Timeout (s)</th>
                        <td><input type="number" name="<?php echo self::OPT; ?>[timeout]" value="<?php echo esc_attr($s['timeout'] ?? 5); ?>" min="3" max="20"/></td></tr>
                    <tr><th>Consent cache (minutes)</th>
                        <td><input type="number" name="<?php echo self::OPT; ?>[consent_cache_minutes]" value="<?php echo esc_attr($s['consent_cache_minutes'] ?? 60); ?>" min="1" max="1440"/></td></tr>
                </table>

                <h2>Form mappings</h2>
                <p>Map CF7 forms to Bloomreach event/consent. Add one row per form.</p>
                <table class="widefat striped">
                    <thead><tr>
                        <th>CF7 Form ID</th>
                        <th>Event Type</th>
                        <th>Consent Key (optional)</th>
                        <th>Email Field (CF7 name)</th>
                        <th>Extra field map (cf7_field=br_property)</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $rows = $s['forms'] ?? [];
                    if (empty($rows)) $rows = [['form_id'=>'','event_type'=>'cf7_submit','consent_key'=>'','email_field'=>'your-email','map'=>[]]];
                    foreach ($rows as $i => $row) {
                        echo '<tr>';
                        printf('<td><input name="%s[forms][%d][form_id]" type="number" value="%s" min="1" /></td>', self::OPT, $i, esc_attr($row['form_id'] ?? ''));
                        printf('<td><input name="%s[forms][%d][event_type]" value="%s" /></td>', self::OPT, $i, esc_attr($row['event_type'] ?? 'cf7_submit'));
                        printf('<td><input name="%s[forms][%d][consent_key]" value="%s" placeholder="e.g. marketing_email"/></td>', self::OPT, $i, esc_attr($row['consent_key'] ?? ''));
                        printf('<td><input name="%s[forms][%d][email_field]" value="%s" placeholder="your-email"/></td>', self::OPT, $i, esc_attr($row['email_field'] ?? 'your-email'));
                        // Simple key=value pairs separated by commas
                        $map_str = '';
                        if (!empty($row['map']) && is_array($row['map'])) {
                            $pairs = [];
                            foreach ($row['map'] as $k => $v) $pairs[] = "{$k}={$v}";
                            $map_str = implode(',', $pairs);
                        }
                        printf('<td><input name="%s[forms][%d][map_str]" value="%s" placeholder="first-name=first_name,last-name=last_name"/></td>', self::OPT, $i, esc_attr($map_str));
                        echo '</tr>';
                    }
                    ?>
                    </tbody>
                </table>
                <p><em>Tip:</em> For more rows, just add and save; the plugin preserves them.</p>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** CF7 submit hook */
    public function on_cf7_submit($contact_form) {
        $s = get_option(self::OPT, []);
        if (empty($s['token'])) return;

        $form_id = absint($contact_form->id());
        $map = $this->find_form_map($s, $form_id);
        if (!$map) return;

        // Extract submission data
        $submission = \WPCF7_Submission::get_instance();
        if (!$submission) return;
        $posted = $submission->get_posted_data();

        $email_field = $map['email_field'] ?: 'your-email';
        $email = isset($posted[$email_field]) ? sanitize_email(is_array($posted[$email_field]) ? reset($posted[$email_field]) : $posted[$email_field]) : '';
        if (!$email) return;

        // Build event properties
        $props = [
            'form_id'     => $form_id,
            'form_title'  => $contact_form->name(),
            'source_url'  => esc_url_raw($submission->get_meta('url')),
            'user_agent'  => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip'          => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        ];

        // Extra field mappings (cf7_name -> br_property)
        $extra_map = $this->parse_map_str($map);
        foreach ($extra_map as $cf7_name => $br_key) {
            if (isset($posted[$cf7_name])) {
                $val = is_array($posted[$cf7_name]) ? implode(', ', array_map('sanitize_text_field', $posted[$cf7_name])) : sanitize_text_field($posted[$cf7_name]);
                $props[$br_key] = $val;
            }
        }

        $job = [
            'type'         => 'submit',
            'event_type'   => $map['event_type'] ?: 'cf7_submit',
            'email'        => $email,
            'properties'   => $props,
            'consent_key'  => $map['consent_key'] ?: '',
            'ts'           => time(),
            'request_id'   => wp_generate_uuid4(), // idempotency-ish
        ];

        // Enqueue job via WP-Cron in ~30s (keeps submit fast)
        wp_schedule_single_event(time() + 30, self::CRON_HOOK, [$job]);
    }

    private function find_form_map($s, $form_id) {
        $rows = $s['forms'] ?? [];
        foreach ($rows as $row) {
            if (absint($row['form_id'] ?? 0) === $form_id) {
                // Rebuild map from map_str if present
                if (!empty($row['map_str'])) {
                    $row['map'] = $this->kv_to_array($row['map_str']);
                }
                return $row;
            }
        }
        return null;
    }

    private function kv_to_array($str) {
        $out = [];
        foreach (explode(',', (string)$str) as $pair) {
            $pair = trim($pair);
            if (!$pair) continue;
            $bits = explode('=', $pair, 2);
            if (count($bits) === 2) $out[trim($bits[0])] = trim($bits[1]);
        }
        return $out;
    }

    private function parse_map_str($map_row) {
        if (!empty($map_row['map']) && is_array($map_row['map'])) return $map_row['map'];
        if (!empty($map_row['map_str'])) return $this->kv_to_array($map_row['map_str']);
        return [];
    }

    /** Cron consumer */
    public function process_job($job) {
        $s = get_option(self::OPT, []);
        $token    = $s['token'] ?? '';
        $api_base = rtrim($s['api_base'] ?? 'https://api.exponea.com', '/');
        $project  = $s['project'] ?? '';
        $timeout  = max(3, absint($s['timeout'] ?? 5));

        if (!$token) return;

        $email  = $job['email'];
        $cids   = ['email' => $email];
        if ($project) $cids['registered'] = $project . ':' . $email; // optional extra ID scheme

        // 1) Push the main form submit event
        $payload = [
            'customer_ids' => $cids,
            'event_type'   => $job['event_type'],
            'properties'   => $job['properties'],
            'timestamp'    => (int)$job['ts'],
        ];
        $this->br_post("{$api_base}/track/v2/projects/events", $token, $payload, $timeout);

        // 2) Conditionally push consent (only if missing)
        $consent_key = $job['consent_key'] ?? '';
        if ($consent_key) {
            if (!$this->has_consent_cached_or_remote($api_base, $token, $email, $consent_key, (int)($s['consent_cache_minutes'] ?? 60), $timeout)) {
                // push consent_granted event (adjust to your BR consent model)
                $consent_payload = [
                    'customer_ids' => $cids,
                    'event_type'   => 'consent_granted',
                    'properties'   => [
                        'consent_key' => $consent_key,
                        'method'      => 'cf7_form',
                        'source'      => 'website',
                    ],
                    'timestamp'    => time(),
                ];
                $this->br_post("{$api_base}/track/v2/projects/events", $token, $consent_payload, $timeout);

                // Mark cache as true
                $this->set_consent_cache($email, $consent_key, true, (int)($s['consent_cache_minutes'] ?? 60));
            }
        }
    }

    /** Consent cache + check **/
    private function cache_key($email, $consent_key) {
        return self::TRANSIENT_PREFIX . md5(strtolower($email) . '|' . $consent_key);
    }

    private function set_consent_cache($email, $consent_key, $val, $minutes) {
        set_transient($this->cache_key($email, $consent_key), $val ? '1' : '0', $minutes * MINUTE_IN_SECONDS);
    }

    private function get_consent_cache($email, $consent_key) {
        $v = get_transient($this->cache_key($email, $consent_key));
        if ($v === false) return null;
        return $v === '1';
    }

    private function has_consent_cached_or_remote($api_base, $token, $email, $consent_key, $cache_minutes, $timeout) {
        $cached = $this->get_consent_cache($email, $consent_key);
        if ($cached !== null) return (bool)$cached;

        // Ask Bloomreach: get customer profile incl. consents
        // Adjust URL/fields to your BR project; this example assumes a v2 customers endpoint that returns consents
        $url = $api_base . '/track/v2/projects/customers/get';
        $payload = [
            'customer_ids' => ['email' => $email],
            'options'      => ['include' => ['consents']],
        ];
        $res = $this->br_post($url, $token, $payload, $timeout);
        $has = false;

        if (is_array($res) && !empty($res['data']['consents']) && is_array($res['data']['consents'])) {
            // Expect structure like: consents: { marketing_email: {status: "opt_in"/"opt_out"} }
            $c = $res['data']['consents'][$consent_key] ?? null;
            if (is_array($c)) {
                $status = strtolower($c['status'] ?? '');
                $has = ($status === 'opt_in' || $status === 'granted' || $status === 'true' || $status === '1');
            }
        }

        $this->set_consent_cache($email, $consent_key, $has, $cache_minutes);
        return $has;
    }

    /** HTTP helper */
    private function br_post($url, $token, $body, $timeout = 5) {
        $args = [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Token ' . $token,
            ],
            'body'    => wp_json_encode($body),
        ];
        $r = wp_remote_post($url, $args);
        if (is_wp_error($r)) return null;

        $code = (int) wp_remote_retrieve_response_code($r);
        $out  = json_decode(wp_remote_retrieve_body($r), true);
        // Optional: log non-2xx
        if ($code < 200 || $code >= 300) {
            error_log('[CF7→BR] Non-2xx: ' . $code . ' ' . $url . ' ' . substr(wp_remote_retrieve_body($r),0,500));
        }
        return $out;
    }
}

new CF7_Bloomreach_Async();
