<?php
/**
 * Plugin Name: Bloomreach Contact Forms (CF7 → BR, Async + Consent-Safe)
 * Description: Sends CF7 submissions to Bloomreach asynchronously. Pushes consent only if the customer doesn't already have it.
 * Version: 1.0.1
 * Author: Steve O'Rourke
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class BR_CF7_Async {
    const OPT = 'br_cf7_settings';
    const CRON_HOOK = 'br_cf7_send_job';
    const TRANSIENT_PREFIX = 'br_cf7_consent_'; // md5(email|consent_key) => '1'/'0'

    public function __construct() {
        // Admin
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', function () {
            add_options_page('Bloomreach Contact Forms', 'Bloomreach → CF7', 'manage_options', 'br-cf7', [$this, 'settings_page']);
        });

        // CF7 hook
        add_action('wpcf7_mail_sent', [$this, 'on_cf7_submit'], 10, 1);

        // Cron worker
        add_action(self::CRON_HOOK, [$this, 'process_job'], 10, 1);
    }

    /* =======================
     * SETTINGS
     * ======================= */

public function register_settings() {
register_setting(self::OPT, self::OPT, function($in) {
    $projectToken = sanitize_text_field($in['project'] ?? '');
    $out = [
        'token'   => sanitize_text_field($in['token'] ?? $projectToken),
        'project' => $projectToken,
        'api_base'=> esc_url_raw($in['api_base'] ?? 'https://api.uk.exponea.com'),
        'timeout' => max(3, absint($in['timeout'] ?? 8)),
        'consent_cache_minutes' => max(1, absint($in['consent_cache_minutes'] ?? 60)),
        'forms'   => [],
    ];

    $badPairs = [];
    $haveAnyNotice = false;

    if (is_array($in['forms'] ?? null)) {
        foreach ($in['forms'] as $row) {
            // Normalize fields
            $form_id     = absint($row['form_id'] ?? 0);
            $event_type  = sanitize_key($row['event_type'] ?? 'cf7_submit');
            $consent_key = sanitize_key($row['consent_key'] ?? '');
            $email_field = sanitize_key($row['email_field'] ?? 'your-email');
            $map_str     = '';

            if (!empty($row['map_str'])) {
                $map_str = (string)$row['map_str'];
            } elseif (!empty($row['map']) && is_array($row['map'])) {
                $pairs = [];
                foreach ($row['map'] as $k => $v) { $pairs[] = "{$k}={$v}"; }
                $map_str = implode(',', $pairs);
            }

            // Skip truly empty rows (prevents duplicate warnings)
            $isRowEmpty = ($form_id === 0) && ($event_type === 'cf7_submit') && ($consent_key === '') && ($email_field === 'your-email') && (trim($map_str) === '');
            if ($isRowEmpty) {
                continue;
            }

            // Parse map_str (comma or newline separated)
            $map = [];
            $pieces = preg_split('/[,\r\n]+/', (string)$map_str);
            foreach ($pieces as $piece) {
                $piece = trim($piece);
                if ($piece === '') continue;
                if (strpos($piece, '=') === false) {
                    $badPairs[] = $piece; // e.g., "12121"
                    continue;
                }
                list($cf7,$br) = array_map('trim', explode('=', $piece, 2));
                if ($cf7 === '' || $br === '') { $badPairs[] = $piece; continue; }
                $map[sanitize_key($cf7)] = sanitize_text_field($br);
            }

            $out['forms'][] = [
                'form_id'     => $form_id,
                'event_type'  => $event_type,
                'consent_key' => $consent_key,
                'email_field' => $email_field,
                'map'         => $map,
            ];
        }
    }

    // ONE notice max, even if multiple rows had bad tokens
    if (!empty($badPairs)) {
        static $didWarn = false;
        if (!$didWarn) {
            add_settings_error(
                self::OPT,
                'br_cf7_map_warn',
                sprintf(
                    'Saved, but ignored %d malformed mapping pair(s): %s. Use the format %s (one per line or comma-separated).',
                    count($badPairs),
                    esc_html(implode(', ', array_unique($badPairs))),
                    '<code>cf7_field=br_property</code>'
                ),
                'warning'
            );
            $didWarn = true;
        }
    }

    return $out;
});
}


public function settings_page() {
    $s = get_option(self::OPT, []);
    $rows = $s['forms'] ?? [];
    if (empty($rows)) {
        $rows = [[
            'form_id'     => '',
            'event_type'  => 'cf7_submit',
            'consent_key' => '',
            'email_field' => 'your-email',
            'map'         => [],
        ]];
    }
    ?>
    <div class="wrap">
        <h1>Bloomreach Contact Forms (CF7 → BR)</h1>

        <?php settings_errors(self::OPT); /* show our notices if any */ ?>

        <form method="post" action="options.php" id="brcf7-admin-form">
            <?php settings_fields(self::OPT); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">API Base</th>
                    <td>
                        <input type="url" name="<?php echo self::OPT; ?>[api_base]"
                               value="<?php echo esc_attr($s['api_base'] ?? 'https://api.uk.exponea.com'); ?>" size="50">
                        <p class="description">Your shard, e.g. https://api.uk.exponea.com</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Project token (UUID)</th>
                    <td>
                        <input type="text" name="<?php echo self::OPT; ?>[project]"
                               value="<?php echo esc_attr($s['project'] ?? ''); ?>" size="50" required>
                        <p class="description">The Project token from Bloomreach. Required and also used in the URL path.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Token (Authorization)</th>
                    <td>
                        <input type="text" name="<?php echo self::OPT; ?>[token]"
                               value="<?php echo esc_attr($s['token'] ?? ''); ?>" size="50">
                        <p class="description">Defaults to the same as Project token. Used as <code>Authorization: Token &lt;token&gt;</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">HTTP timeout (s)</th>
                    <td><input type="number" min="3" max="20" name="<?php echo self::OPT; ?>[timeout]" value="<?php echo esc_attr($s['timeout'] ?? 8); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Consent cache (minutes)</th>
                    <td><input type="number" min="1" max="1440" name="<?php echo self::OPT; ?>[consent_cache_minutes]" value="<?php echo esc_attr($s['consent_cache_minutes'] ?? 60); ?>"></td>
                </tr>
            </table>

            <h2>Form mappings</h2>
            <p>Use <code>cf7_field=br_property</code> pairs, separated by <em>commas or new lines</em>.</p>

            <table class="widefat striped" id="brcf7-matrix">
                <thead>
                    <tr>
                        <th style="width:110px;">CF7 Form ID</th>
                        <th style="width:160px;">Event Type</th>
                        <th style="width:220px;">Consent Key (optional)</th>
                        <th style="width:180px;">Email Field</th>
                        <th>Extra field map (cf7_field=br_property, ...)</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row):
                    $map_str = '';
                    if (!empty($row['map']) && is_array($row['map'])) {
                        $pairs = [];
                        foreach ($row['map'] as $k => $v) { $pairs[] = "{$k}={$v}"; }
                        $map_str = implode(',', $pairs);
                    }
                    ?>
                    <tr>
                        <td><input type="number" min="1" step="1" class="brcf7-id" name="<?php echo self::OPT; ?>[forms][<?php echo $i; ?>][form_id]" value="<?php echo esc_attr($row['form_id']); ?>"></td>
                        <td><input type="text" class="brcf7-event" name="<?php echo self::OPT; ?>[forms][<?php echo $i; ?>][event_type]" value="<?php echo esc_attr($row['event_type']); ?>"></td>
                        <td><input type="text" class="brcf7-consent" name="<?php echo self::OPT; ?>[forms][<?php echo $i; ?>][consent_key]" value="<?php echo esc_attr($row['consent_key']); ?>" placeholder="e.g. marketing_email"></td>
                        <td><input type="text" class="brcf7-email" name="<?php echo self::OPT; ?>[forms][<?php echo $i; ?>][email_field]" value="<?php echo esc_attr($row['email_field']); ?>" placeholder="your-email"></td>
                        <td>
                            <textarea class="brcf7-extra" name="<?php echo self::OPT; ?>[forms][<?php echo $i; ?>][map_str]" rows="2" placeholder="first-name=first_name&#10;last-name=last_name&#10;phone=phone"><?php echo esc_textarea($map_str); ?></textarea>
                        </td>
                        <td><button type="button" class="button brcf7-remove">Remove</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td colspan="6"><button type="button" class="button button-secondary" id="brcf7-add">+ Add row</button></td></tr>
                </tfoot>
            </table>

            <?php submit_button(); ?>
        </form>

        <style>
            #brcf7-matrix input.brcf7-id      { width: 90px; }
            #brcf7-matrix input.brcf7-event   { width: 150px; }
            #brcf7-matrix input.brcf7-consent { width: 210px; }
            #brcf7-matrix input.brcf7-email   { width: 170px; }
            #brcf7-matrix textarea.brcf7-extra{ width:100%; max-width:none; min-height:44px; resize:vertical; }
            #brcf7-matrix td { vertical-align: middle; }
        </style>

        <script>
        (function(){
            const tbody    = document.querySelector('#brcf7-matrix tbody');
            const addBtn   = document.getElementById('brcf7-add');
            let nextIndex  = tbody.querySelectorAll('tr').length;

            addBtn.addEventListener('click', function(){
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><input type="number" min="1" step="1" class="brcf7-id" name="<?php echo esc_js(self::OPT); ?>[forms][${nextIndex}][form_id]" value=""></td>
                    <td><input type="text" class="brcf7-event" name="<?php echo esc_js(self::OPT); ?>[forms][${nextIndex}][event_type]" value="cf7_submit"></td>
                    <td><input type="text" class="brcf7-consent" name="<?php echo esc_js(self::OPT); ?>[forms][${nextIndex}][consent_key]" value="" placeholder="e.g. marketing_email"></td>
                    <td><input type="text" class="brcf7-email" name="<?php echo esc_js(self::OPT); ?>[forms][${nextIndex}][email_field]" value="your-email"></td>
                    <td><textarea class="brcf7-extra" name="<?php echo esc_js(self::OPT); ?>[forms][${nextIndex}][map_str]" rows="2" placeholder="first-name=first_name&#10;last-name=last_name&#10;phone=phone"></textarea></td>
                    <td><button type="button" class="button brcf7-remove">Remove</button></td>
                `;
                tbody.appendChild(tr);
                nextIndex++;
            });

            tbody.addEventListener('click', function(e){
                if (e.target && e.target.classList.contains('brcf7-remove')) {
                    const rows = tbody.querySelectorAll('tr');
                    if (rows.length > 1) {
                        e.target.closest('tr').remove();
                    } else {
                        e.target.closest('tr').querySelectorAll('input,textarea').forEach(el => el.value = '');
                    }
                }
            });
        })();
        </script>
    </div>
    <?php
}
    /* =======================
     * CF7 SUBMIT HOOK
     * ======================= */
    public function on_cf7_submit($contact_form) {
        $s = get_option(self::OPT, []);
        if (empty($s['project']) || empty($s['token'])) return;

        $form_id = absint($contact_form->id());
        $map = $this->find_form_map($s, $form_id);
        if (!$map) return;

        $submission = \WPCF7_Submission::get_instance();
        if (!$submission) return;
        $posted = $submission->get_posted_data();

        $email_field = $map['email_field'] ?: 'your-email';
        $emailVal = $posted[$email_field] ?? '';
        if (is_array($emailVal)) $emailVal = reset($emailVal);
        $email = sanitize_email($emailVal);
        if (!$email) return;

        // Properties
        $props = [
            'form_id'    => $form_id,
            'form_title' => $contact_form->name(),
            'source_url' => esc_url_raw($submission->get_meta('url')),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip'         => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'site'       => home_url(),
        ];
        foreach ($map['map'] as $cf7_name => $br_key) {
            if (isset($posted[$cf7_name])) {
                $val = $posted[$cf7_name];
                if (is_array($val)) $val = implode(', ', array_map('sanitize_text_field', $val));
                $props[$br_key] = sanitize_text_field($val);
            }
        }

        $job = [
            'email'       => $email,
            'event_type'  => $map['event_type'] ?: 'cf7_submit',
            'properties'  => $props,
            'consent_key' => $map['consent_key'] ?: '',
            'ts'          => time(),
            'request_id'  => wp_generate_uuid4(),
        ];

        // Enqueue (30s)
        wp_schedule_single_event(time() + 30, self::CRON_HOOK, [$job]);
    }

    private function find_form_map($s, $form_id) {
        foreach ($s['forms'] as $row) {
            if (absint($row['form_id'] ?? 0) === $form_id) return $row;
        }
        return null;
    }

    /* =======================
     * CRON WORKER
     * ======================= */
    public function process_job($job) {
        $s = get_option(self::OPT, []);
        $token    = trim($s['token'] ?? '');
        $project  = trim($s['project'] ?? '');
        $api_base = rtrim($s['api_base'] ?? 'https://api.uk.exponea.com', '/');
        $timeout  = max(3, absint($s['timeout'] ?? 8));

        if (!$token || !$project) return;

        // Build endpoints (project token MUST be in the path)
        $eventsUrl        = "{$api_base}/track/v2/projects/{$project}/events";
        $customersGetUrl  = "{$api_base}/data/v2/projects/{$project}/customers";
        $customerAttrsUrl = "{$api_base}/track/v2/projects/{$project}/customers/attributes"; // reserved if you switch to attribute writes

        $email = $job['email'];

        // 1) Send main event
        $payload = [
            'customer_ids' => ['email' => $email],
            'event_type'   => $job['event_type'],
            'properties'   => $job['properties'],
            'timestamp'    => (int)$job['ts'],
        ];
        $this->br_post($eventsUrl, $token, $payload, $timeout);

        // 2) Conditionally push consent
        $consent_key = $job['consent_key'] ?? '';
        if ($consent_key) {
            $has = $this->has_consent_cached_or_remote(
                $customersGetUrl, $token, $email, $consent_key,
                (int)($s['consent_cache_minutes'] ?? 60),
                $timeout
            );
            if (!$has) {
                $consent_payload = [
                    'customer_ids' => ['email' => $email],
                    'event_type'   => 'consent_granted',
                    'properties'   => [
                        'consent_key' => $consent_key,
                        'method'      => 'cf7_form',
                        'source'      => 'website',
                    ],
                    'timestamp'    => time(),
                ];
                $this->br_post($eventsUrl, $token, $consent_payload, $timeout);
                $this->set_consent_cache($email, $consent_key, true, (int)($s['consent_cache_minutes'] ?? 60));
            }
        }
    }

    /* =======================
     * CONSENT CACHE + LOOKUP
     * ======================= */
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

    private function has_consent_cached_or_remote($customersGetUrl, $token, $email, $consent_key, $cache_minutes, $timeout) {
        $cached = $this->get_consent_cache($email, $consent_key);
        if ($cached !== null) return (bool)$cached;

        // Read customer incl. consents (Data v2)
        $res = $this->br_post($customersGetUrl, $token, [
            'customer_ids' => ['email' => $email],
            'options'      => ['include' => ['consents']],
        ], $timeout);

        $has = false;
        if (is_array($res)) {
            // Expected shape: { success: true, data: { consents: { key: {status: "..."} } } }
            $consents = $res['data']['consents'] ?? null;
            if (is_array($consents) && isset($consents[$consent_key])) {
                $status = strtolower($consents[$consent_key]['status'] ?? '');
                $has = in_array($status, ['opt_in', 'granted', 'true', '1'], true);
            }
        }

        $this->set_consent_cache($email, $consent_key, $has, $cache_minutes);
        return $has;
    }

    /* =======================
     * HTTP helper
     * ======================= */
    private function br_post($url, $token, $body, $timeout = 8) {
        $args = [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Token ' . $token,
            ],
            'body'    => wp_json_encode($body),
        ];
        $r = wp_remote_post($url, $args);
        if (is_wp_error($r)) {
            error_log('[CF7→BR] WP_Error: ' . $r->get_error_message());
            return null;
        }
        $code = (int)wp_remote_retrieve_response_code($r);
        $body = wp_remote_retrieve_body($r);
        $out  = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            error_log('[CF7→BR] Non-2xx: ' . $code . ' ' . $url . ' ' . substr($body, 0, 500));
        }
        return $out;
    }
}

new BR_CF7_Async();

/* =======================
 * (Optional) PUC bootstrap
 * ======================= */
$__puc_loader = __DIR__ . '/inc/plugin-update-checker/plugin-update-checker.php';
if ( file_exists($__puc_loader) ) {
    require_once $__puc_loader;
    foreach ([
        '\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory',
        '\YahnisElsts\PluginUpdateChecker\v5p6\PucFactory',
        '\YahnisElsts\PluginUpdateChecker\v5\PucFactory',
        'Puc_v5_Factory',
    ] as $candidate) {
        if ( class_exists($candidate) ) {
            $candidate::buildUpdateChecker(
                'https://github.com/infoitteam/bloomreach-contact-forms',
                __FILE__,
                'bloomreach-contact-forms'
            )->setBranch('main');
            break;
        }
    }
}
