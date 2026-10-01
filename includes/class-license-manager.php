<?php
/**
 * RTSM License Manager
 *
 * Binary licensing model: a site either holds an active MMI Suite license
 * (which unlocks ALL MMI plugins, including RTSM) or it does not.
 * There are no per-product tiers — the suite is one subscription.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_License_Manager {

    private static $instance = null;
    private $license_key_option = 'rtsm_license_key';
    private $license_status_option = 'rtsm_license_status';
    private $license_activated_option = 'rtsm_license_activated';

    /** @var string Hub option key for centralized license data */
    private $hub_option = 'mmi_license_mmi-rtsm';

    /** @var string Plugin slug for license lookups */
    private $plugin_slug = 'mmi-rtsm';

    /** @var int Grace period days after license expiry before disabling features */
    const GRACE_PERIOD_DAYS = 7;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_rtsm_activate_license', [$this, 'ajax_activate_license']);
        add_action('wp_ajax_rtsm_deactivate_license', [$this, 'ajax_deactivate_license']);

        // Daily license validation cron
        add_action('rtsm_daily_license_check', [$this, 'periodic_validation']);
        if (!wp_next_scheduled('rtsm_daily_license_check')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'rtsm_daily_license_check');
        }
    }

    /** @var bool|null In-memory cache for the current request */
    private $cached_licensed = null;

    /**
     * Primary license check: returns true when an active MMI Suite (or valid
     * legacy per-plugin) license is found, false otherwise.
     *
     * @return bool
     */
    public function is_licensed(): bool {
        if ( $this->cached_licensed !== null ) {
            return $this->cached_licensed;
        }

        // Short transient cache: avoids repeated option reads across AJAX requests.
        $transient = get_transient( 'rtsm_license_tier' );
        if ( $transient !== false ) {
            $this->cached_licensed = ( $transient === 'licensed' );
            return $this->cached_licensed;
        }

        $licensed = $this->resolve_is_licensed();
        $this->cached_licensed = $licensed;
        set_transient( 'rtsm_license_tier', $licensed ? 'licensed' : 'free', 60 );
        return $licensed;
    }

    /**
     * Backward-compatible tier accessor.
     * Returns 'licensed' when a valid MMI Suite license is active, 'free' otherwise.
     *
     * @return string 'licensed' | 'free'
     */
    public function get_license_tier(): string {
        return $this->is_licensed() ? 'licensed' : 'free';
    }

    /**
     * Invalidate the cached license state (call after activation / deactivation).
     */
    public function invalidate_tier_cache(): void {
        $this->cached_licensed = null;
        delete_transient( 'rtsm_license_tier' );
    }

    /**
     * Resolve whether the site currently holds a valid active license.
     *
     * Resolution order:
     *   1. mmi_is_licensed() — authoritative suite check from MMI Hub.
     *   2. Suite option direct check (load-order fallback).
     *   3. Per-plugin Hub-format option (direct RTSM license).
     *   4. Legacy local boolean option.
     *
     * @return bool
     */
    private function resolve_is_licensed(): bool {
        // Priority 1: Hub helper — authoritative suite + per-plugin check.
        if ( function_exists( 'mmi_is_licensed' ) ) {
            return mmi_is_licensed( $this->plugin_slug );
        }

        if ( ! class_exists( 'MMI_Settings' ) ) {
            return false;
        }

        // Priority 2: Suite option direct check (load-order edge case).
        $suite = MMI_Settings::get( 'mmi_license_mmi-suite' );
        if ( ! empty( $suite['status'] ) && $suite['status'] === 'active' && ! empty( $suite['type'] ) ) {
            $ok = empty( $suite['expiry_date'] )
                || time() <= strtotime( $suite['expiry_date'] ) + ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS );
            if ( ! empty( $suite['trial_ends_at'] ) ) {
                $ok = $ok && ( time() <= strtotime( $suite['trial_ends_at'] ) );
            }
            if ( $ok ) {
                return true;
            }
        }

        // Priority 3: Per-plugin Hub-format option.
        $hub_data = MMI_Settings::get( $this->hub_option );
        if ( $hub_data && isset( $hub_data['status'] ) && $hub_data['status'] === 'active' ) {
            if ( ! empty( $hub_data['expiry_date'] ) ) {
                $grace_end = strtotime( $hub_data['expiry_date'] ) + ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS );
                if ( time() > $grace_end ) {
                    return false;
                }
            }
            return true;
        }

        // Priority 4: Legacy local boolean option.
        $status = MMI_Settings::get( $this->license_status_option, 'free' );
        return $status === 'active';
    }

    /**
     * Check if the current license grants access to a feature.
     * RTSM uses a binary model — all features are unlocked with any valid license.
     * This method is kept for backward compatibility; $required_tier is ignored.
     *
     * @param string $required_tier Ignored — kept for backward compatibility.
     * @return bool
     */
    public function has_tier_access( string $required_tier ): bool {
        return $this->is_licensed();
    }

    /**
     * Check if premium features are unlocked. Alias for is_licensed().
     *
     * @return bool
     */
    public function is_premium(): bool {
        return $this->is_licensed();
    }

    /**
     * Check if the license is in grace period (expired but within grace window).
     * Checks per-plugin option first, then suite data (lifetime suites have no expiry).
     *
     * @return bool
     */
    public function is_in_grace_period() {
        // Per-plugin Hub-format option.
        $hub_data = MMI_Settings::get($this->hub_option);
        if ($hub_data && !empty($hub_data['expiry_date'])) {
            $expiry    = strtotime($hub_data['expiry_date']);
            $grace_end = $expiry + (self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS);
            return time() > $expiry && time() <= $grace_end;
        }
        // Suite data fallback (lifetime plans have no expiry_date).
        $suite = class_exists('MMI_Settings') ? MMI_Settings::get('mmi_license_mmi-suite') : null;
        if ($suite && !empty($suite['expiry_date'])) {
            $expiry    = strtotime($suite['expiry_date']);
            $grace_end = $expiry + (self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS);
            return time() > $expiry && time() <= $grace_end;
        }
        return false;
    }

    /**
     * Get the number of days until license expiry (negative if expired).
     * Checks per-plugin option first, then suite data.
     *
     * @return int|null Days until expiry, or null if no expiry set (e.g. lifetime).
     */
    public function get_days_until_expiry() {
        // Per-plugin Hub-format option.
        $hub_data = MMI_Settings::get($this->hub_option);
        if ($hub_data && !empty($hub_data['expiry_date'])) {
            return (int) round((strtotime($hub_data['expiry_date']) - time()) / DAY_IN_SECONDS);
        }
        // Suite data fallback (lifetime plans have no expiry_date → returns null).
        $suite = class_exists('MMI_Settings') ? MMI_Settings::get('mmi_license_mmi-suite') : null;
        if ($suite && !empty($suite['expiry_date'])) {
            return (int) round((strtotime($suite['expiry_date']) - time()) / DAY_IN_SECONDS);
        }
        return null;
    }

    /**
     * Determine whether the active license comes from the MMI Suite, a direct
     * per-plugin RTSM license, the legacy local option, or nothing at all.
     *
     * Resolution order: suite > direct per-plugin > legacy local
     *
     * @return string 'suite' | 'direct' | 'legacy' | 'free'
     */
    public function get_license_source(): string {
        if (!class_exists('MMI_Settings')) {
            return 'free';
        }

        // 1. Suite license (Model C — one key unlocks all MMI extensions).
        $suite = MMI_Settings::get('mmi_license_mmi-suite');
        if (!empty($suite['status']) && $suite['status'] === 'active' && !empty($suite['type'])) {
            $ok = empty($suite['expiry_date'])
                || time() <= strtotime($suite['expiry_date']) + (self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS);
            if (!empty($suite['trial_ends_at'])) {
                $ok = $ok && (time() <= strtotime($suite['trial_ends_at']));
            }
            if ($ok) return 'suite';
        }

        // 2. Per-plugin Hub-format option (direct RTSM license).
        $hub_data = MMI_Settings::get($this->hub_option);
        if (!empty($hub_data['status']) && $hub_data['status'] === 'active') {
            $grace_ok = empty($hub_data['expiry_date'])
                || time() <= strtotime($hub_data['expiry_date']) + (self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS);
            if ($grace_ok) return 'direct';
        }

        // 3. Legacy local boolean option.
        if (MMI_Settings::get($this->license_status_option) === 'active') {
            return 'legacy';
        }

        return 'free';
    }

    /**
     * Get license status.
     *
     * @return string 'active' | 'free'
     */
    public function get_license_status(): string {
        return $this->is_licensed() ? 'active' : 'free';
    }

    /**
     * Get license key.
     * Resolution order: per-plugin Hub option → legacy local option → suite key.
     */
    public function get_license_key() {
        // 1. Per-plugin Hub-format option (direct RTSM license).
        $hub_data = MMI_Settings::get($this->hub_option);
        if ($hub_data && isset($hub_data['key'])) {
            return $hub_data['key'];
        }
        // 2. Legacy local option.
        $legacy_key = MMI_Settings::get($this->license_key_option, '');
        if ($legacy_key) {
            return $legacy_key;
        }
        // 3. Suite key — the primary licensing path under Model C.
        $suite = class_exists('MMI_Settings') ? MMI_Settings::get('mmi_license_mmi-suite') : null;
        if (!empty($suite['key'])) {
            return $suite['key'];
        }
        return '';
    }

    /**
     * Get activation date
     */
    public function get_activation_date() {
        $hub_data = MMI_Settings::get($this->hub_option);
        if ($hub_data && isset($hub_data['activated_at'])) {
            return $hub_data['activated_at'];
        }

        return MMI_Settings::get($this->license_activated_option, '');
    }

    /**
     * Validate license key format. Accepts any MMI Hub-format key
     * (MMI-XXXX-####-####-####) — not just one whose 4-char code happens to
     * be RTSM's own, since RTSM's binary model means a real MMI Suite
     * bundle key (a different code, e.g. from generate_extension_id()'s
     * fallback algorithm for 'mmi-suite') is exactly as valid here as
     * RTSM's own per-plugin key. The old RTSM-only regex would have
     * rejected the single most common real-world case: a customer entering
     * their Suite key here instead of a plugin-specific one. Legacy format
     * (RTSM-XXXX-XXXX-XXXX-XXXX) is also still accepted for old keys.
     */
    private function validate_license_format($key) {
        $hub_pattern = '/^MMI-[A-Z0-9]{4}-\d{4}-\d{4}-\d{4}$/i';
        $legacy_pattern = '/^RTSM-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/';
        return preg_match($hub_pattern, $key) || preg_match($legacy_pattern, $key);
    }

    /**
     * Activate a license key through the shared MMI licensing engine
     * (MMI_License_API — routes locally on the license server itself, or
     * over REST to mannmade.us from any customer site, including its own
     * network-failure/grace-period handling). On success, mirrors the
     * result into RTSM's own Hub-format option so is_licensed(),
     * get_days_until_expiry(), is_in_grace_period(), etc. keep working
     * exactly as before — none of those read methods changed.
     *
     * @param string $key License key
     * @return array{success: bool, message: string}
     */
    public function activate_license($key) {
        $key = strtoupper(trim($key));

        if (!$this->validate_license_format($key)) {
            return [
                'success' => false,
                'message' => __('Invalid license key format.', 'mmi-rtsm')
            ];
        }

        if (!class_exists('MMI_License_API')) {
            return [
                'success' => false,
                'message' => __('Could not connect to the license server. Please try again later.', 'mmi-rtsm'),
            ];
        }

        $result = MMI_License_API::normalise(MMI_License_API::activate($key, $this->plugin_slug));

        if (empty($result['valid'])) {
            return [
                'success' => false,
                'message' => $result['error'] ?? __('Invalid license key.', 'mmi-rtsm'),
            ];
        }

        $license = $result['license'];

        MMI_Settings::set($this->hub_option, [
            'key'           => $key,
            'type'          => $license->license_type,
            'status'        => 'active',
            'domain'        => get_site_url(),
            'activated_at'  => current_time('mysql'),
            'features'      => $license->features ?? [],
            'expiry_date'   => $license->expiry_date,
            'is_trial'      => $license->is_trial ?? false,
            'trial_ends_at' => $license->trial_ends_at ?? null,
        ]);

        // Also update legacy options for backward compatibility
        MMI_Settings::set($this->license_key_option, $key);
        MMI_Settings::set($this->license_status_option, 'active');
        MMI_Settings::set($this->license_activated_option, current_time('mysql'));

        return [
            'success' => true,
            'message' => __( 'License activated! All MMI Suite features are now unlocked.', 'mmi-rtsm' ),
        ];
    }

    /**
     * Deactivate license through the shared MMI licensing engine. Local
     * data is always cleared regardless of the remote call's outcome — the
     * user explicitly requested removal — matching MMI_License_Client's own
     * deactivate() semantics.
     */
    public function deactivate_license() {
        $key = $this->get_license_key();

        if ($key && class_exists('MMI_License_API')) {
            MMI_License_API::deactivate($key, $this->plugin_slug);
        }

        // Clear local data
        MMI_Settings::delete($this->hub_option);
        MMI_Settings::delete($this->license_key_option);
        MMI_Settings::set($this->license_status_option, 'free');
        MMI_Settings::delete($this->license_activated_option);

        return [
            'success' => true,
            'message' => __( 'License deactivated. Features have been reset to the free plan.', 'mmi-rtsm' ),
        ];
    }

    /**
     * Periodic license validation via daily cron, through the shared MMI
     * licensing engine. MMI_License_API::validate() already carries its own
     * network-failure/grace-period handling (a cached "still valid" result
     * for a bounded window, `network_error` set only once that window is
     * exhausted) — on any network_error this just returns without touching
     * stored state, same "don't invalidate, try again tomorrow" behavior as
     * before.
     */
    public function periodic_validation() {
        $key = $this->get_license_key();
        if (!$key || !class_exists('MMI_License_API')) {
            return;
        }

        $result = MMI_License_API::normalise(MMI_License_API::validate($key, $this->plugin_slug));

        if (!empty($result['network_error'])) {
            return;
        }

        $hub_data = MMI_Settings::get($this->hub_option, []);
        $hub_data['last_validated'] = current_time('mysql');

        if (!empty($result['valid'])) {
            // License still valid — refresh stored data.
            $license = $result['license'];
            $hub_data['status']       = 'active';
            $hub_data['expiry_date']  = $license->expiry_date ?? ($hub_data['expiry_date'] ?? null);
            $hub_data['features']     = $license->features ?? ($hub_data['features'] ?? []);
            $hub_data['is_trial']     = $license->is_trial ?? false;
            $hub_data['trial_ends_at'] = $license->trial_ends_at ?? null;
        } else {
            // License explicitly invalid (revoked, expired beyond grace, etc.)
            $hub_data['status'] = 'expired';
        }

        MMI_Settings::set($this->hub_option, $hub_data);
    }

    /**
     * AJAX handler for license activation
     */
    public function ajax_activate_license() {
        check_ajax_referer('rtsm_license_nonce', 'nonce');

        if (!rtsm_user_can()) {
            rtsm_audit('license.activate', ['outcome' => 'denied']);
            wp_send_json_error('Insufficient permissions');
        }

        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';

        if (empty($license_key)) {
            wp_send_json_error('License key is required');
        }

        $result = $this->activate_license($license_key);

        // Never record the key itself.
        rtsm_audit('license.activate', [
            'object_type' => 'license',
            'outcome'     => $result['success'] ? 'success' : 'failure',
        ]);

        if ($result['success']) {
            $this->invalidate_tier_cache();
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX handler for license deactivation
     */
    public function ajax_deactivate_license() {
        check_ajax_referer('rtsm_license_nonce', 'nonce');

        if (!rtsm_user_can()) {
            rtsm_audit('license.deactivate', ['outcome' => 'denied']);
            wp_send_json_error('Insufficient permissions');
        }

        $result = $this->deactivate_license();

        rtsm_audit('license.deactivate', [
            'object_type' => 'license',
            'outcome'     => $result['success'] ? 'success' : 'failure',
        ]);

        if ($result['success']) {
            $this->invalidate_tier_cache();
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Get upgrade URL — points to the MMI Suite product page.
     * RTSM is bundled with all MMI Suite tiers; standalone per-plugin purchase
     * is available as a legacy option.
     */
    public function get_upgrade_url() {
        return 'https://mannmade.us/mmi-suite/';
    }
}
