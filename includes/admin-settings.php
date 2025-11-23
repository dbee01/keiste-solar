<?php
/**
 * Keiste Solar Report - Admin Settings
 * Handles WordPress admin interface for plugin settings
 * Version: 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class KSRAD_Admin {
    
    private $options;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'page_init'));
        add_filter('plugin_action_links_' . plugin_basename(KSRAD_PLUGIN_BASENAME), array($this, 'add_settings_link'));
    }
    
    /**
     * Add options page
     */
    public function add_plugin_page() {
        // Add top-level menu
        add_menu_page(
            'Keiste Solar',                     // Page title
            'Keiste Solar',                     // Menu title
            'manage_options',                   // Capability
            'keiste-solar',                     // Menu slug
            array($this, 'create_admin_page'),  // Callback
            'dashicons-lightbulb',              // Icon
            30                                   // Position
        );
        
        // Add submenu under Settings as well
        add_options_page(
            'Keiste Solar Settings',           // Page title
            'Keiste Solar',                     // Menu title
            'manage_options',                   // Capability
            'keiste-solar-settings',           // Menu slug
            array($this, 'create_admin_page')  // Callback
        );
    }
    
    /**
     * Add settings link on plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=keiste-solar') . '">' . __('Settings', 'keiste-solar') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Options page callback
     */
    public function create_admin_page() {
        $this->options = get_option('ksrad_options');
        ?>
        <div class="wrap">
            <h1>Keiste Solar Report Settings</h1>
            <p>Configure your Google API keys and default settings for the solar analysis tool.</p>

            <form method="post" action="options.php">
                <?php
                settings_fields('ksrad_option_group');
                do_settings_sections('keiste-solar-admin');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2>Usage Instructions</h2>
            <div class="card">
                <h3>Shortcode</h3>
                <p>Use this shortcode to display the solar analysis tool on any page or post:</p>
                <code>[keiste_solar_analysis]</code>
                
                <h3>PHP Template Tag</h3>
                <p>Or use this PHP code in your theme templates:</p>
                <code>&lt;?php if (function_exists('ksrad_render_analysis')) ksrad_render_analysis(); ?&gt;</code>
            </div>
            
            <hr>
            
            <h2>API Key Setup</h2>
            <div class="card">
                <h3>Google Solar API</h3>
                <ol>
                    <li>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                    <li>Create a new project or select an existing one</li>
                    <li>Enable the <strong>Solar API</strong></li>
                    <li>Create credentials (API Key)</li>
                    <li>Copy the API key and paste it above</li>
                </ol>
                
                <h3>Google Maps JavaScript API</h3>
                <ol>
                    <li>In the same Google Cloud project</li>
                    <li>Enable the <strong>Maps JavaScript API</strong></li>
                    <li>Enable the <strong>Places API</strong></li>
                    <li>Use the same API key or create a new one</li>
                </ol>
                
                <p><strong>Note:</strong> Both APIs may have usage costs. Check Google's pricing for current rates.</p>
            </div>
        </div>
        
        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin: 20px 0;
            }
            .card h3 {
                margin-top: 0;
            }
            .card code {
                background: #f0f0f1;
                padding: 10px;
                display: block;
                margin: 10px 0;
                border-radius: 3px;
            }
        </style>
        <?php
    }
    
    /**
     * Register and add settings
     */
    public function page_init() {
        register_setting(
            'ksrad_option_group',      // Option group
            'ksrad_options',            // Option name
            array($this, 'sanitize')           // Sanitize callback
        );
        
        // API Keys Section
        add_settings_section(
            'api_keys_section',                // ID
            'API Configuration',               // Title
            array($this, 'api_keys_info'),     // Callback
            'keiste-solar-admin'               // Page
        );
        
        add_settings_field(
            'google_solar_api_key',
            'Google Solar API Key',
            array($this, 'google_solar_api_key_callback'),
            'keiste-solar-admin',
            'api_keys_section'
        );
        
        add_settings_field(
            'google_maps_api_key',
            'Google Maps API Key',
            array($this, 'google_maps_api_key_callback'),
            'keiste-solar-admin',
            'api_keys_section'
        );
        
        add_settings_field(
            'report_key',
            'Report Key',
            array($this, 'report_key_callback'),
            'keiste-solar-admin',
            'api_keys_section'
        );
        
        // Default Values Section
        add_settings_section(
            'default_values_section',
            'Default Values',
            array($this, 'default_values_info'),
            'keiste-solar-admin'
        );
        
        add_settings_field(
            'default_electricity_rate',
            'Default Electricity Rate (€/kWh)',
            array($this, 'default_electricity_rate_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'default_export_rate',
            'Default Export Rate (%)',
            array($this, 'default_export_rate_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'default_feed_in_tariff',
            'Feed-in Tariff (€/kWh)',
            array($this, 'default_feed_in_tariff_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'default_loan_apr',
            'Default Loan APR (%)',
            array($this, 'default_loan_apr_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'loan_term',
            'Loan Term (Years)',
            array($this, 'loan_term_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'annual_price_increase',
            'Annual Price Increase (%)',
            array($this, 'annual_price_increase_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'currency',
            'Currency',
            array($this, 'currency_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'country',
            'Country',
            array($this, 'country_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'system_cost_ratio',
            'System Cost Ratio (€/kWp)',
            array($this, 'system_cost_ratio_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'seai_grant_rate',
            'Solar Grant Rate (%)',
            array($this, 'seai_grant_rate_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'seai_grant_cap',
            'Solar Grant Cap (€)',
            array($this, 'seai_grant_cap_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        add_settings_field(
            'aca_rate',
            'ACA Saving Allowance Rate (%)',
            array($this, 'aca_rate_callback'),
            'keiste-solar-admin',
            'default_values_section'
        );
        
        // Display Options
        add_settings_section(
            'display_options_section',
            'Display Options',
            array($this, 'display_options_info'),
            'keiste-solar-admin'
        );
        
        add_settings_field(
            'enable_pdf_export',
            'Enable PDF Export',
            array($this, 'enable_pdf_export_callback'),
            'keiste-solar-admin',
            'display_options_section'
        );
    }
    
    /**
     * Sanitize each setting field as needed
     */
    public function sanitize($input) {
        $new_input = array();
        
        // Sanitize API keys (alphanumeric, dashes, underscores only)
        if (isset($input['google_solar_api_key'])) {
            $new_input['google_solar_api_key'] = sanitize_text_field($input['google_solar_api_key']);
        }
        
        if (isset($input['google_maps_api_key'])) {
            $new_input['google_maps_api_key'] = sanitize_text_field($input['google_maps_api_key']);
        }
        
        // Validate and sanitize numeric values with range checks
        if (isset($input['default_electricity_rate'])) {
            $rate = floatval($input['default_electricity_rate']);
            $new_input['default_electricity_rate'] = ($rate >= 0 && $rate <= 10) ? $rate : 0.35;
        }
        
        if (isset($input['default_export_rate'])) {
            $rate = floatval($input['default_export_rate']);
            $new_input['default_export_rate'] = ($rate >= 0 && $rate <= 100) ? $rate : 40;
        }
        
        if (isset($input['default_feed_in_tariff'])) {
            $tariff = floatval($input['default_feed_in_tariff']);
            $new_input['default_feed_in_tariff'] = ($tariff >= 0 && $tariff <= 10) ? $tariff : 0.21;
        }
        
        if (isset($input['default_loan_apr'])) {
            $apr = floatval($input['default_loan_apr']);
            $new_input['default_loan_apr'] = ($apr >= 0 && $apr <= 100) ? $apr : 5;
        }
        
        if (isset($input['loan_term'])) {
            $term = intval($input['loan_term']);
            $new_input['loan_term'] = ($term >= 1 && $term <= 30) ? $term : 7;
        }
        
        if (isset($input['annual_price_increase'])) {
            $increase = floatval($input['annual_price_increase']);
            $new_input['annual_price_increase'] = ($increase >= 0 && $increase <= 50) ? $increase : 5;
        }
        
        if (isset($input['currency'])) {
            $allowed = array('€', '$', '£');
            $new_input['currency'] = in_array($input['currency'], $allowed) ? $input['currency'] : '€';
        }
        
        if (isset($input['country'])) {
            $allowed = array('Ireland', 'UK', 'United States', 'Canada');
            $new_input['country'] = in_array($input['country'], $allowed) ? $input['country'] : 'Ireland';
        }
        
        if (isset($input['system_cost_ratio'])) {
            $ratio = floatval($input['system_cost_ratio']);
            $new_input['system_cost_ratio'] = ($ratio >= 0 && $ratio <= 10000) ? $ratio : 1500;
        }
        
        if (isset($input['seai_grant_rate'])) {
            $rate = floatval($input['seai_grant_rate']);
            $new_input['seai_grant_rate'] = ($rate >= 0 && $rate <= 100) ? $rate : 30;
        }
        
        if (isset($input['seai_grant_cap'])) {
            $cap = floatval($input['seai_grant_cap']);
            $new_input['seai_grant_cap'] = ($cap >= 0 && $cap <= 1000000) ? $cap : 162000;
        }
        
        if (isset($input['aca_rate'])) {
            $aca = floatval($input['aca_rate']);
            $new_input['aca_rate'] = ($aca >= 0 && $aca <= 100) ? $aca : 12.5;
        }
        
        if (isset($input['report_key'])) {
            $new_input['report_key'] = sanitize_text_field($input['report_key']);
        }
        
        if (isset($input['enable_pdf_export'])) {
            $new_input['enable_pdf_export'] = (bool)$input['enable_pdf_export'];
        }
        
        return $new_input;
    }
    
    /**
     * Section info callbacks
     */
    public function api_keys_info() {
        echo '<p>Enter your Google API keys. These are required for the solar analysis tool to function.</p>';
    }
    
    public function default_values_info() {
        echo '<p>Set default values for financial calculations. Users can override these in the calculator.</p>';
    }
    
    public function display_options_info() {
        echo '<p>Configure display and feature options for the solar analysis tool.</p>';
    }
    
    /**
     * Field callbacks
     */
    public function google_solar_api_key_callback() {
        printf(
            '<input type="text" id="google_solar_api_key" name="ksrad_options[google_solar_api_key]" value="%s" class="regular-text" />',
            isset($this->options['google_solar_api_key']) ? esc_attr($this->options['google_solar_api_key']) : ''
        );
        echo '<p class="description">Required for fetching solar potential data.</p>';
    }
    
    public function google_maps_api_key_callback() {
        printf(
            '<input type="text" id="google_maps_api_key" name="ksrad_options[google_maps_api_key]" value="%s" class="regular-text" />',
            isset($this->options['google_maps_api_key']) ? esc_attr($this->options['google_maps_api_key']) : ''
        );
        echo '<p class="description">Required for location search and map display.</p>';
    }
    
    public function default_electricity_rate_callback() {
        printf(
            '<input type="number" step="0.01" id="default_electricity_rate" name="ksrad_options[default_electricity_rate]" value="%s" />',
            isset($this->options['default_electricity_rate']) ? esc_attr($this->options['default_electricity_rate']) : '0.35'
        );
        echo ' <span class="description">€/kWh (e.g., 0.35 for €0.35/kWh)</span>';
    }
    
    public function default_export_rate_callback() {
        printf(
            '<input type="number" step="1" id="default_export_rate" name="ksrad_options[default_export_rate]" value="%s" />',
            isset($this->options['default_export_rate']) ? esc_attr($this->options['default_export_rate']) : '40'
        );
        echo ' <span class="description">% (percentage of energy exported to grid)</span>';
    }
    
    public function default_feed_in_tariff_callback() {
        printf(
            '<input type="number" step="0.01" id="default_feed_in_tariff" name="ksrad_options[default_feed_in_tariff]" value="%s" />',
            isset($this->options['default_feed_in_tariff']) ? esc_attr($this->options['default_feed_in_tariff']) : '0.21'
        );
        echo ' <span class="description">€/kWh</span>';
    }
    
    public function default_loan_apr_callback() {
        printf(
            '<input type="number" step="0.1" id="default_loan_apr" name="ksrad_options[default_loan_apr]" value="%s" />',
            isset($this->options['default_loan_apr']) ? esc_attr($this->options['default_loan_apr']) : '5'
        );
        echo ' <span class="description">% (annual percentage rate)</span>';
    }
    
    public function loan_term_callback() {
        printf(
            '<input type="number" step="1" id="loan_term" name="ksrad_options[loan_term]" value="%s" />',
            isset($this->options['loan_term']) ? esc_attr($this->options['loan_term']) : '7'
        );
        echo ' <span class="description">Years (length of payback period)</span>';
    }
    
    public function annual_price_increase_callback() {
        printf(
            '<input type="number" step="0.1" id="annual_price_increase" name="ksrad_options[annual_price_increase]" value="%s" />',
            isset($this->options['annual_price_increase']) ? esc_attr($this->options['annual_price_increase']) : '5'
        );
        echo ' <span class="description">% (expected electricity price inflation)</span>';
    }
    
    public function currency_callback() {
        $current = isset($this->options['currency']) ? $this->options['currency'] : '€';
        $currencies = array('€' => 'Euro (€)', '$' => 'US Dollar ($)', '£' => 'British Pound (£)');
        echo '<select id="currency" name="ksrad_options[currency]">';
        foreach ($currencies as $symbol => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($symbol),
                selected($current, $symbol, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }
    
    public function country_callback() {
        $current = isset($this->options['country']) ? $this->options['country'] : 'Ireland';
        $countries = array('Ireland', 'UK', 'United States', 'Canada');
        echo '<select id="country" name="ksrad_options[country]">';
        foreach ($countries as $country) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($country),
                selected($current, $country, false),
                esc_html($country)
            );
        }
        echo '</select>';
    }
    
    public function system_cost_ratio_callback() {
        printf(
            '<input type="number" step="0.01" id="system_cost_ratio" name="ksrad_options[system_cost_ratio]" value="%s" />',
            isset($this->options['system_cost_ratio']) ? esc_attr($this->options['system_cost_ratio']) : '1500'
        );
        echo ' <span class="description">€/kWp (cost per kilowatt peak installed)</span>';
    }
    
    public function seai_grant_rate_callback() {
        printf(
            '<input type="number" step="0.1" id="seai_grant_rate" name="ksrad_options[seai_grant_rate]" value="%s" />',
            isset($this->options['seai_grant_rate']) ? esc_attr($this->options['seai_grant_rate']) : '30'
        );
        echo ' <span class="description">% (solar grant percentage)</span>';
    }
    
    public function seai_grant_cap_callback() {
        printf(
            '<input type="number" step="1" id="seai_grant_cap" name="ksrad_options[seai_grant_cap]" value="%s" />',
            isset($this->options['seai_grant_cap']) ? esc_attr($this->options['seai_grant_cap']) : '162000'
        );
        echo ' <span class="description">€ (maximum grant amount)</span>';
    }
    
    public function aca_rate_callback() {
        printf(
            '<input type="number" step="0.1" id="aca_rate" name="ksrad_options[aca_rate]" value="%s" />',
            isset($this->options['aca_rate']) ? esc_attr($this->options['aca_rate']) : '12.5'
        );
        echo ' <span class="description">% (ACA saving allowance rate)</span>';
    }
    
    public function report_key_callback() {
        printf(
            '<input type="text" id="report_key" name="ksrad_options[report_key]" value="%s" class="regular-text" />',
            isset($this->options['report_key']) ? esc_attr($this->options['report_key']) : ''
        );
        echo '<p class="description">Optional key for report authentication</p>';
    }
    
    public function enable_pdf_export_callback() {
        $checked = isset($this->options['enable_pdf_export']) && $this->options['enable_pdf_export'] ? 'checked' : '';
        printf(
            '<input type="checkbox" id="enable_pdf_export" name="ksrad_options[enable_pdf_export]" value="1" %s />',
            esc_attr($checked)
        );
        echo esc_html(' <span class="description">Allow users to export analysis as PDF</span>'
        );}
}

// Initialize admin class if in admin area
if (is_admin()) {
    $ksrad_admin = new KSRAD_Admin();
}

/**
 * Helper function to get plugin options
 */
function ksrad_get_option($key, $default = '') {
    $options = get_option('ksrad_options');
    return isset($options[$key]) ? $options[$key] : $default;
}
