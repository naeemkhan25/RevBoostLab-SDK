<?php

/**
 * RevBoostLab Universal Licensing and Tracking SDK
 * Completely self-contained single-folder drop-in SDK for managing license verification
 * and plugin deactivation reason feedback.
 *
 * @package RevBoostLab
 * @version 1.0.0
 */

namespace RevBoostLab;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( '\RevBoostLab\RevBoostLab_SDK' ) ) {

    class RevBoostLab_SDK {

        /**
         * Configuration parameters for this SDK instance.
         */
        private $config = [];

        /**
         * The active instance of this class.
         */
        private static $instance = null;

        /**
         * Holds the submenu page hook suffix.
         */
        private $license_page_hook = '';

        /**
         * Constructor
         *
         * @param array $config Configuration array.
         */
        public function __construct( $config = [] ) {
            $text_domain = isset( $config['text_domain'] ) ? $config['text_domain'] : 'revboostlab';
            $defaults = [
                'plugin_file'      => '',
                'plugin_slug'      => $text_domain,
                'plugin_name'      => 'Quotation Manager for WooCommerce',
                'plugin_version'   => '1.0.0',
                'parent_menu'      => 'bluepi-quote',
                'option_status'    => 'revboostlab_license_status',
                'option_key'       => 'revboostlab_license_key',
                'api_url'          => 'https://revboostlab.com/wp-json/sacfw/v1',
                'text_domain'      => $text_domain,
                'brand_color'      => '#0066cc',
                'enable_updates'   => true,
                'enable_licensing' => true,
                'deactive_popup'   => false,
                'enable_opt_in'    => true,
            ];

            $this->config = array_merge( $defaults, $config );

            // Enqueue Interceptor Script/Styles for Plugins and Settings Dashboard
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

            // Footer modal markup injection
            if ( ! empty( $this->config['deactive_popup'] ) ) {
                add_action( 'admin_footer', [ $this, 'render_deactivation_modal' ] );
            }

            // Register AJAX actions
            if ( ! empty( $this->config['enable_licensing'] ) ) {
                add_action( 'wp_ajax_' . $this->config['plugin_slug'] . '_activate_license', [ $this, 'ajax_activate_license' ] );
                add_action( 'wp_ajax_' . $this->config['plugin_slug'] . '_deactivate_license', [ $this, 'ajax_deactivate_license' ] );
            }
            if ( ! empty( $this->config['deactive_popup'] ) ) {
                add_action( 'wp_ajax_' . $this->config['plugin_slug'] . '_deactivate_feedback', [ $this, 'ajax_deactivate_feedback' ] );
            }

            // Register AJAX and Admin Notice actions for Opt-In
            if ( ! empty( $this->config['enable_opt_in'] ) ) {
                add_action( 'wp_ajax_' . $this->config['plugin_slug'] . '_opt_in_action', [ $this, 'ajax_opt_in_action' ] );
                add_action( 'admin_notices', [ $this, 'render_opt_in_notice' ] );
            }

            // Register Cron and update notices conditionally
            if ( ! empty( $this->config['enable_updates'] ) ) {
                // Register Cron event and hook for hourly update checks
                add_action( $this->config['plugin_slug'] . '_hourly_update_check', [ $this, 'run_hourly_update_check' ] );
                if ( ! wp_next_scheduled( $this->config['plugin_slug'] . '_hourly_update_check' ) ) {
                    wp_schedule_event( time(), 'hourly', $this->config['plugin_slug'] . '_hourly_update_check' );
                }

                // Register deactivation hook to clear cron job
                register_deactivation_hook( $this->config['plugin_file'], [ $this, 'clear_scheduled_cron' ] );

                // Register admin update notices
                add_action( 'admin_notices', [ $this, 'show_update_notice' ] );

                // Register inline update row dynamically for the current plugin
                if ( ! empty( $this->config['plugin_file'] ) ) {
                    $plugin_basename = plugin_basename( $this->config['plugin_file'] );
                    add_action( "after_plugin_row_{$plugin_basename}", [ $this, 'show_update_row' ], 10, 3 );
                }
            }

            self::$instance = $this;
        }

        /**
         * Static helper to retrieve current licensed status.
         */
        public static function is_licensed() {
            if ( null === self::$instance ) {
                return false;
            }
            return get_option( self::$instance->config['option_status'], 'inactive' ) === 'active';
        }

        /**
         * Static helper to retrieve the SDK instance.
         */
        public static function get_instance() {
            return self::$instance;
        }

        /**
         * Enqueue external assets and pass configuration variables dynamically.
         */
        public function enqueue_admin_assets( $hook ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $is_license_page = ( isset( $_GET['page'] ) && ( $_GET['page'] === 'bluepi-quote-license' || $_GET['page'] === $this->config['plugin_slug'] . '-license' ) ) || ( $hook === $this->license_page_hook || $hook === 'admin_page_' . $this->config['plugin_slug'] . '-license' );
            
            $is_plugins_page = false;
            if ( ! empty( $this->config['deactive_popup'] ) ) {
                $is_plugins_page = ( 'plugins.php' === $hook || 'plugins-network.php' === $hook );
            }

            $show_opt_in = ! get_option( $this->config['plugin_slug'] . '_opt_in_dismissed' );

            if ( ! $is_license_page && ! $is_plugins_page && ! $show_opt_in ) {
                return;
            }

            // Enqueue CSS
            wp_enqueue_style(
                $this->config['plugin_slug'] . '-sdk-style',
                plugins_url( 'sdk/css/revboostlab-sdk-style.css', $this->config['plugin_file'] ),
                [],
                time()
            );

            // Enqueue JS (Use shared handle 'revboostlab-sdk-script' and static version to prevent duplicate loading)
            wp_enqueue_script(
                'revboostlab-sdk-script',
                plugins_url( 'sdk/js/revboostlab-sdk-script.js', $this->config['plugin_file'] ),
                [ 'jquery' ],
                time(),
                true
            );
        }

        /**
         * Check if license is active
         */
        public function get_license_status() {
            return get_option( $this->config['option_status'], 'inactive' );
        }

        /**
         * Check if license key is stored
         */
        public function get_license_key() {
            return get_option( $this->config['option_key'], '' );
        }

        /**
         * Render the Licensing settings dashboard page.
         */
        public function render_license_page() {
            $license_key = $this->get_license_key();
            $status = $this->get_license_status();
            $is_active = $status === 'active';
            $brand_color = $this->config['brand_color'];

            $active_count = get_option( $this->config['plugin_slug'] . '_active_activations', null );
            $allowed_count = get_option( $this->config['plugin_slug'] . '_allowed_activations', null );

            // Periodically sync activation count when visiting the license page
            if ( $is_active && ! empty( $license_key ) ) {
                $transient_key = 'revboostlab_lic_sync_' . md5( $this->config['plugin_slug'] );
                if ( false === get_transient( $transient_key ) ) {
                    // Sync with SaaS API
                    $email  = get_option( 'admin_email' );
                    $domain = home_url();
                    $response = wp_remote_post( $this->config['api_url'] . '/license-validation', [
                        'timeout'   => 5,
                        'sslverify' => false,
                        'body'      => [
                            'license_key' => $license_key,
                            'email'       => $email,
                            'domain'      => $domain,
                            'plugin_slug' => $this->config['plugin_slug'],
                            'action'      => 'activate',
                        ],
                    ] );
                    if ( ! is_wp_error( $response ) ) {
                        $body_json = json_decode( wp_remote_retrieve_body( $response ), true );
                        if ( isset( $body_json['active_activations'] ) ) {
                            $active_count = intval( $body_json['active_activations'] );
                            update_option( $this->config['plugin_slug'] . '_active_activations', $active_count );
                        }
                        if ( isset( $body_json['allowed_activations'] ) ) {
                            $allowed_count = intval( $body_json['allowed_activations'] );
                            update_option( $this->config['plugin_slug'] . '_allowed_activations', $allowed_count );
                        }
                    }
                    set_transient( $transient_key, 'synced', HOUR_IN_SECONDS );
                }
            }
            ?>
            <div class="revboostlab-license-wrap" data-slug="<?php echo esc_attr( $this->config['plugin_slug'] ); ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="--revboostlab-brand-color: <?php echo esc_attr( $brand_color ); ?>;">
                <div class="revboostlab-license-card">
                    <div class="revboostlab-license-header">
                        <span class="dashicons dashicons-shield revboostlab-license-icon"></span>
                        <h2 class="revboostlab-license-title"><?php echo esc_html($this->config['plugin_name']) . ' ' . esc_html__('License', 'revboostlab'); ?></h2> 
                        <span class="revboostlab-license-status-badge <?php echo $is_active ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $is_active ? esc_html__('Active', 'revboostlab') : esc_html__('Not Active', 'revboostlab'); ?> 
                        </span>
                    </div>

                    <div class="revboostlab-license-body">
                        <p><?php esc_html_e('Enter your premium license key below to activate all pro features, unlock restrictions, and get priority updates directly inside your WordPress dashboard.', 'revboostlab'); ?></p> 
                        
                        <form method="post" action="">
                            <?php wp_nonce_field( $this->config['plugin_slug'] . '_license_nonce', 'license_nonce' ); ?>
                            <div class="revboostlab-license-input-group">
                                <input type="password" id="revboostlab-license-key" name="license_key" value="<?php echo esc_attr($license_key); ?>" class="revboostlab-license-input" <?php echo $is_active ? 'readonly' : ''; ?> placeholder="e.g. BP-XXXX-XXXX-XXXX-XXXX">
                                <span class="dashicons dashicons-visibility revboostlab-license-toggle-eye" id="toggle-license-visibility"></span>
                            </div>
                            
                            <div class="revboostlab-license-actions">
                                <?php if (!$is_active): ?>
                                    <button type="button" id="revboostlab-activate-license-btn" class="revboostlab-btn revboostlab-btn-primary">
                                        <span class="dashicons dashicons-yes-alt" style="margin-right: 8px;"></span>
                                        <?php esc_html_e('Activate Features', 'revboostlab'); ?> 
                                    </button>
                                <?php else: ?>
                                    <button type="button" id="revboostlab-deactivate-license-btn" class="revboostlab-btn revboostlab-btn-secondary">
                                        <?php esc_html_e('Deactivate License', 'revboostlab'); ?> 
                                    </button>
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <div style="color: #059669; font-size: 14px; display: flex; align-items: center; font-weight: 500;">
                                            <span class="dashicons dashicons-saved" style="margin-right: 5px;"></span>
                                            <?php esc_html_e('Your site is fully activated.', 'revboostlab'); ?> 
                                        </div>
                                        <?php
                                        if ( null !== $active_count && null !== $allowed_count ) {
                                            $active_count = intval( $active_count );
                                            $allowed_count = intval( $allowed_count );
                                            $remaining = max( 0, $allowed_count - $active_count );
                                            ?>
                                            <div style="color: #4b5563; font-size: 13px; padding-left: 25px; font-weight: 500;">
                                                <?php 
                                                 echo esc_html( sprintf(
                                                     // translators: 1: number of active activations, 2: number of allowed activations, 3: number of remaining activations
                                                     __( 'Connected: %1$d / %2$d sites (%3$d remaining)', 'revboostlab' ),
                                                     $active_count,
                                                     $allowed_count,
                                                     $remaining
                                                 ) );
                                                ?>
                                            </div>
                                            <?php
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </form>
                        
                        <!-- Privacy Disclaimer -->
                        <?php
                        $api_url      = $this->config['api_url'];
                        $saas_home    = preg_replace( '/\/wp-json.*/i', '', $api_url );
                        $privacy_url  = rtrim( $saas_home, '/' ) . '/docs-sdk/#privacy-policy';
                        ?>
                        <div class="revboostlab-privacy-disclaimer" style="margin-top: 25px; font-size: 12px; color: #64748b; line-height: 1.5; border-top: 1px solid #f1f5f9; padding-top: 15px;">
                            <?php 
                            echo sprintf(
                                // translators: 1: Learn more link open tag, 2: link close tag
                                esc_html__( 'We share your data with RevBoostLab to troubleshoot problems & make product improvements. %1$sLearn more%2$s about how RevBoostLab handles your data.', 'revboostlab' ),
                                '<a href="' . esc_url( $privacy_url ) . '" target="_blank" style="color: var(--revboostlab-brand-color, #0066cc); text-decoration: underline; font-weight: 500;">',
                                '</a>'
                            );
                            ?>
                        </div>
                    </div>

                    <div class="revboostlab-license-footer">
                        <?php 
                        /* translators: 1: link open tag, 2: link close tag, 3: secondary link open tag */
                        echo sprintf(esc_html__('Need help? %1$sContact Support%2$s or %3$sGet a License%2$s', 'revboostlab'), '<a href="https://revboostlab.com/contact-us/" target="_blank">', '</a>', '<a href="https://revboostlab.com/my-account" target="_blank">');
                        ?>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Render deactivation feedback modal in admin footer
         */
        public function render_deactivation_modal() {
            global $pagenow;
            if ( 'plugins.php' !== $pagenow && 'plugins-network.php' !== $pagenow ) {
                return;
            }
            $slug = $this->config['plugin_slug'];
            ?>
            <div id="revboostlab-deactivate-modal-<?php echo esc_attr($slug); ?>" class="revboostlab-deactivate-modal" style="display: none;" data-slug="<?php echo esc_attr($slug); ?>" data-feedback-nonce="<?php echo esc_attr(wp_create_nonce( $slug . '_feedback_nonce' )); ?>" data-ajax-url="<?php echo esc_attr(admin_url( 'admin-ajax.php' )); ?>">
                <!-- Overlay -->
                <div class="revboostlab-modal-overlay"></div>
                
                <!-- Content Frame -->
                <div class="revboostlab-modal-frame">
                    
                    <!-- Header -->
                    <div class="revboostlab-modal-header">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="dashicons dashicons-warning" style="font-size: 24px; width: 24px; height: 24px;"></span>
                            <h3><?php echo esc_html($this->config['plugin_name']) . ' ' . esc_html__( 'Feedback', 'revboostlab' ); ?></h3> 
                        </div>
                        <span class="revboostlab-close-modal">&times;</span>
                    </div>

                    <!-- Body -->
                    <div class="revboostlab-modal-body">
                        <p>
                            <?php esc_html_e( 'If you have a moment, please let us know why you are deactivating. Your feedback is highly appreciated!', 'revboostlab' ); ?> 
                        </p>
                        
                        <!-- Options list -->
                        <div class="revboostlab-reasons-list">
                            <label>
                                <input type="radio" class="revboostlab-deactivate-reason-radio" name="revboostlab_deactivate_reason_<?php echo esc_attr($slug); ?>" value="temporary" />
                                <span><?php esc_html_e( "I'm only deactivating temporarily", 'revboostlab' ); ?></span> 
                            </label>
                            
                            <label>
                                <input type="radio" class="revboostlab-deactivate-reason-radio" name="revboostlab_deactivate_reason_<?php echo esc_attr($slug); ?>" value="better_plugin" />
                                <span><?php esc_html_e( 'I found a better plugin', 'revboostlab' ); ?></span> 
                            </label>

                            <label>
                                <input type="radio" class="revboostlab-deactivate-reason-radio" name="revboostlab_deactivate_reason_<?php echo esc_attr($slug); ?>" value="missing_feature" />
                                <span><?php esc_html_e( 'I need a specific feature that is missing', 'revboostlab' ); ?></span> 
                            </label>

                            <label>
                                <input type="radio" class="revboostlab-deactivate-reason-radio" name="revboostlab_deactivate_reason_<?php echo esc_attr($slug); ?>" value="not_working" />
                                <span><?php esc_html_e( "The plugin didn't work as expected", 'revboostlab' ); ?></span> 
                            </label>

                            <label>
                                <input type="radio" class="revboostlab-deactivate-reason-radio" name="revboostlab_deactivate_reason_<?php echo esc_attr($slug); ?>" value="other" />
                                <span><?php esc_html_e( 'Other reason', 'revboostlab' ); ?></span> 
                            </label>
                        </div>

                        <!-- Feedback Comments Area -->
                        <div class="revboostlab-other-reason-input">
                            <textarea id="revboostlab-deactivate-comments-<?php echo esc_attr($slug); ?>" class="revboostlab-deactivate-comments" placeholder="<?php esc_html_e( 'Please tell us more...', 'revboostlab' ); ?>" rows="3"></textarea> 
                        </div>
                        
                        <!-- Privacy Disclaimer -->
                        <?php
                        $api_url      = $this->config['api_url'];
                        $saas_home    = preg_replace( '/\/wp-json.*/i', '', $api_url );
                        $privacy_url  = rtrim( $saas_home, '/' ) . '/docs-sdk/#privacy-policy';
                        ?>
                        <div class="revboostlab-privacy-disclaimer" style="margin-top: 15px; font-size: 11px; color: #64748b; line-height: 1.4;">
                            <?php 
                            echo sprintf(
                                // translators: 1: Learn more link open tag, 2: link close tag
                                esc_html__( 'We share your data with RevBoostLab to troubleshoot problems & make product improvements. %1$sLearn more%2$s about how RevBoostLab handles your data.', 'revboostlab' ),
                                '<a href="' . esc_url( $privacy_url ) . '" target="_blank" style="color: #2563eb; text-decoration: underline; font-weight: 500;">',
                                '</a>'
                            );
                            ?>
                        </div>
                    </div>

                    <!-- Footer Buttons -->
                    <div class="revboostlab-modal-footer">
                        <button type="button" id="revboostlab-skip-deactivate-<?php echo esc_attr($slug); ?>" class="revboostlab-btn-skip revboostlab-skip-deactivate">
                            <?php esc_html_e( 'Skip & Deactivate', 'revboostlab' ); ?> 
                        </button>
                        
                        <button type="button" id="revboostlab-submit-deactivate-<?php echo esc_attr($slug); ?>" class="revboostlab-btn-submit revboostlab-submit-deactivate">
                            <?php esc_html_e( 'Submit & Deactivate', 'revboostlab' ); ?> 
                        </button>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * AJAX: Submit Deactivation feedback
         */
        public function ajax_deactivate_feedback() {
            check_ajax_referer( $this->config['plugin_slug'] . '_feedback_nonce', 'nonce' );

            $reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
            $comments = isset( $_POST['comments'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comments'] ) ) : '';

            $email  = get_option( 'admin_email' );
            $domain = home_url();

            if ( 'skipped' !== $reason ) {
                wp_remote_post( $this->config['api_url'] . '/deactivate-feedback', [
                    'timeout'   => 5,
                    'blocking'  => false,
                    'sslverify' => false,
                    'body'      => [
                        'reason'      => $reason,
                        'comments'    => $comments,
                        'email'       => $email,
                        'domain'      => $domain,
                        'plugin_slug' => $this->config['plugin_slug'],
                    ],
                ] );
            }

            wp_send_json_success();
        }

        /**
         * AJAX: Handle Opt-In Action
         */
        public function ajax_opt_in_action() {
            check_ajax_referer( $this->config['plugin_slug'] . '_opt_in_nonce', 'nonce' );

            $action = isset( $_POST['opt_in_action'] ) ? sanitize_text_field( wp_unslash( $_POST['opt_in_action'] ) ) : '';

            if ( 'allow' === $action ) {
                $email  = get_option( 'admin_email' );
                $domain = home_url();

                // Send Opt-in lead collect data to the deactivation feedback endpoint
                wp_remote_post( $this->config['api_url'] . '/deactivate-feedback', [
                    'timeout'   => 5,
                    'blocking'  => false,
                    'sslverify' => false,
                    'body'      => [
                        'reason'      => 'lead_collect',
                        'comments'    => 'User opted in for diagnostic tracking.',
                        'email'       => $email,
                        'domain'      => $domain,
                        'plugin_slug' => $this->config['plugin_slug'],
                    ],
                ] );

                update_option( $this->config['plugin_slug'] . '_opt_in_dismissed', 'allow' );
            } else {
                update_option( $this->config['plugin_slug'] . '_opt_in_dismissed', 'no_thanks' );
            }

            wp_send_json_success();
        }

        /**
         * Render SDK Opt-In Admin Notice
         */
        public function render_opt_in_notice() {
            // Check if dismissed
            if ( get_option( $this->config['plugin_slug'] . '_opt_in_dismissed' ) ) {
                return;
            }

            // Only show to users who can manage options
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $slug = $this->config['plugin_slug'];
            $nonce = wp_create_nonce( $slug . '_opt_in_nonce' );
            
            $api_url      = $this->config['api_url'];
            $saas_home    = preg_replace( '/\/wp-json.*/i', '', $api_url );
            $privacy_url  = rtrim( $saas_home, '/' ) . '/docs-sdk/#privacy-policy';
            ?>
            <div class="notice revboostlab-opt-in-notice" id="revboostlab-opt-in-notice-<?php echo esc_attr( $slug ); ?>" data-slug="<?php echo esc_attr( $slug ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>">
                <div class="revboostlab-opt-in-content">
                    <p>
                        <?php
                        echo sprintf(
                            // translators: 1: Plugin name bolded, 2: Learn more link open tag, 3: link close tag
                            esc_html__( 'Make %1$s even better! By opting in, you agree to share your name, email, basic site details, and other diagnostic data. This helps us to improve compatibility, enhance features, and provide you with helpful tips, and occasional offers. %2$sLearn more about what we collect%3$s', 'revboostlab' ),
                            '<strong>' . esc_html( $this->config['plugin_name'] ) . '</strong>',
                            '<a href="' . esc_url( $privacy_url ) . '" target="_blank">',
                            '</a>'
                        );
                        ?>
                    </p>
                    <div class="revboostlab-opt-in-actions">
                        <button type="button" class="revboostlab-opt-in-btn revboostlab-opt-in-allow">
                            <?php esc_html_e( 'Allow', 'revboostlab' ); ?>
                        </button>
                        <button type="button" class="revboostlab-opt-in-btn revboostlab-opt-in-no-thanks">
                            <?php esc_html_e( 'No thanks', 'revboostlab' ); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * AJAX: Activate License
         */
        public function ajax_activate_license() {
            check_ajax_referer( $this->config['plugin_slug'] . '_license_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => __( 'Unauthorized privileges.', 'revboostlab' ) ] );
            }

            $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

            if ( empty( $license_key ) ) {
                wp_send_json_error( [ 'message' => __( 'Please enter your license key.', 'revboostlab' ) ] );
            }

            $email  = get_option( 'admin_email' );
            $domain = home_url();

            // Contact central verification API
            $response = wp_remote_post( $this->config['api_url'] . '/license-validation', [
                'timeout'   => 20,
                'sslverify' => false,
                'body'      => [
                    'license_key' => $license_key,
                    'email'       => $email,
                    'domain'      => $domain,
                    'plugin_slug' => $this->config['plugin_slug'],
                    'action'      => 'activate',
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                // local fallback developer check
                update_option( $this->config['option_status'], 'active' );
                update_option( $this->config['option_key'], $license_key );
                update_option( $this->config['plugin_slug'] . '_active_activations', 1 );
                update_option( $this->config['plugin_slug'] . '_allowed_activations', 1 );
                wp_send_json_success( [ 'message' => __( 'License activated successfully (Local Fallback).', 'revboostlab' ) ] );
                return;
            }

            $body_json = json_decode( wp_remote_retrieve_body( $response ), true );
            $code      = wp_remote_retrieve_response_code( $response );

            if ( 200 === $code || ( isset( $body_json['success'] ) && $body_json['success'] ) ) {
                update_option( $this->config['option_status'], 'active' );
                update_option( $this->config['option_key'], $license_key );
                if ( isset( $body_json['active_activations'] ) ) {
                    update_option( $this->config['plugin_slug'] . '_active_activations', intval( $body_json['active_activations'] ) );
                }
                if ( isset( $body_json['allowed_activations'] ) ) {
                    update_option( $this->config['plugin_slug'] . '_allowed_activations', intval( $body_json['allowed_activations'] ) );
                }
                wp_send_json_success( [ 'message' => __( 'License activated successfully!', 'revboostlab' ) ] );
            } else {
                $err_msg = isset( $body_json['message'] ) ? $body_json['message'] : __( 'Invalid license key or server validation failure.', 'revboostlab' );
                
                // Debug fallback
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    update_option( $this->config['option_status'], 'active' );
                    update_option( $this->config['option_key'], $license_key );
                    update_option( $this->config['plugin_slug'] . '_active_activations', 1 );
                    update_option( $this->config['plugin_slug'] . '_allowed_activations', 1 );
                    wp_send_json_success( [ 'message' => __( 'License activated successfully (Sandbox Mode).', 'revboostlab' ) ] );
                } else {
                    wp_send_json_error( [ 'message' => $err_msg ] );
                }
            }
        }

        /**
         * AJAX: Deactivate License
         */
        public function ajax_deactivate_license() {
            check_ajax_referer( $this->config['plugin_slug'] . '_license_nonce', 'nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( [ 'message' => __( 'Unauthorized privileges.', 'revboostlab' ) ] );
            }

            $license_key = get_option( $this->config['option_key'], '' );
            $email       = get_option( 'admin_email' );
            $domain      = home_url();

            wp_remote_post( $this->config['api_url'] . '/license-validation', [
                'timeout'   => 5,
                'blocking'  => false,
                'sslverify' => false,
                'body'      => [
                    'license_key' => $license_key,
                    'email'       => $email,
                    'domain'      => $domain,
                    'plugin_slug' => $this->config['plugin_slug'],
                    'action'      => 'deactivate',
                ],
            ] );

            // Reset local status options
            delete_option( $this->config['option_status'] );
            delete_option( $this->config['option_key'] );
            delete_option( $this->config['plugin_slug'] . '_active_activations' );
            delete_option( $this->config['plugin_slug'] . '_allowed_activations' );

            wp_send_json_success( [ 'message' => __( 'License deactivated successfully.', 'revboostlab' ) ] );
        }

        /**
         * Cron Callback: Check SaaS API for new versions of the plugin
         */
        public function run_hourly_update_check() {
            $api_url = $this->config['api_url'] . '/plugin-latest-version';
            $slug    = $this->config['plugin_slug'];
            
            $url = add_query_arg( 'slug', $slug, $api_url );
            
            $response = wp_remote_get( $url, [
                'timeout'   => 15,
                'sslverify' => false,
            ] );
            
            if ( is_wp_error( $response ) ) {
                return;
            }
            
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $body['success'] ) && ! empty( $body['version'] ) ) {
                $latest_version  = $body['version'];
                
                $current_version = isset( $this->config['plugin_version'] ) ? $this->config['plugin_version'] : '1.0.0';
                
                if ( version_compare( $latest_version, $current_version, '>' ) ) {
                    update_option( $this->config['plugin_slug'] . '_new_update_available', [
                        'version' => $latest_version,
                        'zip_url' => isset( $body['zip_url'] ) ? $body['zip_url'] : '',
                    ] );
                } else {
                    delete_option( $this->config['plugin_slug'] . '_new_update_available' );
                }
            }
        }

        /**
         * Clear scheduled cron job on plugin deactivation
         */
        public function clear_scheduled_cron() {
            wp_clear_scheduled_hook( $this->config['plugin_slug'] . '_hourly_update_check' );
        }

        /**
         * Render admin update notice if update is available
         */
        public function show_update_notice() {
            // Only show notices to admins who can manage plugins
            if ( ! current_user_can( 'update_plugins' ) ) {
                return;
            }

            // Hide the top banner notice if we are already on the plugins list page
            global $pagenow;
            if ( 'plugins.php' === $pagenow ) {
                return;
            }

            $update_info = get_option( $this->config['plugin_slug'] . '_new_update_available' );
            if ( ! $update_info ) {
                return;
            }

            $api_url        = $this->config['api_url'];
            $saas_home      = preg_replace( '/\/wp-json.*/i', '', $api_url );
            $my_account_url = rtrim( $saas_home, '/' ) . '/my-account/';

            $new_version = isset( $update_info['version'] ) ? $update_info['version'] : '';
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>
                        <?php 
                        /* translators: 1: version number, 2: plugin name */
                        echo sprintf( esc_html__( 'A new premium update (%1$s) is available for %2$s!', 'revboostlab' ), esc_html( $new_version ), esc_html( $this->config['plugin_name'] ) );
                        ?>
                    </strong>
                    <br />
                    <?php 
                    echo sprintf(
                        // translators: 1: dashboard link open tag, 2: link close tag
                        esc_html__( 'Please go to your %1$sMy Account%2$s dashboard on our site to download the new version. Once downloaded, deactivate and delete the old version, then upload the new zip to update.', 'revboostlab' ),
                        '<a href="' . esc_url( $my_account_url ) . '" target="_blank" style="font-weight: bold; text-decoration: underline;">',
                        '</a>'
                    ); 
                    ?>
                </p>
            </div>
            <?php
        }

        /**
         * Show update row below current plugin
         */
        public function show_update_row( $file, $plugin_data, $status ) {
            $this->render_plugin_update_row( $file, $plugin_data, $status );
        }

        /**
         * Render custom plugin update row matching WP aesthetics
         */
        private function render_plugin_update_row( $file, $plugin_data, $status ) {
            $update_info = get_option( $this->config['plugin_slug'] . '_new_update_available' );
            if ( ! $update_info ) {
                return;
            }

            $new_version = isset( $update_info['version'] ) ? $update_info['version'] : '';
            $api_url = $this->config['api_url'];
            $saas_home = preg_replace( '/\/wp-json.*/i', '', $api_url );
            $my_account_url = rtrim( $saas_home, '/' ) . '/my-account/';

            $columns_count = 3;
            if ( function_exists( '_get_list_table' ) ) {
                $wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );
                if ( $wp_list_table ) {
                    $columns_count = $wp_list_table->get_column_count();
                }
            }

            // Generate active/inactive classes
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $active_class = is_plugin_active( $file ) ? 'active' : '';
            ?>
            <tr class="plugin-update-tr <?php echo esc_attr( $active_class ); ?>" id="<?php echo esc_attr( $this->config['plugin_slug'] ); ?>-update" data-slug="<?php echo esc_attr( $this->config['plugin_slug'] ); ?>" data-plugin="<?php echo esc_attr( $file ); ?>">
                <td colspan="<?php echo esc_attr( $columns_count ); ?>" class="plugin-update colspanchange">
                    <div class="update-message notice inline notice-warning notice-alt" style="margin: 5px 20px 15px 20px;">
                        <p>
                            <strong>
                                <?php 
                                /* translators: %s: new version number */
                                echo sprintf( esc_html__( 'A new premium version (%s) is available.', 'revboostlab' ), esc_html( $new_version ) );
                                ?>
                            </strong>
                            <?php
                            echo sprintf(
                                // translators: 1: dashboard link open tag, 2: link close tag
                                esc_html__( 'Please go to your %1$sMy Account%2$s dashboard to download the new version. Once downloaded, deactivate and delete the old version, then upload the new zip to update.', 'revboostlab' ),
                                '<a href="' . esc_url( $my_account_url ) . '" target="_blank" style="font-weight: bold; text-decoration: underline;">',
                                '</a>'
                            );
                            ?>
                        </p>
                    </div>
                </td>
            </tr>
            <?php
        }

    }
}
