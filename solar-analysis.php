<?php
/**
 * Plugin Name: Keiste Solar Report
 * Plugin URI: https://keiste.ie/solar-analysis
 * Description: Comprehensive solar panel analysis tool with ROI calculations, Google Solar API integration, interactive charts, and PDF report generation.
 * Version: 1.0.9
 * Author: Keiste
 * Author URI: https://keiste.ie
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: keiste-solar
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * Solar Analysis Form
 * Financial and energy analysis based on Google's Solar API data
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants (only once) with ksrad_ namespace
if (!defined('KSRAD_VERSION')) {
    define('KSRAD_VERSION', '1.0.9');
}
if (!defined('KSRAD_PLUGIN_DIR')) {
    define('KSRAD_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('KSRAD_PLUGIN_URL')) {
    define('KSRAD_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('KSRAD_PLUGIN_BASENAME')) {
    define('KSRAD_PLUGIN_BASENAME', plugin_basename(__FILE__));
}
if (!defined('KSRAD_RENDERING')) {
    define('KSRAD_RENDERING_CONST', false);
}

// GOOGLE SOLAR API URL
if (!defined('KSRAD_GOOGLE_SOLAR_API_URL')) {
    define('KSRAD_GOOGLE_SOLAR_API_URL', 'https://solar.googleapis.com/v1/buildingInsights:findClosest');
}

// Load plugin initialization (WordPress integration) - only once
if (!class_exists('KSRAD_Plugin')) {
    require_once KSRAD_PLUGIN_DIR . 'includes/plugin-init.php';
}

// Stop here if we're just activating the plugin
// The rest of the code should only run when actually rendering the shortcode
if (!defined('KSRAD_RENDERING')) {
    return;
}

// Load configuration
require_once(__DIR__ . '/solar-config.php');

// HTTP Authentication removed - access is now managed by WordPress

// Function to fetch solar data from Google Solar API
if (!function_exists('ksrad_fetch_solar_data')) {
    function ksrad_fetch_solar_data($lat, $lng)
    {
        $apiKey = ksrad_get_option('google_solar_api_key', '');
        if (empty($apiKey)) {
            throw new Exception("Google Solar API key is not configured. Please add your API key in the WordPress admin settings page.");
        }

    // Construct the URL with proper encoding
    $params = http_build_query([
        'location.latitude' => $lat,
        'location.longitude' => $lng,
        'requiredQuality' => 'MEDIUM',
        'key' => $apiKey
    ]);

    $url = KSRAD_GOOGLE_SOLAR_API_URL . '?' . $params;

    $response = wp_remote_get($url, array(
        'timeout' => 15,
        'sslverify' => true,
        'headers' => array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $apiKey,
            'Referer' => 'https://keiste.com/'
        )
    ));

    if (is_wp_error($response)) {
        $error = $response->get_error_message();
        throw new Exception(esc_html($error));
    }

    $httpCode = wp_remote_retrieve_response_code($response);
    $response = wp_remote_retrieve_body($response);

    if ($httpCode !== 200) {
        $errorResponse = json_decode($response, true);
        $errorMessage = isset($errorResponse['error']['message'])
            ? $errorResponse['error']['message']
            : "API request failed with status code: " . $httpCode;
        // If the API reports the entity was not found, return a 404 to the client
        if (isset($errorResponse['error']['code']) && intval($errorResponse['error']['code']) === 404) {
            // Send a 404 response and a friendly message
            if (!headers_sent()) {
                $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_PROTOCOL'])) : 'HTTP/1.1';
                header($protocol . ' 404 Not Found', true, 404);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<div style="font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; border: 1px solid #dc3545; border-radius: 8px; background: #fff;">';
            echo '<h2 style="color: #dc3545;">⚠️ Solar Data Not Found (404)</h2>';
            echo '<p>The Google Solar API reports that the requested entity was not found for the provided coordinates.</p>';
            if (isset($errorResponse['error']['message'])) {
                echo '<p><strong>Message:</strong> ' . esc_html($errorResponse['error']['message']) . '</p>';
            }
            echo '<p>Please verify the latitude and longitude, or try a location within Google Solar coverage.</p>';
            echo '</div>';
            exit;
        }
        throw new Exception(esc_html($errorMessage));
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Failed to parse API response: " . esc_html(json_last_error_msg()));
    }

    return $data;
    }
}

// Get coordinates from query parameters or use defaults (Dublin as default)
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters are for display only, not form submission
$ksrad_latitude = isset($_GET['lat']) ? floatval(sanitize_text_field(wp_unslash($_GET['lat']))) : 51.886656;
$ksrad_longitude = isset($_GET['lng']) ? floatval(sanitize_text_field(wp_unslash($_GET['lng']))) : -8.535580;
$ksrad_business_name = isset($_GET['business_name']) ? sanitize_text_field(wp_unslash($_GET['business_name'])) : '';

// Check if this is an AJAX request (has lat/lng parameters) or initial page load
$ksrad_isAjaxRequest = isset($_GET['lat']) && isset($_GET['lng']);
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$ksrad_solarDataAvailable = false;
$ksrad_errorMessage = null;
$ksrad_solarData = null;

// Only fetch solar data if AJAX request with coordinates
if ($ksrad_isAjaxRequest) {
    try {
        $ksrad_solarData = ksrad_fetch_solar_data($ksrad_latitude, $ksrad_longitude);
        if (!empty($ksrad_solarData)) {
            $ksrad_solarDataAvailable = true;
        } else {
            $ksrad_solarData = null;
        }
    } catch (Exception $e) {
        $ksrad_errorMessage = $e->getMessage();
        $ksrad_solarData = null;
    }
}

// Helper function to format kWh numbers
if (!function_exists('ksrad_format_kwh')) {
    function ksrad_format_kwh($kwh)
    {
        return number_format($kwh, 2);
    }
}

// Helper function to format area in m²
if (!function_exists('ksrad_format_area')) {
    function ksrad_format_area($area)
    {
        return number_format($area, 2);
    }
}

// Function to get satellite imagery from Google Maps Static API
if (!function_exists('ksrad_get_maps_static_satellite_image')) {
    function ksrad_get_maps_static_satellite_image($latitude, $longitude, $apiKey)
    {
    // Returns URL for satellite image from Google Maps Static API
    // Works globally for any building coordinates
    $zoom = 20; // Bird's-eye rooftop view (0-21, 19 is optimal for buildings)
    $size = "800x500";
    $url = "https://maps.googleapis.com/maps/api/staticmap"
        . "?center=" . urlencode("$latitude,$longitude")
        . "&zoom=$zoom"
        . "&size=$size"
        . "&maptype=satellite"
        . "&key=" . urlencode($apiKey);

    return $url;
    }
}

// Get satellite image from Google Maps Static API (only for AJAX requests)
$ksrad_mapsStaticImageUrl = '';
$ksrad_imageSource = '';
if ($ksrad_isAjaxRequest) {
    $ksrad_mapsStaticImageUrl = ksrad_get_maps_static_satellite_image($ksrad_latitude, $ksrad_longitude, ksrad_get_option('google_solar_api_key', ''));

    // Helper: perform a HEAD request to verify the Maps Static URL is reachable / authorized
    if (!function_exists('ksrad_checkUrlHead')) {
        function ksrad_checkUrlHead($url, $timeout = 6)
        {
        $response = wp_remote_head($url, array(
            'timeout' => $timeout,
            'sslverify' => true,
            'redirection' => 5
        ));
        
        if (is_wp_error($response)) {
            return 0;
        }
        
        return (int) wp_remote_retrieve_response_code($response);
        }
    }

    // Check the Maps Static response
    $ksrad_mapsResponseCode = 0;
    try {
        $ksrad_mapsResponseCode = ksrad_checkUrlHead($ksrad_mapsStaticImageUrl, 6);
    } catch (Exception $e) {
        $ksrad_mapsResponseCode = 0;
    }

    if ($ksrad_mapsResponseCode !== 200) {
        // Use an inline SVG data URI as a local fallback to avoid external DNS errors
        $ksrad_svgPlaceholder = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600">'
            . '<rect width="100%" height="100%" fill="#f5f5f5"/>'
            . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle"'
            . ' font-family="Inter, Arial, sans-serif" font-size="20" fill="#666">'
            . 'Satellite Image Unavailable'
            . '</text>'
            . '</svg>';
        $ksrad_mapsStaticImageUrl = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($ksrad_svgPlaceholder);
        $ksrad_imageSource = 'Local placeholder (inline SVG) - Maps Static API unavailable';
    } else {
        $ksrad_imageSource = 'Google Maps Satellite (Rooftop View)';
    }
}

?>
<?php
// Start output buffering to prevent header issues
ob_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solar Report: <?php echo esc_html($ksrad_business_name); ?></title>
    <!-- Main stylesheet loaded via WordPress in plugin-init.php -->

</head>

<body id="pdf-content">
    <!-- Loading Indicator -->
    <div id="ajaxLoader"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center; flex-direction: column;">
        <div style="background: white; padding: 2rem; border-radius: 8px; text-align: left;">
            <div
                style="border: 4px solid #f3f3f3; border-top: 4px solid #1B4D3E; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 1rem;">
            </div>
            <p style="color: #1B4D3E; font-weight: 600; margin: 0;">Loading solar data...</p>
        </div>
        <style>
            @keyframes spin {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(360deg);
                }
            }

            #ajaxLoader[style*="display: flex"] {
                display: flex !important;
            }
        </style>
    </div>


    <!-- ROI Modal Popup (jQuery-powered, accessible) -->
    <dialog id="roiModal">
        <form id="roiForm" method="dialog" class="roi-modal-form">
            <?php wp_nonce_field('ksrad_roi_form', 'ksrad_roi_nonce'); ?>
            <h3 class="roi-modal-title">Solar Report Download (RESIDENTIAL)</h3>
            <div class="roi-form-group text-center">
                <label>I have entered both my electricity bill and panel number choice. I am happy with my figures and I understand I can only download one report.</label>
            </div>
            <div class="roi-form-group">
                <label for="roiFullName">Full Name <span class="required">*</span></label>
                <input id="roiFullName" name="fullName" type="text" class="roi-input" required autofocus
                    placeholder="Enter your full name">
            </div>
            <div class="roi-form-group">
                <label for="roiEmail">Email <span class="required">*</span></label>
                <input id="roiEmail" name="email" type="email" class="roi-input" required
                    placeholder="Enter your email address">
            </div>
            <div class="roi-form-group">
                <label for="roiPhone">Phone <span class="optional">(optional)</span></label>
                <input id="roiPhone" name="phone" type="tel" class="roi-input" placeholder="Enter your phone number">
            </div>
            <div class="roi-form-group roi-gdpr-group">
                <input id="roiGdpr" name="gdpr" type="checkbox" required>
                <label for="roiGdpr" class="roi-gdpr-label small">I agree to the <a href="https://keiste.com/data-use-keiste-solar-report/"
                        target="_blank">Data Use Policy</a><span
                        class="required">*</span></label>
            </div>
            <div class="roi-form-group roi-disclaimer">
                <span class="roi-disclaimer-text">Disclaimer: This is an estimate, not a formal quote. Actual figures may vary.</span>
            </div>
            <menu class="roi-modal-menu">
                <button value="cancel" id="roiCancelBtn" type="button" class="roi-btn roi-btn-cancel">Cancel</button>
                <button value="submit" id="roiSubmitBtn" class="roi-btn roi-btn-submit">Download</button>
            </menu>
        </form>
    </dialog>

    <div class="container">

        <div class="text-center nopdf">
            <?php
            $ksrad_logo_url = ksrad_get_option('logo_url', '');
            if (empty($ksrad_logo_url)) {
                // Fallback to default plugin logo if no custom logo uploaded
                $ksrad_logo_url = KSRAD_PLUGIN_URL . 'assets/images/keiste-logo.png';
            }
            ?>
            <a href="https://keiste.com/">
                <img src="<?php echo esc_url($ksrad_logo_url); ?>" alt="Company Logo"
                    class="img-fluid logo-image" style="max-width: 130px; width: 100%;">
            </a>

        </div>

        <!-- Initial Page (when NOT an AJAX request) -->
        <?php if (!$ksrad_isAjaxRequest): ?>
            <div id="ajaxHeader" class="alert alert-info" role="alert"
                style="text-align: center; background: rgba(255, 215, 0, 0.1); border: 2px solid var(--accent-yellow); border-radius: 8px; padding: 2rem; margin: 2rem auto; max-width: 600px;">
                <h4 style="color: var(--primary-green); margin-bottom: 1rem; font-size: 1.3em;">🔍 Find Your Solar Potential 
                </h4>
                <h5 class="align-center">(RESIDENTIAL VERSION)</h5>
                <p style="color: var(--primary-green); margin-bottom: 0.5rem;">Use the search box below to select an
                    address.</p>
                <p style="color: var(--primary-green); font-size: 0.95rem; margin-bottom: 0;">We'll analyze solar potential
                    and show you financial projections for your building.</p>
            </div>
        <?php endif; ?>


        <div class="text-center mb-4">

            <div class="report-header" id="reportHeader">
                <h3><em><?php echo esc_html($ksrad_business_name); ?></em></h3>
            </div>

        </div>



        <div id="pacContainer" class="nopdf" style="display: none;">
            <gmp-place-autocomplete id="pac" fields="id,location,formattedAddress,displayName" style="min-width: 320px;
                placeholder: 'Search your address';
                background-color: #fff;
                color: #222;
                text-align: left;
                font-size: 16px;
                padding: 8px 12px;
                border: 1px solid #ccc;
                border-radius: 15px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <input id="pacInput" placeholder="Search your address" />
            </gmp-place-autocomplete>

        </div>

        <div class="row">
            <div class="section map-section col-md-12" id="map-section">
                <div id="map" style="height: 400px;width: 100%;"></div>
            </div>
        </div>

        <script>
            // ===== DEBUG: Self-loading Maps + Places(New) + Autocomplete → lat/lng =====
            async function initMaps() {
                // ---- Config pulled safely from PHP ----
                const KEY = <?php echo wp_json_encode(ksrad_get_option('google_solar_api_key', '')); ?>;  // your API key as a JS string
                
                // Check if API key exists
                if (!KEY || KEY === '') {
                    console.error("[boot] No Google Maps API key configured. Please add your API key in the WordPress admin settings.");
                    const pacContainer = document.getElementById("pacContainer");
                    if (pacContainer) {
                        pacContainer.innerHTML = '<div style="padding: 1rem; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; margin: 1rem 0;"><strong>⚠️ Configuration Required:</strong> Google Maps API key is not configured. Please add your API key in the plugin settings.</div>';
                    }
                    return;
                }
                
                const BUSINESS_NAME = <?php echo wp_json_encode(isset($ksrad_business_name) ? (string) $ksrad_business_name : 'Location'); ?>;
                const LAT = Number(<?php echo wp_json_encode(isset($ksrad_latitude) ? (float) $ksrad_latitude : 51.886656); ?>);
                const LNG = Number(<?php echo wp_json_encode(isset($ksrad_longitude) ? (float) $ksrad_longitude : -8.535580); ?>);
                const DEFAULT_CENTER = {
                    lat: Number.isFinite(LAT) ? LAT : 51.886656,
                    lng: Number.isFinite(LNG) ? LNG : -8.535580
                };
                
                // Country restriction from dashboard settings
                const COUNTRY_SETTING = <?php echo wp_json_encode(ksrad_get_option('country', 'Ireland')); ?>;
                const COUNTRY_CODE_MAP = {
                    'Ireland': 'ie',
                    'UK': 'gb',
                    'United States': 'us',
                    'Canada': 'ca'
                };
                const REGION_CODE = COUNTRY_CODE_MAP[COUNTRY_SETTING] || 'us';

                const mapEl = document.getElementById("map");
                const pacMount = document.getElementById("pacContainer");
                if (!mapEl || !pacMount) {
                    console.error("[boot] Missing required elements (#map or #pacContainer).");
                    return;
                }

                // ---- Load Maps JS if needed (no callback, no legacy libs) ----
                async function ensureMapsLoaded() {
                    if (window.google?.maps?.importLibrary) return true;

                    // avoid double-inserting the script
                    const already = [...document.getElementsByTagName("script")].some(s => {
                        const has = s.src && s.src.includes("maps.googleapis.com/maps/api/js");
                        return has;
                    }
                    );
                    if (!already) {
                        const s = document.createElement("script");
                        s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(KEY)}&v=weekly`;
                        s.async = true;
                        s.defer = true;
                        document.head.appendChild(s);
                    }

                    // wait for importLibrary to exist
                    const start = Date.now();
                    let attempts = 0;
                    while (!window.google?.maps?.importLibrary) {
                        attempts++;
                        if (Date.now() - start > 15000) {
                            console.error("[boot] Maps JS never ready (check key, network, CSP/referrer).");
                            return false;
                        }
                        await new Promise(r => setTimeout(r, 50));
                    }
                    return true;
                }

                if (!(await ensureMapsLoaded())) return;

                // ---- Import modern libs ----
                let GoogleMap;
                try {
                    const mapsLib = await google.maps.importLibrary("maps");
                    GoogleMap = mapsLib.Map;

                    const { PlaceAutocompleteElement } = await google.maps.importLibrary("places");
                } catch (e) {
                    console.error("[boot] Error importing libraries:", e);
                    return;
                }

                // ---- Create map with explicit Maps JavaScript API ----
                const map = new google.maps.Map(mapEl, {
                    center: DEFAULT_CENTER,
                    zoom: 18,
                    mapTypeId: "satellite",
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: false
                });

                const marker = new google.maps.Marker({
                    map,
                    position: DEFAULT_CENTER,
                    draggable: true,
                    title: BUSINESS_NAME
                });

                // expose if your other code uses them
                window.map = map;
                window.marker = marker;

                marker.addListener("dragend", (e) => {
                    const pos = e.latLng;
                    window.updateLocationViaAjax?.(pos.lat(), pos.lng(), BUSINESS_NAME);
                });
                map.addListener("click", (e) => {
                    marker.setPosition(e.latLng);

                    window.updateLocationViaAjax?.(e.latLng.lat(), e.latLng.lng(), BUSINESS_NAME);
                });



                
                // ---- Get Place Autocomplete Element reference ----
                const pac = document.getElementById('pac');
                if (!pac) {
                    console.error('[places] Could not find place autocomplete element');
                    return;
                }
                
                // Show the autocomplete container now that Maps API is ready
                if (pacMount) {
                    pacMount.style.display = 'block';
                }

                const inputEl = pac.querySelector("#pacInput");
                const geocoder = new google.maps.Geocoder();

                // Set region restriction based on dashboard country setting
                pac.includedRegionCodes = [REGION_CODE];
        
                // Add event listener for place selection using modern Places API
                pac.addEventListener('gmp-select', async ({ placePrediction }) => {

                    const place = placePrediction.toPlace();
                    
                    await place.fetchFields({
                        fields: ['displayName', 'formattedAddress', 'location', 'viewport']
                    });

                    if (place.viewport) {
                        map.fitBounds(place.viewport);
                    } else if (place.location) {
                        map.setCenter(place.location);
                        map.setZoom(17);
                    }

                    if (place.location) {
                        applyCoords(
                            {
                                lat: place.location.lat(),
                                lng: place.location.lng()
                            },
                            place.formattedAddress || place.displayName
                        );
                    } else {
                        console.error('[places] No location data in place result');
                    }
                });
                
                // Using modern Places API with gmp-select event, no alternative needed

                // helpers
                const toNums = (loc) => {
                    if (!loc) return null;
                    const lat = typeof loc.lat === "function" ? loc.lat() : loc.lat;
                    const lng = typeof loc.lng === "function" ? loc.lng() : loc.lng;
                    return (Number.isFinite(lat) && Number.isFinite(lng)) ? { lat, lng } : null;
                };
                function applyCoords(coords, label) {
                    if (!coords || typeof coords.lat !== "number" || typeof coords.lng !== "number") {
                        console.error("[location] Invalid coordinates:", coords);
                        return;
                    }


                    // Use the correct map var for your setup:
                    const ll = new google.maps.LatLng(coords.lat, coords.lng);
                    marker.setPosition(ll);
                    if (typeof map !== 'undefined' && map && typeof map.panTo === 'function') {
                        map.panTo(ll);
                    }

                    // Optional loader
                    const loader = document.getElementById("ajaxLoader");
                    if (loader) loader.style.display = "flex";

                    // Build params + redirect (no XHR)
                    const params = new URLSearchParams({
                        lat: String(coords.lat),
                        lng: String(coords.lng),
                        business_name: label || "Location",
                    });

                    const dest = `https://keiste.com/keiste-solar-report/?${params.toString()}`;
                    window.location.href = dest;
                }

                // unchanged helper
                async function resolveFromPlace(place) {
                    let nums = toNums(place?.location);
                    if (nums) return { coords: nums, label: place.formattedAddress || place.displayName || inputEl.value };
                    if (place?.fetchFields) {
                        try {
                            await place.fetchFields({ fields: ["location", "formattedAddress", "displayName"] });
                            nums = toNums(place.location);
                            if (nums) return { coords: nums, label: place.formattedAddress || place.displayName || inputEl.value };
                        } catch (_) { }
                    }
                    return null;
                }

                async function geocodeText(text) {
                    if (!text) return null;
                    try {
                        const { results } = await geocoder.geocode({ address: text });
                        const loc = results?.[0]?.geometry?.location;
                        const nums = toNums(loc);
                        return nums ? { coords: nums, label: results?.[0]?.formatted_address || text } : null;
                    } catch (e) {
                        return null;
                    }
                }

                // Add event listener for when the map is displayed
                mapEl.style.display = 'block';

                // Ensure map is properly initialized
                google.maps.event.trigger(map, 'resize');

                // user pressed Enter without selecting
                inputEl.addEventListener("keydown", async (ev) => {
                    if (ev.key !== "Enter") return;
                    ev.preventDefault();
                    const text = inputEl.value?.trim();
                    if (!text) return;
                    const resolved = await geocodeText(text);
                    if (resolved) applyCoords(resolved.coords, resolved.label);
                });
            }
            // Expose and run initializer so it can be re-run after AJAX DOM replacement
            window.initMaps = initMaps;
            initMaps();
        </script>

        <!-- Display satellite rooftop image from Google Maps Static API (AJAX ONLY) -->
        <?php if ($ksrad_isAjaxRequest): ?>
            <?php
            // Display Google Maps Static API satellite image
            $ksrad_imageSource = 'Google Maps Satellite (Rooftop View)';
            ?>

            <div class="newContainer">

                <?php if (!$ksrad_solarDataAvailable): ?>
                    <div class="alert alert-warning nopdf" role="alert"
                        style="text-align: left; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 1.5rem; margin: 2rem auto; max-width: 600px;">
                        <h4 style="color: #856404; margin-bottom: 1rem;">⚠️ Solar Data Not Available</h4>
                        <p style="color: #856404; margin-bottom: 0.5rem;">Unfortunately, Google Solar API does not have coverage
                            for this location yet.</p>
                        <p style="color: #856404; margin-bottom: 1rem;"><strong>Location:</strong>
                            <?php echo esc_html($ksrad_business_name); ?></p>
                        <p style="color: #856404; font-size: 0.9rem; margin-bottom: 0;">
                            <strong>Coordinates:</strong> <?php echo number_format($ksrad_latitude, 6); ?>,
                            <?php echo number_format($ksrad_longitude, 6); ?>
                        </p>
                        <?php if ($ksrad_errorMessage): ?>
                            <p
                                style="color: #721c24; background: #f8d7da; padding: 0.75rem; border-radius: 4px; margin-top: 1rem; font-size: 0.85rem;">
                                <strong>Error:</strong> <?php echo esc_html($ksrad_errorMessage); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <form id="solarForm" class="needs-validation mt-4" novalidate>
                        <?php wp_nonce_field('ksrad_solar_form', 'ksrad_solar_nonce'); ?>

                        <div class="section financial-form pdf-page">

                            <!-- Results Section -->
                            <div class="results-section" id="results">
                                <h4 class="text-center">Your Return on Investment (ROI)</h4>
                                <!-- END .section.site-overview -->
                                <div class="row mt-4">
                                    <div class="col-md-4 ml-auto mr-auto text-center"></div>
                                    <div class="col-md-4 ml-auto mr-auto text-center">
                                        <div class="colDirection">
                                            <div class="colFlex">
                                                <i class="fas fa-exchange-alt"></i>
                                                <div class="resultsCol" style="border-left: unset;">
                                                    <span class="highlight" id="netIncome">0</span>
                                                    <div class="ms-2 underWrite">MONTHLY INC/EXP</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 ml-auto mr-auto text-center"></div>
                                </div>
                                <div class="row mt-4">
                                    <div class="col-md-6 ml-auto mr-auto" style="border-right: 1px #ccc solid;">
                                        <div class="colDirection">
                                            <div class="colFlexRight">
                                                <i class="fas fa-euro-sign"></i>
                                                <div class="resultsCol">
                                                    <span class="highlight" id="netCost"><?php echo esc_html(ksrad_get_option('currency', '€')); ?>0</span>
                                                    <div class="ms-2 underWrite">NET INSTALL COST</div>
                                                </div>
                                            </div>
                                            <div class="colFlexRight">
                                                <i class="fas fa-clock"></i>
                                                <div class="resultsCol">
                                                    <span class="highlight" id="paybackPeriod">0 <span
                                                            style="font-size: medium;"> yrs</span></span>
                                                    <div class="ms-2 underWrite">PAYBACK PERIOD</div>
                                                </div>
                                            </div>
                                            <div class="colFlexRight">
                                                <i class="fas fa-piggy-bank"></i>
                                                <div class="resultsCol">
                                                    <span class="highlight" id="annualSavings"><?php echo esc_html(ksrad_get_option('currency', '€')); ?>0</span>
                                                    <div class="ms-2 underWrite">ANNUAL SAVINGS (YEAR 1)</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 ml-auto mr-auto">
                                        <div class="colDirection">
                                            <div class="colFlexLeft">
                                                <i class="fas fa-chart-line"></i>
                                                <div class="resultsCol">
                                                    <span class="highlight" id="totalSavings"><?php echo esc_html(ksrad_get_option('currency', '€')); ?>0</span>
                                                    <div class="ms-2 underWrite">25-YEAR SAVINGS</div>
                                                </div>
                                            </div>
                                            <div class="colFlexLeft">
                                                <i class="fas fa-percentage"></i>
                                                <div class="resultsCol">
                                                    <span class="highlight" id="roi">0%</span>
                                                    <div class="ms-2 underWrite">ROI (25 YEARS)</div>
                                                </div>
                                            </div>
                                            <div class="colFlexLeft">
                                                <i class="fas fa-leaf"></i>
                                                <div class="resultsCol">
                                                    <span class="highlight" id="co2Reduction">0</span>
                                                    <div class="ms-2 underWrite">CO₂ REDUCTION (TONNES)</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <div class="row nopdf mt-4">
                                    <div class="col-md-12 text-center mt-4">

                                        <script>
                                            // --- DYNAMIC ROI CALCULATION (reworked per provided pseudocode) ---
                                            // Normalize modal element id mismatches (legacy: roiUserModal vs current roiModal)
                                            var roiBtn = document.getElementById('roiBtn');
                                            var roiForm = document.getElementById('roiForm');
                                            var resultsSection = document.getElementById('results');

                                            window.showModal = showModal;
                                            window.hideModal = hideModal;

                                            function setCookie(name, value, days) {
                                                let expires = "";
                                                if (days) {
                                                    const date = new Date();
                                                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                                                    expires = "; expires=" + date.toUTCString();
                                                }
                                                document.cookie = name + "=" + (value || "") + expires + "; path=/";
                                            }
                                            function getCookie(name) {
                                                const nameEQ = name + "=";
                                                const ca = document.cookie.split(';');
                                                for (let i = 0; i < ca.length; i++) {
                                                    let c = ca[i];
                                                    while (c.charAt(0) == ' ') c = c.substring(1, c.length);
                                                    if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
                                                }
                                                return null;
                                            }

                                            function _getRoiModalEl() {
                                                return document.getElementById('roiUserModal') || document.getElementById('roiModal');
                                            }

                                            function showModal() {
                                                var modalEl = _getRoiModalEl();
                                                if (!modalEl) return; // nothing to show
                                                // If <dialog> is supported, use its API for better UX
                                                if (typeof modalEl.showModal === 'function') {
                                                    try { modalEl.showModal(); }
                                                    catch (e) { modalEl.setAttribute('open', ''); modalEl.style.display = 'flex'; }
                                                } else {
                                                    modalEl.style.display = 'flex';
                                                }
                                                // Keep a safe global reference (if not already set)
                                                if (typeof window.roiModal === 'undefined') window.roiModal = modalEl;
                                                if (window.roiModal && window.roiModal.classList) window.roiModal.classList.add('active');
                                                document.body.classList.add('modal-open');
                                                // Focus modal for accessibility
                                                setTimeout(function () {
                                                    var dialog = modalEl.querySelector('.modal-dialog');
                                                    if (dialog && typeof dialog.focus === 'function') dialog.focus();
                                                    var firstInput = modalEl.querySelector('input, select, textarea, button');
                                                    if (firstInput && typeof firstInput.focus === 'function') firstInput.focus();
                                                }, 10);
                                                if (typeof trapFocus === 'function') trapFocus(modalEl);
                                            }

                                            function hideModal() {
                                                var modalEl = _getRoiModalEl();
                                                if (!modalEl) return;
                                                if (typeof modalEl.close === 'function') {
                                                    try { modalEl.close(); }
                                                    catch (e) { modalEl.removeAttribute('open'); modalEl.style.display = 'none'; }
                                                } else {
                                                    modalEl.style.display = 'none';
                                                }
                                                if (window.roiModal && window.roiModal.classList) window.roiModal.classList.remove('active');
                                                document.body.classList.remove('modal-open');
                                            }

                                            // Download Report button - attach listener when DOM is ready
                                            function attachDownloadButtonListener() {
                                                const roiBtnEl = document.getElementById('roiBtn');
                                                if (roiBtnEl) {
                                                    roiBtnEl.addEventListener('click', function (e) {
                                                        e.preventDefault();
                                                        console.log('Download button clicked, showing modal...');
                                                        showModal();
                                                    });
                                                    console.log('Download button listener attached');
                                                } else {
                                                    console.error('roiBtn element not found');
                                                }
                                            }

                                            // Try to attach immediately if button exists
                                            if (roiBtn) {
                                                roiBtn.addEventListener('click', function (e) {
                                                    e.preventDefault();
                                                    showModal();
                                                });
                                            } else {
                                                // Wait for DOM to be ready and try again
                                                if (document.readyState === 'loading') {
                                                    document.addEventListener('DOMContentLoaded', attachDownloadButtonListener);
                                                } else {
                                                    // DOM already loaded, attach now
                                                    setTimeout(attachDownloadButtonListener, 100);
                                                }
                                            }

                                            // Handle form submission
                                            if (roiForm) {
                                                roiForm.addEventListener('submit', function (e) {
                                                    e.preventDefault();
                                                    // Simple validation
                                                    if (!roiForm.checkValidity()) {
                                                        roiForm.reportValidity();
                                                        return;
                                                    }
                                                    
                                                    // Get form data
                                                    const formData = {
                                                        fullName: document.getElementById('roiFullName')?.value || '',
                                                        email: document.getElementById('roiEmail')?.value || '',
                                                        phone: document.getElementById('roiPhone')?.value || ''
                                                    };
                                                    
                                                    // Show loading state in the form (keep modal open)
                                                    const formEl = document.getElementById('roiForm');
                                                    if (formEl) {
                                                        formEl.innerHTML = '<div style="padding: 2rem; text-align: center;"><div style="border: 4px solid #f3f3f3; border-top: 4px solid #1B4D3E; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 1rem;"></div><p style="color: #1B4D3E; font-weight: 600;">Generating your report...</p></div>';
                                                    }
                                                    
                                                    console.log('Generating PDF report...');
                                                    
                                                    // Call Gamma API to generate PDF
                                                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                                        method: 'POST',
                                                        headers: {
                                                            'Content-Type': 'application/x-www-form-urlencoded',
                                                        },
                                                        body: new URLSearchParams({
                                                            action: 'ksrad_generate_gamma_pdf',
                                                            nonce: '<?php echo wp_create_nonce('ksrad_gamma_pdf'); ?>',
                                                            fullName: formData.fullName,
                                                            email: formData.email,
                                                            phone: formData.phone,
                                                            // Include solar data from current calculation
                                                            panelCount: getPanelCount ? getPanelCount() : 0,
                                                            location: '<?php echo esc_js($ksrad_business_name ?? ''); ?>'
                                                        })
                                                    })
                                                    .then(response => {
                                                        console.log('=== AJAX RESPONSE ===');
                                                        console.log('Response status:', response.status);
                                                        console.log('Response ok:', response.ok);
                                                        
                                                        // Parse JSON even on error to get error details
                                                        return response.json().then(data => {
                                                            console.log('Parsed JSON data:', data);
                                                            return { response, data };
                                                        }).catch(jsonError => {
                                                            console.error('Failed to parse JSON response:', jsonError);
                                                            throw new Error('Invalid JSON response from server');
                                                        });
                                                    })
                                                    .then(({ response, data }) => {
                                                        console.log('=== PROCESSING RESPONSE ===');
                                                        console.log('Full response data:', data);
                                                        console.log('data.success:', data.success);
                                                        console.log('data.data:', data.data);
                                                        console.log('data.curl_command exists?:', !!data.curl_command);
                                                        console.log('data keys:', Object.keys(data));
                                                        
                                                        // ALWAYS LOG CURL COMMAND IF AVAILABLE
                                                        if (data.curl_command) {
                                                            console.log('%c=== COPY THIS CURL COMMAND TO TEST ===', 'background: #222; color: #bada55; font-size: 16px; font-weight: bold; padding: 10px;');
                                                            console.log(data.curl_command);
                                                            console.log('%c=== END CURL COMMAND ===', 'background: #222; color: #bada55; font-size: 16px; font-weight: bold; padding: 10px;');
                                                        }
                                                        
                                                        // Log the Gamma API call details if available
                                                        if (data.debug) {
                                                            console.log('=== GAMMA API CALL DETAILS ===');
                                                            console.log('URL:', data.debug.url);
                                                            console.log('Method:', data.debug.method);
                                                            console.log('Headers:', data.debug.headers);
                                                            console.log('Body:', data.debug.body);
                                                            console.log('=== END GAMMA API CALL ===');
                                                        }
                                                        
                                                        // Log debug info on error
                                                        if (data.data && data.data.debug) {
                                                            console.log('=== GAMMA API CALL DETAILS (FROM data.data.debug) ===');
                                                            console.log('URL:', data.data.debug.url);
                                                            console.log('Method:', data.data.debug.method);
                                                            console.log('Headers:', data.data.debug.headers);
                                                            console.log('Request Body:', JSON.stringify(data.data.debug.body, null, 2));
                                                            console.log('Response Code:', data.data.debug.response_code);
                                                            console.log('Response Body:', data.data.debug.response_body);
                                                            console.log('=== END GAMMA API CALL ===');
                                                        }
                                                        
                                                        if (data.success) {
                                                            console.log('✅ PDF generated successfully:', data.data);
                                                            
                                                            // Build success message
                                                            let successMsg = '<div style="padding: 2rem; text-align: center; color: #28a745;"><i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 1rem;"></i>';
                                                            successMsg += '<h3 style="color: #28a745; margin-bottom: 1rem;">Report Generation Started!</h3>';
                                                            
                                                            if (data.data && data.data.email_scheduled) {
                                                                successMsg += '<p style="font-size: 1.1rem; margin-bottom: 0.5rem;">📧 An email will be sent to <strong>' + formData.email + '</strong></p>';
                                                                successMsg += '<p style="color: #666; margin-bottom: 1rem;">in approximately <strong>15 minutes</strong> with a link to your personalized solar report.</p>';
                                                                successMsg += '<p style="color: #999; font-size: 0.9rem;">The Gamma AI system needs a few minutes to generate your custom report with all the calculations and visualizations.</p>';
                                                            } else {
                                                                successMsg += '<p style="color: #666; margin-top: 1rem;">Your report is being generated. Check your inbox in 15 minutes.</p>';
                                                            }
                                                            
                                                            successMsg += '</div>';
                                                            
                                                            // Update form with success message
                                                            const formEl = document.getElementById('roiForm');
                                                            if (formEl) {
                                                                formEl.innerHTML = successMsg;
                                                            }
                                                        } else {
                                                            console.error('❌ PDF generation failed');
                                                            console.error('data.success:', data.success);
                                                            console.error('data.data type:', typeof data.data);
                                                            console.error('data.data value:', data.data);
                                                            console.error('data.message:', data.message);
                                                            console.error('Full data object:', JSON.stringify(data, null, 2));
                                                            
                                                            // Log curl command if available
                                                            if (data.curl_command) {
                                                                console.log('=== CURL COMMAND TO TEST ===');
                                                                console.log(data.curl_command);
                                                                console.log('=== END CURL COMMAND ===');
                                                            }
                                                            
                                                            let errorMsg = 'Unknown error occurred';
                                                            
                                                            if (typeof data.data === 'string' && data.data) {
                                                                errorMsg = data.data;
                                                            } else if (data.data && typeof data.data === 'object') {
                                                                errorMsg = data.data.message || data.data.error || JSON.stringify(data.data);
                                                            } else if (data.message) {
                                                                errorMsg = data.message;
                                                            }
                                                            
                                                            console.error('Final error message:', errorMsg);
                                                            
                                                            // Update form with error message
                                                            const formEl = document.getElementById('roiForm');
                                                            if (formEl) {
                                                                formEl.innerHTML = '<div style="padding: 2rem; text-align: center; color: #dc3545; font-size: 1.2rem; font-weight: 600;"><i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i><br>ERROR: Contact the admin<br><small style="font-size: 0.8rem; margin-top: 1rem; display: block;">' + errorMsg + '</small></div>';
                                                            }
                                                        }
                                                    })
                                                    .catch(error => {
                                                        console.error('❌ CATCH BLOCK: Error in fetch chain:', error);
                                                        console.error('Error message:', error.message);
                                                        console.error('Error stack:', error.stack);
                                                        
                                                        // Update form with error message
                                                        const formEl = document.getElementById('roiForm');
                                                        if (formEl) {
                                                            formEl.innerHTML = '<div style="padding: 2rem; text-align: center; color: #dc3545; font-size: 1.2rem; font-weight: 600;"><i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i><br>ERROR: Contact the admin<br><small style="font-size: 0.8rem; margin-top: 1rem; display: block;">' + error.message + '</small></div>';
                                                        }
                                                    });
                                                    
                                                    // Directly trigger calculation logic
                                                    if (typeof calculateROI === 'function') {
                                                        calculateROI();
                                                    } else if (window.solarForm) {
                                                        // fallback: submit solarForm if JS calc not available
                                                        window.solarForm.requestSubmit();
                                                    }
                                                    if (resultsSection) resultsSection.style.display = 'block';
                                                });
                                            }

                                            // Wire cancel button(s) to hide the modal
                                            var roiCancel = document.getElementById('roiCancelBtn');
                                            if (roiCancel) roiCancel.addEventListener('click', function (ev) { ev.preventDefault(); hideModal(); });

                                            // Close modal on Escape key for accessibility
                                            document.addEventListener('keydown', function (ev) {
                                                if (ev.key === 'Escape' || ev.key === 'Esc') {
                                                    var m = _getRoiModalEl();
                                                    if (m && (m.hasAttribute('open') || m.style.display === 'flex')) hideModal();
                                                }
                                            });

                                            // --- GLOBAL FAILSAFE: Block any default submission of roiUserForm ---
                                            document.addEventListener('submit', function (e) {
                                                const form = e.target;
                                                if (form && form.id === 'roiUserForm') {
                                                    e.preventDefault();
                                                    console.log('Global failsafe: blocked roiUserForm submit');
                                                }
                                            }, true); // useCapture=true to catch before default

                                        </script>

                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- END .financial-form.section -->
                    
        
                        <!-- Solar Investment Analysis -->
                        <div class="solar-investment-analysis section" id="solar-investment-analysis">
                            <div class="row pdf-page">
                                <h4 class="text-center mb-4">Choose System Size</h4>
                                <div class="col-md-2 mb-4"></div>

                                <div class="col-md-8 mb-4">
                                    <?php $ksrad_maxPanels = $ksrad_solarData['solarPotential']['maxArrayPanelsCount']; ?>
                                    <div class="d-flex justify-content-between align-items-baseline mb-2">
                                        <label for="panelCount" class="form-label mb-0">Panels: <span
                                                id="panelCountValue">0</span></label>
                                        <div class="system-size">
                                            <?php
                                            // Server-side default for compact installCost (mirror logic used later)
                                            $ksrad_header_four_panel_yearly = null;
                                            if (isset($ksrad_solarData['solarPotential']['solarPanelConfigs'])) {
                                                foreach ($ksrad_solarData['solarPotential']['solarPanelConfigs'] as $ksrad_c) {
                                                    if (!empty($ksrad_c['panelsCount']) && $ksrad_c['panelsCount'] == 4) {
                                                        $ksrad_header_four_panel_yearly = $ksrad_c['yearlyEnergyDcKwh'];
                                                        break;
                                                    }
                                                }
                                            }
                                            if ($ksrad_header_four_panel_yearly) {
                                                $ksrad_header_installed_kwp = floatval($ksrad_header_four_panel_yearly) / 1000.0;
                                            } else {
                                                $ksrad_header_installed_kwp = (4 * 400) / 1000.0; // fallback 1.6 kWp
                                            }
                                            if ($ksrad_header_installed_kwp <= 100) {
                                                $ksrad_header_default_install = $ksrad_header_installed_kwp * 1500;
                                            } elseif ($ksrad_header_installed_kwp <= 250) {
                                                $ksrad_header_default_install = (100 * 1500) + (($ksrad_header_installed_kwp - 100) * 1300);
                                            } else {
                                                $ksrad_header_default_install = (100 * 1500) + (150 * 1300) + (($ksrad_header_installed_kwp - 250) * 1100);
                                            }
                                            ?>
                                            <label for="panelCount" class="form-label mb-0">Cost: <span
                                                    id="installCost"><?php echo number_format(round($ksrad_header_default_install), 0, '.', ','); ?></span>
                                                </label>
                                        </div>
                                    </div>
                                    <div class="slider-container">
                                        <input type="range" class="form-range custom-slider" id="panelCount" min="0"
                                            max="<?php echo esc_attr($ksrad_maxPanels); ?>" value="0" step="1" required>
                                        <div class="slider-labels">
                                            <span>0</span>
                                            <span><?php echo esc_html($ksrad_maxPanels); ?></span>
                                        </div>
                                    </div>
                                    <div class="input-help text-center">Drag slider to adjust number of panels (Maximum
                                        capacity: <?php echo esc_html($ksrad_maxPanels); ?> panels)</div>
                                    <style>
                                        .slider-container {
                                            position: relative;
                                            padding: 1.5rem 0 0.5rem;
                                        }
                                        #roiBtn {
                                            margin: 2rem auto;
                                            text-align: center;
                                            text-transform: uppercase;
                                            background-color: var(--primary-green);
                                            color: #ffffff;
                                            padding: 0.75rem 1.5rem;
                                            border: none;
                                            border-radius: 4px;
                                            font-size: 1rem;
                                            cursor: pointer;
                                            transition: background-color 0.3s ease; 
                                            max-width: 200px;
                                        }

                                        .custom-slider {
                                            -webkit-appearance: none;
                                            width: 100%;
                                            height: 8px;
                                            border-radius: 4px;
                                            background: var(--light-green);
                                            outline: none;
                                            margin: 1rem 0;
                                        }

                                        .custom-slider::-webkit-slider-thumb {
                                            -webkit-appearance: none;
                                            appearance: none;
                                            width: 24px;
                                            height: 24px;
                                            border-radius: 50%;
                                            background: var(--accent-yellow);
                                            cursor: pointer;
                                            border: 2px solid var(--light-yellow);
                                            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
                                            transition: all 0.3s ease;
                                        }

                                        .custom-slider::-moz-range-thumb {
                                            width: 24px;
                                            height: 24px;
                                            border-radius: 50%;
                                            background: var(--accent-yellow);
                                            cursor: pointer;
                                            border: 2px solid var(--light-yellow);
                                            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
                                            transition: all 0.3s ease;
                                        }

                                        .custom-slider::-webkit-slider-thumb:hover {
                                            background: var(--light-yellow);
                                            transform: scale(1.1);
                                        }

                                        .custom-slider::-moz-range-thumb:hover {
                                            background: var(--light-yellow);
                                            transform: scale(1.1);
                                        }

                                        .downloadLink {
                                            color: var(--primary-green);
                                            margin: 1rem auto;
                                            display: block;
                                            text-transform: uppercase;
                                            text-decoration: none;
                                        }

                                        .slider-labels {
                                            display: flex;
                                            justify-content: space-between;
                                            padding: 0 12px;
                                            margin-top: 0.5rem;
                                            color: var(--text-color);
                                            font-size: 0.85rem;
                                        }

                                        .input-help {
                                            margin-top: 0.25rem;
                                            color: var(--text-color);
                                            text-align: center;
                                            font-style: italic;
                                            font-size: 0.85rem;
                                        }

                                        #panelCountValue,
                                        #systemSizeValue {
                                            color: var(--primary-green);
                                            font-weight: 600;
                                            font-size: 1.1em;
                                        }

                                        .system-size {
                                            color: var(--text-color);
                                            font-size: 0.95rem;
                                        }
                                    </style>
                                    <div class="col-md-2 mb-4"></div>
                                </div>
                                <div class="col-md-6 mb-3" style="display: none;">
                                    <label for="systemSize" class="form-label">System Size (kW)</label>
                                    <input type="number" class="form-control" id="systemSize" step="0.1" required>
                                    <div class="input-help">Based on 400W panels</div>
                                </div>

                                <h5 class="text-center mb-4">Your Finances</h5>

                                <div class="mb-4 mt-4">
                                    <div class="row">
                                        <div class="col-md-6 mb-3 elecbill"
                                            style="text-align: right;border-right: 1px #ccc solid;padding-right: 2rem;">
                                            <div>
                                                <label for="electricityBill" class="form-label"
                                                    style="color: var(--primary-green);">Your Monthly Electricity Bill
                                                    (<?php echo esc_html(ksrad_get_option('currency', '€')); ?>)</label>
                                            </div>
                                            <input type="number" min="0" class="form-control"
                                                style="margin-right: unset;display: inline-block;text-align: right;"
                                                id="electricityBill" maxlength="12" placeholder="0" required>
                                        </div>

                                        <div class="col-md-6 mb-3 align-center grant-box" style="padding-left: 2rem;">
                                            <div>
                                                <input type="checkbox" id="inclGrant" checked required>
                                                <label for="inclGrant" class="form-label"><I>Include Solar Domestic Grant (%)</I></label>
                                            </div>
                                            <div style="display: none;">
                                                <input type="checkbox" id="inclACA" required>
                                                <label for="inclACA" class="form-label"><I>Include ACA Saving Allowance
                                                        (ACA) (%)</I></label>
                                            </div>
                                            <div>
                                                <input type="checkbox" id="inclLoan" required>
                                                <label for="inclLoan" class="form-label"><I>Loan (<?php echo esc_html(ksrad_get_option('loan_term', '7')); ?> year @ <?php echo esc_html(ksrad_get_option('default_loan_apr', '5')); ?>% APR)
                                                        (%)</I></label>
                                            </div>
                                        </div>

                                        <div class="mb-4 mt-4">
                                            <div class="row">
                                                <h6 class="col-md-12 mb-3 text-center" style="font-size: small;" >
                                                    for DOMESTIC insallations only. calculations include one solar battery and use 400W panels. 
                                                </h6>
                                            </div>
                                        </div>


                                    </div>
                                </div>

                                     <!-- Download button (triggered via JS after calculation) -->            
                                <div class="text-center mt-4">
                                    <button id="roiBtn" type="button" class="btn btn-primary" style="display: none;">DOWNLOAD<br>REPORT</button>
                                </div>

                            </div>
                        </div>  

                        <!-- Combined Chart Page: Break Even & Energy Production -->
                        <div class="pdf-page mt-5">
                            <h4 class="text-center mb-4">Financial Analysis Charts</h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="chart-container" style="position: relative; height: 300px; background: transparent;">
                                        <canvas id="breakEvenChart" style="background: transparent;"></canvas>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="chart-container" style="position: relative; height: 300px; background: transparent;">
                                        <canvas id="energyChart" style="background: transparent;"></canvas>
                                    </div>
                                </div>
                            </div>
                            
                        </div>

                        <!-- Solar Investment Analysis -->
                        <div class="section financial-form mt-5" id="installation-details">
                            <h4 class="text-center mb-4">Your Installation Details</h4>
                            <!-- System Size Section -->
                            <div class="install-details-grid">
                                <div class="install-details-row">
                                    <div class="install-details-cell">
                                        <label for="installationCost" class="form-label-left">Upfront Installation Cost
                                            (<?php echo esc_html(ksrad_get_option('currency', '€')); ?>)</label>
                                        <?php
                                        // Default to 0 panels - user will adjust slider to calculate cost
                                        $ksrad_default_install_cost = 0;
                                        ?>
                                        <div class="energy-display-left"><span id="installationCost"
                                                class="highlighted-value"><?php echo number_format(round($ksrad_default_install_cost), 0); ?></span>
                                        </div>
                                        <div class="input-help-left">Total installation cost (not including grant)</div>
                                    </div>
                                    <div class="install-details-cell install-details-border">
                                        <label for="grant" class="form-label-right">Available Grant (<?php echo esc_html(ksrad_get_option('currency', '€')); ?>)</label>
                                        <div class="energy-display-right"><span id="grant"
                                                class="highlighted-value">0</span></div>
                                        <div class="input-help-right"><?php echo esc_html(ksrad_get_option('seai_grant_rate', '%')); ?>% Grant (max <?php echo esc_html(ksrad_get_option('seai_grant_cap', '€')); ?>)</div>
                                    </div>
                                </div>
                                <div class="install-details-row">
                                    <div class="install-details-cell">
                                        <label for="panelCount" class="form-label-left">Number of Panels</label>
                                        <div class="energy-display-left"><span id="panelCount"
                                                class="highlighted-value">0</span></div>
                                        <div class="input-help-left">Total solar panels to be installed</div>
                                    </div>
                                    <div class="install-details-cell install-details-border">
                                        <label for="electricityRate" class="form-label-right">Electricity Rate (<?php echo esc_html(ksrad_get_option('currency', '€')); ?>/kWh)</label>
                                        <input type="number" class="form-control" id="electricityRate" value="<?php echo esc_attr(ksrad_get_option('default_electricity_rate', '0.45')); ?>" step="0.01"
                                            min="0" required>
                                        <div class="input-help-right">Enter your current unit cost per kWh</div>
                                    </div>
                                </div>
                                <div class="install-details-row">
                                    <div class="install-details-cell">
                                        <label for="yearlyEnergy" class="form-label-left">Annual Energy Production (KWh)</label>
                                        <div class="energy-display-left">
                                            <span id="yearlyEnergyValue" class="highlighted-value">0</span>
                                        </div>
                                        <input type="hidden" id="yearlyEnergy" value="0" required>
                                        <div class="input-help-left">Estimated from solar analysis</div>
                                    </div>
                                    <div class="install-details-cell install-details-border">
                                        <label for="exportRate" class="form-label-right">Feed-in Tariff (<?php echo esc_html(ksrad_get_option('currency', '€')); ?>/kWh)</label>
                                        <input type="number" class="form-control" id="exportRate" value="<?php echo esc_attr(ksrad_get_option('default_feed_in_tariff', '0.21')); ?>" step="0.01"
                                            min="0" required>
                                        <div class="input-help-right">Clean Export Guarantee / Feed-in tariff</div>
                                    </div>
                                </div>
                                <div class="install-details-row">
                                    <div class="install-details-cell">
                                        <label for="monthlyBill" class="form-label-left">Electricity Bill (Monthly)</label>
                                        <div class="energy-display-left"><span id="monthlyBill"
                                                class="highlighted-value">0</span></div>
                                        <div class="input-help-left">Current monthly electricity expense (<?php echo esc_html(ksrad_get_option('currency', '€')); ?>)</div>
                                    </div>
                                    <div class="install-details-cell install-details-border">
                                        <label for="annualIncrease" class="form-label-right">Annual Price Increase</label>
                                        <div class="energy-display-right"><span id="annualIncrease"
                                                class="highlighted-value"><?php echo esc_html(ksrad_get_option('annual_price_increase', '5')); ?></span></div>
                                        <div class="input-help-right">Expected electricity price inflation</div>
                                    </div>
                                </div>
                            </div>
                            <style>
                                .install-details-grid {
                                    display: flex;
                                    flex-direction: column;
                                    gap: 0.5rem;
                                    width: 100%;
                                    max-width: 700px;
                                    margin: 0 auto 0 auto;
                                }

                                .install-details-row {
                                    display: flex;
                                    flex-direction: row;
                                    align-items: stretch;
                                    width: 100%;
                                }

                                .install-details-cell {
                                    flex: 1 1 0;
                                    display: flex;
                                    flex-direction: column;
                                    align-items: center;
                                    justify-content: center;
                                    padding: 1.1rem 1rem 0.7rem 1rem;
                                }

                                .install-details-border {
                                    border-left: 1px solid #bbb;
                                }

                                @media (max-width: 900px) {
                                    .install-details-row {
                                        flex-direction: column;
                                    }

                                    .install-details-border {
                                        border-left: none;
                                        border-top: 1px solid #bbb;
                                    }

                                    /* Mobile-specific adjustments to make inputs and displays full-width */
                                    .form-control {
                                        width: 250px !important;
                                        max-width: 100% !important;
                                        margin-left: 0 !important;
                                        margin-right: 0 !important;
                                        box-sizing: border-box !important;
                                    }

                                    #electricityBill {
                                        width: 150px !important;
                                    }

                                    .energy-display-left,
                                    .energy-display-right {
                                        width: 250px !important;
                                        margin: 0.4rem 0 !important;
                                        padding: 0.5rem 0.75rem !important;
                                        box-sizing: border-box !important;
                                        text-align: center !important;
                                    }

                                    .form-label-left,
                                    .form-label-right {
                                        width: auto !important;
                                        max-width: 100% !important;
                                        text-align: center !important;
                                        display: block !important;
                                        margin-bottom: 0.35rem !important;
                                    }

                                    .input-help-left,
                                    .input-help-right {
                                        text-align: center !important;
                                        margin-bottom: 0.5rem !important;
                                    }

                                    .install-details-cell {
                                        padding: 0.6rem 0.6rem 0.4rem 0.6rem !important;
                                    }
                                }
                            </style>
                        </div>
                        <script>
                            // --- Utility functions for dynamic financial figures ---
                            // These must be defined before updateFinancialFigures is called
                            const solarConfigs = <?php echo wp_json_encode(!empty($ksrad_solarData['solarPotential']['solarPanelConfigs']) ? $ksrad_solarData['solarPotential']['solarPanelConfigs'] : []); ?>;
                            function estimateEnergyProduction(panelCount) {
                                // Find the closest configurations
                                let lowerConfig = null;
                                let upperConfig = null;
                                for (const config of solarConfigs) {
                                    if (config.panelsCount <= panelCount) {
                                        lowerConfig = config;
                                    }
                                    if (config.panelsCount >= panelCount && !upperConfig) {
                                        upperConfig = config;
                                    }
                                }
                                // If exact match found
                                if (lowerConfig && lowerConfig.panelsCount === panelCount) {
                                    return lowerConfig.yearlyEnergyDcKwh;
                                }
                                // If panel count is less than smallest configuration
                                if (!lowerConfig) {
                                    const smallestConfig = solarConfigs[0];
                                    return (panelCount / smallestConfig.panelsCount) * smallestConfig.yearlyEnergyDcKwh;
                                }
                                // If panel count is more than largest configuration
                                if (!upperConfig) {
                                    const largestConfig = solarConfigs[solarConfigs.length - 1];
                                    return (panelCount / largestConfig.panelsCount) * largestConfig.yearlyEnergyDcKwh;
                                }
                                // Interpolate between configurations
                                const panelDiff = upperConfig.panelsCount - lowerConfig.panelsCount;
                                const energyDiff = upperConfig.yearlyEnergyDcKwh - lowerConfig.yearlyEnergyDcKwh;
                                const ratio = (panelCount - lowerConfig.panelsCount) / panelDiff;
                                return lowerConfig.yearlyEnergyDcKwh + (energyDiff * ratio);
                            }
                            function calculateInstallationCost(yearlyEnergyKwh) {
                                // Convert yearly energy (kWh) to installed capacity (kWp)
                                // Using average solar irradiance: 1 kWp produces ~1000 kWh/year in Ireland
                                const installedCapacityKwp = yearlyEnergyKwh / 1000;
                                // Apply sliding scale pricing
                                let totalCost = 0;
                                if (installedCapacityKwp <= 100) {
                                    // 0-100 kWp: €1,500 per kWp
                                    totalCost = installedCapacityKwp * 1500;
                                } else if (installedCapacityKwp <= 250) {
                                    // 100-250 kWp: €1,300 per kWp
                                    // First 100 kWp at €1,500, remainder at €1,300
                                    totalCost = (100 * 1500) + ((installedCapacityKwp - 100) * 1300);
                                } else {
                                    // 250+ kWp: €1,100 per kWp
                                    // First 100 kWp at €1,500, next 150 kWp at €1,300, remainder at €1,100
                                    totalCost = (100 * 1500) + (150 * 1300) + ((installedCapacityKwp - 250) * 1100);
                                }
                                return totalCost;
                            }
                            // Currency formatting helper (JS)
                            const CURRENCY_SYMBOL = '<?php echo esc_js(ksrad_get_option('currency', '€')); ?>';
                            function formatCurrency(value, decimals = 0) {
                                // Use locale formatting and prefix with currency symbol from settings
                                const num = Number(value) || 0;
                                return CURRENCY_SYMBOL + num.toLocaleString('en-IE', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
                            }


                        </script>

                        <script>
                            (function () {
                                'use strict';

                                const SEAI_GRANT_RATE = <?php echo esc_js(ksrad_get_option('seai_grant_rate', '30') / 100); ?>;
                                const SEAI_GRANT_CAP = <?php echo esc_js(ksrad_get_option('seai_grant_cap', '162000')); ?>;
                                const ACA_RATE = <?php echo esc_js(ksrad_get_option('aca_rate', '12.5') / 100); ?>;

                window.getPanelCount = function getPanelCount() {
                    const range = document.querySelector('input[type="range"]#panelCount');
                    if (range) return parseInt(range.value, 10) || 0;
                    const disp = document.getElementById('panelCountValue') || document.getElementById('panelCountDisplay');
                    if (disp) return parseInt(disp.textContent.trim(), 10) || 0;
                    return 0;
                };
                
                function fmt(v, d = 0) {
                                    return (typeof formatCurrency === 'function') ? formatCurrency(v, d) : (CURRENCY_SYMBOL + Number(v).toLocaleString());
                                }

                                function updateCosts() {
                                    try {
                                        const panelCount = getPanelCount();
                                        // determine yearly energy using existing helper if available
                                        const yearlyEnergy = (typeof estimateEnergyProduction === 'function')
                                            ? estimateEnergyProduction(panelCount)
                                            : (parseFloat(document.getElementById('yearlyEnergy')?.value || 0) || 0);

                                        // installation cost uses existing helper if present
                                        const installCost = (typeof calculateInstallationCost === 'function')
                                            ? calculateInstallationCost(yearlyEnergy)
                                            : (yearlyEnergy / 1000) * 1500;

                                        const inclGrant = !!document.getElementById('inclGrant')?.checked;
                                        const inclACA = !!document.getElementById('inclACA')?.checked;

                                        const seaiGrant = inclGrant ? Math.min(installCost * SEAI_GRANT_RATE, SEAI_GRANT_CAP) : 0;
                                        const acaGrant = inclACA ? (installCost * ACA_RATE) : 0;
                                        const totalGrant = seaiGrant + acaGrant;

                                        const netCost = Math.max(0, installCost - totalGrant);

                                        // update DOM elements (handle duplicate IDs/duplicate blocks by updating all matches)
                                        // Update visible cost and panel count displays. Note: there are duplicate
                                        // elements with id="panelCount" in the markup (a range input and a
                                        // display span). We intentionally skip input elements when updating
                                        // textual displays so the range input is not modified here.
                                        const idsToSet = ['installCost', 'installationCost', 'netCost', 'grant', 'panelCountValue', 'panelCountDisplay', 'panelCount'];
                                        idsToSet.forEach(id => {
                                            document.querySelectorAll('#' + id).forEach(el => {
                                                if (!el) return;
                                                // Don't overwrite form inputs (the slider) when updating
                                                // the displayed panel count — only update non-inputs.
                                                if (el.tagName && el.tagName.toUpperCase() === 'INPUT') return;
                                                switch (id) {
                                                    case 'installCost':
                                                    case 'installationCost':
                                                        el.textContent = Math.round(installCost).toLocaleString();
                                                        break;
                                                    case 'netCost':
                                                        el.textContent = fmt(netCost, 0);
                                                        break;
                                                    case 'grant':
                                                        el.textContent = fmt(totalGrant, 0);
                                                        break;
                                                    case 'panelCountValue':
                                                    case 'panelCountDisplay':
                                                    case 'panelCount':
                                                        el.textContent = String(panelCount);
                                                        break;
                                                    default:
                                                        break;
                                                }
                                            });
                                        });

                                        // NOTE: netIncome / monthly charge is handled by the main calculateROI/keyFigures flow
                                        // to avoid conflicting parallel listeners. Do not set #netIncome here.

                                        // trigger charts/update hooks
                                        if (typeof updateEnergyChart === 'function') updateEnergyChart();
                                        if (typeof calculateBreakEvenDataSimple === 'function' && window.breakEvenChart) {
                                            try {
                                                const cfg = { panelsCount: panelCount, yearlyEnergyDcKwh: yearlyEnergy };
                                                const be = calculateBreakEvenDataSimple(cfg);
                                                if (be && be.savings && window.breakEvenChart && window.breakEvenChart.data && window.breakEvenChart.data.datasets && window.breakEvenChart.data.datasets[0]) {
                                                    // Create a new array reference to ensure Chart.js detects the change
                                                    window.breakEvenChart.data.datasets[0].data = [...be.savings];
                                                    window.breakEvenChart.data.datasets[0].label = `${panelCount} Panels`;
                                                    // Force chart to recalculate scales and redraw
                                                    window.breakEvenChart.options.scales.y.min = undefined;
                                                    window.breakEvenChart.options.scales.y.max = undefined;
                                                    window.breakEvenChart.update('active');
                                                }
                                            } catch (e) {
                                                console.error('Chart update error:', e);
                                            }
                                        }
                                    } catch (err) {
                                        console.error('updateCosts error', err);
                                    }
                                }

                                function attachHooks() {
                                    const range = document.querySelector('input[type="range"]#panelCount');
                                    if (range) {
                                        range.addEventListener('input', calculateROI);
                                        range.addEventListener('change', calculateROI);
                                    }
                                    ['inclGrant', 'inclACA', 'inclLoan'].forEach(id => {
                                        const el = document.getElementById(id);
                                        if (el) el.addEventListener('change', calculateROI);
                                    });
                                    ['electricityRate', 'exportRate', 'degradation', 'loanApr', 'loanTerm'].forEach(id => {
                                        const el = document.getElementById(id);
                                        if (el) el.addEventListener('input', calculateROI);
                                    });
                                    // Don't run initial calculation - keep ROI values at zero until user interacts
                                    // if (typeof calculateROI === 'function') calculateROI();
                                }

                                if (document.readyState === 'complete' || document.readyState === 'interactive') {
                                    setTimeout(attachHooks, 10);
                                } else {
                                    document.addEventListener('DOMContentLoaded', attachHooks);
                                }
                            })();
                        </script>
                </div>
                <!-- Building Overview -->


                <div class="section site-overview mt-4 pdf-page">

                    <div class="middle-column">

                        <h4 class="text-center mb-4">Building Overview</h4>

                        <style>
                            .overview-grid {
                                display: flex;
                                flex-wrap: wrap;
                                gap: 1rem;
                                justify-content: center;
                                align-items: stretch;
                                margin-bottom: 0.5rem;
                            }

                            .overview-item {
                                flex: 1 1 220px;
                                max-width: 320px;
                                background: transparent;
                                padding: 0.5rem 0.75rem;
                            }

                            .overview-item h6 {
                                margin: 0 0 0.35rem 0;
                                font-size: 0.95rem;
                                font-weight: 600;
                            }

                            .overview-item .value {
                                font-size: 1rem;
                                color: var(--text-color);
                            }

                            @media (max-width: 600px) {
                                .overview-item {
                                    flex: 1 1 100%;
                                    max-width: 100%;
                                }
                            }
                        </style>

                        <div class="overview-grid">
                            <div class="overview-item text-center">
                                <h6>Location</h6>
                                <div class="value"><?php echo esc_html($ksrad_business_name); ?></div>
                            </div>

                            <div class="overview-item text-center">
                                <h6>Coordinates</h6>
                                <div class="value"><?php echo number_format($ksrad_latitude, 6); ?>,
                                    <?php echo number_format($ksrad_longitude, 6); ?></div>
                            </div>

                            <div class="overview-item text-center">
                                <h6>Roof Orientation / Azimuth (°)</h6>
                                <div class="value">
                                    <?php
                                    $ksrad_azimuth = null;
                                    if (isset($ksrad_solarData['roofSegmentStats'][0]['azimuthDegrees'])) {
                                        $ksrad_azimuth = $ksrad_solarData['roofSegmentStats'][0]['azimuthDegrees'];
                                    } elseif (isset($ksrad_solarData['solarPotential']['roofSegmentStats'][0]['azimuthDegrees'])) {
                                        $ksrad_azimuth = $ksrad_solarData['solarPotential']['roofSegmentStats'][0]['azimuthDegrees'];
                                    }
                                    function ksrad_azimuthToCompass($azimuth)
                                    {
                                        if (!is_numeric($azimuth))
                                            return '';
                                        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW', 'N'];
                                        $normalized = fmod((float) $azimuth, 360.0);
                                        $ix = (int) round($normalized / 45.0);
                                        return $directions[$ix];
                                    }
                                    if ($ksrad_azimuth !== null) {
                                        $ksrad_azimuthVal = floatval($ksrad_azimuth);
                                        $ksrad_compass = ksrad_azimuthToCompass($ksrad_azimuthVal);
                                        echo esc_html(number_format($ksrad_azimuthVal, 2)) . '°';
                                        if ($ksrad_compass)   
                                            echo ' (' . esc_html($ksrad_compass) . ')';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="overview-item text-center">
                                <h6>Roof Pitch (°)</h6>
                                <div class="value">
                                    <?php
                                    $ksrad_pitch = null;
                                    if (isset($ksrad_solarData['roofSegmentStats'][0]['pitchDegrees'])) {
                                        $ksrad_pitch = $ksrad_solarData['roofSegmentStats'][0]['pitchDegrees'];
                                    } elseif (isset($ksrad_solarData['solarPotential']['roofSegmentStats'][0]['pitchDegrees'])) {
                                        $ksrad_pitch = $ksrad_solarData['solarPotential']['roofSegmentStats'][0]['pitchDegrees'];
                                    }
                                    echo ($ksrad_pitch !== null) ? floatval($ksrad_pitch) : 'N/A';
                                    ?>
                                </div>
                            </div>

                            <div class="overview-item text-center">
                                <h6>Solar Potential</h6>
                                <div class="value">
                                    <?php
                                    // Robustly find a yearlyEnergyDcKwh value to display.
                                    $ksrad_last_kwh = 0;
                                    // Prefer the roofSegmentStats inside solarPotential when present
                                    if (!empty($ksrad_solarData['solarPotential']['roofSegmentStats']) && is_array($ksrad_solarData['solarPotential']['roofSegmentStats'])) {
                                        $ksrad_lastSeg = end($ksrad_solarData['solarPotential']['roofSegmentStats']);
                                        if (isset($ksrad_lastSeg['yearlyEnergyDcKwh'])) {
                                            $ksrad_last_kwh = (float) $ksrad_lastSeg['yearlyEnergyDcKwh'];
                                        } elseif (isset($ksrad_lastSeg['stats']['yearlyEnergyDcKwh'])) {
                                            $ksrad_last_kwh = (float) $ksrad_lastSeg['stats']['yearlyEnergyDcKwh'];
                                        }
                                    }
                                    // Fallback: take the first solarPanelConfig yearlyEnergy if available
                                    if (empty($ksrad_last_kwh) && !empty($ksrad_solarData['solarPotential']['solarPanelConfigs']) && isset($ksrad_solarData['solarPotential']['solarPanelConfigs'][0]['yearlyEnergyDcKwh'])) {
                                        $ksrad_last_kwh = (float) $ksrad_solarData['solarPotential']['solarPanelConfigs'][0]['yearlyEnergyDcKwh'];
                                    }
                                    if (!empty($ksrad_last_kwh) && $ksrad_last_kwh > 0) {
                                        echo esc_html(ksrad_format_kwh($ksrad_last_kwh));
                                    } else {
                                        echo esc_html('N/A');
                                    }
                                    ?>
                                </div>

                            <?php if (isset($ksrad_solarData['solarPotential']['maxArrayPanelsCount'])): ?>
                                <div class="overview-item text-center">
                                    <h6>Max Panel Capacity</h6>
                                    <div class="value"><?php echo esc_html($ksrad_solarData['solarPotential']['maxArrayPanelsCount']); ?> panels
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($ksrad_solarData['solarPotential']['maxArrayAreaMeters2'])): ?>
                                <div class="overview-item text-center">
                                    <h6>Max Array Area</h6>
                                    <div class="value">
                                        <?php echo esc_html(ksrad_format_area($ksrad_solarData['solarPotential']['maxArrayAreaMeters2'])); ?> m²</div>
                                </div>
                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <!-- Solar Panel Configurations -->
                <div class="section nopdf mt-5">
                    <h2 class="text-center mb-3">
                        <button class="btn btn-link" type="button" data-bs-toggle="collapse"
                            data-bs-target="#configurationsCollapse" aria-expanded="false"
                            aria-controls="configurationsCollapse"
                            style="text-decoration: none; color: #fff; padding: 20px 40px;">
                            <large>Recommended Solar Panel Configurations</large>
                            <small>(click to open)</small>
                        </button>
                    </h2>
                    <div class="collapse" id="configurationsCollapse">
                        <?php foreach ($ksrad_solarData['solarPotential']['solarPanelConfigs'] as $ksrad_index => $ksrad_config): ?>
                            <div class="panel-config">
                                <h3>Configuration <?php echo esc_html($ksrad_index + 1); ?></h3>
                                <div class="data-row">
                                    <span class="data-label">Number of Panels:</span>
                                    <span><?php echo esc_html($ksrad_config['panelsCount']); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Annual Energy Production:</span>
                                    <span class="display-grant">
                                        <?php
                                        // Always show the 4-panel config value as placeholder
                                        $ksrad_fourPanelConfig2 = null;
                                        foreach ($ksrad_solarData['solarPotential']['solarPanelConfigs'] as $ksrad_c) {
                                            if ($ksrad_c['panelsCount'] == 4) {
                                                $ksrad_fourPanelConfig2 = $ksrad_c;
                                                break;
                                            }
                                        }
                                        if ($ksrad_fourPanelConfig2) {
                                            echo esc_html(ksrad_format_kwh($ksrad_fourPanelConfig2['yearlyEnergyDcKwh']));
                                        } else {
                                            echo esc_html(ksrad_format_kwh($ksrad_config['yearlyEnergyDcKwh']));
                                        }
                                        ?> kWh
                                    </span>
                                </div>

                                <!-- Configuration Details Table -->
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Roof Segment</th>
                                            <th>Panels</th>
                                            <th>Pitch (°)</th>
                                            <th>Azimuth (°)</th>
                                            <th>Energy (kWh/year)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ksrad_config['roofSegmentSummaries'] as $ksrad_segment): ?>
                                            <tr>
                                                <td><?php echo esc_html($ksrad_segment['segmentIndex'] + 1); ?></td>
                                                <td><?php echo esc_html($ksrad_segment['panelsCount']); ?></td>
                                                <td><?php echo esc_html(number_format($ksrad_segment['pitchDegrees'], 1)); ?>°</td>
                                                <td><?php echo esc_html(number_format($ksrad_segment['azimuthDegrees'], 1)); ?>°</td>
                                                <td><?php echo esc_html(ksrad_format_kwh($ksrad_segment['yearlyEnergyDcKwh'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($ksrad_solarDataAvailable): ?>
        <script>
            // Wait for Chart.js to be loaded before initializing charts
            (function() {
                if (window.ksradChartsInitialized) return; // Prevent duplicate initialization
                
                console.log('Starting chart initialization check, Chart available:', typeof Chart);
                
                function initializeChartsWhenReady() {
                    if (typeof Chart === 'undefined') {
                        console.log('Chart.js not yet loaded, waiting...');
                        setTimeout(initializeChartsWhenReady, 50);
                        return;
                    }
                    console.log('Chart.js loaded! Version:', Chart.version);
                    initializeCharts();
                }
                
                function initializeCharts() {
                    console.log('initializeCharts called', {
                        alreadyInit: window.ksradChartsInitialized,
                        hasBreakEvenChart: !!window.breakEvenChart,
                        hasEnergyChart: !!window.energyChart
                    });
                    
                    if (window.ksradChartsInitialized) {
                        console.log('Charts already initialized, skipping');
                        return;
                    }
                    window.ksradChartsInitialized = true;
                    
                    // Check if charts already exist (from external js files)
                    if (window.breakEvenChart || window.energyChart) {
                        console.log('Charts already exist externally, skipping');
                        return;
                    }
                    
            console.log('Starting chart creation...');
            // --- Break Even Chart ---
            // Make this function globally accessible so updateBreakEvenChart can use it
            window.calculateBreakEvenDataSimple = function calculateBreakEvenDataSimple(config) {
                const yearlyEnergy = config.yearlyEnergyDcKwh;
                const panelCount = config.panelsCount;
                const electricityRate = parseFloat(document.getElementById('electricityRate')?.value) || 0.45;
                // exportRate in the UI is expressed as a percent (e.g. "40" for 40%).
                // Normalize to a fraction here so calling code can assume 0..1.
                const exportRate = (() => {
                    const v = document.getElementById('exportRate')?.value;
                    const p = parseFloat(String(v || '').replace(/[^0-9.\-]/g, ''));
                    return Number.isFinite(p) ? (p / 100) : 0.4;
                })();
                const inclGrant = document.getElementById('inclGrant')?.checked;
                const inclACA = document.getElementById('inclACA')?.checked;
                const inclLoan = document.getElementById('inclLoan')?.checked;
                const degradation = 0.005;
                // Compute installation cost from yearlyEnergy using sliding-scale per kWp
                const installedCapacityKwp = yearlyEnergy / 1000;
                let computedInstallCost = 0;
                if (installedCapacityKwp <= 100) {
                    computedInstallCost = installedCapacityKwp * 1500;
                } else if (installedCapacityKwp <= 250) {
                    computedInstallCost = (100 * 1500) + ((installedCapacityKwp - 100) * 1300);
                } else {
                    computedInstallCost = (100 * 1500) + (150 * 1300) + ((installedCapacityKwp - 250) * 1100);
                }
                const seaiGrant = inclGrant ? Math.min(computedInstallCost * 0.3, 162000) : 0;
                const acaGrant = inclACA ? (computedInstallCost * 0.125) : 0;
                const totalGrant = seaiGrant + acaGrant;
                const totalCost = computedInstallCost - totalGrant;
                const years = Array.from({ length: 25 }, (_, i) => i); // 0 to 24 years (25-year horizon)
                const savings = years.map(year => {
                    const yearDegradation = Math.pow(1 - degradation, year);
                    const yearlyEnergyProduction = yearlyEnergy * yearDegradation;
                    const selfConsumedEnergy = yearlyEnergyProduction * (1 - exportRate);
                    const exportedEnergy = yearlyEnergyProduction * exportRate;
                    const yearlySaving = (selfConsumedEnergy * electricityRate) + (exportedEnergy * exportRate);
                    const totalSaving = year === 0 ? 0 : years
                        .slice(1, year + 1)
                        .reduce((acc, y) => {
                            const yDegradation = Math.pow(1 - degradation, y);
                            const yEnergyProduction = yearlyEnergy * yDegradation;
                            const ySelfConsumed = yEnergyProduction * (1 - exportRate);
                            const yExported = yEnergyProduction * exportRate;
                            return acc + (ySelfConsumed * electricityRate) + (yExported * exportRate);
                        }, 0);
                    return totalSaving - totalCost;
                });
                return { cost: totalCost, savings: savings, breakEvenYear: savings.findIndex(saving => saving >= 0) };
            };
            try {
            const breakEvenCanvas = document.getElementById('breakEvenChart');
            if (!breakEvenCanvas) {
                console.error('Break Even Chart canvas not found');
                return;
            }
            console.log('Initializing Break Even Chart...', breakEvenCanvas);
            const breakEvenCtx = breakEvenCanvas.getContext('2d');
            // Create global chart instance so it can be updated later
            const configurations = <?php echo wp_json_encode($ksrad_solarData['solarPotential']['solarPanelConfigs']); ?>;
            console.log('Solar configurations:', configurations);
            const config = configurations[0]; // initial config for first chart render
            // Initialize with zero data - actual data will populate on user interaction
            const data = { cost: 0, savings: Array(25).fill(0), breakEvenYear: -1 };
            console.log('Creating Chart.js instance with data:', data);
            window.breakEvenChart = new Chart(breakEvenCtx, {
                type: 'line',
                data: {
                    labels: Array.from({ length: 25 }, (_, i) => `Year ${i}`),
                    datasets: [{
                        label: `0 Panels`,
                        data: data.savings,
                        borderColor: 'rgba(42, 157, 143, 1)',
                        backgroundColor: 'rgba(42, 157, 143, 0.08)',
                        fill: false,
                        tension: 0.3,
                        pointRadius: window.innerWidth > 600 ? 2 : 0,
                        pointHoverRadius: 4,
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: window.innerWidth > 600,
                            text: window.innerWidth > 600 ? 'Investment Return Over Time' : '',
                            font: { size: window.innerWidth > 600 ? 16 : 12, family: "'Inter', sans-serif", weight: '600' }
                        },
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const value = context.raw;
                                    return value >= 0
                                        ? `Profit: ${formatCurrency(value, 0)}`
                                        : `Investment: ${formatCurrency(Math.abs(value), 0)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            title: {
                                display: window.innerWidth > 600,
                                text: window.innerWidth > 600 ? 'Net Financial Position (' + CURRENCY_SYMBOL + ')' : '',
                                font: { weight: '500', size: window.innerWidth > 600 ? 14 : 11 }
                            },
                            ticks: {
                                callback: function (value) {
                                    return CURRENCY_SYMBOL + value.toLocaleString('en-IE', { maximumFractionDigits: 0 });
                                },
                                font: { size: window.innerWidth > 600 ? 13 : 10 }
                            },
                            grid: { display: window.innerWidth > 600 }
                        },
                        x: {
                            title: {
                                display: window.innerWidth > 600,
                                text: window.innerWidth > 600 ? 'Years' : '',
                                font: { weight: '500', size: window.innerWidth > 600 ? 14 : 11 }
                            },
                            ticks: { font: { size: window.innerWidth > 600 ? 13 : 10 }, autoSkip: true, maxTicksLimit: window.innerWidth > 600 ? 12 : 6 },
                            grid: { display: window.innerWidth > 600 }
                        }
                    }
                }
            });
            console.log('Break Even Chart created successfully!', window.breakEvenChart);
            } catch (error) {
                console.error('Error creating Break Even Chart:', error);
            }
            
            // --- Energy Production Chart ---
            try {
            const ctx = document.getElementById('energyChart').getContext('2d');
            // Energy production over time for the currently selected panel count
            window.energyChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: Array.from({ length: 25 }, (_, i) => `Year ${i}`),
                    datasets: [{
                        label: 'Projected Annual Energy Production (kWh)',
                        data: Array.from({ length: 25 }, () => 0),
                        backgroundColor: 'rgba(40, 167, 69, 0.6)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Annual Energy Production (kWh)'
                            }
                        },
                        x: {
                            ticks: { autoSkip: true, maxTicksLimit: window.innerWidth > 600 ? 12 : 6 }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Projected Energy Production (per year)'
                        }
                    }
                }
            });
            console.log('Energy Chart created successfully!', window.energyChart);
            } catch (error) {
                console.error('Error creating Energy Chart:', error);
            }

            // updater: recompute the energyChart dataset using the current slider value
            window.updateEnergyChart = function () {
                try {
                    if (!window.energyChart) return;
                    const panelCount = parseInt(document.getElementById('panelCount')?.value || 0);
                    const yearlyEnergy = estimateEnergyProduction(panelCount);
                    const degradation = parseFloat(document.getElementById('degradation')?.value) / 100 || 0.005; // default 0.5% -> 0.005
                    const years = Array.from({ length: 25 }, (_, i) => i);
                    const data = years.map(year => {
                        return yearlyEnergy * Math.pow(1 - degradation, year);
                    });
                    window.energyChart.data.datasets[0].data = data;
                    window.energyChart.data.datasets[0].label = `${panelCount} panels — Annual Production (kWh)`;
                    window.energyChart.update();
                } catch (e) {
                    console.error('updateEnergyChart error', e);
                }
            };
            
            // After charts are initialized, trigger calculateROI to populate them with initial data
            console.log('Charts initialized, triggering initial calculateROI');
            if (typeof window.calculateROI === 'function') {
                setTimeout(() => window.calculateROI(), 100);
            }
            } // end initializeCharts
            
            // Start checking for Chart.js availability after DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initializeChartsWhenReady);
            } else {
                initializeChartsWhenReady();
            }
            })(); // end IIFE to prevent duplicate initialization
        </script>
    <?php endif; ?>

        <script>
        (() => {
            'use strict';

            // ========= 1) CONSTANTS & DEFAULTS =========
            // Calendar
            const DAYS_IN_YR = 365.4;
            const MONTHS_IN_YR = 12.3;
            const HOURS_IN_DAY = 24;

            // Financial
            const CORPORATION_TAX = 0.125;        // 12.5%
            const CURRENCY_SYMBOL = '<?php echo esc_js(ksrad_get_option('currency', '€')); ?>';  // Currency from admin settings
            const SEAI_GRANT_RATE = <?php echo esc_js(ksrad_get_option('seai_grant_rate', '30') / 100); ?>;         // Grant rate from admin settings
            const SEAI_GRANT_CAP = <?php echo esc_js(ksrad_get_option('seai_grant_cap', '162000')); ?>;        // Grant cap from admin settings
            const LOAN_APR_DEFAULT = 0.07;        // 7% APR
            const FEED_IN_TARIFF_DEFAULT = 0.21;  // €/kWh
            const COMPOUND_7_YRS = 1.07;          // coefficient @5% over 7years
            const ANNUAL_INCREASE = 0.05;         // 5% bill increase
            const SOLAR_PANEL_DEGRADATION = 0.005;// 0.5%/yr
            const LENGTH_OF_PAYBACK = 7;          // loan length, years (used when needed)

            // Energy
            const PANEL_POWER_W = 400;            // W per panel (kWp = panels*0.4)
            const YRS_OF_SYSTEM = 25;
            // CO2 coefficient: tonnes CO2 avoided per kWh generated.
            // Typical grid factor ~400 g CO2/kWh => 0.0004 tonnes/kWh
            const CO2_COEFFICIENT_TONNES = 0.0004;  // tonnes per kWh
            const DAY_POWER_AVG = 1.85;           // kWh/day per 400W panel on average in Ireland

            // UI defaults
            const DEFAULT_PANELS = 0;
            const DEFAULT_EXPORT_PERCENT = 0.4;   // 40% of production exported (assumption if no slider)
            const DEFAULT_RETAIL_RATE = 0.35;     // €/kWh – sensible default if blank
            const DEFAULT_FEED_IN_TARIFF = FEED_IN_TARIFF_DEFAULT;
            const DEFAULT_APR = LOAN_APR_DEFAULT;

            // ========= 2) HELPERS =========
            const byId = id => document.getElementById(id);
            const num = v => {
                if (v === null || v === undefined) return 0;
                const n = typeof v === 'number' ? v : parseFloat(String(v).replace(/[^\d.\-]/g, ''));
                return Number.isFinite(n) ? n : 0;
            };
            const clamp = (x, lo, hi) => Math.min(Math.max(x, lo), hi);

            function fmtEuro(x) {
                try { return CURRENCY_SYMBOL + Math.round(x).toLocaleString('en-IE'); }
                catch { return `${CURRENCY_SYMBOL}${Math.round(x).toLocaleString('en-IE')}`; }
            }
            function fmtNum(x, digits = 2) {
                return Number(x || 0).toLocaleString('en-IE', { maximumFractionDigits: digits });
            }

            // parse solar panel configurations from the DOM tables and normalize fields
            const parsedSolarConfigs = [];
            const parseNumber = (s) => {
                if (s === null || s === undefined) return 0;
                const str = String(s).trim();
                if (!str) return 0;
                // Remove non-numeric except dot and minus, but also handle comma as thousands sep
                const cleaned = str.replace(/,/g, '').replace(/[^0-9.\-]/g, '');
                const n = parseFloat(cleaned);
                return Number.isFinite(n) ? n : 0;
            };

            Array.from(document.getElementsByClassName('table-striped')).forEach(table => {
                try {
                    const cfg = { panelsCount: 0, yearlyEnergyDcKwh: 0 };
                    const tbody = table.querySelector('tbody');
                    if (!tbody) return;
                    const rows = tbody.querySelectorAll('tr');
                    rows.forEach(row => {
                        const cells = row.querySelectorAll('td');
                        if (cells.length < 2) return;
                        const key = cells[0].innerText.trim().toLowerCase();
                        const value = cells[1].innerText.trim();
                        if (key.includes('panel')) {
                            cfg.panelsCount = parseInt(parseNumber(value)) || cfg.panelsCount;
                        } else if (key.includes('energy') || key.includes('annual')) {
                            cfg.yearlyEnergyDcKwh = parseNumber(value) || cfg.yearlyEnergyDcKwh;
                        } else if (key.includes('kwh')) {
                            cfg.yearlyEnergyDcKwh = parseNumber(value) || cfg.yearlyEnergyDcKwh;
                        } else {
                            // store raw fallback for any other key
                            cfg[key] = value;
                        }
                    });
                    // only push cfgs that have at least a panels count or energy estimate
                    if ((cfg.panelsCount && cfg.panelsCount > 0) || (cfg.yearlyEnergyDcKwh && cfg.yearlyEnergyDcKwh > 0)) {
                        parsedSolarConfigs.push(cfg);
                    }
                } catch (e) {
                    // ignore malformed tables
                }
            });

            // expose parsed configs for debugging / fallback
            window.__parsedSolarConfigs = parsedSolarConfigs;



            // ========= 3) COST MODEL =========
            /**
             * Estimate total installed cost for solar system in Ireland.
             * @param {number} panels - number of solar panels (each assumed 400 W)
             * @param {number} batteryKWh - optional battery size in kWh
             * @param {boolean} includeDiverter - whether to include a diverter (€550)
             * @returns {number} total cost (€)
             */
            function estimateSolarCost(panels, batteryKWh = 0, includeDiverter = true) {
                const panelWatt = 400;              // average panel size
                const costPerKwP = 1200;            // €/kWp installed
                const batteryCostPerKWh = 500;      // €/kWh installed
                const diverterCost = 550;           // one-off
                const systemKwP = (panels * panelWatt) / 1000;
                const panelCost = systemKwP * costPerKwP;
                const batteryCost = batteryKWh * batteryCostPerKWh;
                const diverter = includeDiverter ? diverterCost : 0;
                return panelCost + batteryCost + diverter;
            }

            // ========= 4) CORE CALCULATIONS =========
            function readInputs() {
                // sliders / checkboxes
                const inclGrant = !!(byId('inclGrant')?.checked);
                const inclACA = !!(byId('inclACA')?.checked);
                const inclLoan = !!(byId('inclLoan')?.checked);

                const panelCountEl = byId('panelCount');
                const panels = panelCountEl ? clamp(num(panelCountEl.value), 0, 10000) : DEFAULT_PANELS;

                // key text inputs
                const exportRate = (() => {
                    const val = byId('exportRate')?.value;
                    const p = num(val) / 100; // assume percent in UI
                    return Number.isFinite(p) && p > 0 ? clamp(p, 0, 1) : DEFAULT_EXPORT_PERCENT;
                })();

                const electricityRate = (() => {
                    const val = byId('electricityRate')?.value;
                    const r = num(val);
                    return r > 0 ? r : DEFAULT_RETAIL_RATE;
                })();

                const feedInTariff = FEED_IN_TARIFF_DEFAULT; // can be extended to be editable if needed
                // Allow user-specified loan APR if there's an input (id=loanApr), otherwise fall back to default
                const parsedLoanApr = (() => {
                    const v = byId('loanApr')?.value;
                    const n = Number.parseFloat(String(v || '').replace(/[^0-9.\-]/g, ''));
                    return Number.isFinite(n) && n > 0 ? n : LOAN_APR_DEFAULT;
                })();
                // APR to use for loan calculations (0 if loan not selected)
                const APR = inclLoan ? parsedLoanApr : 0;

                // bill (required to run figs)
                const billMonthly = (() => {
                    const val = byId('electricityBill')?.value;
                    // Ensure we never treat a negative input as a negative bill - clamp to 0
                    const r = Math.max(0, num(val));
                    return r;
                })();
                
                // Show Download Report button only if both bill > 0 AND panels > 0
                const roiBtnEl = document.getElementById('roiBtn');
                if (roiBtnEl) {
                    const shouldShow = billMonthly > 0 && panels > 0;
                    console.log('[ROI Button] billMonthly:', billMonthly, 'panels:', panels, 'shouldShow:', shouldShow);
                    roiBtnEl.style.setProperty('display', shouldShow ? 'block' : 'none', 'important');
                    console.log('[ROI Button] After setting - display:', roiBtnEl.style.display, 'computed:', window.getComputedStyle(roiBtnEl).display);
                }

                // add an energy production estimate based on panels and available solar configs
                let yearlyEnergy = 0;
                // prefer parsed DOM configs, then server-provided `solarConfigs` (may be a top-level const, not on window)
                const availableConfigs = (Array.isArray(window.__parsedSolarConfigs) && window.__parsedSolarConfigs.length > 0)
                    ? window.__parsedSolarConfigs
                    : (typeof solarConfigs !== 'undefined' && Array.isArray(solarConfigs) && solarConfigs.length > 0 ? solarConfigs : []);
                if (availableConfigs.length > 0) {
                    // helper: try to extract a numeric kWh value from a config object safely
                    const extractKwh = (cfg) => {
                        if (!cfg) return 0;
                        // try common keys first
                        const candidates = [cfg['yearlyEnergyDcKwh'], cfg['yearlyEnergy'], cfg['Annual Energy Production'], cfg['Annual Energy Production (kWh)'], cfg['Annual Energy Production kWh'], cfg['Annual Energy Production:'], cfg['Annual Energy Production\u00A0']];
                        for (const cand of candidates) {
                            if (cand && typeof cand === 'string') {
                                const n = parseFloat(cand.replace(/[^0-9.\-]/g, '').replace(/,/g, ''));
                                if (Number.isFinite(n) && n > 0) return n;
                            }
                            if (Number.isFinite(cand) && cand > 0) return Number(cand);
                        }
                        // fallback: inspect any string value on the config object and pick first number-like token
                        for (const v of Object.values(cfg)) {
                            if (!v) continue;
                            const s = String(v);
                            const m = s.match(/([0-9\.,]+)\s*/);
                            if (m) {
                                const n = parseFloat(m[1].replace(/,/g, ''));
                                if (Number.isFinite(n) && n > 0) return n;
                            }
                        }
                        return 0;
                    };

                    // find config matching panel count
                    for (let i = 0; i < availableConfigs.length; i++) {
                        const config = availableConfigs[i];
                        const configPanels = parseInt(config['panelsCount'] || config['panels'] || config['Number of Panels'] || config['Panels']) || 0;
                        if (configPanels === panels) {
                            yearlyEnergy = extractKwh(config) || 0;
                            break;
                        }
                    }
                    // if there isn't an exact matching panels, choose the closest lower one
                    if (yearlyEnergy === 0) {
                        let closestDiff = Infinity;
                        for (let i = 0; i < availableConfigs.length; i++) {
                            const config = availableConfigs[i];
                            const configPanels = parseInt(config['panelsCount'] || config['panels'] || config['Number of Panels'] || config['Panels']) || 0;
                            const diff = panels - configPanels;
                            if (diff >= 0 && diff < closestDiff) {
                                closestDiff = diff;
                                yearlyEnergy = extractKwh(config) || 0;
                            }
                        }
                    }
                }

                return { inclGrant, inclACA, inclLoan, panels, exportRate, electricityRate, feedInTariff, APR, billMonthly, yearlyEnergy };
            }

            function keyFigures(state) {
                const { inclGrant, inclACA, inclLoan, panels, exportRate, electricityRate: RETAIL, feedInTariff: FIT, APR, billMonthly, yearlyEnergy } = state;
                // Derived system constants
                const kWp = (panels * PANEL_POWER_W) / 1000;
                const baseCost = Math.round(estimateSolarCost(state.panels)); // €
                console.log('baseCost', baseCost);

                const seaiGrant = state.inclGrant ? Math.min(Number(baseCost * SEAI_GRANT_RATE), SEAI_GRANT_CAP) : 0;
                console.log('seaiGrant', seaiGrant);
                const acaAllowance = state.inclACA ? Math.min(Number(baseCost - seaiGrant), Number(baseCost) * CORPORATION_TAX ) : 0;
                console.log('acaAllowance', acaAllowance);

                // interest multiplier used when including loan-related uplift (simple proxy)
                const interest = Math.min(Number(APR ? APR * LENGTH_OF_PAYBACK : 0) || 0, 0.5); // cap at 50%
                console.log('interest', interest);
                
                // Install costs
                // convert computed floats to rounded integers for display/consistency
                const install_cost = Math.round(Number(baseCost));
                console.log('install_cost', install_cost);

                const net_install_cost = Math.round(Number(baseCost - seaiGrant + (inclLoan ? (baseCost - seaiGrant) * interest : 0)));
                console.log('net_install_cost', net_install_cost);

                // Production
                const yearlyEnergyKWhYr0 = yearlyEnergy; // year 0 nominal from config
                const yearlyEnergyKWh = yearlyEnergyKWhYr0; // displayed headline (yr0  value)

                // Loan modelling (monthly amortisation uses the loan term, not the 25-year system life)
                const m = 12, n = LENGTH_OF_PAYBACK * m;
                const principal = Math.max(0, baseCost - seaiGrant);
                const r = APR / m;
                const monthlyRepay = Math.round(inclLoan ? principal * (r / (1 - Math.pow(1 + r, -n))) : 0);
                const yearlyLoanCost = Math.round(inclLoan ? monthlyRepay * 12 : 0);

                // Total 25-year savings (benefits - cost + ACA if included)
                const benefits25 = Array.from({ length: YRS_OF_SYSTEM }, (_, y) => {
                    const pvYear = panels * DAY_POWER_AVG * DAYS_IN_YR * Math.pow(1 - SOLAR_PANEL_DEGRADATION, y);
                    const self = pvYear * (1 - exportRate);
                    const exp = pvYear * exportRate;
                    const retailY = RETAIL * Math.pow(1 + ANNUAL_INCREASE, y);
                    const fitY = FIT; // could escalate, left constant per provided spec
                    return self * retailY + exp * fitY;
                }).reduce((a, b) => a + b, 0);

                // Total loan payments over the evaluation window: stop counting repayments after the loan term
                const loanYearsCount = Math.min(YRS_OF_SYSTEM, LENGTH_OF_PAYBACK);
                const loanCost25 = inclLoan ? (monthlyRepay * 12 * loanYearsCount) : principal; // total paid on loan within evaluation window
                const total_yr_savings = benefits25 - loanCost25 + (inclACA ? acaAllowance : 0);

                // First-year annual saving (calculate this BEFORE payback so we can use it)
                const savings_year0 = (() => {
                    // Current electricity usage based on their bill
                    const current_usage_kwh = (billMonthly * 12) / RETAIL;
                    
                    // Total solar production
                    const annual_solar_kwh = panels * DAY_POWER_AVG * DAYS_IN_YR;
                    
                    // Self-consumption: what they can use themselves (capped by their actual usage)
                    const max_self_consumption_kwh = annual_solar_kwh * (1 - exportRate);
                    const actual_self_consumption_kwh = Math.min(max_self_consumption_kwh, current_usage_kwh);
                    
                    // Export: everything not self-consumed
                    const export_kwh = annual_solar_kwh - actual_self_consumption_kwh;
                    
                    // Financial benefits
                    const bill_savings = actual_self_consumption_kwh * RETAIL;  // Avoided electricity cost
                    const export_income = export_kwh * FIT;                     // Export income
                    const loan_cost = inclLoan ? yearlyLoanCost : 0;
                    const acaBump = (inclACA ? acaAllowance : 0);
                    
                    return bill_savings + export_income - loan_cost + acaBump;
                })();

                // Monthly charge (Year 0) - based on actual savings
                const monthly_charge = (savings_year0 / 12) - billMonthly;

                // Payback period (years) - uses actual net annual savings
                const payback_period = (() => {
                    // Investment amount to pay back
                    const investment = inclLoan ? loanCost25 : net_install_cost;
                    
                    // Net annual savings (year 0) - this is what actually goes back into your pocket
                    const annualSavings = savings_year0;
                    
                    // Payback = Investment / Annual Savings
                    return annualSavings > 0 ? investment / annualSavings : 0;
                })();

                // ROI over 25 years (%)
                const ROI_25Y = (() => {
                    const cost = inclLoan ? (monthlyRepay * 12 * loanYearsCount) : principal;
                    return cost > 0 ? ((benefits25 - cost) / cost) * 100 : 0;
                })();

                // CO2 reduction over life
                const co2_reduction = CO2_COEFFICIENT_TONNES *
                    Array.from({ length: YRS_OF_SYSTEM }, (_, y) =>
                        panels * DAY_POWER_AVG * Math.pow(1 - SOLAR_PANEL_DEGRADATION, y) * DAYS_IN_YR
                    ).reduce((a, b) => a + b, 0);

                return {
                    baseCost, seaiGrant, acaAllowance,
                    install_cost, net_install_cost,
                    yearlyEnergyKWh, monthly_charge, total_yr_savings,
                    payback_period, ROI_25Y, savings_year0, co2_reduction
                };
            }

            // ========= 5) GUI UPDATES =========
            function updateResults(state, figs) {
                const setTxt = (id, txt) => {
                    document.querySelectorAll('#' + id).forEach(el => {
                        if (!el) return;
                        // Do not overwrite form inputs (slider). Only update visible text elements.
                        if (el.tagName && el.tagName.toUpperCase() === 'INPUT') return;
                        el.textContent = txt;
                    });
                };
                // #results
                setTxt('installationCost', fmtEuro(figs.install_cost));
                setTxt('grant', fmtEuro(figs.seaiGrant));
                setTxt('panelCount', fmtNum(state.panels, 0));
                setTxt('yearlyEnergy', fmtNum(figs.yearlyEnergyKWh, 0) + ' kWh');
                setTxt('monthlyBill', fmtEuro(state.billMonthly));
                setTxt('annualIncrease', (ANNUAL_INCREASE * 100).toFixed(1) + '%');
                // if the income is negative, show in red (format/sign is handled here)
                if (figs.monthly_charge < 0) {
                    setTxt('netIncome', '-' + fmtEuro(Math.abs(figs.monthly_charge)));
                    const el = byId('netIncome');
                    if (el) el.style.color = 'red';
                } else {
                    setTxt('netIncome', '+' +  fmtEuro(figs.monthly_charge));
                    const el = byId('netIncome');
                    if (el) el.style.color = 'black';
                }
                setTxt('exportRate', (state.exportRate * 100).toFixed(0) + '%');
                setTxt('electricityRate', fmtEuro(state.electricityRate));
            }

            function updateInstallationDetails(state, figs) {
                const setTxt = (id, txt) => {
                    document.querySelectorAll('#' + id).forEach(el => {
                        if (!el) return;
                        if (el.tagName && el.tagName.toUpperCase() === 'INPUT') return;
                        el.textContent = txt;
                    });
                };
                // #installation-details
                setTxt('netCost', fmtEuro(figs.net_install_cost));
                setTxt('totalSavings', fmtEuro(figs.total_yr_savings));
                setTxt('roi', fmtNum(figs.ROI_25Y, 1) + '%');
                setTxt('panelCount', fmtNum(state.panels, 0));

                // show one decimal for CO₂ reductions for readability
                setTxt('co2Reduction', fmtNum(figs.co2_reduction, 1) + ' t');
                setTxt('annualSavings', fmtEuro(figs.savings_year0));
                setTxt('paybackPeriod', fmtNum(figs.payback_period, 2) + ' years');
            }

            // Minimal canvas updates so the IDs react without external libs
            function updateBreakEvenChart(state, figs) {
                console.log('updateBreakEvenChart called', { 
                    hasChart: !!window.breakEvenChart,
                    hasChartData: !!(window.breakEvenChart && window.breakEvenChart.data),
                    hasFunc: typeof window.calculateBreakEvenDataSimple === 'function',
                    billMonthly: state.billMonthly,
                    panels: state.panels 
                });
                
                // Prefer updating Chart.js instance when available (keeps rendering consistent)
                if (window.breakEvenChart && window.breakEvenChart.data && typeof window.calculateBreakEvenDataSimple === 'function') {
                    try {
                        // On page load with no bill, show zero baseline
                        if (!state.billMonthly || state.billMonthly === 0) {
                            console.log('Setting chart to zero baseline');
                            window.breakEvenChart.data.datasets[0].data = Array(25).fill(0);
                            window.breakEvenChart.data.datasets[0].label = '0 Panels';
                            window.breakEvenChart.update('none');
                            return;
                        }
                        
                        const cfg = { panelsCount: state.panels, yearlyEnergyDcKwh: figs.yearlyEnergyKWh || (state.panels * DAY_POWER_AVG * DAYS_IN_YR) };
                        console.log('Calling calculateBreakEvenDataSimple with:', cfg);
                        const be = window.calculateBreakEvenDataSimple(cfg);
                        console.log('Break even data:', be);
                        
                        if (be && be.savings && window.breakEvenChart.data && window.breakEvenChart.data.datasets && window.breakEvenChart.data.datasets[0]) {
                            console.log('Updating chart with', be.savings.length, 'data points');
                            window.breakEvenChart.data.datasets[0].data = [...be.savings];
                            window.breakEvenChart.data.datasets[0].label = `${state.panels} Panels`;
                            // Force chart to recalculate scales and redraw
                            window.breakEvenChart.options.scales.y.min = undefined;
                            window.breakEvenChart.options.scales.y.max = undefined;
                            window.breakEvenChart.update('active');
                            console.log('Chart updated successfully');
                            return;
                        } else {
                            console.warn('Missing chart data structure:', { 
                                hasBe: !!be, 
                                hasSavings: be?.savings, 
                                hasChartData: !!window.breakEvenChart.data 
                            });
                        }
                    } catch (e) {
                        console.error('Chart update error:', e);
                        // fall back to canvas drawing below
                    }
                } else {
                    console.warn('Chart not available:', {
                        hasChart: !!window.breakEvenChart,
                        hasFunc: typeof window.calculateBreakEvenDataSimple
                    });
                }

                const c = byId('breakEvenChart');
                if (!c || !c.getContext) return;
                const ctx = c.getContext('2d');
                const W = c.width, H = c.height;
                ctx.clearRect(0, 0, W, H);
                // Simple two-bar visual: Cost vs 25y Benefits
                const cost = Math.max(1, figs.net_install_cost);
                const benefit = Math.max(1, figs.total_yr_savings + figs.net_install_cost);
                const maxV = Math.max(cost, benefit);
                const barW = W * 0.3, gap = W * 0.1;
                const scale = (v) => (v / maxV) * (H * 0.8);
                ctx.fillRect(W * 0.15, H - scale(cost), barW, scale(cost));
                ctx.fillRect(W * 0.55, H - scale(benefit), barW, scale(benefit));
            }

            function updateEnergyChart(state, figs) {
                // If Chart.js instance exists, update its dataset instead of raw canvas drawing
                if (window.energyChart) {
                    try {
                        const years = YRS_OF_SYSTEM;
                        const degradation = SOLAR_PANEL_DEGRADATION;
                        const data = Array.from({ length: years }, (_, y) => {
                            return state.panels * DAY_POWER_AVG * DAYS_IN_YR * Math.pow(1 - degradation, y);
                        });
                        window.energyChart.data.datasets[0].data = data;
                        window.energyChart.data.datasets[0].label = `${state.panels} panels — Annual Production (kWh)`;
                        window.energyChart.update();
                        return;
                    } catch (e) {
                        // fall back to canvas drawing
                    }
                }

                const c = byId('energyChart');
                if (!c || !c.getContext) return;
                const ctx = c.getContext('2d');
                const W = c.width, H = c.height;
                ctx.clearRect(0, 0, W, H);
                // Plot annual production decline by degradation
                const years = YRS_OF_SYSTEM;
                const values = Array.from({ length: years }, (_, y) => state.panels * DAY_POWER_AVG * DAYS_IN_YR * Math.pow(1 - SOLAR_PANEL_DEGRADATION, y));
                const maxV = Math.max(...values);
                const xstep = W / (years + 1);
                ctx.beginPath();
                values.forEach((v, i) => {
                    const x = xstep * (i + 1);
                    const y = H - (v / maxV) * (H * 0.85);
                    if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
                });
                ctx.stroke();
            }

            function updateSolarInvestmentAnalysis(state, figs) {
                const setTxt = (id, txt) => {
                    document.querySelectorAll('#' + id).forEach(el => {
                        if (!el) return;
                        if (el.tagName && el.tagName.toUpperCase() === 'INPUT') return;
                        el.textContent = txt;
                    });
                };
                setTxt('panelCountValue', fmtNum(state.panels, 0));
                setTxt('installCost', fmtEuro(figs.baseCost));
            }

            // ========= 6) MASTER =========
            function calculateROI() {
                const state = readInputs();
                // Guard: require monthly bill to compute robustly
                if (!state.billMonthly) {
                    // still update zero-ish defaults so UI doesn't look stale
                    const zeroFigs = keyFigures({ ...state, billMonthly: 0 });
                    updateResults(state, zeroFigs);
                    updateInstallationDetails(state, zeroFigs);
                    // Only update charts if they exist
                    if (window.breakEvenChart) updateBreakEvenChart(state, zeroFigs);
                    if (window.energyChart) updateEnergyChart(state, zeroFigs);
                    updateSolarInvestmentAnalysis(state, zeroFigs);
                    return;
                }
                const figs = keyFigures(state);
                updateResults(state, figs);
                updateInstallationDetails(state, figs);
                // Only update charts if they exist
                if (window.breakEvenChart) updateBreakEvenChart(state, figs);
                if (window.energyChart) updateEnergyChart(state, figs);
                updateSolarInvestmentAnalysis(state, figs);
            }

            // ========= 7) EVENT WIRING =========
            function wireEvents() {
                const onChangeRecalc = (id, ev = 'change') => {
                    const el = byId(id);
                    if (el) el.addEventListener(ev, calculateROI);
                };
                onChangeRecalc('inclGrant', 'change');
                onChangeRecalc('inclACA', 'change');
                onChangeRecalc('inclLoan', 'change');
                onChangeRecalc('panelCount', 'input');

                // keyup handlers for text inputs
                onChangeRecalc('exportRate', 'keyup');
                onChangeRecalc('electricityRate', 'keyup');

                const btn = byId('openRoiModalButton');
                if (btn) btn.addEventListener('click', calculateROI);

                // Also recalc on electricityBill keyup so users see instant feedback
                onChangeRecalc('electricityBill', 'keyup');
            }

            // ========= 8) INITIALISE =========
            function initialPopulate() {
                // Explicitly set all ROI values to zero on page load
                const setZero = (id, txt) => {
                    const el = byId(id);
                    if (el && el.tagName !== 'INPUT') el.textContent = txt;
                };
                setZero('netIncome', CURRENCY_SYMBOL + '0');
                setZero('netCost', CURRENCY_SYMBOL + '0');
                setZero('paybackPeriod', '0 yrs');
                setZero('annualSavings', CURRENCY_SYMBOL + '0');
                setZero('totalSavings', CURRENCY_SYMBOL + '0');
                setZero('roi', '0%');
                setZero('co2Reduction', '0');
                
                // Initialize charts with zero data if they exist and are fully initialized
                if (window.breakEvenChart && window.breakEvenChart.data && window.breakEvenChart.data.datasets && window.breakEvenChart.data.datasets[0]) {
                    window.breakEvenChart.data.datasets[0].data = Array(25).fill(0);
                    window.breakEvenChart.data.datasets[0].label = '0 Panels';
                    window.breakEvenChart.update('none');
                }
            }

            document.addEventListener('DOMContentLoaded', () => {
                wireEvents();
                initialPopulate();
                
                // Ensure values stay at zero after a short delay (to override any other initialization)
                // Also check if charts are ready
                setTimeout(() => {
                    initialPopulate();
                }, 200);
            });

            // Expose calculateROI if needed from elsewhere
            window.calculateROI = calculateROI;
        })();
        </script>

        <!-- INLINED ROI Calculator JS for cache busting - v1.0.9 -->

</body>

</html>
<?php
// Output the buffered content
ob_end_flush();

// AJAX Handler for Gamma PDF Generation
if (!function_exists('ksrad_handle_gamma_pdf_generation')) {
    function ksrad_handle_gamma_pdf_generation() {
    // Send a test response first to see if function is even called
    error_log('=== GAMMA PDF GENERATION FUNCTION CALLED ===');
    
    // Check if this is even an AJAX request
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        error_log('ERROR: Not an AJAX request');
        wp_send_json_error(array('message' => 'Not an AJAX request'));
        return;
    }
    
    error_log('POST data: ' . print_r($_POST, true));
    
    // Verify nonce - but don't die on failure, log it
    $nonce_valid = check_ajax_referer('ksrad_gamma_pdf', 'nonce', false);
    if (!$nonce_valid) {
        error_log('NONCE VERIFICATION FAILED');
        wp_send_json_error(array(
            'message' => 'Security check failed. Please refresh the page and try again.',
            'error_type' => 'nonce_failure'
        ));
        wp_die();
    }
    
    // Get form data
    $full_name = sanitize_text_field($_POST['fullName'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $panel_count = intval($_POST['panelCount'] ?? 0);
    $location = sanitize_text_field($_POST['location'] ?? '');
    
    @error_log('Form data received: Name=' . $full_name . ', Email=' . $email . ', Panels=' . $panel_count);
    
    // Get API key from settings
    $gamma_api_key = ksrad_get_option('gamma_api_key', 'sk-gamma-9KmJzFjq38EdudoBOD0L0Ospjrj9Q4xUeaaaON5I');
    $gamma_template_id = ksrad_get_option('gamma_template_id', 'g_6h8kwcjnyzhxn9f');
    // gamma folder id is needed 
    $gamma_folder_id = ksrad_get_option('gamma_folder_id', '7mknfm68zejkpsf');

    if (empty($gamma_api_key) || empty($gamma_template_id)) {
        wp_send_json_error('Gamma API key or template ID not configured');
        return;
    }
    
    // Get essential solar data for the report
    $ksrad_solarData = ksrad_get_option('', ksrad_get_default_solar_data());
    
    // Extract only key metrics to avoid overwhelming the API
    $max_panels = $ksrad_solarData['solarPotential']['maxArrayPanelsCount'] ?? 0;
    $max_area = $ksrad_solarData['solarPotential']['maxArrayAreaMeters2'] ?? 0;
    $yearly_energy = 0;
    
    // Find energy production for selected panel count
    if (isset($ksrad_solarData['solarPotential']['solarPanelConfigs'])) {
        foreach ($ksrad_solarData['solarPotential']['solarPanelConfigs'] as $config) {
            if (intval($config['panelsCount'] ?? 0) === $panel_count) {
                $yearly_energy = floatval($config['yearlyEnergyDcKwh'] ?? 0);
                break;
            }
        }
    }
    
    // Create concise data summary
    $solar_summary = sprintf(
        "Max capacity: %d panels on %.1f m². Selected system size (chosen by user): %d panels producing ~%.0f kWh/year.",
        $max_panels,
        $max_area,
        $panel_count,
        $yearly_energy
    );
    
    // Build prompt with essential data only
    $prompt = sprintf( 
        "Generate a professional solar report for %s at %s.\n\nChosen system Details:\n- %d x 400W solar panels\n- Annual production: %.0f kWh\n- Contact: %s\n- Phone: %s\n\nProperty Analysis:\n%s",
        $full_name,
        $location,
        $panel_count,
        $yearly_energy,
        $email,
        $phone,
        $solar_summary
    );
    
    // Prepare Gamma API request body
    $request_body = array(
        'gammaId' => $gamma_template_id,
        'prompt' => $prompt,
        'themeId' => 'default-light',
        'exportAs' => 'pdf',
        'imageOptions' => array(
            'source' => 'ai-generated',
            'model' => 'imagen-4-pro',
            'style' => 'minimal black and white, line art at 60% transparency'
        ),
        'sharingOptions' => array(
            'workspaceAccess' => 'view',
            'externalAccess' => 'view',
            'emailOptions' => array(
                'recipients' => array($email),
                'access' => 'view'
            )
        )
    );
    
    // Encode JSON with proper options
    $json_body = json_encode($request_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    // Log request for debugging
    error_log('Gamma API Request - Template ID: ' . $gamma_template_id);
    error_log('Gamma API Request - Email: ' . $email);
    error_log('Gamma API Request - Prompt length: ' . strlen($prompt));
    error_log('Gamma API Request Body: ' . $json_body);
    error_log('Gamma API Request Body Length: ' . strlen($json_body));
    error_log('=== CURL COMMAND FOR BROWSER/TESTING ===');
    error_log('curl -X POST https://public-api.gamma.app/v1.0/generations/from-template \\');
    error_log('  -H "Content-Type: application/json" \\');
    error_log('  -H "X-API-KEY: ' . $gamma_api_key . '" \\');
    error_log('  -d \'' . $json_body . '\'');
    error_log('=== END CURL COMMAND ===');
    
    // Call Gamma API
    $response = wp_remote_post('https://public-api.gamma.app/v1.0/generations/from-template', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-API-KEY' => $gamma_api_key
        ),
        'body' => $json_body,
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
        return;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $response_code = wp_remote_retrieve_response_code($response);
    
    // Log detailed error information for debugging
    error_log('Gamma API Response Code: ' . $response_code);
    error_log('Gamma API Response Body: ' . $body);
    
    if ($response_code !== 200) {
        $error_message = $data['message'] ?? $data['error'] ?? 'Unknown error from Gamma API (Status: ' . $response_code . ')';
        error_log('Gamma API Error: ' . $error_message);
        
        // Build curl command for debugging
        $curl_command = "curl -X POST 'https://public-api.gamma.app/v1.0/generations/from-template' \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-API-KEY: " . $gamma_api_key . "' \\\n  -d '" . str_replace("'", "'\\''", $json_body) . "'";
        
        // Return detailed debug info even on error
        wp_send_json_error(array(
            'message' => $error_message,
            'curl_command' => $curl_command,
            'debug' => array(
                'url' => 'https://public-api.gamma.app/v1.0/generations/from-template',
                'method' => 'POST',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-API-KEY' => substr($gamma_api_key, 0, 10) . '...'
                ),
                'body' => $request_body,
                'response_code' => $response_code,
                'response_body' => $data
            )
        ));
        return;
    }
    
    // Build curl command for debugging
    $curl_command = "curl -X POST 'https://public-api.gamma.app/v1.0/generations/from-template' \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-API-KEY: " . $gamma_api_key . "' \\\n  -d '" . str_replace("'", "'\\''", $json_body) . "'";
    
    // Log the generation (optional)
    error_log(sprintf(
        'Gamma PDF generated for %s (%s) - Panel count: %d',
        $full_name,
        $email,
        $panel_count
    ));
  
    // email the user a link to their generated report on gamma.app in the format:
    // eg. https://gamma.app/docs/Solar-Report-for-John-Thomas-j9or10uoquw3l52
    $ksrad_user_report_email = "https://gamma.app/docs/Solar-Report-for-" . str_replace(' ', '-', $full_name) . "-" . ($data['documentId'] ?? 'unknownid');


    wp_send_json_success(array(
        'message' => 'PDF generated successfully',
        'gamma_response' => $data,
        'curl_command' => $curl_command,
        'debug' => array(
            'url' => 'https://public-api.gamma.app/v1.0/generations/from-template',
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-KEY' => substr($gamma_api_key, 0, 10) . '...' // Partial key for security
            ),
            'body' => $request_body
        )
    ));
    }
}

// Register AJAX handlers OUTSIDE function_exists check
add_action('wp_ajax_ksrad_generate_gamma_pdf', 'ksrad_handle_gamma_pdf_generation');
add_action('wp_ajax_nopriv_ksrad_generate_gamma_pdf', 'ksrad_handle_gamma_pdf_generation');
?>