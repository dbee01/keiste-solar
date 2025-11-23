=== Keiste Solar Report ===
Contributors: keiste
Tags: solar, solar panels, roi calculator, google solar api, energy analysis
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Comprehensive solar panel analysis tool with ROI calculations, Google Solar API integration, interactive charts, and PDF report generation.

== Description ==

Keiste Solar Analysis is a powerful WordPress plugin that provides comprehensive solar panel analysis for Ireland, the UK, the USA and many other regions. Using Google's Solar API, it delivers accurate solar potential assessments, financial projections, and detailed ROI calculations.

= Key Features =

* **Google Solar API Integration** - Real-time solar potential data for any address
* **Interactive Address Search** - Google Maps autocomplete for easy location selection
* **Financial Analysis** - Detailed ROI calculations with customizable parameters
* **Grant Integration** - Automatic grant calculations based on government schemes
* **Interactive Charts** - Visual break-even analysis and energy production projections
* **PDF Report Generation** - Professional downloadable reports for clients
* **Mobile Responsive** - Fully optimized for all devices
* **Admin Settings Panel** - Easy configuration of API keys and default values

= Use Cases =

* Solar installation companies offering free assessments
* Energy consultants providing solar feasibility studies
* Property developers evaluating solar potential
* Homeowners researching solar panel investments
* Educational institutions teaching renewable energy

= How It Works =

1. Users enter an address using Google Maps autocomplete
2. Plugin fetches solar data from Google Solar API
3. System calculates ROI based on user's electricity costs and financing options
4. Interactive charts display financial projections over 25 years
5. Users can download a comprehensive PDF report

= Requirements =

* Google Solar API key (obtain from Google Cloud Console)
* Active WordPress installation (5.8 or higher)
* PHP 7.4 or higher

== Installation ==

1. Upload the `keiste-solar` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Keiste Solar to configure your API keys
4. Add the shortcode `[keiste_solar_analysis]` to any page or post

= Configuration Steps =

1. **Get Google Solar API Key:**
   - Visit Google Cloud Console
   - Enable Solar API and Maps JavaScript API
   - Create credentials (API key)
   - Restrict key to your domain for security

2. **Plugin Settings:**
   - Navigate to Settings > Keiste Solar in WordPress admin
   - Enter your Google Solar API key
   - Configure default values for:
     * Installation costs per kW
     * Grant rates and caps
     * Electricity costs and inflation rates
     * Export tariff rates
     * Loan terms and interest rates

3. **Add to Page:**
   - Create or edit a page
   - Add the shortcode: `[keiste_solar_analysis]`
   - Publish and test

== Frequently Asked Questions ==

= Do I need a Google Solar API key? =

Yes, you need a Google Solar API key to fetch solar potential data. You can obtain one from the Google Cloud Console. The API is free for limited usage.

= Which countries are supported? =

The plugin supports solar analysis in regions where Google Solar API provides coverage. See the Country Coverage section below for a visual map of supported areas.

== Country Coverage ==

The Keiste Solar Report plugin leverages Google's Solar API, which provides solar potential data for numerous countries and regions worldwide. Coverage includes:

* **HIGH resolution**: United States, Canada, most of Europe (including Ireland, UK, Germany, France, Spain, Italy, etc.), Japan, South Korea, and others
* **MEDIUM resolution**: Additional European countries, parts of Asia
* **BASE resolution**: South America (including Brazil, Colombia, Bolivia), parts of Africa, Southeast Asia, Australia

![Google Solar API Coverage Map](assets/images/google-solar-coverage-map.png)

The map above shows the current global coverage of the Google Solar API. Areas in pink/purple indicate HIGH or MEDIUM resolution coverage, while areas in orange indicate BASE resolution coverage.

**Note:** The plugin can be adapted for any supported country by adjusting grant settings, currency, electricity rates, and other default parameters in the admin panel (Settings > Keiste Solar).

= Can I customize the ROI calculations? =

Yes, all financial parameters can be customized through the Settings > Keiste Solar admin panel, including installation costs, grant rates, electricity costs, export tariffs, and loan terms.

= Is the plugin mobile-friendly? =

Yes, the plugin is fully responsive and optimized for mobile devices, tablets, and desktops.

= Can users download reports? =

Yes, users can download comprehensive PDF reports containing all analysis data, charts, and financial projections.

= Does it work with any WordPress theme? =

Yes, the plugin is designed to work with any WordPress theme. It uses Bootstrap 5 for styling and is fully self-contained.

= Is there a cost for using this plugin? =

The plugin itself is free. However, you'll need a Google Solar API key, which has free tier usage limits. Check Google Cloud pricing for current rates.

= Can I use this on multiple sites? =

Yes, you can use the plugin on multiple WordPress installations. Each site will need its own configured Google Solar API key.

== Screenshots ==

1. Address search interface with Google Maps autocomplete
2. ROI results dashboard showing payback period and savings
3. Interactive break-even chart over 25 years
4. System size configuration with panel slider
5. Financial inputs and customization options
6. Detailed installation specifications
7. PDF report download interface
8. Admin settings panel

== Changelog ==

= 1.0.0 =
* Initial release
* Google Solar API integration
* ROI calculator with customizable parameters
* Solar grant calculations
* Interactive charts (Chart.js)
* PDF report generation (jsPDF)
* Google Maps address autocomplete
* Mobile-responsive design
* Admin settings panel
* Bootstrap 5 UI framework
* WordPress coding standards compliance
* Security best practices (nonces, sanitization, escaping)

== Upgrade Notice ==

= 1.0.0 =
Initial release of Keiste Solar Analysis plugin.

== Additional Information ==

= Support =

For support, please visit [keiste.com](https://keiste.com/) or contact us through our website.

= Credits =

* Developed by Keiste
* Uses Google Solar API for solar potential data
* Charts powered by Chart.js
* PDF generation by jsPDF
* UI framework: Bootstrap 5

= Privacy Policy =

This plugin sends address data to Google's Solar API and Maps API for analysis. No personal data is stored by the plugin itself. Users who download reports provide their name and email, which is handled according to your site's privacy policy.

= Links =

* [Plugin Homepage](https://keiste.ie/keiste-solar-report)
* [Documentation](https://keiste.ie/keiste-solar-report/docs)
* [Support](https://keiste.com/)
