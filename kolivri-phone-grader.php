<?php
/**
 * Plugin Name: Kolivri Phone Grader ("Critic Girlfriend" Self-Audit)
 * Plugin URI: https://kolivri.com/he/
 * Description: Interactive 5-dimension SMB Phone Grader, "Critic Girlfriend" Hebrew/English roast card, lost ILS revenue calculator, and SMS OTP-gated live AI mystery-shopper self-audit.
 * Version: 1.0.0
 * Author: Kolivri
 * Author URI: https://kolivri.com/he/
 * License: GPL v2 or later
 * Text Domain: kolivri-phone-grader
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOLIVRI_PHONE_GRADER_VERSION')) {
    define('KOLIVRI_PHONE_GRADER_VERSION', '1.0.0');
}

if (!defined('KOLIVRI_PHONE_GRADER_PATH')) {
    $kpg_dir = function_exists('plugin_dir_path')
        ? plugin_dir_path(__FILE__)
        : rtrim(dirname(__FILE__), '/\\') . '/';
    define('KOLIVRI_PHONE_GRADER_PATH', $kpg_dir);
}

require_once KOLIVRI_PHONE_GRADER_PATH . 'includes/class-kolivri-grader-engine.php';

class Kolivri_Phone_Grader {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (function_exists('add_shortcode')) {
            add_shortcode('kolivri_phone_grader', array($this, 'render_shortcode'));
        }
        if (function_exists('add_action')) {
            add_action('rest_api_init', array($this, 'register_rest_routes'));
        }
    }

    /**
     * Register REST endpoints for AJAX audit scoring and SMS OTP ownership verification.
     */
    public function register_rest_routes() {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('kolivri-phone-grader/v1', '/audit', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_rest_audit'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('kolivri/v1', '/phone-grader/score', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_rest_audit'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('kolivri-phone-grader/v1', '/otp/request', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_rest_otp_request'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('kolivri/v1', '/phone-grader/otp/request', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_rest_otp_request'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('kolivri-phone-grader/v1', '/otp/verify', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_rest_otp_verify'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('kolivri/v1', '/phone-grader/otp/verify', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_rest_otp_verify'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * REST handler: calculate score + Critic roast + lost ILS revenue.
     *
     * @param mixed $request WP_REST_Request or array
     * @return array|WP_REST_Response
     */
    public function handle_rest_audit($request) {
        $params = is_object($request) && method_exists($request, 'get_json_params')
            ? (array) $request->get_json_params()
            : (array) $request;
        $result = Kolivri_Grader_Engine::generateCriticRoast($params, $params);
        return function_exists('rest_ensure_response') ? rest_ensure_response($result) : $result;
    }

    /**
     * REST handler: send 6-digit SMS OTP for phone ownership verification.
     *
     * @param mixed $request WP_REST_Request or array
     * @return array|WP_REST_Response
     */
    public function handle_rest_otp_request($request) {
        $params = is_object($request) && method_exists($request, 'get_json_params')
            ? (array) $request->get_json_params()
            : (array) $request;
        $phone = isset($params['phone']) ? (string) $params['phone'] : '';
        $otp = Kolivri_Grader_Engine::createSelfAuditOtp($phone, 300);
        // Never leak the raw OTP code in public HTTP responses.
        unset($otp['code']);
        return function_exists('rest_ensure_response') ? rest_ensure_response($otp) : $otp;
    }

    /**
     * REST handler: verify 6-digit SMS OTP and authorize live AI self-audit call.
     *
     * @param mixed $request WP_REST_Request or array
     * @return array|WP_REST_Response
     */
    public function handle_rest_otp_verify($request) {
        $params = is_object($request) && method_exists($request, 'get_json_params')
            ? (array) $request->get_json_params()
            : (array) $request;
        $phone = isset($params['phone']) ? (string) $params['phone'] : '';
        $code  = isset($params['code']) ? (string) $params['code'] : '';
        $verification = Kolivri_Grader_Engine::verifySelfAuditOtp($phone, $code);
        return function_exists('rest_ensure_response') ? rest_ensure_response($verification) : $verification;
    }

    /**
     * HTML-escape helper that works both with and without WordPress loaded.
     *
     * @param string $text
     * @return string
     */
    private static function esc($text) {
        if (function_exists('esc_html')) {
            return esc_html((string) $text);
        }
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    private static function render_lab_switcher($lang = 'he') {
        $is_en = (strtolower((string) $lang) === 'en');
        $prefix = $is_en ? '' : '/he';
        $home_href = $prefix . '/#ai-voice-lab';
        $home_label = $is_en ? '← Home' : '← חזרה לדף הבית';

        $tools = array(
            'phone-grader'       => array('path' => '/phone-grader/',       'he' => '🔥 מדד המענה',          'en' => '🔥 Phone Grader'),
            'amnesia-test'       => array('path' => '/amnesia-test/',       'he' => '🧠 מבחן האמנזיה',       'en' => '🧠 Amnesia Test'),
            'invoice-recovery'   => array('path' => '/invoice-recovery/',   'he' => '💰 גביית חשבוניות',     'en' => '💰 Invoice Recovery'),
            'ghost-site'         => array('path' => '/ghost-site/',         'he' => '🌐 מיני-סייט חינם',     'en' => '🌐 Ghost Mini-Site'),
            'call-for-me'        => array('path' => '/call-for-me/',        'he' => '🤖 הבוט שיתקשר במקומך', 'en' => '🤖 Call For Me'),
            'dispatch'           => array('path' => '/dispatch/',           'he' => '⚡ זירת חיוג מקבילי',   'en' => '⚡ FOMO Dispatch'),
            'woo-voice-recovery' => array('path' => '/woo-voice-recovery/', 'he' => '🛒 WooCommerce קולי',   'en' => '🛒 Woo Voice ROI'),
            'mcp'                => array('path' => '/mcp/',                'he' => '🛠️ MCP למפתחים',        'en' => '🛠️ Developer MCP'),
        );

        $html = '<nav class="klv-lab-switcher" aria-label="Kolivri AI Voice Lab Navigation">';
        $html .= '<a href="' . self::esc($home_href) . '" class="klv-lab-home">' . self::esc($home_label) . '</a>';
        foreach ($tools as $slug => $meta) {
            $href = $prefix . $meta['path'];
            $label = $is_en ? $meta['en'] : $meta['he'];
            $current = ($slug === 'phone-grader') ? ' aria-current="page"' : '';
            $html .= '<a href="' . self::esc($href) . '"' . $current . '>' . self::esc($label) . '</a>';
        }
        $html .= '</nav>';
        return $html;
    }

    /**
     * Render the `[kolivri_phone_grader]` RTL Hebrew interactive grader & Shareable Roast Card.
     *
     * @param array $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    public function render_shortcode($atts = array()) {
        $raw_atts = is_array($atts) ? $atts : array();
        $is_en = (isset($raw_atts['lang']) && strtolower((string) $raw_atts['lang']) === 'en')
            || (empty($raw_atts['lang']) && defined('ICL_LANGUAGE_CODE') && strtolower((string) ICL_LANGUAGE_CODE) === 'en')
            || (empty($raw_atts['lang']) && !empty($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/he/') === false && strpos((string) $_SERVER['REQUEST_URI'], '/he') !== 0);

        $defaults = array(
            'lang'            => $is_en ? 'en' : 'he',
            'pickup_rings'    => '4_5_rings',
            'after_hours'     => 'nothing',
            'in_call_booking' => 'call_back_later',
            'lead_capture'    => 'manual_note',
            'recovery_sec'    => 'under_1h',
            'missed_weekly'   => 8,
            'avg_job_ils'     => 1200,
            'close_rate'      => 35,
        );
        if (function_exists('shortcode_atts')) {
            $atts = shortcode_atts($defaults, (array) $atts, 'kolivri_phone_grader');
        } else {
            $atts = array_merge($defaults, (array) $atts);
        }

        $initialAudit = Kolivri_Grader_Engine::generateCriticRoast(array(
            'pickupRings'               => $atts['pickup_rings'],
            'afterHoursCoverage'        => $atts['after_hours'],
            'inCallBooking'             => $atts['in_call_booking'],
            'leadCaptureCrm'            => $atts['lead_capture'],
            'missedCallRecoverySeconds' => $atts['recovery_sec'],
            'missedCallsPerWeek'        => (float) $atts['missed_weekly'],
            'avgJobValueILS'            => (float) $atts['avg_job_ils'],
            'closeRate'                 => (float) $atts['close_rate'],
        ));

        $score      = (int) $initialAudit['score']['totalScore'];
        $grade      = self::esc($initialAudit['score']['grade']);
        $statusHe   = self::esc($initialAudit['score']['statusLabelHe']);
        $headlineHe = self::esc($initialAudit['headlineHe']);
        $roastHe    = self::esc($initialAudit['roastHe']);
        $roastEn    = self::esc($initialAudit['roastEn']);
        $liftHe     = self::esc($initialAudit['kolivriLiftSummaryHe']);
        $monthlyHe  = self::esc($initialAudit['revenue']['formattedMonthlyHe']);
        $annualHe   = self::esc($initialAudit['revenue']['formattedAnnualHe']);

        ob_start();
        ?>
<div class="kpg-wrap" dir="<?php echo $is_en ? 'ltr' : 'rtl'; ?>" lang="<?php echo $is_en ? 'en' : 'he'; ?>" data-plugin="kolivri-phone-grader" data-version="<?php echo self::esc(KOLIVRI_PHONE_GRADER_VERSION); ?>">
    <style>
        .kpg-wrap {
            font-family: 'Rubik', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(145deg, #0B1220 0%, #111C33 100%);
            color: #F8FAFC;
            border-radius: 20px;
            padding: 32px;
            max-width: 980px;
            margin: 24px auto;
            box-shadow: 0 24px 60px rgba(11, 18, 32, 0.45);
            border: 1px solid rgba(56, 189, 248, 0.22);
            box-sizing: border-box;
        }
        .kpg-wrap * { box-sizing: border-box; }
        .kpg-badge {
            display: inline-block;
            background: rgba(244, 63, 94, 0.16);
            color: #FDA4AF;
            border: 1px solid rgba(244, 63, 94, 0.4);
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .kpg-title {
            font-size: 28px;
            font-weight: 800;
            margin: 0 0 8px;
            color: #FFFFFF;
            line-height: 1.25;
        }
        .kpg-subtitle {
            color: #94A3B8;
            font-size: 16px;
            margin: 0 0 28px;
            line-height: 1.5;
        }
        .kpg-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 24px;
        }
        @media (max-width: 820px) {
            .kpg-grid { grid-template-columns: 1fr; }
            .kpg-wrap { padding: 20px; }
        }
        @media (max-width: 540px) {
            .kpg-rev-row { grid-template-columns: 1fr; }
            .kpg-otp-row { flex-direction: column; }
            .kpg-score-header { flex-direction: column; align-items: flex-start; }
            .kpg-title { font-size: 22px; }
        }
        .kpg-panel {
            background: rgba(15, 23, 42, 0.78);
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 16px;
            padding: 22px;
        }
        .kpg-field { margin-bottom: 16px; }
        .kpg-field label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #E2E8F0;
            margin-bottom: 6px;
        }
        .kpg-field select, .kpg-field input {
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            background: #0B1220;
            color: #F8FAFC;
            font-size: 14px;
            font-family: inherit;
        }
        .kpg-rev-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 12px;
        }
        .kpg-roast-card {
            background: linear-gradient(160deg, #1E1B4B 0%, #0F172A 100%);
            border: 2px solid #F43F5E;
            border-radius: 16px;
            padding: 22px;
            position: relative;
        }
        .kpg-score-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .kpg-score-circle {
            font-size: 36px;
            font-weight: 900;
            color: #FB7185;
            line-height: 1;
        }
        .kpg-lift-pill {
            background: rgba(16, 185, 129, 0.18);
            border: 1px solid #10B981;
            color: #6EE7B7;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }
        .kpg-loss-box {
            background: rgba(244, 63, 94, 0.12);
            border-right: 4px solid #F43F5E;
            padding: 12px 14px;
            border-radius: 8px;
            margin: 14px 0;
        }
        .kpg-wrap[dir="ltr"] .kpg-loss-box {
            border-right: none;
            border-left: 4px solid #F43F5E;
        }
        .kpg-loss-amount {
            font-size: 24px;
            font-weight: 800;
            color: #FDA4AF;
        }
        .kpg-roast-text {
            font-size: 15px;
            line-height: 1.6;
            color: #F1F5F9;
            margin-bottom: 14px;
        }
        .kpg-lift-box {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.35);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13.5px;
            color: #D1FAE5;
            margin-bottom: 18px;
            line-height: 1.5;
        }
        .kpg-cta-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        .kpg-btn-primary {
            flex: 1 1 auto;
            min-height: 44px;
            text-align: center;
            background: linear-gradient(90deg, #10B981 0%, #059669 100%);
            color: #FFFFFF !important;
            text-decoration: none !important;
            font-weight: 800;
            font-size: 15px;
            padding: 12px 18px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: inline-block;
        }
        .kpg-btn-secondary {
            flex: 1 1 auto;
            min-height: 44px;
            text-align: center;
            background: rgba(56, 189, 248, 0.14);
            color: #38BDF8 !important;
            border: 1px solid rgba(56, 189, 248, 0.4);
            text-decoration: none !important;
            font-weight: 700;
            font-size: 14px;
            padding: 11px 16px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-block;
        }
        .kpg-otp-box {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px dashed rgba(148, 163, 184, 0.25);
        }
        .kpg-otp-row {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        .klv-lab-switcher {
            position: sticky;
            top: 8px;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 8px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding: 8px 10px;
            margin: 0 0 20px;
            background: rgba(11, 18, 32, 0.92);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(56, 189, 248, 0.28);
            border-radius: 14px;
            white-space: nowrap;
        }
        .klv-lab-switcher a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            color: #CBD5E1 !important;
            background: rgba(30, 41, 59, 0.75);
            border: 1px solid rgba(148, 163, 184, 0.22);
            text-decoration: none !important;
            flex-shrink: 0;
            transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        }
        .klv-lab-switcher a:hover {
            color: #FFFFFF !important;
            border-color: rgba(56, 189, 248, 0.5);
        }
        .klv-lab-switcher a.klv-lab-home {
            background: rgba(56, 189, 248, 0.14);
            color: #38BDF8 !important;
            border-color: rgba(56, 189, 248, 0.4);
        }
        .klv-lab-switcher a[aria-current="page"] {
            background: linear-gradient(90deg, #FB8123 0%, #EA580C 100%);
            color: #FFFFFF !important;
            border-color: #FB8123;
        }
    </style>

    <?php echo self::render_lab_switcher($is_en ? 'en' : 'he'); ?>
    <?php if ($is_en) : ?>
    <span class="kpg-badge">🔥 Business Phone Response Benchmark &amp; Critic Phone Grader</span>
    <h2 class="kpg-title">Grade Your Business Phone Line (0–100) — How Much Revenue Are Missed Calls Costing You?</h2>
    <p class="kpg-subtitle">
        Audit your business across 5 objective phone-handling dimensions and get a sharp diagnosis from the Kolivri Critic — including exact monthly revenue lost and how a 30-second switch to <code>*61*</code> lifts your score to <strong>100/100</strong>.
    </p>

    <div class="kpg-grid">
        <div class="kpg-panel">
            <div class="kpg-field">
                <label for="kpg-pickup-rings">1. How many rings before your business answers? (0–25 pts)</label>
                <select id="kpg-pickup-rings" name="pickupRings">
                    <option value="1_ring" data-pts="25"<?php echo $atts['pickup_rings'] === '1_ring' ? ' selected' : ''; ?>>First ring (1) — 25 pts</option>
                    <option value="2_3_rings" data-pts="18"<?php echo $atts['pickup_rings'] === '2_3_rings' ? ' selected' : ''; ?>>2–3 rings — 18 pts</option>
                    <option value="4_5_rings" data-pts="8"<?php echo $atts['pickup_rings'] === '4_5_rings' ? ' selected' : ''; ?>>4–5 rings (when busy on a job) — 8 pts</option>
                    <option value="voicemail" data-pts="0"<?php echo $atts['pickup_rings'] === 'voicemail' ? ' selected' : ''; ?>>6+ rings / Voicemail / Unanswered — 0 pts</option>
                </select>
            </div>

            <div class="kpg-field">
                <label for="kpg-after-hours">2. After-hours &amp; weekend coverage (0–20 pts)</label>
                <select id="kpg-after-hours" name="afterHoursCoverage">
                    <option value="24_7_ai_human" data-pts="20"<?php echo $atts['after_hours'] === '24_7_ai_human' ? ' selected' : ''; ?>>24/7 Live Voice (AI / Answering Service) — 20 pts</option>
                    <option value="whatsapp_auto" data-pts="10"<?php echo $atts['after_hours'] === 'whatsapp_auto' ? ' selected' : ''; ?>>Automated WhatsApp / SMS reply only — 10 pts</option>
                    <option value="nothing" data-pts="0"<?php echo $atts['after_hours'] === 'nothing' ? ' selected' : ''; ?>>Nothing — evening callers get no response — 0 pts</option>
                </select>
            </div>

            <div class="kpg-field">
                <label for="kpg-in-call-booking">3. Live appointment booking during the call (0–20 pts)</label>
                <select id="kpg-in-call-booking" name="inCallBooking">
                    <option value="in_call_calendar" data-pts="20"<?php echo $atts['in_call_booking'] === 'in_call_calendar' ? ' selected' : ''; ?>>Instant calendar booking on the first call — 20 pts</option>
                    <option value="call_back_later" data-pts="5"<?php echo $atts['in_call_booking'] === 'call_back_later' ? ' selected' : ''; ?>>&ldquo;I'll finish working and call you back tonight&rdquo; — 5 pts</option>
                    <option value="none" data-pts="0"<?php echo $atts['in_call_booking'] === 'none' ? ' selected' : ''; ?>>No structured booking — 0 pts</option>
                </select>
            </div>

            <div class="kpg-field">
                <label for="kpg-lead-capture">4. CRM lead capture &amp; call transcription (0–20 pts)</label>
                <select id="kpg-lead-capture" name="leadCaptureCrm">
                    <option value="auto_crm_transcript" data-pts="20"<?php echo $atts['lead_capture'] === 'auto_crm_transcript' ? ' selected' : ''; ?>>Automated CRM lead card + full call transcript — 20 pts</option>
                    <option value="manual_note" data-pts="8"<?php echo $atts['lead_capture'] === 'manual_note' ? ' selected' : ''; ?>>Manual note / message to self on WhatsApp — 8 pts</option>
                    <option value="memory_only" data-pts="0"<?php echo $atts['lead_capture'] === 'memory_only' ? ' selected' : ''; ?>>Keep it in my head — 0 pts</option>
                </select>
            </div>

            <div class="kpg-field">
                <label for="kpg-recovery-sec">5. Missed-call recovery speed (0–15 pts)</label>
                <select id="kpg-recovery-sec" name="missedCallRecoverySeconds">
                    <option value="under_30s" data-pts="15"<?php echo $atts['recovery_sec'] === 'under_30s' ? ' selected' : ''; ?>>Under 30 seconds (instant callback / automated SMS) — 15 pts</option>
                    <option value="under_1h" data-pts="7"<?php echo $atts['recovery_sec'] === 'under_1h' ? ' selected' : ''; ?>>Within 1 hour — 7 pts</option>
                    <option value="never" data-pts="0"<?php echo $atts['recovery_sec'] === 'never' ? ' selected' : ''; ?>>Several hours later / Never — 0 pts</option>
                </select>
            </div>

            <div class="kpg-rev-row">
                <div class="kpg-field">
                    <label for="kpg-missed-weekly">Missed Calls / Week</label>
                    <input type="number" id="kpg-missed-weekly" min="0" max="500" value="<?php echo (int) $atts['missed_weekly']; ?>">
                </div>
                <div class="kpg-field">
                    <label for="kpg-job-value">Avg. Job Value (₪)</label>
                    <input type="number" id="kpg-job-value" min="50" max="100000" step="50" value="<?php echo (int) $atts['avg_job_ils']; ?>">
                </div>
                <div class="kpg-field">
                    <label for="kpg-close-rate">Close Rate (%)</label>
                    <input type="number" id="kpg-close-rate" min="5" max="100" value="<?php echo (int) $atts['close_rate']; ?>">
                </div>
            </div>
        </div>

        <div class="kpg-roast-card" id="kpg-roast-card">
            <div class="kpg-score-header">
                <div>
                    <div class="kpg-score-circle" id="kpg-score-display"><?php echo $score; ?>/100</div>
                    <div style="font-size:13px;color:#CBD5E1;margin-top:4px;" id="kpg-grade-display">
                        Current Grade: <strong><?php echo $grade; ?></strong>
                    </div>
                </div>
                <div class="kpg-lift-pill">With Kolivri (*61*): 100/100 🚀</div>
            </div>

            <div class="kpg-loss-box">
                <div style="font-size:12px;color:#FECDD3;">Estimated Monthly Revenue Lost from Leaked Calls:</div>
                <div class="kpg-loss-amount" id="kpg-monthly-loss"><?php echo $monthlyHe; ?> / month</div>
                <div style="font-size:12px;color:#FDA4AF;" id="kpg-annual-loss">(<?php echo $annualHe; ?> / year handed to competitors)</div>
            </div>

            <div class="kpg-roast-text" id="kpg-roast-en" dir="ltr" lang="en"><?php echo $roastEn; ?></div>
            <div class="kpg-roast-text" id="kpg-roast-he" dir="rtl" lang="he" style="display:none;"><?php echo $roastHe; ?></div>

            <div class="kpg-lift-box" id="kpg-lift-summary">
                How to jump from <?php echo $score; ?>/100 to 100/100 in 30 seconds: Dial <code>*61*</code> smart call forwarding to Kolivri (<code>/follow-me/</code>) for first-ring 24/7 AI voice pickup, live calendar booking, automated CRM lead cards + transcripts, and sub-30s SMS follow-up.
            </div>

            <div class="kpg-cta-group">
                <a href="<?php echo self::esc(Kolivri_Grader_Engine::KOLIVRI_START_URL); ?>" class="kpg-btn-primary">
                    Start 14-Day Free Trial →
                </a>
                <a href="/follow-me/" class="kpg-btn-secondary">
                    30s *61* Call Forwarding Guide
                </a>
            </div>

            <div class="kpg-cta-group" style="margin-top:10px;">
                <a href="https://wa.me/972547875670" target="_blank" rel="noopener" class="kpg-btn-secondary">
                    WhatsApp Kolivri: <?php echo self::esc(Kolivri_Grader_Engine::KOLIVRI_PHONE_DISPLAY); ?>
                </a>
                <a href="https://kolivri.com/" class="kpg-btn-secondary">
                    Kolivri Website (kolivri.com)
                </a>
            </div>

            <div class="kpg-otp-box" id="kpg-self-audit-otp-gate">
                <div style="font-size:13.5px;font-weight:700;color:#E2E8F0;">
                    🔒 Live AI Mystery-Shopper Self-Audit (SMS OTP Verified)
                </div>
                <p style="font-size:12.5px;color:#94A3B8;margin:4px 0 8px;">
                    To prevent competitors from harassing third-party businesses, live AI test calls are placed strictly to phone numbers verified via 6-digit SMS OTP.
                </p>
                <div class="kpg-otp-row">
                    <input type="tel" id="kpg-owner-phone" name="phone" placeholder="Enter your mobile (e.g. 054-787-5670)" dir="ltr">
                    <select id="kpg-carrier" name="carrier" aria-label="Mobile Carrier" style="max-width:150px;">
                        <option value="">Select carrier...</option>
                        <option value="partner">Partner / 012</option>
                        <option value="pelephone">Pelephone</option>
                        <option value="cellcom">Cellcom</option>
                        <option value="hot_mobile">HOT Mobile</option>
                        <option value="golan">Golan Telecom</option>
                        <option value="019">019 Mobile</option>
                        <option value="we4g">We4G</option>
                        <option value="bezeq">Bezeq</option>
                    </select>
                    <input type="text" id="kpg-otp-code" maxlength="6" placeholder="6-digit OTP" dir="ltr" style="max-width:120px;">
                </div>
            </div>
        </div>
    </div>
    <?php else : ?>
    <span class="kpg-badge">🔥 מדד המענה הישראלי + המבקרת של קוליברי (Critic Phone Grader)</span>
    <h2 class="kpg-title">כמה כסף העסק שלך שורף על שיחות שלא נענות?</h2>
    <p class="kpg-subtitle">
        דרג את העסק שלך ב-5 מדדים אובייקטיביים וקבל אבחון חד מהמבקרת של קוליברי — כולל חישוב הפסד חודשי מדויק בשקלים ואיך מעבר של 30 שניות ל-<code>*61*</code> מקפיץ אותך ל-<strong>100/100</strong>.
    </p>

    <div class="kpg-grid">
        <div class="kpg-panel">
            <div class="kpg-field">
                <label for="kpg-pickup-rings">1. תוך כמה צלצולים עונים אצלך בעסק? (0–25 נק׳)</label>
                <select id="kpg-pickup-rings" name="pickupRings">
                    <option value="1_ring" data-pts="25"<?php echo $atts['pickup_rings'] === '1_ring' ? ' selected' : ''; ?>>צלצול ראשון (1) — 25 נק׳</option>
                    <option value="2_3_rings" data-pts="18"<?php echo $atts['pickup_rings'] === '2_3_rings' ? ' selected' : ''; ?>>2–3 צלצולים — 18 נק׳</option>
                    <option value="4_5_rings" data-pts="8"<?php echo $atts['pickup_rings'] === '4_5_rings' ? ' selected' : ''; ?>>4–5 צלצולים (כשאתה באמצע עבודה) — 8 נק׳</option>
                    <option value="voicemail" data-pts="0"<?php echo $atts['pickup_rings'] === 'voicemail' ? ' selected' : ''; ?>>6+ צלצולים / תא קולי / אין מענה — 0 נק׳</option>
                </select>
            </div>

            <div class="kpg-field">
                <label for="kpg-after-hours">2. מענה אחרי שעות העבודה ובסופ״ש (0–20 נק׳)</label>
                <select id="kpg-after-hours" name="afterHoursCoverage">
                    <option value="24_7_ai_human" data-pts="20"<?php echo $atts['after_hours'] === '24_7_ai_human' ? ' selected' : ''; ?>>מענה קולי 24/7 (AI / מוקד אנושי) — 20 נק׳</option>
                    <option value="whatsapp_auto" data-pts="10"<?php echo $atts['after_hours'] === 'whatsapp_auto' ? ' selected' : ''; ?>>הודעת ווטסאפ אוטומטית בלבד — 10 נק׳</option>
                    <option value="nothing" data-pts="0"<?php echo $atts['after_hours'] === 'nothing' ? ' selected' : ''; ?>>כלום — מי שמתקשר בערב לא נענה — 0 נק׳</option>
                </select>
            </div>

            <div class="kpg-field">
                <label for="kpg-in-call-booking">3. תיאום פגישה או ביקור תוך כדי השיחה (0–20 נק׳)</label>
                <select id="kpg-in-call-booking" name="inCallBooking">
                    <option value="in_call_calendar" data-pts="20"<?php echo $atts['in_call_booking'] === 'in_call_calendar' ? ' selected' : ''; ?>>שיבוץ חכם ביומן כבר בשיחה הראשונה — 20 נק׳</option>
                    <option value="call_back_later" data-pts="5"<?php echo $atts['in_call_booking'] === 'call_back_later' ? ' selected' : ''; ?>>״אני אסיים לעבוד ואחזור אליך בערב״ — 5 נק׳</option>
                    <option value="none" data-pts="0"<?php echo $atts['in_call_booking'] === 'none' ? ' selected' : ''; ?>>אין תיאום מסודר — 0 נק׳</option>
                </select>
            </div>

            <div class="kpg-field">
                <label for="kpg-lead-capture">4. תיעוד לידים ופרטי שיחה ב-CRM (0–20 נק׳)</label>
                <select id="kpg-lead-capture" name="leadCaptureCrm">
                    <option value="auto_crm_transcript" data-pts="20"<?php echo $atts['lead_capture'] === 'auto_crm_transcript' ? ' selected' : ''; ?>>תיעוד אוטומטי ב-CRM + תמלול מלא של השיחה — 20 נק׳</option>
                    <option value="manual_note" data-pts="8"<?php echo $atts['lead_capture'] === 'manual_note' ? ' selected' : ''; ?>>פתק ידני / הודעה לעצמי בווטסאפ — 8 נק׳</option>
                    <option value="memory_only" data-pts="0"<?php echo $atts['lead_capture'] === 'memory_only' ? ' selected' : ''; ?>>זוכר בראש — 0 נק׳</option>
                </select>
            </div>

            <div class="kpg-field">
                <label for="kpg-recovery-sec">5. זמן התאוששות משיחה שלא נענתה (0–15 נק׳)</label>
                <select id="kpg-recovery-sec" name="missedCallRecoverySeconds">
                    <option value="under_30s" data-pts="15"<?php echo $atts['recovery_sec'] === 'under_30s' ? ' selected' : ''; ?>>פחות מ-30 שניות (שיחה חוזרת / SMS אוטומטי) — 15 נק׳</option>
                    <option value="under_1h" data-pts="7"<?php echo $atts['recovery_sec'] === 'under_1h' ? ' selected' : ''; ?>>תוך שעה — 7 נק׳</option>
                    <option value="never" data-pts="0"<?php echo $atts['recovery_sec'] === 'never' ? ' selected' : ''; ?>>אחרי כמה שעות / לעולם לא — 0 נק׳</option>
                </select>
            </div>

            <div class="kpg-rev-row">
                <div class="kpg-field">
                    <label for="kpg-missed-weekly">שיחות מפוספסות/שבוע</label>
                    <input type="number" id="kpg-missed-weekly" min="0" max="500" value="<?php echo (int) $atts['missed_weekly']; ?>">
                </div>
                <div class="kpg-field">
                    <label for="kpg-job-value">שווי עבודה ממוצע (₪)</label>
                    <input type="number" id="kpg-job-value" min="50" max="100000" step="50" value="<?php echo (int) $atts['avg_job_ils']; ?>">
                </div>
                <div class="kpg-field">
                    <label for="kpg-close-rate">אחוז סגירה (%)</label>
                    <input type="number" id="kpg-close-rate" min="5" max="100" value="<?php echo (int) $atts['close_rate']; ?>">
                </div>
            </div>
        </div>

        <div class="kpg-roast-card" id="kpg-roast-card">
            <div class="kpg-score-header">
                <div>
                    <div class="kpg-score-circle" id="kpg-score-display"><?php echo $score; ?>/100</div>
                    <div style="font-size:13px;color:#CBD5E1;margin-top:4px;" id="kpg-grade-display">
                        דירוג נוכחי: <strong><?php echo $grade; ?></strong> · <?php echo $statusHe; ?>
                    </div>
                </div>
                <div class="kpg-lift-pill">עם קוליברי (*61*): 100/100 🚀</div>
            </div>

            <div class="kpg-loss-box">
                <div style="font-size:12px;color:#FECDD3;">הפסד הכנסות חודשי משוער מלידים שזלגו:</div>
                <div class="kpg-loss-amount" id="kpg-monthly-loss"><?php echo $monthlyHe; ?> / חודש</div>
                <div style="font-size:12px;color:#FDA4AF;" id="kpg-annual-loss">(<?php echo $annualHe; ?> בשנה שנכנסים לכיס של המתחרים)</div>
            </div>

            <div class="kpg-roast-text" id="kpg-roast-he"><?php echo $roastHe; ?></div>
            <div class="kpg-roast-text" id="kpg-roast-en" dir="ltr" lang="en" style="display:none;font-size:13.5px;color:#CBD5E1;"><?php echo $roastEn; ?></div>

            <div class="kpg-lift-box" id="kpg-lift-summary"><?php echo $liftHe; ?></div>

            <div class="kpg-cta-group">
                <a href="<?php echo self::esc(Kolivri_Grader_Engine::KOLIVRI_FOLLOW_ME_URL); ?>" class="kpg-btn-primary">
                    הפעל עקוב אחריי חכם (*61*) ועלה ל-100/100 ←
                </a>
                <a href="<?php echo self::esc(Kolivri_Grader_Engine::KOLIVRI_START_URL); ?>" class="kpg-btn-secondary">
                    התחל עכשיו (/start/)
                </a>
            </div>

            <div class="kpg-cta-group" style="margin-top:10px;">
                <a href="https://wa.me/972547875670" target="_blank" rel="noopener" class="kpg-btn-secondary">
                    ווטסאפ לקוליברי: <?php echo self::esc(Kolivri_Grader_Engine::KOLIVRI_PHONE_DISPLAY); ?>
                </a>
                <a href="<?php echo self::esc(Kolivri_Grader_Engine::KOLIVRI_HE_HOME_URL); ?>" class="kpg-btn-secondary">
                    לאתר קוליברי (kolivri.com/he)
                </a>
            </div>

            <div class="kpg-otp-box" id="kpg-self-audit-otp-gate">
                <div style="font-size:13.5px;font-weight:700;color:#E2E8F0;">
                    🔒 בדיקת לקוח סמוי חיה (Self-Audit עם אימות SMS OTP)
                </div>
                <p style="font-size:12.5px;color:#94A3B8;margin:4px 0 8px;">
                    כדי למנוע ממתחרים להטריד בעלי מקצוע, חיוג בדיקה חי של סוכן ה-AI מתבצע אך ורק למספר שעבר אימות בעלות בקוד SMS בן 6 ספרות.
                </p>
                <div class="kpg-otp-row">
                    <input type="tel" id="kpg-owner-phone" name="phone" placeholder="הזן נייד שלך (למשל 054-787-5670)" dir="ltr">
                    <select id="kpg-carrier" name="carrier" aria-label="מפעיל סלולרי" style="max-width:150px;">
                        <option value="">בחר מפעיל...</option>
                        <option value="partner">Partner / 012</option>
                        <option value="pelephone">Pelephone</option>
                        <option value="cellcom">Cellcom</option>
                        <option value="hot_mobile">HOT Mobile</option>
                        <option value="golan">Golan Telecom</option>
                        <option value="019">019 Mobile</option>
                        <option value="we4g">We4G</option>
                        <option value="bezeq">Bezeq</option>
                    </select>
                    <input type="text" id="kpg-otp-code" maxlength="6" placeholder="קוד 6 ספרות" dir="ltr" style="max-width:120px;">
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    (function() {
        var root = document.querySelector('.kpg-wrap');
        if (!root) return;
        var params = (typeof window !== 'undefined' && window.location) ? new URLSearchParams(window.location.search) : null;
        var state = {
            phone: params ? (params.get('phone') || '') : '',
            carrier: params ? (params.get('carrier') || '') : '',
            score: params ? (params.get('score') || '<?php echo (int) $score; ?>') : '<?php echo (int) $score; ?>'
        };
        function syncContextLinks() {
            var qsParts = [];
            if (state.phone) qsParts.push('phone=' + encodeURIComponent(state.phone));
            if (state.carrier) qsParts.push('carrier=' + encodeURIComponent(state.carrier));
            if (state.score !== '' && state.score !== null && state.score !== undefined) {
                qsParts.push('score=' + encodeURIComponent(state.score));
            }
            var qs = qsParts.join('&');
            var links = document.querySelectorAll('.klv-lab-switcher a, a[href*="/he/start/"], a[href*="/start/"], a[href*="/he/follow-me/"], a[href*="/follow-me/"]');
            links.forEach(function(a) {
                var rawHref = a.getAttribute('data-base-href') || a.getAttribute('href') || '';
                if (!rawHref) return;
                if (!a.getAttribute('data-base-href')) {
                    a.setAttribute('data-base-href', rawHref);
                }
                var hashIdx = rawHref.indexOf('#');
                var hash = hashIdx !== -1 ? rawHref.slice(hashIdx) : '';
                var baseNoHash = hashIdx !== -1 ? rawHref.slice(0, hashIdx) : rawHref;
                var qIdx = baseNoHash.indexOf('?');
                var cleanPath = qIdx !== -1 ? baseNoHash.slice(0, qIdx) : baseNoHash;
                a.setAttribute('href', qs ? (cleanPath + '?' + qs + hash) : (cleanPath + hash));
            });
        }
        var phoneEl = document.getElementById('kpg-owner-phone');
        var carrierEl = document.getElementById('kpg-carrier');
        if (state.phone && phoneEl) {
            phoneEl.value = state.phone;
        }
        if (state.carrier && carrierEl) {
            carrierEl.value = state.carrier;
        }
        if (phoneEl) {
            var onPhoneChange = function() {
                state.phone = (phoneEl.value || '').trim();
                syncContextLinks();
            };
            phoneEl.addEventListener('input', onPhoneChange);
            phoneEl.addEventListener('change', onPhoneChange);
        }
        if (carrierEl) {
            var onCarrierChange = function() {
                state.carrier = (carrierEl.value || '').trim();
                syncContextLinks();
            };
            carrierEl.addEventListener('change', onCarrierChange);
            carrierEl.addEventListener('input', onCarrierChange);
        }
        var isEn = <?php echo $is_en ? 'true' : 'false'; ?>;
        var ids = ['kpg-pickup-rings', 'kpg-after-hours', 'kpg-in-call-booking', 'kpg-lead-capture', 'kpg-recovery-sec', 'kpg-missed-weekly', 'kpg-job-value', 'kpg-close-rate'];
        function recalc() {
            var total = 0;
            ['kpg-pickup-rings', 'kpg-after-hours', 'kpg-in-call-booking', 'kpg-lead-capture', 'kpg-recovery-sec'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el && el.selectedOptions && el.selectedOptions[0]) {
                    total += parseInt(el.selectedOptions[0].getAttribute('data-pts') || '0', 10);
                }
            });
            var missed = Math.max(0, parseFloat((document.getElementById('kpg-missed-weekly') || {}).value || '0'));
            var jobVal = Math.max(0, parseFloat((document.getElementById('kpg-job-value') || {}).value || '0'));
            var rate   = Math.max(0, Math.min(100, parseFloat((document.getElementById('kpg-close-rate') || {}).value || '35'))) / 100;
            var monthly = Math.round(missed * 4 * jobVal * rate);
            var annual  = monthly * 12;
            var scoreEl = document.getElementById('kpg-score-display');
            var monthEl = document.getElementById('kpg-monthly-loss');
            var yearEl  = document.getElementById('kpg-annual-loss');
            if (scoreEl) scoreEl.textContent = total + '/100';
            if (monthEl) monthEl.textContent = '₪' + monthly.toLocaleString('en-US') + (isEn ? ' / month' : ' / חודש');
            if (yearEl)  yearEl.textContent  = isEn
                ? '(₪' + annual.toLocaleString('en-US') + ' / year handed to competitors)'
                : '(₪' + annual.toLocaleString('en-US') + ' בשנה שנכנסים לכיס של המתחרים)';
            state.score = String(total);
            syncContextLinks();
        }
        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', recalc);
                el.addEventListener('input', recalc);
            }
        });
        syncContextLinks();
    })();
    </script>
</div>
        <?php
        return trim((string) ob_get_clean());
    }
}

Kolivri_Phone_Grader::get_instance();
