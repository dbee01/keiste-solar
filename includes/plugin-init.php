<?php
/**
 * Keiste Solar Report - Plugin Initialization
 * Handles WordPress integration, shortcodes, and asset loading
 * Version: 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class KSRAD_Plugin {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load admin settings
        require_once KSRAD_PLUGIN_DIR . 'includes/admin-settings.php';
        
        // Register shortcode
        add_shortcode('keiste_solar_analysis', array($this, 'render_shortcode'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Register AJAX handlers
        add_action('wp_ajax_ksrad_generate_gamma_pdf', array($this, 'handle_gamma_pdf_generation'));
        add_action('wp_ajax_nopriv_ksrad_generate_gamma_pdf', array($this, 'handle_gamma_pdf_generation'));
        
        // Add activation/deactivation hooks
        register_activation_hook(KSRAD_PLUGIN_BASENAME, array($this, 'activate'));
        register_deactivation_hook(KSRAD_PLUGIN_BASENAME, array($this, 'deactivate'));
    }
    
    /**
     * Enqueue plugin assets
     */
    public function enqueue_assets() {
        // Only load on pages with the shortcode (check if we're in the loop)
        global $post;
        if (is_a($post, 'WP_Post') && !has_shortcode($post->post_content, 'keiste_solar_analysis')) {
            return;
        }
        
        // Font Awesome (Local)
        wp_enqueue_style(
            'font-awesome',
            KSRAD_PLUGIN_URL . 'assets/vendor/font-awesome.min.css',
            array(),
            '6.4.0'
        );
        
        // Bootstrap CSS (Local)
        wp_enqueue_style(
            'bootstrap',
            KSRAD_PLUGIN_URL . 'assets/vendor/bootstrap.min.css',
            array(),
            '5.1.3'
        );
        
        // Plugin stylesheet
        wp_enqueue_style(
            'keiste-solar-styles',
            KSRAD_PLUGIN_URL . 'assets/css/solar-analysis.css',
            array('bootstrap'),
            KSRAD_VERSION
        );
        
        // Chart.js (Local)
        wp_enqueue_script(
            'chartjs',
            KSRAD_PLUGIN_URL . 'assets/vendor/chart.min.js',
            array(),
            '3.9.1',
            true
        );
        
        // jsPDF for PDF generation (Local)
        wp_enqueue_script(
            'jspdf',
            KSRAD_PLUGIN_URL . 'assets/vendor/jspdf.umd.min.js',
            array(),
            '2.5.1',
            true
        );
        
        // html2canvas for PDF generation (Local)
        wp_enqueue_script(
            'html2canvas',
            KSRAD_PLUGIN_URL . 'assets/vendor/html2canvas.min.js',
            array(),
            '1.4.1',
            true
        );
        
        // Bootstrap JS (Local)
        wp_enqueue_script(
            'bootstrap-js',
            KSRAD_PLUGIN_URL . 'assets/vendor/bootstrap.bundle.min.js',
            array(),
            '5.1.3',
            true
        );
        
        // Google Maps API is loaded dynamically by solar-analysis.php JavaScript
        // to ensure proper initialization timing for the autocomplete component
        
        // Plugin JavaScript files (load in order)
        wp_enqueue_script(
            'keiste-solar-utilities',
            KSRAD_PLUGIN_URL . 'assets/js/utilities.js',
            array('jquery'),
            KSRAD_VERSION,
            true
        );
        
        wp_enqueue_script(
            'keiste-solar-charts',
            KSRAD_PLUGIN_URL . 'assets/js/charts.js',
            array('chartjs', 'keiste-solar-utilities'),
            KSRAD_VERSION,
            true
        );
        
        wp_enqueue_script(
            'keiste-solar-roi',
            KSRAD_PLUGIN_URL . 'assets/js/roi-calculator.js',
            array('keiste-solar-utilities', 'keiste-solar-charts'),
            KSRAD_VERSION,
            true
        );
        
        // Pass PHP data to JavaScript
        wp_localize_script('keiste-solar-roi', 'ksradData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ksrad_nonce'),
            'googleSolarApiKey' => ksrad_get_option('google_solar_api_key', ''),
            'reportKey' => ksrad_get_option('report_key', ''),
            'defaultElectricityRate' => ksrad_get_option('default_electricity_rate', '0.45'),
            'defaultExportRate' => ksrad_get_option('default_export_rate', '40'),
            'defaultFeedInTariff' => ksrad_get_option('default_feed_in_tariff', '0.21'),
            'defaultLoanApr' => ksrad_get_option('default_loan_apr', '5'),
            'loanTerm' => ksrad_get_option('loan_term', '7'),
            'annualPriceIncrease' => ksrad_get_option('annual_price_increase', '5'),
            'currency' => ksrad_get_option('currency', '€'),
            'country' => ksrad_get_option('country', 'Ireland'),
            'systemCostRatio' => ksrad_get_option('system_cost_ratio', '1500'),
            'seaiGrantRate' => ksrad_get_option('seai_grant_rate', '30'),
            'seaiGrantCap' => ksrad_get_option('seai_grant_cap', '162000'),
            'acaRate' => ksrad_get_option('aca_rate', '12.5'),
            'enablePdfExport' => ksrad_get_option('enable_pdf_export', true),
        ));
    }
    
    /**
     * Render shortcode
     */
    public function render_shortcode($atts = array()) {
        // Parse and sanitize attributes
        $atts = shortcode_atts(array(
            'location' => '',
            'business_name' => '',
        ), $atts, 'keiste_solar_analysis');
        
        $atts['location'] = sanitize_text_field($atts['location']);
        $atts['business_name'] = sanitize_text_field($atts['business_name']);
        
        // Start output buffering
        ob_start();
        
        // Define constant to allow solar-analysis.php to run
        define('KSRAD_RENDERING', true);
        
        // Load the main analysis file
        // Note: The original solar-analysis.php will need refactoring to work as a template
        // For now, we'll include it directly
        include KSRAD_PLUGIN_DIR . 'solar-analysis.php';
        
        return ob_get_clean();
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'google_solar_api_key' => '',
            'google_maps_api_key' => '',
            'report_key' => '',
            'logo_url' => '',
            'default_electricity_rate' => '0.45',
            'default_export_rate' => '40',
            'default_feed_in_tariff' => '0.21',
            'default_loan_apr' => '5',
            'loan_term' => '7',
            'annual_price_increase' => '5',
            'currency' => '€',
            'country' => 'Ireland',
            'system_cost_ratio' => '1500',
            'seai_grant_rate' => '30',
            'seai_grant_cap' => '162000',
            'aca_rate' => '12.5',
            'enable_pdf_export' => true,
        );
        
        add_option('ksrad_options', $default_options);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Handle Gamma PDF Generation AJAX Request
     */
    public function handle_gamma_pdf_generation() {
        error_log('=== GAMMA PDF GENERATION FUNCTION CALLED ===');
        
        // Verify nonce
        $nonce_valid = check_ajax_referer('ksrad_gamma_pdf', 'nonce', false);
        if (!$nonce_valid) {
            error_log('NONCE VERIFICATION FAILED');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Get form data
        $full_name = sanitize_text_field($_POST['fullName'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $panel_count = intval($_POST['panelCount'] ?? 0);
        $location = sanitize_text_field($_POST['location'] ?? '');
        
        // Get API credentials
        $gamma_api_key = ksrad_get_option('gamma_api_key', 'sk-gamma-9KmJzFjq38EdudoBOD0L0Ospjrj9Q4xUeaaaON5I');
        $gamma_template_id = ksrad_get_option('gamma_template_id', 'g_6h8kwcjnyzhxn9f');
        
        if (empty($gamma_api_key) || empty($gamma_template_id)) {
            wp_send_json_error('API not configured');
            return;
        }
        
        // Build prompt
        $prompt = sprintf(
            "Generate a professional solar report for %s at %s.\n\nSystem Details:\n- %d x 400W solar panels\n- Annual production: 7500 kWh\n- Contact: %s\n- Phone: %s",
            $full_name, $location, $panel_count, $email, $phone
        );
        
        // Prepare request
        $request_body = array(
            'gammaId' => $gamma_template_id,
            'prompt' => $prompt,
            'themeId' => 'default-light',
            'exportAs' => 'pdf',
            'imageOptions' => array('model' => 'imagen-4-pro', 'style' => 'Line Art'),
            'sharingOptions' => array(
                'workspaceAccess' => 'view',
                'externalAccess' => 'noAccess',
                'emailOptions' => array('recipients' => array($email), 'access' => 'comment')
            )
        );
        
        $json_body = json_encode($request_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Call API
        $response = wp_remote_post('https://public-api.gamma.app/v1.0/generations/from-template', array(
            'headers' => array('Content-Type' => 'application/json', 'X-API-KEY' => $gamma_api_key),
            'body' => $json_body,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // 200 OK and 201 Created are both success codes
        if ($response_code !== 200 && $response_code !== 201) {
            wp_send_json_error(array('message' => 'API Error: ' . $response_code, 'body' => $body));
            return;
        }
        
        $result = json_decode($body, true);
        
        // Extract the web URL from the response
        $web_url = $result['webUrl'] ?? null;
        
        // Send email to user with report link
        if (!empty($email) && !empty($web_url)) {
            $subject = 'Your Keiste Solar Report is Ready';
            $message = sprintf(
                "Hello %s,\n\n" .
                "Your personalized solar report has been generated successfully!\n\n" .
                "You can view your report here:\n%s\n\n" .
                "Report Details:\n" .
                "- Location: %s\n" .
                "- Solar Panels: %d x 400W\n\n" .
                "If you have any questions about your report, please don't hesitate to contact us.\n\n" .
                "Best regards,\n" .
                "Keiste Solar Team",
                $full_name,
                $web_url,
                $location,
                $panel_count
            );
            
            $headers = array(
                'Content-Type: text/plain; charset=UTF-8',
                'From: Keiste Solar <noreply@' . $_SERVER['HTTP_HOST'] . '>'
            );
            
            $email_sent = wp_mail($email, $subject, $message, $headers);
            
            error_log('Email sent to ' . $email . ': ' . ($email_sent ? 'SUCCESS' : 'FAILED'));
        }
        
        wp_send_json_success(array(
            'message' => 'PDF generation started successfully',
            'generation_id' => $result['generationId'] ?? null,
            'web_url' => $web_url,
            'email_sent' => isset($email_sent) ? $email_sent : false,
            'response' => $result
        ));
    }
}

// Initialize the plugin
function ksrad_init() {
    return new KSRAD_Plugin();
}

// Start the plugin
add_action('plugins_loaded', 'ksrad_init');

/**
 * Template function for direct PHP calls
 */
function ksrad_render_analysis($args = array()) {
    $plugin = ksrad_init();
    // Output is already escaped within render_shortcode method
    echo $plugin->render_shortcode($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
