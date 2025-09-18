<?php
/**
 * Plugin Name: Bloomreach Contact Forms (CF7 → BR, Async + Consent-Safe)
 * Description: Sends CF7 submissions to Bloomreach. Pushes consent only if the customer doesn't already have it.
 * Version: 1.0.4
 * Author: Steve O'Rourke
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class BR_CF7_Async {
    const OPT = 'br_cf7_settings';
    const CRON_HOOK = 'br_cf7_send_job';
    const TRANSIENT_PREFIX = 'br_cf7_consent_'; // md5(email|consent_key) => '1'/'0'

private function sanitize_phone($raw) {
    // Keep leading +, strip other non-digits
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $raw = preg_replace('/[^\d+]+/', '', $raw);
    // If multiple +, keep only the first
    if (substr_count($raw, '+') > 1) {
        $raw = '+' . preg_replace('/\+/', '', $raw, 1);
    }
    return $raw;
}

/**
 * Try to find a phone value from the submission.
 * Priority:
 *   1) A CF7 field that you mapped to a BR key likely named "phone", "phone_number", "telephone", "mobile"
 *   2) Common CF7 field names ("phone", "tel", "telephone", "mobile", "movil", "móvil", "telefono", "teléfono", "phone-number", "contact-phone")
 */
private function extract_phone_from_post(array $posted, array $map_row) {
    // 1) Check mapping pairs first
    $candidate_keys = ['phone','phone_number','telephone','mobile','mobile_phone','tel'];
    if (!empty($map_row['map']) && is_array($map_row['map'])) {
        foreach ($map_row['map'] as $cf7_name => $br_key) {
            if (in_array(strtolower($br_key), $candidate_keys, true) && isset($posted[$cf7_name])) {
                $v = $posted[$cf7_name];
                if (is_array($v)) $v = reset($v);
                $v = $this->sanitize_phone($v);
                if ($v !== '') return $v;
            }
        }
    }
    // 2) Heuristic over common CF7 field names
    $posted_lower = array_change_key_case($posted, CASE_LOWER);
    $common_cf7 = ['phone','tel','telephone','mobile','movil','móvil','telefono','teléfono','phone-number','contact-phone'];
    foreach ($common_cf7 as $k) {
        if (isset($posted_lower[$k])) {
            $v = $posted_lower[$k];
            if (is_array($v)) $v = reset($v);
            $v = $this->sanitize_phone($v);
            if ($v !== '') return $v;
        }
    }
    return '';
}


    private function build_auth_header($v){
    // Allow passing full header too (e.g., "Basic ...", "Token ...")
    if (stripos($v,'Basic ')===0 || stripos($v,'Token ')===0) return $v;
    // Private key entered as "KEYID:SECRET" → use Basic
    if (strpos($v,':')!==false) return 'Basic '.base64_encode($v);
        // Otherwise treat as a Public API token
        return 'Token '.$v;
    }

    private function auth_mode($v){
        if (stripos($v,'Basic ')===0 || strpos($v,':')!==false) return 'basic';
        return 'token';
    }


        private function log($msg, array $ctx = []) {
        // Only log if WP_DEBUG_LOG is enabled
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return;
        }
        $prefix = '[BLOOM CF7] ';
        // Redact obvious secrets/PII
        $safe = $this->redact($ctx);
        // Keep logs compact
        $line = $prefix . $msg;
        if (!empty($safe)) {
            $line .= ' | ' . wp_json_encode($safe, JSON_UNESCAPED_SLASHES);
        }
        error_log($line);
    }

    private function redact(array $ctx): array {
        $out = [];
        foreach ($ctx as $k => $v) {
            if (is_string($v)) {
                // redact token-like values
                if (stripos($k, 'token') !== false || stripos($k, 'auth') !== false) {
                    $out[$k] = $this->mask_middle($v);
                    continue;
                }
                // mask emails
                if (filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $out[$k] = $this->mask_email($v);
                    continue;
                }
                // trim very long strings
                $out[$k] = (strlen($v) > 400) ? substr($v, 0, 400) . '…' : $v;
            } elseif (is_array($v)) {
                // shallow sanitize arrays
                $out[$k] = $this->redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function mask_middle($s) {
        $len = strlen($s);
        if ($len <= 8) return str_repeat('*', $len);
        return substr($s, 0, 4) . str_repeat('*', $len - 8) . substr($s, -4);
    }

    private function mask_email($email) {
        $parts = explode('@', strtolower($email));
        if (count($parts) !== 2) return $this->mask_middle($email);
        $local = $parts[0];
        $domain = $parts[1];
        if (strlen($local) <= 2) {
            $local = str_repeat('*', strlen($local));
        } else {
            $local = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1);
        }
        return $local . '@' . $domain;
    }


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
            $event_type  = sanitize_key($row['event_type'] ?? 'contact_forms');
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
            'event_type'  => 'contact_forms',
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
                        <p class="description">
                                Public: paste API token (sends <code>Authorization: Token …</code>).
                                Private: paste <code>KEYID:SECRET</code> (sends <code>Authorization: Basic …</code>).
                        </p>
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
                    <td><input type="text" class="brcf7-event" name="<?php echo esc_js(self::OPT); ?>[forms][${nextIndex}][event_type]" value="contact_forms"></td>
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
    $this->log('CF7 hook fired', [
        'form_id' => method_exists($contact_form, 'id') ? $contact_form->id() : null,
        'form_name' => method_exists($contact_form, 'name') ? $contact_form->name() : null,
    ]);

    $s = get_option(self::OPT, []);
    if (empty($s['project']) || empty($s['token'])) {
        $this->log('Missing BR credentials; skipping', [
            'have_project' => !empty($s['project']),
            'have_token'   => !empty($s['token']),
        ]);
        return;
    }

    $form_id = absint($contact_form->id());
    $map = $this->find_form_map($s, $form_id);
    if (!$map) {
        $this->log('No mapping found for form; skipping', ['form_id' => $form_id]);
        return;
    }
    $this->log('Mapping row selected', [
        'form_id'     => $form_id,
        'event_type'  => $map['event_type'] ?? null,
        'consent_key' => $map['consent_key'] ?? null,
        'email_field' => $map['email_field'] ?? null,
        'map_keys'    => array_keys($map['map'] ?? []),
    ]);

    $submission = \WPCF7_Submission::get_instance();
    if (!$submission) {
        $this->log('No CF7 submission instance (likely Ajax failure) — skip');
        return;
    }
    $posted = $submission->get_posted_data();
    $this->log('Posted keys snapshot', ['keys' => array_keys((array)$posted)]);

    $email_field = $map['email_field'] ?: 'your-email';
    $emailVal = $posted[$email_field] ?? '';
    if (is_array($emailVal)) $emailVal = reset($emailVal);
    $email = sanitize_email($emailVal);
    if (!$email) {
        $this->log('No valid email found; skipping', ['email_field' => $email_field, 'raw' => (string)$emailVal]);
        return;
    }

    // Event properties (baseline + mapped)
    $props = [
        'form_id'    => $form_id,
        'form_title' => method_exists($contact_form,'title') ? $contact_form->title() : $contact_form->name(),
        'form_slug'  => $contact_form->name(), // (optional) keep the slug too
        'source_url' => esc_url_raw($submission->get_meta('url')),
        'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'ip'         => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        'site'       => home_url(),
    ];
    // Build mapped props once so we can reuse them for profile update
    $customerProps = [];
    foreach ($map['map'] as $cf7_name => $br_key) {
        if (isset($posted[$cf7_name])) {
            $val = $posted[$cf7_name];
            if (is_array($val)) $val = implode(', ', array_map('sanitize_text_field', $val));
            $val = sanitize_text_field($val);
            $props[$br_key] = $val;          // goes into event
            $customerProps[$br_key] = $val;  // goes into profile update
        }
    }

    // Identify customer: email + optional phone
    $phone = $this->extract_phone_from_post((array)$posted, $map);
    $ids = ['email' => $email];
    if ($phone !== '') $ids['phone'] = $phone;

    $job = [
        'ids'         => $ids,                // <-- used for profile update
        'email'       => $email,              // still used for main event
        'event_type'  => $map['event_type'] ?: 'contact_forms',
        'properties'  => $props,
        'customer_props' => $customerProps,   // <-- properties to write to profile
        'consent_key' => $map['consent_key'] ?: '',
        'ts'          => time(),
        'request_id'  => wp_generate_uuid4(),
    ];

    $next = time() + 30;
    $ok = wp_schedule_single_event($next, self::CRON_HOOK, [$job]);
    $this->log('Enqueued job', [
        'ok'         => $ok,
        'run_at'     => gmdate('c', $next),
        'request_id' => $job['request_id'],
        'email'      => $email,
        'event_type' => $job['event_type'],
        'prop_keys'  => array_keys($props),
        'id_keys'    => array_keys($ids),
    ]);
    if (!$ok) {
        $this->log('WARNING: wp_schedule_single_event failed — check WP Cron status.');
    }
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
    $customersUrl = "{$api_base}/track/v2/projects/{$project}/customers";           // Update profile


    $this->log('Cron worker start', [
        'request_id' => $job['request_id'] ?? null,
        'email'      => $job['email'] ?? null,
        'event_type' => $job['event_type'] ?? null,
        'api_base'   => $api_base,
        'timeout'    => $timeout,
    ]);

// 1b) Update customer profile properties (from mapped fields)
$ids = $job['ids'] ?? ['email' => $email];
$customerProps = $job['customer_props'] ?? [];
if (!empty($customerProps) && is_array($customerProps)) {
    // Strip out any obviously non-attribute keys just in case
    foreach (['form_id','form_title','source_url','user_agent','ip','site'] as $k) {
        unset($customerProps[$k]);
    }
    $this->log('POST customers (profile update)', [
        'url'       => $customersUrl,
        'id_keys'   => array_keys($ids),
        'prop_keys' => array_keys($customerProps),
    ]);
    $rp = $this->br_post($customersUrl, $token, [
        'customer_ids' => $ids,
        'properties'   => $customerProps,
    ], $timeout);
    $this->log('POST customers response', [
        'http_code' => $rp['code'] ?? null,
        'success'   => $rp['json']['success'] ?? null,
        'errors'    => $rp['json']['errors'] ?? null,
    ]);
}


    if (!$token || !$project) {
        $this->log('Missing token/project in cron; abort', [
            'have_project' => (bool)$project,
            'have_token'   => (bool)$token,
        ]);
        return;
    }

$eventsUrl        = "{$api_base}/track/v2/projects/{$project}/customers/events";      // Add event
$customersAttrUrl = "{$api_base}/data/v2/projects/{$project}/customers/attributes";   // Read attributes (consent)
$customersUrl     = "{$api_base}/track/v2/projects/{$project}/customers";             // Update profile

    $email = $job['email'];

    // 1) Send main event
    $payload = [
        'customer_ids' => ['email' => $email],
        'event_type'   => $job['event_type'],
        'properties'   => $job['properties'],
        'timestamp'    => (int)($job['ts'] ?? time()),
    ];
    $this->log('POST events (main)', [
        'url'         => $eventsUrl,
        'request_id'  => $job['request_id'] ?? null,
        'event_type'  => $payload['event_type'],
        'prop_keys'   => array_keys($payload['properties'] ?? []),
    ]);
    $r1 = $this->br_post($eventsUrl, $token, $payload, $timeout);
    $this->log('POST events (main) response', [
        'http_code' => $r1['code'] ?? null,
        'success'   => $r1['json']['success'] ?? null,
        'errors'    => $r1['json']['errors'] ?? null,
    ]);

    // 2) Conditionally push consent
    $consent_key = $job['consent_key'] ?? '';
    if ($consent_key) {
        $has = $this->has_consent_cached_or_remote(
            $customersAttrUrl, $token, $email, $consent_key,
            (int)($s['consent_cache_minutes'] ?? 60),
            $timeout
        );
        $this->log('Consent decision', [
            'consent_key' => $consent_key,
            'already_has' => $has,
        ]);

        if (!$has) {
            // Standard consent event shape
            $consent_payload = [
                'customer_ids' => ['email' => $email],
                'event_type'   => 'consent',
                'timestamp'    => time(),
                'properties'   => [
                    'action'      => 'accept',
                    'category'    => $consent_key,
                    'valid_until' => 'unlimited',
                    'source'      => 'public_api',
                    'message'     => 'CF7 form submission',
                ],
            ];
            $this->log('POST events (consent)', [
                'url'        => $eventsUrl,
                'consent_key'=> $consent_key,
            ]);
            $r2 = $this->br_post($eventsUrl, $token, $consent_payload, $timeout);
            $this->log('POST events (consent) response', [
                'http_code' => $r2['code'] ?? null,
                'success'   => $r2['json']['success'] ?? null,
                'errors'    => $r2['json']['errors'] ?? null,
            ]);

            // Only cache consent if write succeeded
            if (($r2['code'] ?? 0) >= 200 && ($r2['code'] ?? 0) < 300 && (!isset($r2['json']) || !isset($r2['json']['success']) || $r2['json']['success'] === true)) {
                $this->set_consent_cache($email, $consent_key, true, (int)($s['consent_cache_minutes'] ?? 60));
            } else {
                $this->log('Consent not cached due to non-success');
            }
        }
    }

    $this->log('Cron worker done', ['request_id' => $job['request_id'] ?? null]);
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

    private function has_consent_cached_or_remote($customersAttrUrl, $token, $email, $consent_key, $cache_minutes, $timeout) {
    $cached = $this->get_consent_cache($email, $consent_key);
    if ($cached !== null) {
        $this->log('Consent cache hit', [
            'consent_key' => $consent_key,
            'cached'      => (bool)$cached,
            'email'       => $email,
        ]);
        return (bool)$cached;
    }

    $this->log('Consent cache miss → remote check', [
        'url'         => $customersAttrUrl,
        'consent_key' => $consent_key,
        'email'       => $email,
    ]);

    $res = $this->br_post($customersAttrUrl, $token, [
        'customer_ids' => ['email' => $email],
        'attributes'   => [[
            'type'     => 'consent',
            'category' => $consent_key,
            'mode'     => 'valid'
        ]],
    ], $timeout);

    $has = false;
    if (isset($res['json']['results'][0]['success']) && $res['json']['results'][0]['success'] === true) {
        $has = (bool)($res['json']['results'][0]['value'] ?? false);
    }

    $this->log('Consent remote result', [
        'consent_key' => $consent_key,
        'has'         => $has,
        'http_code'   => $res['code'] ?? null,
    ]);

    $this->set_consent_cache($email, $consent_key, $has, $cache_minutes);
    return $has;
}



    /* =======================
     * HTTP helper
     * ======================= */

     private function br_post($url, $token, $body, $timeout = 8) {
    $authHeader = $this->build_auth_header($token);

    $args = [
        'timeout' => $timeout,
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => $authHeader,
        ],
        'body'    => wp_json_encode($body),
    ];

    $this->log('HTTP POST → BR', [
        'url'       => $url,
        'timeout'   => $timeout,
        'body_keys' => is_array($body) ? array_keys($body) : null,
        'auth_mode' => $this->auth_mode($token),
    ]);

    $r = wp_remote_post($url, $args);

    if (is_wp_error($r)) {
        $this->log('HTTP error (WP_Error)', [
            'message' => $r->get_error_message(),
            'url'     => $url,
        ]);
        return ['code' => null, 'body' => null, 'json' => null, 'error' => $r->get_error_message()];
    }

    $code = (int)wp_remote_retrieve_response_code($r);
    $raw  = wp_remote_retrieve_body($r);
    $json = json_decode($raw, true);

    if ($code < 200 || $code >= 300) {
        $this->log('HTTP non-2xx', [
            'code' => $code,
            'url'  => $url,
            'body' => (strlen($raw) > 600) ? substr($raw, 0, 600) . '…' : $raw,
        ]);
    } else {
        $this->log('HTTP 2xx', [
            'code'    => $code,
            'url'     => $url,
            'success' => is_array($json) ? ($json['success'] ?? null) : null,
        ]);
    }

     return ['code' => $code, 'body' => $raw, 'json' => $json, 'error' => null];
} // end br_post()

} // ← CLOSE THE CLASS BR_CF7_Async

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
