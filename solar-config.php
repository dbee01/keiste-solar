<?php

// API keys are now managed through WordPress admin settings
// Use ksrad_get_option('google_solar_api_key') to retrieve the Solar API key
// Use ksrad_get_option('google_maps_api_key') to retrieve the Maps API key
// Use ksrad_get_option('report_key') to retrieve the Report API key

// API Configuration
define('GOOGLE_SOLAR_API_URL', 'https://solar.googleapis.com/v1/buildingInsights:findClosest');