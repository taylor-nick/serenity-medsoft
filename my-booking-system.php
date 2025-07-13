<?php
/**
 * Plugin Name: Serenity MedSoft Booking Proxy
 * Description: Securely connects the booking form to the MedSoft API.
 * Version: 6
 * Author: Augment
 */

if (!defined('ABSPATH')) exit; // Block direct access

/**
 * Check if WooCommerce is active and properly loaded
 */
function is_woocommerce_active() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    
    $active_plugins = apply_filters('active_plugins', get_option('active_plugins'));
    $woocommerce_active = in_array('woocommerce/woocommerce.php', $active_plugins);
    
    file_put_contents($log_file, "WooCommerce active check: " . ($woocommerce_active ? 'Yes' : 'No') . "\n", FILE_APPEND | LOCK_EX);
    
    if ($woocommerce_active) {
        // Check if WC function exists
        $wc_function_exists = function_exists('WC');
        file_put_contents($log_file, "WC function exists: " . ($wc_function_exists ? 'Yes' : 'No') . "\n", FILE_APPEND | LOCK_EX);
        
        if (!$wc_function_exists) {
            // Try to include WooCommerce
            include_once(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php');
            file_put_contents($log_file, "Attempted to include WooCommerce manually\n", FILE_APPEND | LOCK_EX);
            
            // Check again
            $wc_function_exists = function_exists('WC');
            file_put_contents($log_file, "WC function exists after include: " . ($wc_function_exists ? 'Yes' : 'No') . "\n", FILE_APPEND | LOCK_EX);
        }
        
        return $wc_function_exists;
    }
    
    return false;
}

// Include WooCommerce integration if WooCommerce is active
// Re-enable WooCommerce integration after fresh installation
$upload_dir = wp_upload_dir();
$log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

// Check if WooCommerce is properly installed with required functions
if (is_woocommerce_active() && function_exists('wc_get_cart_item_data_hash') && function_exists('wc_get_order')) {
    require_once plugin_dir_path(__FILE__) . 'medsoft-woocommerce-integration.php';
    file_put_contents($log_file, "WooCommerce integration loaded successfully after fresh installation\n", FILE_APPEND | LOCK_EX);
} else {
    file_put_contents($log_file, "WooCommerce integration disabled - missing functions or not active\n", FILE_APPEND | LOCK_EX);
    if (!is_woocommerce_active()) {
        file_put_contents($log_file, "- WooCommerce plugin not active\n", FILE_APPEND | LOCK_EX);
    }
    if (!function_exists('wc_get_cart_item_data_hash')) {
        file_put_contents($log_file, "- Missing wc_get_cart_item_data_hash function\n", FILE_APPEND | LOCK_EX);
    }
    if (!function_exists('wc_get_order')) {
        file_put_contents($log_file, "- Missing wc_get_order function\n", FILE_APPEND | LOCK_EX);
    }
}

// Register all our WordPress REST API endpoints
add_action('rest_api_init', function () {
    // GET endpoints can remain public
    register_rest_route('mybooking/v1', '/locations', ['methods' => 'GET', 'callback' => 'get_medsoft_locations', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/services', ['methods' => 'GET', 'callback' => 'get_medsoft_services', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/doctors', ['methods' => 'GET', 'callback' => 'get_medsoft_doctors', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/slots', ['methods' => 'GET', 'callback' => 'get_medsoft_slots', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/doctorSchedule', ['methods' => 'GET', 'callback' => 'get_medsoft_doctor_schedule', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/service-time', ['methods' => 'GET', 'callback' => 'get_medsoft_service_time', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/appointment-scopes', ['methods' => 'GET', 'callback' => 'get_medsoft_appointment_scopes', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/location-schedule', ['methods' => 'GET', 'callback' => 'get_medsoft_location_schedule', 'permission_callback' => '__return_true']);

    // Category-based booking endpoints
    register_rest_route('mybooking/v1', '/categories', ['methods' => 'GET', 'callback' => 'get_booking_categories', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/location-categories', ['methods' => 'GET', 'callback' => 'get_booking_categories', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/category-services/(?P<category>[^/]+)', ['methods' => 'GET', 'callback' => 'get_category_services', 'permission_callback' => '__return_true']);
    register_rest_route('mybooking/v1', '/available-slots', [
        'methods' => 'GET',
        'callback' => 'get_available_slots',
        'permission_callback' => function() {
            error_log('available-slots permission callback called');
            return true;
        }
    ]);

    // Add a test endpoint to debug REST API issues
    register_rest_route('mybooking/v1', '/test', [
        'methods' => 'GET',
        'callback' => function() {
            error_log('Test endpoint called successfully');
            return ['status' => 'success', 'message' => 'REST API is working'];
        },
        'permission_callback' => '__return_true'
    ]);

    // Alternative registration for available-slots with different approach
    register_rest_route('mybooking/v1', '/slots-available', [
        'methods' => 'GET',
        'callback' => 'get_available_slots',
        'permission_callback' => '__return_true',
        'args' => [
            'locationId' => [
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ],
            'serviceId' => [
                'required' => false,
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);
    register_rest_route('mybooking/v1', '/create-appointment', ['methods' => 'POST', 'callback' => 'create_medsoft_appointment', 'permission_callback' => 'validate_booking_nonce']);

    // Manual sync endpoint for admin
    register_rest_route('mybooking/v1', '/sync-medsoft', [
        'methods' => 'POST',
        'callback' => 'manual_medsoft_sync',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Update products with duration endpoint
    register_rest_route('mybooking/v1', '/update-duration', [
        'methods' => 'POST',
        'callback' => 'manual_update_duration',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Restore trashed products endpoint (temporary fix)
    register_rest_route('mybooking/v1', '/restore-products', [
        'methods' => 'POST',
        'callback' => 'restore_trashed_medsoft_products',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // WooCommerce-based endpoints (faster, cached data)
    register_rest_route('mybooking/v1', '/wc-categories', [
        'methods' => 'GET',
        'callback' => 'get_woocommerce_categories',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('mybooking/v1', '/wc-category-services/(?P<category>[^/]+)', [
        'methods' => 'GET',
        'callback' => 'get_woocommerce_category_services',
        'permission_callback' => '__return_true'
    ]);

    // Cached time slots endpoint
    register_rest_route('mybooking/v1', '/cached-slots', [
        'methods' => 'GET',
        'callback' => 'get_cached_available_slots',
        'permission_callback' => '__return_true'
    ]);

    // Debug endpoint to check WooCommerce data
    register_rest_route('mybooking/v1', '/debug-wc', [
        'methods' => 'GET',
        'callback' => 'debug_woocommerce_data',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Also register the endpoint in the api/estetique/v1 namespace to match the original site
    register_rest_route('api/estetique/v1', '/service-time', ['methods' => 'GET', 'callback' => 'get_medsoft_service_time', 'permission_callback' => '__return_true']);

    // POST endpoint needs proper nonce validation
    register_rest_route('mybooking/v1', '/book', [
        'methods' => 'POST',
        'callback' => 'create_medsoft_appointment',
        'permission_callback' => 'validate_booking_nonce'
    ]);

    // Logging endpoints
    register_rest_route('mybooking/v1', '/log-api-request', [
        'methods' => 'POST',
        'callback' => 'log_api_request',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('mybooking/v1', '/log-api-response', [
        'methods' => 'POST',
        'callback' => 'log_api_response',
        'permission_callback' => '__return_true'
    ]);

    // Manual cache slots endpoint
    register_rest_route('mybooking/v1', '/cache-slots', [
        'methods' => 'POST',
        'callback' => 'manual_cache_slots',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Clear cache endpoint
    register_rest_route('mybooking/v1', '/clear-cache', [
        'methods' => 'POST',
        'callback' => 'clear_time_slots_cache',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Test cache endpoint
    register_rest_route('mybooking/v1', '/test-cache', [
        'methods' => 'POST',
        'callback' => 'test_cache_entry',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});

/**
 * Validate nonce for booking submission
 */
function validate_booking_nonce($request) {
    $nonce = $request->get_header('X-WP-Nonce');
    
    if (!$nonce) {
        return new WP_Error('missing_nonce', 'Nonce is required', ['status' => 401]);
    }
    
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error('invalid_nonce', 'Invalid nonce', ['status' => 401]);
    }
    
    return true;
}

/**
 * The master API request function.
 */
function medsoft_api_request($endpoint, $method = 'GET', $body = null) {
    // --- Define base credentials ---
    $client_path = defined('MEDSOFT_CLIENT_PATH') ? MEDSOFT_CLIENT_PATH : '';
    $base_url = defined('MEDSOFT_BASE_URL') ? MEDSOFT_BASE_URL : '';
    
    // --- Use same API key for both GET and POST (like working plugin.php) ---
    $api_key = defined('MEDSOFT_API_KEY') ? MEDSOFT_API_KEY : '';
    error_log('Using API key for ' . $method . ' request to: ' . $endpoint);

    $url = $base_url . '/' . $client_path . '/api/integrations/programari-online/public/' . $client_path . $endpoint;

    if (empty($api_key) || empty($client_path) || empty($base_url)) {
        return new WP_Error('config_error', 'MedSoft API credentials are not configured.', ['status' => 500]);
    }

    // --- Prepare Request ---
    $args = [
        'method' => $method,
        'headers' => [
            'X-API-KEY' => $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 20,
    ];

    if ($body) {
        $args['body'] = json_encode($body);
    }

    $response = wp_remote_request($url, $args);

    // --- Logging for verification ---
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    $response_body_for_log = is_wp_error($response) ? 'WP_Error: ' . $response->get_error_message() : wp_remote_retrieve_body($response);
    $log_entry = "========================================================\n";
    $log_entry .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    $log_entry .= "Request Method: " . $method . "\n";
    $log_entry .= "Request URL: " . $url . "\n";
    if ($body) { $log_entry .= "Request Body: " . json_encode($body, JSON_PRETTY_PRINT) . "\n"; }
    $log_entry .= "Headers Sent: " . json_encode($args['headers']) . "\n";
    $log_entry .= "----------------- RESPONSE -----------------\n";
    $log_entry .= "Raw Response Body: " . $response_body_for_log . "\n";
    $log_entry .= "========================================================\n\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

    // --- Process Response ---
    if (is_wp_error($response)) { return $response; }
    $response_body = wp_remote_retrieve_body($response);

    // Use a completely different approach - manual JSON fixing
    $original_body = $response_body;

    // Method 1: Try to fix the JSON character by character approach
    $response_body = str_replace('"cod": "', '"cod":"', $response_body);
    $response_body = str_replace('", "denumire": "', '","denumire":"', $response_body);
    $response_body = str_replace('", "pret": "', '","pret":"', $response_body);

    // Fix the nested quotes
    $response_body = str_replace('""Detox de Dragoste – Corp și Scalp""', '"Detox de Dragoste – Corp și Scalp"', $response_body);
    $response_body = str_replace('"Spa Day Duo – "Lovers\' Day""', '"Spa Day Duo – Lovers Day"', $response_body);
    $response_body = str_replace('"Spa Day Single – "Love Yourself""', '"Spa Day Single – Love Yourself"', $response_body);

    $data = json_decode($response_body);

    // If still failing, try a completely different approach - extract data manually
    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents($log_file, "JSON parsing failed, trying manual extraction\n", FILE_APPEND | LOCK_EX);

        // Since JSON parsing is failing, let's extract the data manually using regex
        // This is a fallback approach to get the appointment scopes working

        $scopes = [];

        // Extract each appointment scope using regex - improved pattern to handle quotes
        preg_match_all('/\{"cod":(\d+),"scop":"([^"]*(?:\\.[^"]*)*)"(?:,"durata":(\d+))?/', $original_body, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $scope = new stdClass();
            $scope->cod = (int)$match[1];
            $scope->scop = $match[2];
            $scope->durata = isset($match[3]) ? (int)$match[3] : 60;
            $scopes[] = $scope;
        }

        if (!empty($scopes)) {
            file_put_contents($log_file, "Manual extraction successful, found " . count($scopes) . " scopes\n", FILE_APPEND | LOCK_EX);
            return $scopes;
        } else {
            file_put_contents($log_file, "Manual extraction failed too\n", FILE_APPEND | LOCK_EX);
            return new WP_Error('invalid_response', 'Could not parse appointment scopes data', ['status' => 500]);
        }
    }
    if (isset($data->Status) && $data->Status === 0) {
        return $data->ReturnData;
    } else {
        $message = isset($data->ErrorMessage) ? $data->ErrorMessage : (isset($data->Message) ? $data->Message : 'An unknown API error occurred.');
        $error_code = isset($data->ErrorCode) ? $data->ErrorCode : 'api_error';
        return new WP_Error($error_code, $message, ['status' => 400]);
    }
}

// --- Callback Functions ---

/**
 * Get clinic locations
 */
function get_medsoft_locations($request) { 
    return medsoft_api_request('/clinicLocations'); 
}

/**
 * Get services (price list)
 */
function get_medsoft_services($request) { 
    $result = medsoft_api_request('/priceList');
    
    // If there's an error, return it
    if (is_wp_error($result)) {
        return $result;
    }
    
    // Define service types to exclude (swapped - now excluding the previously included ones)
    $excluded_types = [
        'TRATAMENTE FACIALE',
        'DRENAJ (PRESOTERAPIE & TERMOTERAPIE)',
        'EPILARE DEFINITIVA LASER',
        'REMODELARE CORPORALA',
        'TERAPIE CRANIO-SACRALA'
    ];
    
    // Filter out excluded service types
    if (is_array($result)) {
        $filtered_services = array_filter($result, function($service) use ($excluded_types) {
            // Check if the service has a tip_serviciu property
            if (isset($service->tip_serviciu)) {
                // Return false (exclude) if the service type is in our excluded list
                return !in_array($service->tip_serviciu, $excluded_types);
            }
            // Include services that don't have a tip_serviciu property
            return true;
        });
        
        // Reset array keys
        $filtered_services = array_values($filtered_services);
        
        // Log how many services were filtered out
        error_log('MedSoft services: ' . count($result) . ' total, ' . count($filtered_services) . ' after filtering');
        
        return $filtered_services;
    }
    
    // If result is not an array, return it as is
    return $result;
}

/**
 * Get appointment scopes (types of appointments)
 * This is a new endpoint based on the documentation
 */
function get_medsoft_appointment_scopes($request) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== get_medsoft_appointment_scopes called ===\n", FILE_APPEND | LOCK_EX);

    $result = medsoft_api_request('/appointmentScop');

    if (is_wp_error($result)) {
        file_put_contents($log_file, "Error in get_medsoft_appointment_scopes: " . $result->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
        return $result;
    }

    file_put_contents($log_file, "Raw result type: " . gettype($result) . "\n", FILE_APPEND | LOCK_EX);
    file_put_contents($log_file, "Raw result: " . json_encode($result) . "\n", FILE_APPEND | LOCK_EX);

    // Check if result is an array
    if (is_array($result)) {
        file_put_contents($log_file, "Result is array with " . count($result) . " items\n", FILE_APPEND | LOCK_EX);

        // Log first item to see structure
        if (!empty($result)) {
            file_put_contents($log_file, "First item: " . json_encode($result[0]) . "\n", FILE_APPEND | LOCK_EX);
            file_put_contents($log_file, "First item type: " . gettype($result[0]) . "\n", FILE_APPEND | LOCK_EX);
        }

        return $result;
    } else {
        file_put_contents($log_file, "Result is not an array, type: " . gettype($result) . "\n", FILE_APPEND | LOCK_EX);

        // If it's an object, try to convert it
        if (is_object($result)) {
            file_put_contents($log_file, "Converting object to array\n", FILE_APPEND | LOCK_EX);
            $array_result = (array) $result;
            file_put_contents($log_file, "Converted result: " . json_encode($array_result) . "\n", FILE_APPEND | LOCK_EX);
            return $array_result;
        }

        // Return empty array as fallback
        file_put_contents($log_file, "Returning empty array as fallback\n", FILE_APPEND | LOCK_EX);
        return [];
    }
}

// --- Service Categories Configuration ---
function get_medsoft_service_categories() {
    // Get all services from MedSoft to extract unique categories
    $services = medsoft_api_request('/priceList');

    if (is_wp_error($services)) {
        error_log('Failed to get services from MedSoft: ' . $services->get_error_message());
        return [];
    }

    if (empty($services)) {
        error_log('No services returned from MedSoft priceList');
        return [];
    }

    // Define excluded categories (swapped - now excluding the previously included ones, but keeping MASAJ & SPA)
    $excluded_categories = [
        'TRATAMENTE FACIALE',
        'DRENAJ (PRESOTERAPIE & TERMOTERAPIE)',
        'EPILARE DEFINITIVA LASER',
        'REMODELARE CORPORALA',
        'TERAPIE CRANIO-SACRALA'
    ];

    $categories = [];
    $category_descriptions = [
        'HEADSPA' => 'Tratamente specializate pentru îngrijirea scalpului și relaxare',
        'HEADSPA & DRENAJ' => 'Combinație de tratamente pentru scalp și drenaj limfatic',
        'HEADSPA & TERAPIE CRANIO-SACRALA' => 'Tratamente holistice pentru scalp și echilibrul cranio-sacral',
        'HEADSPA & TRATAMENTE FACIALE' => 'Îngrijire completă pentru scalp și față',
        'MASAJ & SPA' => 'Tratamente de relaxare și îngrijire holistică',
        'TERAPIE CRANIO-SACRALA' => 'Terapii specializate pentru echilibrul corpului',
        'DERMATOLOGIE' => 'Tratamente dermatologice specializate',
        'COSMETICA MEDICALA' => 'Proceduri cosmetice medicale avansate'
    ];

    $category_icons = [
        'HEADSPA' => '💆',
        'HEADSPA & DRENAJ' => '💆💧',
        'HEADSPA & TERAPIE CRANIO-SACRALA' => '💆🌿',
        'HEADSPA & TRATAMENTE FACIALE' => '💆✨',
        'MASAJ & SPA' => '🧘',
        'TERAPIE CRANIO-SACRALA' => '🌿',
        'DERMATOLOGIE' => '🏥',
        'COSMETICA MEDICALA' => '💉'
    ];

    // Extract unique categories from services
    foreach ($services as $service) {
        if (isset($service->tip_serviciu) && !in_array($service->tip_serviciu, $excluded_categories)) {
            $category_key = $service->tip_serviciu;

            if (!isset($categories[$category_key])) {
                $categories[$category_key] = [
                    'name' => $category_key,
                    'description' => isset($category_descriptions[$category_key]) ?
                                   $category_descriptions[$category_key] :
                                   'Servicii specializate ' . strtolower($category_key),
                    'icon' => isset($category_icons[$category_key]) ?
                             $category_icons[$category_key] : '🏥',
                    'service_count' => 0
                ];
            }

            $categories[$category_key]['service_count']++;
        }
    }

    error_log('Extracted categories: ' . implode(', ', array_keys($categories)));
    error_log('Total categories found: ' . count($categories));

    return $categories;
}

/**
 * Quick check if a service has available time slots at a location
 */
function check_service_has_slots($service_id, $location_id, $date) {
    try {
        // Get location doctors
        $doctors_response = medsoft_api_request('/locationDoctors?locationId=' . $location_id);
        if (is_wp_error($doctors_response) || empty($doctors_response)) {
            error_log("check_service_has_slots: Failed to get location doctors for location $location_id");
            return false;
        }

        $location_doctors = [];
        foreach ($doctors_response as $doctor) {
            if (isset($doctor->DoctorId)) {
                $location_doctors[] = $doctor->DoctorId;
            }
        }

        if (empty($location_doctors)) {
            error_log("check_service_has_slots: No doctors found at location $location_id");
            return false;
        }

        // Get service details to check doctor assignment
        $services_response = medsoft_api_request('/priceList');
        if (is_wp_error($services_response) || empty($services_response)) {
            error_log("check_service_has_slots: Failed to get service list");
            return false;
        }

        $service = null;
        foreach ($services_response as $s) {
            if (isset($s->cod) && $s->cod == $service_id) {
                $service = $s;
                break;
            }
        }

        if (!$service) {
            error_log("check_service_has_slots: Service $service_id not found");
            return false;
        }

        // Check if service has available doctors at this location
        $available_doctors = [];

        // First check if service is available at this location (punct_lucru)
        if (isset($service->punct_lucru) && !empty($service->punct_lucru)) {
            $service_locations = explode(',', $service->punct_lucru);
            if (!in_array($location_id, $service_locations)) {
                error_log("check_service_has_slots: Service $service_id not available at location $location_id (punct_lucru: {$service->punct_lucru})");
                return false;
            }
        }

        // Then check doctor assignment
        if (isset($service->cod_utilizator) && !empty($service->cod_utilizator)) {
            // Service has specific doctor - check if they work at this location
            if (in_array($service->cod_utilizator, $location_doctors)) {
                $available_doctors[] = $service->cod_utilizator;
                error_log("check_service_has_slots: Service $service_id has specific doctor {$service->cod_utilizator} at location $location_id");
            } else {
                error_log("check_service_has_slots: Service $service_id assigned to doctor {$service->cod_utilizator} who doesn't work at location $location_id");
                return false;
            }
        } else {
            // Service has no specific doctor - any doctor at this location can perform it
            $available_doctors = $location_doctors;
            error_log("check_service_has_slots: Service $service_id available at location $location_id with any doctor");
        }

        if (empty($available_doctors)) {
            error_log("check_service_has_slots: No available doctors for service $service_id at location $location_id");
            return false;
        }

        // Instead of checking individual doctor schedules, just check if there are any doctors available
        // The actual slot generation will handle the detailed schedule checking
        error_log("check_service_has_slots: Service $service_id has " . count($available_doctors) . " available doctors at location $location_id");
        return true;

    } catch (Exception $e) {
        error_log('Error checking service slots: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get booking categories for the frontend - filtered by location availability
 */
function get_booking_categories($request) {
    $location_id = sanitize_text_field($request->get_param('locationId'));

    if (!$location_id) {
        // If no location specified, return all categories
        $categories = get_medsoft_service_categories();
        error_log('Categories returned to frontend (no location filter): ' . json_encode(array_keys($categories)));
        return $categories;
    }

    error_log('=== FILTERING CATEGORIES BY LOCATION ' . $location_id . ' ===');

    // Get all categories first
    $all_categories = get_medsoft_service_categories();

    // Get all services from MedSoft
    $all_services = medsoft_api_request('/priceList');
    if (is_wp_error($all_services)) {
        error_log('Error getting services for category filtering: ' . $all_services->get_error_message());
        return $all_categories; // Return all categories if we can't filter
    }

    // Get location doctors for filtering
    $location_doctors = [];
    $doctors_response = medsoft_api_request('/locationDoctors?locationId=' . $location_id);
    if (!is_wp_error($doctors_response)) {
        foreach ($doctors_response as $doctor) {
            if (isset($doctor->DoctorId)) {
                $location_doctors[] = $doctor->DoctorId;
            }
        }
        error_log('Location ' . $location_id . ' has doctors: ' . implode(', ', $location_doctors));
    }

    // Filter categories that have available services at this location
    $available_categories = [];

    foreach ($all_categories as $category_key => $category_info) {
        $category_has_services = false;

        // Check if this category has any services with actual available time slots at this location
        foreach ($all_services as $service) {
            if (isset($service->tip_serviciu) && $service->tip_serviciu === $category_key) {
                // Check if service has available doctors at this location
                $service_has_doctors = false;

                if (isset($service->cod_utilizator) && !empty($service->cod_utilizator)) {
                    // Service has specific doctor - check if that doctor works at this location
                    if (in_array($service->cod_utilizator, $location_doctors)) {
                        $service_has_doctors = true;
                    }
                } else {
                    // Service has no specific doctor assignment - check if service location includes this location
                    if (isset($service->punct_lucru)) {
                        $service_locations = explode(',', $service->punct_lucru);
                        if (in_array($location_id, $service_locations) && !empty($location_doctors)) {
                            $service_has_doctors = true;
                        }
                    }
                }

                // If service has potential doctors, check if it actually has available time slots
                if ($service_has_doctors) {
                    // Make a quick check for available slots for this service
                    $today = date('Y-m-d');
                    $slots_available = check_service_has_slots($service->cod, $location_id, $today);

                    if ($slots_available) {
                        $category_has_services = true;
                        error_log('✓ Service ' . $service->cod . ' (' . $service->denumire . ') has available slots at location ' . $location_id);
                        break; // Found at least one service with available slots, category is available
                    } else {
                        error_log('✗ Service ' . $service->cod . ' (' . $service->denumire . ') has NO available slots at location ' . $location_id);
                    }
                }
            }
        }

        if ($category_has_services) {
            $available_categories[$category_key] = $category_info;
            error_log('✓ Category "' . $category_key . '" has available services at location ' . $location_id);
        } else {
            error_log('✗ Category "' . $category_key . '" has NO available services at location ' . $location_id);
        }
    }

    error_log('=== CATEGORY FILTERING RESULT ===');
    error_log('Total categories: ' . count($all_categories));
    error_log('Available categories at location ' . $location_id . ': ' . count($available_categories));
    error_log('Available category names: ' . implode(', ', array_keys($available_categories)));

    return $available_categories;
}

/**
 * Get services for a specific category using MedSoft priceList
 */
function get_category_services($request) {
    $raw_category = $request->get_param('category');
    $location_id = sanitize_text_field($request->get_param('locationId'));
    $category = urldecode($raw_category);
    // Decode HTML entities that might be in the URL
    $category = html_entity_decode($category, ENT_QUOTES, 'UTF-8');
    $category = sanitize_text_field($category);
    $categories = get_medsoft_service_categories();

    // Debug logging
    error_log('Raw category parameter: ' . $raw_category);
    error_log('Decoded category: ' . $category);
    error_log('Location ID for filtering: ' . $location_id);
    error_log('Category length: ' . strlen($category));
    error_log('Available categories: ' . implode(', ', array_keys($categories)));

    // Check for exact match first
    if (!isset($categories[$category])) {
        // Try to find a close match (in case of encoding issues)
        $found_match = false;
        foreach (array_keys($categories) as $available_category) {
            // Try multiple matching strategies
            $normalized_available = str_replace([' ', '&', '(', ')'], '', $available_category);
            $normalized_requested = str_replace([' ', '&', '(', ')'], '', $category);

            if ($normalized_available === $normalized_requested) {
                error_log('Found close match: ' . $available_category . ' for ' . $category);
                $category = $available_category;
                $found_match = true;
                break;
            }
        }

        if (!$found_match) {
            return new WP_Error('invalid_category', 'Category not found: ' . $category . '. Available: ' . implode(', ', array_keys($categories)), ['status' => 404]);
        }
    }

    // Get all services from MedSoft priceList
    $all_services = medsoft_api_request('/priceList');

    if (is_wp_error($all_services)) {
        return $all_services;
    }

    // Get location doctors if location is specified for filtering
    $location_doctors = [];
    if ($location_id) {
        $doctors_response = medsoft_api_request('/locationDoctors?locationId=' . $location_id);
        if (!is_wp_error($doctors_response)) {
            foreach ($doctors_response as $doctor) {
                if (isset($doctor->DoctorId)) {
                    $location_doctors[] = $doctor->DoctorId;
                }
            }
            error_log('Location ' . $location_id . ' has doctors: ' . implode(', ', $location_doctors));
        }
    }

    // Filter services based on category (tip_serviciu) and location availability
    error_log('=== FILTERING SERVICES FOR CATEGORY "' . $category . '" AT LOCATION ' . $location_id . ' ===');

    $filtered_services = [];

    foreach ($all_services as $service) {
        if (isset($service->tip_serviciu) && $service->tip_serviciu === $category) {
            $service_available = false;

            // If location is specified, check if service can be performed at this location
            if ($location_id && !empty($location_doctors)) {
                // Check if service has a specific doctor assignment
                if (isset($service->cod_utilizator) && !empty($service->cod_utilizator)) {
                    // Service has specific doctor - check if that doctor works at this location
                    if (in_array($service->cod_utilizator, $location_doctors)) {
                        $service_available = true;
                        error_log('✓ Service ' . $service->cod . ' (' . $service->denumire . ') - assigned doctor ' . $service->cod_utilizator . ' works at location ' . $location_id);
                    } else {
                        error_log('✗ Service ' . $service->cod . ' (' . $service->denumire . ') - assigned doctor ' . $service->cod_utilizator . ' does NOT work at location ' . $location_id);
                    }
                } else {
                    // Service has no specific doctor assignment - available if location has any doctors
                    if (!empty($location_doctors)) {
                        $service_available = true;
                        error_log('✓ Service ' . $service->cod . ' (' . $service->denumire . ') - no specific assignment, location has doctors');
                    } else {
                        error_log('✗ Service ' . $service->cod . ' (' . $service->denumire . ') - no doctors at location');
                    }
                }
            } else {
                // No location specified or no doctors found - include all services (fallback)
                $service_available = true;
                error_log('✓ Service ' . $service->cod . ' (' . $service->denumire . ') - no location filter applied');
            }

            // If service passes basic availability check, also verify it has actual time slots
            if ($service_available) {
                $today = date('Y-m-d');
                $has_slots = check_service_has_slots($service->cod, $location_id, $today);

                if ($has_slots) {
                    $filtered_services[] = $service;
                    error_log('✓ Service ' . $service->cod . ' (' . $service->denumire . ') - HAS available time slots');
                } else {
                    error_log('✗ Service ' . $service->cod . ' (' . $service->denumire . ') - NO available time slots');
                }
            }
        }
    }

    error_log('=== SERVICE FILTERING RESULT ===');
    error_log('Category: ' . $category);
    error_log('Location: ' . $location_id);
    error_log('Total services in category: ' . count(array_filter($all_services, function($s) use ($category) {
        return isset($s->tip_serviciu) && $s->tip_serviciu === $category;
    })));
    error_log('Available services at location: ' . count($filtered_services));

    if (empty($filtered_services)) {
        error_log('WARNING: No services available for category "' . $category . '" at location ' . $location_id);
    }

    return [
        'category' => $categories[$category],
        'services' => $filtered_services
    ];
}

/**
 * Get available time slots for booking using MedSoft API
 */
function get_available_slots($request) {
    // Add debugging to see if function is being called
    error_log('=== get_available_slots function called ===');
    error_log('Request parameters: ' . json_encode($request->get_params()));

    $location_id = sanitize_text_field($request->get_param('locationId'));
    $service_id = sanitize_text_field($request->get_param('serviceId'));
    $date = sanitize_text_field($request->get_param('date')) ?: date('Y-m-d');

    error_log('Parsed parameters - locationId: ' . $location_id . ', serviceId: ' . $service_id . ', date: ' . $date);

    if (!$location_id) {
        error_log('Missing location ID, returning error');
        return new WP_Error('missing_location', 'Location ID is required', ['status' => 400]);
    }

    error_log('Getting slots for location: ' . $location_id . ', service: ' . $service_id . ', date: ' . $date);

    // Get location schedule for the next 7 days (as per MedSoft documentation)
    $end_date = date('Y-m-d', strtotime($date . ' +7 days'));
    $schedule_url = '/locationSchedule?locationId=' . $location_id . '&date=' . $date . '&dateEnd=' . $end_date;

    error_log('Calling MedSoft API: ' . $schedule_url);
    $schedule = medsoft_api_request($schedule_url);

    if (is_wp_error($schedule)) {
        error_log('Error getting schedule: ' . $schedule->get_error_message());
        return $schedule;
    }

    // Get doctors for this location (as per MedSoft documentation)
    $doctors_url = '/locationDoctors?locationId=' . $location_id;
    $doctors = medsoft_api_request($doctors_url);

    if (is_wp_error($doctors)) {
        error_log('Error getting doctors: ' . $doctors->get_error_message());
        // Continue without doctors info
        $doctors = [];
    }

    // Debug: Check what doctors appear in schedule vs locationDoctors
    $schedule_doctors = [];
    foreach ($schedule as $period) {
        if (isset($period->DoctorId)) {
            $doctor_id = $period->DoctorId;
            if (!isset($schedule_doctors[$doctor_id])) {
                $schedule_doctors[$doctor_id] = [
                    'SpecialtyId' => isset($period->SpecialtyId) ? $period->SpecialtyId : 'NULL',
                    'LocationId' => isset($period->LocationId) ? $period->LocationId : 'NULL'
                ];
            }
        }
    }

    $location_doctor_ids = [];
    foreach ($doctors as $doctor) {
        if (isset($doctor->DoctorId)) {
            $location_doctor_ids[] = $doctor->DoctorId;
        }
    }

    error_log('Schedule doctors with SpecialtyId: ' . json_encode($schedule_doctors));
    error_log('LocationDoctors API returned: ' . implode(', ', $location_doctor_ids));

    // Find doctors in schedule but not in locationDoctors and add them if they have valid SpecialtyId
    foreach ($schedule_doctors as $doctor_id => $info) {
        if (!in_array($doctor_id, $location_doctor_ids)) {
            error_log('Doctor ' . $doctor_id . ' appears in schedule but NOT in locationDoctors - SpecialtyId: ' . $info['SpecialtyId']);

            // If doctor has a valid SpecialtyId (not NULL), add them to the doctors list
            if ($info['SpecialtyId'] !== 'NULL' && !empty($info['SpecialtyId'])) {
                $missing_doctor = (object) [
                    'DoctorId' => $doctor_id,
                    'SpecialtyId' => $info['SpecialtyId'],
                    'LocationId' => $info['LocationId'],
                    'Name' => 'Doctor disponibil', // We don't have the name from schedule
                    'SlotDuration' => 30 // Default slot duration
                ];
                $doctors[] = $missing_doctor;
                error_log('Added missing doctor ' . $doctor_id . ' from schedule to doctors list');
            }
        }
    }

    // CRITICAL: Filter doctors who can perform the selected service
    if ($service_id && !empty($doctors)) {
        error_log('=== DOCTOR-SERVICE FILTERING START ===');
        error_log('Filtering doctors for service ID: ' . $service_id);
        error_log('Total doctors before filtering: ' . count($doctors));

        // Get service details from priceList
        $service_details = null;
        $price_list = medsoft_api_request('/priceList');

        if (!is_wp_error($price_list)) {
            foreach ($price_list as $service) {
                if (isset($service->cod) && strval($service->cod) === strval($service_id)) {
                    $service_details = $service;
                    error_log('Found service details: ' . json_encode($service));
                    break;
                }
            }
        }

        if (!$service_details) {
            error_log('ERROR: Service details not found for service ID ' . $service_id);
            return new WP_Error('service_not_found', 'Service not found', ['status' => 404]);
        }

        $service_doctors = [];

        // METHOD 1: Check for specific doctor assignment (cod_utilizator)
        if (isset($service_details->cod_utilizator) && !empty($service_details->cod_utilizator)) {
            error_log('Service has specific doctor assignment: ' . $service_details->cod_utilizator);

            foreach ($doctors as $doctor) {
                if (isset($doctor->DoctorId) && $doctor->DoctorId == $service_details->cod_utilizator) {
                    $service_doctors[] = $doctor;
                    $doctor_name = isset($doctor->Name) ? $doctor->Name : 'Doctor ' . $doctor->DoctorId;
                    error_log('✓ Doctor ' . $doctor->DoctorId . ' (' . $doctor_name . ') is specifically assigned to this service');
                    break; // Only one doctor can be specifically assigned
                }
            }
        } else {
            error_log('Service has no specific doctor assignment - checking schedule compatibility');

            // METHOD 2: Check which doctors have schedule slots that can accommodate this service
            // This is the key improvement - we check the actual schedule data
            foreach ($doctors as $doctor) {
                $doctor_name = isset($doctor->Name) ? $doctor->Name : 'Doctor ' . $doctor->DoctorId;
                $doctor_can_perform = false;

                // Check if this doctor has any schedule slots
                foreach ($schedule as $slot) {
                    if (isset($slot->DoctorId) && $slot->DoctorId == $doctor->DoctorId) {
                        // Check if the slot is available and matches the service requirements
                        if (isset($slot->IsAvailable) && $slot->IsAvailable == 1) {
                            $doctor_can_perform = true;
                            error_log('✓ Doctor ' . $doctor->DoctorId . ' (' . $doctor_name . ') has available schedule slots');
                            break;
                        }
                    }
                }

                // Additional check: If doctor has a SpecialtyId, verify it's compatible
                if ($doctor_can_perform && isset($doctor->SpecialtyId)) {
                    // Get all SpecialtyIds that appear in the schedule for this location
                    $schedule_specialties = [];
                    foreach ($schedule as $slot) {
                        if (isset($slot->SpecialtyId) && !in_array($slot->SpecialtyId, $schedule_specialties)) {
                            $schedule_specialties[] = $slot->SpecialtyId;
                        }
                    }

                    if (!in_array($doctor->SpecialtyId, $schedule_specialties)) {
                        error_log('✗ Doctor ' . $doctor->DoctorId . ' (' . $doctor_name . ') specialty not found in schedule');
                        $doctor_can_perform = false;
                    }
                }

                if ($doctor_can_perform) {
                    $service_doctors[] = $doctor;
                    error_log('✓ Doctor ' . $doctor->DoctorId . ' (' . $doctor_name . ') can perform service ' . $service_id);
                } else {
                    error_log('✗ Doctor ' . $doctor->DoctorId . ' (' . $doctor_name . ') cannot perform service ' . $service_id);
                }
            }
        }

        // Apply the filtering
        if (!empty($service_doctors)) {
            $doctors = $service_doctors;
            error_log('=== FILTERING RESULT: ' . count($service_doctors) . ' doctors can perform service ' . $service_id . ' ===');
            foreach ($service_doctors as $doctor) {
                $doctor_name = isset($doctor->Name) ? $doctor->Name : 'Doctor ' . $doctor->DoctorId;
                error_log('- Doctor ' . $doctor->DoctorId . ': ' . $doctor_name);
            }
        } else {
            error_log('=== FILTERING RESULT: NO doctors found for service ' . $service_id . ' ===');
            // Return empty result instead of showing all doctors
            return [];
        }

        error_log('=== DOCTOR-SERVICE FILTERING END ===');
    }

    // Get service duration if service_id is provided
    $service_duration = 60; // Default duration
    if ($service_id) {
        $services = medsoft_api_request('/priceList');
        if (!is_wp_error($services)) {
            foreach ($services as $service) {
                if (isset($service->cod) && $service->cod == $service_id) {
                    // Get duration from service (durata field as per documentation)
                    $service_duration = isset($service->durata) ? intval($service->durata) : 60;
                    error_log('Found service duration: ' . $service_duration . ' minutes');
                    break;
                }
            }
        }
    }

    // Process schedule into available slots
    $slots = process_schedule_to_slots($schedule, $service_duration, $doctors);

    error_log('Generated ' . count($slots) . ' time slots');
    return $slots;
}

/**
 * Process location schedule into bookable time slots according to MedSoft API format
 */
function process_schedule_to_slots($schedule, $duration_minutes = 60, $doctors = []) {
    if (empty($schedule) || !is_array($schedule)) {
        error_log('Empty or invalid schedule data');
        return [];
    }

    $slots = [];
    $slot_duration = $duration_minutes * 60; // Convert to seconds

    // Create doctor lookup for names
    $doctor_names = [];
    if (!empty($doctors)) {
        foreach ($doctors as $doctor) {
            if (isset($doctor->DoctorId) && isset($doctor->Name)) {
                $doctor_names[$doctor->DoctorId] = $doctor->Name;
            }
        }
    }

    // Create a list of allowed doctor IDs from the filtered doctors ONLY
    $allowed_doctor_ids = [];
    if (!empty($doctors)) {
        foreach ($doctors as $doctor) {
            if (isset($doctor->DoctorId)) {
                $allowed_doctor_ids[] = $doctor->DoctorId;
            }
        }
    }

    error_log('=== SLOT GENERATION START ===');
    error_log('Allowed doctor IDs for slots (after filtering): ' . implode(', ', $allowed_doctor_ids));
    error_log('Total schedule periods to process: ' . count($schedule));

    $processed_periods = 0;
    $skipped_periods = 0;

    foreach ($schedule as $period) {
        // Check if this period is available (as per MedSoft documentation)
        if (!isset($period->IsAvailable) || $period->IsAvailable != 1) {
            $skipped_periods++;
            continue;
        }

        if (!isset($period->StartDateTime) || !isset($period->EndDateTime)) {
            $skipped_periods++;
            continue;
        }

        // CRITICAL: Only process schedule periods for doctors who can perform the service
        $doctor_id = isset($period->DoctorId) ? $period->DoctorId : null;
        if (!empty($allowed_doctor_ids) && $doctor_id && !in_array($doctor_id, $allowed_doctor_ids)) {
            error_log('Skipping schedule period for doctor ' . $doctor_id . ' (not in filtered doctor list)');
            $skipped_periods++;
            continue;
        }

        if (empty($allowed_doctor_ids)) {
            error_log('WARNING: No filtered doctors available - no slots will be generated');
            $skipped_periods++;
            continue;
        }

        $processed_periods++;

        // Parse MedSoft datetime format (ISO 8601 with timezone)
        $start_time = strtotime($period->StartDateTime);
        $end_time = strtotime($period->EndDateTime);

        if ($start_time === false || $end_time === false) {
            error_log('Invalid datetime format: ' . $period->StartDateTime . ' - ' . $period->EndDateTime);
            continue;
        }

        $current_time = $start_time;

        // Generate slots within this period
        while ($current_time + $slot_duration <= $end_time) {
            $slot_start = date('Y-m-d H:i:s', $current_time);
            $slot_end = date('Y-m-d H:i:s', $current_time + $slot_duration);

            $doctor_id = isset($period->DoctorId) ? $period->DoctorId : null;
            $doctor_name = isset($doctor_names[$doctor_id]) ? $doctor_names[$doctor_id] : 'Doctor disponibil';

            $slots[] = [
                'start_time' => $slot_start,
                'end_time' => $slot_end,
                'date' => date('Y-m-d', $current_time),
                'time' => date('H:i', $current_time),
                'doctor_id' => $doctor_id,
                'doctor_name' => $doctor_name,
                'location_id' => isset($period->LocationId) ? $period->LocationId : null,
                'specialty_id' => isset($period->SpecialtyId) ? $period->SpecialtyId : null,
                'available' => true
            ];

            $current_time += $slot_duration;
        }
    }

    // Group slots by date for easier frontend handling
    $grouped_slots = [];
    foreach ($slots as $slot) {
        $date = $slot['date'];
        if (!isset($grouped_slots[$date])) {
            $grouped_slots[$date] = [];
        }
        $grouped_slots[$date][] = $slot;
    }

    error_log('=== SLOT GENERATION SUMMARY ===');
    error_log('Processed periods: ' . $processed_periods);
    error_log('Skipped periods: ' . $skipped_periods);
    error_log('Generated slots: ' . count($slots));
    error_log('Days with slots: ' . count($grouped_slots));
    error_log('=== SLOT GENERATION END ===');

    return $grouped_slots;
}





/**
 * Get doctors for a specific location
 */
function get_medsoft_doctors($request) {
    $location_id = sanitize_text_field($request->get_param('locationId'));
    if (!$location_id) {
        return new WP_Error('missing_location', 'Location ID is required', ['status' => 400]);
    }
    return medsoft_api_request('/locationDoctors?locationId=' . $location_id);
}

/**
 * Get location schedule
 * This is a new endpoint based on the documentation
 */
function get_medsoft_location_schedule($request) {
    $location_id = sanitize_text_field($request->get_param('locationId'));
    $service_id = sanitize_text_field($request->get_param('serviceId'));
    $start_date = sanitize_text_field($request->get_param('date')) ?: date('Y-m-d');
    $end_date = sanitize_text_field($request->get_param('dateEnd')) ?: date('Y-m-d', strtotime($start_date . ' +7 days'));

    if (!$location_id) {
        return new WP_Error('missing_location', 'Location ID is required', ['status' => 400]);
    }

    if (!$service_id) {
        return new WP_Error('missing_service', 'Service ID is required', ['status' => 400]);
    }

    // Get raw schedule data from MedSoft
    $schedule_response = medsoft_api_request('/locationSchedule?locationId=' . $location_id . '&date=' . $start_date . '&dateEnd=' . $end_date);

    if (is_wp_error($schedule_response)) {
        return $schedule_response;
    }

    // Get doctors for this location
    $doctors_response = medsoft_api_request('/locationDoctors?locationId=' . $location_id);

    if (is_wp_error($doctors_response)) {
        return $doctors_response;
    }

    // Get service duration
    $service_duration = 60; // Default duration
    $scopes_response = medsoft_api_request('/appointmentScop');
    if (!is_wp_error($scopes_response)) {
        foreach ($scopes_response as $scope) {
            if (isset($scope->servicii) && is_array($scope->servicii)) {
                foreach ($scope->servicii as $service) {
                    if (isset($service->cod) && $service->cod == $service_id && isset($scope->durata)) {
                        $service_duration = $scope->durata;
                        break 2;
                    }
                }
            }
        }
    }

    // Process and group slots by doctor
    $grouped_slots = [];

    foreach ($schedule_response as $slot) {
        // Check if this slot is for a service that matches our service_id
        if (isset($slot->servicii) && is_array($slot->servicii)) {
            $service_found = false;
            foreach ($slot->servicii as $service) {
                if (isset($service->cod) && $service->cod == $service_id) {
                    $service_found = true;
                    break;
                }
            }

            if (!$service_found) {
                continue; // Skip this slot if it doesn't match our service
            }
        }

        // Find doctor info
        $doctor_info = null;
        foreach ($doctors_response as $doctor) {
            if (isset($doctor->DoctorId) && $doctor->DoctorId == $slot->DoctorId) {
                $doctor_info = $doctor;
                break;
            }
        }

        if (!$doctor_info) {
            continue; // Skip if we can't find doctor info
        }

        // Generate time slots based on service duration
        $start_time = strtotime($slot->StartDateTime);
        $end_time = strtotime($slot->EndDateTime);
        $duration_seconds = $service_duration * 60;

        $current_time = $start_time;
        while ($current_time + $duration_seconds <= $end_time) {
            $slot_start = date('Y-m-d H:i:s', $current_time);
            $slot_end = date('Y-m-d H:i:s', $current_time + $duration_seconds);

            // Group by doctor
            $doctor_key = $doctor_info->DoctorId;
            if (!isset($grouped_slots[$doctor_key])) {
                $grouped_slots[$doctor_key] = [
                    'doctor' => [
                        'id' => $doctor_info->DoctorId,
                        'name' => $doctor_info->Name ?? 'Doctor ' . $doctor_info->DoctorId
                    ],
                    'slots' => []
                ];
            }

            $grouped_slots[$doctor_key]['slots'][] = [
                'start' => $slot_start,
                'end' => $slot_end,
                'available' => true
            ];

            $current_time += $duration_seconds;
        }
    }

    // Convert to array and sort by doctor name
    $result = array_values($grouped_slots);
    usort($result, function($a, $b) {
        return strcmp($a['doctor']['name'], $b['doctor']['name']);
    });

    return $result;
}

/**
 * Get available slots for a doctor
 * This function now follows the MedSoft logic more closely:
 * 1. Get doctor schedule
 * 2. Process into slots based on appointment scope duration
 */
function get_medsoft_slots($request) {
    $doctor_id = sanitize_text_field($request->get_param('doctorId'));
    $scope_id = sanitize_text_field($request->get_param('scopeId'));
    $interval_length = sanitize_text_field($request->get_param('intervalLength')) ?: 60; // Default to 60 min if not specified
    
    if (!$doctor_id) {
        return new WP_Error('missing_doctor', 'Doctor ID is required', ['status' => 400]);
    }
    
    // If scope_id is provided, try to get its duration
    if ($scope_id) {
        $scopes = medsoft_api_request('/appointmentScop');
        if (!is_wp_error($scopes)) {
            foreach ($scopes as $scope) {
                if ($scope->cod == $scope_id && isset($scope->durata)) {
                    $interval_length = $scope->durata;
                    break;
                }
            }
        }
    }
    
    date_default_timezone_set('Europe/Bucharest');
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d', strtotime($start_date . ' +30 days'));
    
    // Get raw schedule data
    $response = medsoft_api_request('/doctorSchedule?doctorId=' . $doctor_id . '&date=' . $start_date . '&dateEnd=' . $end_date);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    // Process the slots with our custom logic
    $processed_slots = process_time_slots($response, $interval_length);
    
    return $processed_slots;
}

/**
 * Process raw time slots into appointment intervals
 */
function process_time_slots($raw_slots, $interval_length = 60) {
    if (empty($raw_slots) || !is_array($raw_slots)) {
        return [];
    }
    
    $result = [];
    $interval_in_seconds = $interval_length * 60;
    
    foreach ($raw_slots as $slot) {
        $start_time = strtotime($slot['StartDateTime']);
        $end_time = strtotime($slot['EndDateTime']);
        $current = $start_time;
        
        while ($current + $interval_in_seconds <= $end_time) {
            $interval_start = date('Y-m-d H:i:s', $current);
            $interval_end = date('Y-m-d H:i:s', $current + $interval_in_seconds);
            
            $result[] = [
                'DoctorId' => $slot['DoctorId'],
                'StartDateTime' => $interval_start,
                'EndDateTime' => $interval_end,
                'IsAvailable' => $slot['IsAvailable'],
                'SpecialtyId' => $slot['SpecialtyId'],
                'LocationId' => $slot['LocationId']
            ];
            
            $current += $interval_in_seconds;
        }
    }
    
    // Sort intervals by start time
    usort($result, function($a, $b) {
        return strtotime($a['StartDateTime']) - strtotime($b['StartDateTime']);
    });
    
    return $result;
}

/**
 * Create a new appointment (using working plugin.php logic)
 */
function create_medsoft_appointment($request) {
    error_log('=== create_medsoft_appointment function called ===');
    error_log('Request method: ' . $request->get_method());
    error_log('Content type: ' . $request->get_content_type());

    $data = $request->get_json_params();

    // Log received data for debugging
    error_log('Received appointment data: ' . json_encode($data));

    if (!$data) {
        error_log('No JSON data received, trying to get params differently');
        $data = $request->get_params();
        error_log('Alternative data: ' . json_encode($data));
    }

    // Validate required fields
    $required_fields = ['doctorId', 'locationId', 'startDateTime', 'endDateTime', 'patientName', 'patientPhoneNumber'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            error_log("Missing required field: " . $field . " (value: " . (isset($data[$field]) ? $data[$field] : 'not set') . ")");
            return new WP_Error('missing_field', "Field '{$field}' is required", ['status' => 400]);
        }
    }

    // Handle doctorId - if null or 0, try to get one from location doctors
    if (empty($data['doctorId']) || $data['doctorId'] === 'null') {
        error_log('No valid doctorId provided, trying to get one from location doctors');
        $doctors = medsoft_api_request('/locationDoctors?locationId=' . intval($data['locationId']));
        if (!is_wp_error($doctors) && !empty($doctors)) {
            $data['doctorId'] = $doctors[0]->DoctorId; // Use first available doctor
            error_log('Using first available doctor: ' . $data['doctorId']);
        } else {
            return new WP_Error('missing_doctor', 'No doctor available for this location', ['status' => 400]);
        }
    }

    // Sanitize data
    $data['doctorId'] = intval($data['doctorId']);
    $data['locationId'] = intval($data['locationId']);
    $data['patientName'] = sanitize_text_field($data['patientName']);
    $data['patientPhoneNumber'] = sanitize_text_field($data['patientPhoneNumber']);
    $data['appointmentDetails'] = isset($data['appointmentDetails']) ? sanitize_text_field($data['appointmentDetails']) : '';

    // Log the original times for debugging
    error_log("Original appointment times - Start: " . $data['startDateTime'] . ", End: " . $data['endDateTime']);

    // IMPORTANT: The times are already in Europe/Bucharest timezone, so we don't need to convert them
    // Just ensure they're in the correct format
    try {
        // Parse the datetime strings without changing timezone
        $start_datetime = new DateTime($data['startDateTime']);
        $end_datetime = new DateTime($data['endDateTime']);

        // Format them correctly for the API
        $data['startDateTime'] = $start_datetime->format('Y-m-d H:i:s');
        $data['endDateTime'] = $end_datetime->format('Y-m-d H:i:s');

        // Log the formatted times
        error_log("Formatted appointment times - Start: " . $data['startDateTime'] . ", End: " . $data['endDateTime']);
    } catch (Exception $e) {
        return new WP_Error('date_format_error', 'Invalid date format from client: ' . $e->getMessage(), ['status' => 400]);
    }

    $data['codPacient'] = null;

    // Create clean data matching exactly what working js.js sends
    $clean_data = [
        'doctorId' => $data['doctorId'],
        'locationId' => $data['locationId'],
        'startDateTime' => $data['startDateTime'],
        'endDateTime' => $data['endDateTime'],
        'patientName' => $data['patientName'],
        'patientPhoneNumber' => $data['patientPhoneNumber'],
        'appointmentDetails' => $data['appointmentDetails']
    ];

    // Add patientEmail if provided (like working js.js does)
    if (!empty($data['patientEmail'])) {
        $clean_data['patientEmail'] = sanitize_email($data['patientEmail']);
    }

    // Add appointmentNotes if provided
    if (!empty($data['appointmentNotes'])) {
        $clean_data['appointmentNotes'] = sanitize_text_field($data['appointmentNotes']);
    }

    // Log the complete data being sent to MedSoft
    error_log('Complete appointment data being sent to MedSoft: ' . json_encode($clean_data));

    // Try different possible endpoint names
    $possible_endpoints = ['/createAppointment', '/createAppointments', '/appointments', '/appointment'];

    foreach ($possible_endpoints as $endpoint) {
        error_log("Trying endpoint: " . $endpoint);
        $result = medsoft_api_request($endpoint, 'POST', $clean_data);

        if (is_wp_error($result)) {
            error_log("Error from endpoint " . $endpoint . ": " . $result->get_error_message());
            error_log("Error code: " . $result->get_error_code());
            error_log("Error data: " . json_encode($result->get_error_data()));
        } else {
            error_log("Success from endpoint " . $endpoint . ": " . json_encode($result));
        }

        // If we don't get a 405 error, return the result (success or other error)
        if (!is_wp_error($result) || $result->get_error_code() !== 'invalid_response' ||
            strpos($result->get_error_message(), '405') === false) {

            if (!is_wp_error($result)) {
                error_log("Booking successful for: " . $clean_data['patientName'] . " at " . $clean_data['startDateTime'] . " using endpoint: " . $endpoint);

                // Try to set appointment status to confirmed
                $appointment_id = null;
                if (is_array($result) && isset($result[0]->AppointmentId)) {
                    $appointment_id = $result[0]->AppointmentId;
                }

                if ($appointment_id) {
                    error_log('Attempting to set appointment ' . $appointment_id . ' status to confirmed');

                    // First, check current status to see available options
                    $status_check = medsoft_api_request('/appointmentStatus?appointment=' . $appointment_id);
                    if (!is_wp_error($status_check)) {
                        error_log('Current appointment status: ' . json_encode($status_check));

                        // Try different modifyAppointment formats based on documentation
                        $modify_attempts = [
                            '/modifyAppointment/' . $appointment_id . '/0/PROGRAMARE_CONFIRMATA',
                            '/modifyAppointment/' . $appointment_id . '/' . $clean_data['patientPhoneNumber'] . '/CONFIRMAT',
                            '/modifyAppointment/' . $appointment_id . '/0/CONFIRMAT'
                        ];

                        foreach ($modify_attempts as $modify_url) {
                            error_log('Trying modifyAppointment with URL: ' . $modify_url);
                            $modify_result = medsoft_api_request($modify_url, 'POST');

                            if (!is_wp_error($modify_result)) {
                                error_log('Successfully set appointment status via: ' . $modify_url);
                                error_log('Modify result: ' . json_encode($modify_result));
                                break;
                            } else {
                                error_log('Failed with URL ' . $modify_url . ': ' . $modify_result->get_error_message());
                            }
                        }
                    } else {
                        error_log('Failed to get current appointment status: ' . $status_check->get_error_message());
                    }
                }

                return $result;
            }
            return $result;
        }
    }

    // If all endpoints failed with 405, return a helpful error
    return new WP_Error('endpoint_not_found', 'Could not find a valid booking endpoint. Please check the API documentation.', ['status' => 500]);
}

/**
 * Get raw doctor schedule
 */
function get_medsoft_doctor_schedule($request) {
    $doctor_id = sanitize_text_field($request->get_param('doctorId'));
    $start_date = sanitize_text_field($request->get_param('date')) ?: date('Y-m-d');
    $end_date = sanitize_text_field($request->get_param('dateEnd')) ?: date('Y-m-d', strtotime($start_date . ' +30 days'));
    
    if (!$doctor_id) {
        return new WP_Error('missing_doctor', 'Doctor ID is required', ['status' => 400]);
    }
    
    // Get raw schedule data
    return medsoft_api_request('/doctorSchedule?doctorId=' . $doctor_id . '&date=' . $start_date . '&dateEnd=' . $end_date);
}

/**
 * Get service time information
 * This is now a fallback for compatibility with the old system
 */
function get_medsoft_service_time($request) {
    // First try to get appointment scopes which is the correct way according to docs
    $scopes = medsoft_api_request('/appointmentScop');
    
    if (!is_wp_error($scopes) && !empty($scopes)) {
        // Convert appointment scopes to the service time format
        $service_times = [];
        foreach ($scopes as $scope) {
            if (isset($scope->cod) && isset($scope->scop) && isset($scope->durata)) {
                $service_times[] = [
                    'cod' => $scope->cod,
                    'denumireServiciu' => $scope->scop,
                    'timpProcedura' => $scope->durata
                ];
            }
        }
        
        if (!empty($service_times)) {
            return $service_times;
        }
    }
    
    // If appointment scopes fails, try the old serviceTime endpoint
    $response = medsoft_api_request('/serviceTime');
    
    // If we get an error or empty response, use a simple fallback
    if (is_wp_error($response) || empty($response)) {
        // Get the services from priceList to create a minimal fallback
        $services = medsoft_api_request('/priceList');
        
        if (!is_wp_error($services) && !empty($services)) {
            // Define service types to exclude (swapped - now excluding the previously included ones, but keeping MASAJ & SPA)
            $excluded_types = [
                'TRATAMENTE FACIALE',
                'DRENAJ (PRESOTERAPIE & TERMOTERAPIE)',
                'EPILARE DEFINITIVA LASER',
                'REMODELARE CORPORALA',
                'TERAPIE CRANIO-SACRALA'
            ];
            
            // Create a fallback with 60 minutes for each service
            $fallback = [];
            foreach ($services as $service) {
                // Skip excluded service types
                if (isset($service->tip_serviciu) && in_array($service->tip_serviciu, $excluded_types)) {
                    continue;
                }
                
                if (isset($service->cod) && isset($service->denumire)) {
                    $fallback[] = [
                        'cod' => $service->cod,
                        'denumireServiciu' => $service->denumire,
                        'timpProcedura' => 60 // Default to 60 minutes
                    ];
                }
            }
            
            if (!empty($fallback)) {
                error_log('Using generated fallback for service times with ' . count($fallback) . ' services');
                return $fallback;
            }
        }
        
        // If all else fails, return a minimal fallback
        error_log('Using minimal fallback for service times');
        return [
            ['cod' => 999, 'denumireServiciu' => 'Programare standard', 'timpProcedura' => 60]
        ];
    }
    
    return $response;
}

// --- Script Enqueueing ---
function enqueue_booking_script() {
    if (is_page('programare')) {
        wp_enqueue_style('flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
        wp_enqueue_script('flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr', [], null, true);
        wp_enqueue_script('flatpickr-ro', 'https://npmcdn.com/flatpickr/dist/l10n/ro.js', ['flatpickr-js'], null, true);
        wp_enqueue_script('my-booking-script', plugin_dir_url(__FILE__) . 'new-script.js', ['wp-api', 'flatpickr-js'], '2.6', true);
        wp_localize_script('my-booking-script', 'bookingApi', [
            'root' => esc_url_raw(rest_url('mybooking/v1/')),
            'nonce' => wp_create_nonce('wp_rest')
        ]);

        // Add inline CSS for step navigation
        wp_add_inline_style('flatpickr-css', '
            .booking-container section.is-inactive {
                display: none !important;
            }
            .booking-container section {
                display: block;
            }
        ');
    }
}

// --- Category Booking Shortcode ---
function category_booking_shortcode($atts) {
    // Enqueue the category booking script and styles
    wp_enqueue_style('serenity-booking-style', plugin_dir_url(__FILE__) . 'serenity-booking.css', [], '1.0');
    wp_enqueue_script('category-booking-script', plugin_dir_url(__FILE__) . 'category-booking.js', ['wp-api'], '39.0', true);

    // Localize script with API configuration including location data
    wp_localize_script('category-booking-script', 'bookingApiConfig', [
        'root' => esc_url_raw(rest_url('mybooking/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'locations' => [
            [
                'id' => '1',
                'name' => 'Serenity HeadSpa ARCU',
                'address' => 'Sos. Arcu nr. 79, Iași',
                'lat' => 47.17152,
                'lng' => 27.56374,
                'mapUrl' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2712.8!2d27.563716!3d47.171253!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x64a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20ARCU!5e0!3m2!1sen!2sro!4v1234567890'
            ],
            [
                'id' => '3',
                'name' => 'Serenity HeadSpa CARPAȚI',
                'address' => 'Strada Carpați nr. 9A, Iași',
                'lat' => 47.1435892186774,
                'lng' => 27.582183626983806,
                'mapUrl' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2713.2!2d27.582248!3d47.143363!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x58a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20CARPATI!5e0!3m2!1sen!2sro!4v1234567891'
            ],
            [
                'id' => '2',
                'name' => 'Serenity HeadSpa București',
                'address' => 'Strada Serghei Vasilievici Rahmaninov 38, interfon 05, București',
                'lat' => 44.468694785767745,
                'lng' => 26.106015899473665,
                'mapUrl' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2848.1!2d26.105458!3d44.468534!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x63a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20Bucuresti!5e0!3m2!1sen!2sro!4v1234567892'
            ]
        ]
    ]);

    // Return complete HTML structure
    ob_start();
    ?>
    <div id="category-booking-container" class="serenity-booking">

        <!-- Step 1: Location Selection -->
        <div id="locations-section" class="step-container active">
            <div class="step-header">
                <div class="step-number">1</div>
                <h2 class="step-title">Selectați locația</h2>
            </div>
            <div id="geolocation-status" class="geolocation-status" style="display: none;"></div>
            <div id="locations-loading" class="loading-spinner">
                <div class="spinner"></div>
                Se detectează locația...
            </div>
            <div id="locations-error" class="error-message" style="display: none;"></div>
            <div id="locations-grid" class="location-grid"></div>
        </div>

        <!-- Step 2: Category Selection -->
        <div id="categories-section" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">2</div>
                <h2 class="step-title">Selectați categoria de servicii</h2>
            </div>
            <div id="categories-loading" class="loading-spinner">
                <div class="spinner"></div>
                Se încarcă categoriile...
            </div>
            <div id="categories-error" class="error-message" style="display: none;"></div>
            <div id="categories-grid" class="categories-grid"></div>
        </div>

        <!-- Step 3: Service Selection -->
        <div id="services-section" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">3</div>
                <h2 class="step-title">Selectați serviciul</h2>
            </div>
            <div id="services-loading" class="loading-spinner">
                <div class="spinner"></div>
                Se încarcă serviciile...
            </div>
            <div id="services-error" class="error-message" style="display: none;"></div>
            <div id="services-grid" class="services-grid"></div>
        </div>

        <!-- Step 4: Date & Time Selection -->
        <div id="datetime-section" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">4</div>
                <h2 class="step-title">Selectați data și ora</h2>
            </div>
            <div id="datetime-loading" class="loading-spinner">
                <div class="spinner"></div>
                Se încarcă intervalele disponibile...
            </div>
            <div id="datetime-error" class="error-message" style="display: none;"></div>
            <div id="datetime-picker"></div>
        </div>

        <!-- Step 5: Confirmation -->
        <div id="confirmation-section" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">5</div>
                <h2 class="step-title">Confirmați programarea</h2>
            </div>
            <div id="booking-summary"></div>
            <form id="booking-form">
                <div class="form-group">
                    <label for="customer-name">Nume complet:</label>
                    <input type="text" id="customer-name" name="customerName" required>
                </div>
                <div class="form-group">
                    <label for="customer-email">Email:</label>
                    <input type="email" id="customer-email" name="customerEmail" required>
                </div>
                <div class="form-group">
                    <label for="customer-phone">Telefon:</label>
                    <input type="tel" id="customer-phone" name="customerPhone" required>
                </div>
                <div class="form-group">
                    <label for="appointment-notes">Observații (opțional):</label>
                    <textarea id="appointment-notes" name="appointmentNotes" rows="3"></textarea>
                </div>
                <button type="submit" id="confirm-booking" class="btn-primary">Confirmă programarea</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}



// Register the shortcode
add_shortcode('category_booking', 'category_booking_shortcode');
add_action('wp_enqueue_scripts', 'enqueue_booking_script');

/**
 * Log API requests to a file
 */
function log_api_request($request) {
    $params = $request->get_json_params();
    
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    
    $log_entry = "========================================================\n";
    $log_entry .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    $log_entry .= "Request Method: " . ($params['method'] ?? 'Unknown') . "\n";
    $log_entry .= "Request URL: " . ($params['url'] ?? 'Unknown') . "\n";
    $log_entry .= "Endpoint: " . ($params['endpoint'] ?? 'Unknown') . "\n";
    
    if (!empty($params['body'])) {
        $log_entry .= "Request Body: " . $params['body'] . "\n";
    }
    
    $log_entry .= "User IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
    $log_entry .= "User Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n";
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    return ['success' => true];
}

/**
 * Log API responses to a file
 */
function log_api_response($request) {
    $params = $request->get_json_params();
    
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    
    $log_entry = "----------------- RESPONSE -----------------\n";
    $log_entry .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    $log_entry .= "Endpoint: " . ($params['endpoint'] ?? 'Unknown') . "\n";
    $log_entry .= "Status: " . ($params['status'] ?? 'Unknown') . " " . ($params['statusText'] ?? '') . "\n";
    
    if (isset($params['success']) && $params['success']) {
        $log_entry .= "Success: true\n";
        if (!empty($params['data'])) {
            $log_entry .= "Response Data: " . $params['data'] . "\n";
        }
    } else {
        $log_entry .= "Success: false\n";
        if (!empty($params['error'])) {
            $log_entry .= "Error: " . $params['error'] . "\n";
        }
    }
    
    $log_entry .= "========================================================\n\n";
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

    return ['success' => true];
}

// ===== MEDSOFT DAILY SYNC SYSTEM =====

// Register cron jobs for daily sync
add_action('wp', 'schedule_medsoft_sync_crons');

function schedule_medsoft_sync_crons() {
    // Primary sync at 3 AM
    if (!wp_next_scheduled('medsoft_daily_sync_primary')) {
        wp_schedule_event(strtotime('03:00:00'), 'daily', 'medsoft_daily_sync_primary');
    }

    // Backup sync at 6 AM
    if (!wp_next_scheduled('medsoft_daily_sync_backup')) {
        wp_schedule_event(strtotime('06:00:00'), 'daily', 'medsoft_daily_sync_backup');
    }
}

// Hook the sync functions to cron events
add_action('medsoft_daily_sync_primary', 'run_medsoft_sync_primary');
add_action('medsoft_daily_sync_backup', 'run_medsoft_sync_backup');

// Primary sync function (3 AM)
function run_medsoft_sync_primary() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-sync-log.txt';

    file_put_contents($log_file, "\n=== PRIMARY SYNC STARTED at " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND | LOCK_EX);

    $result = perform_medsoft_sync();

    if ($result['success']) {
        file_put_contents($log_file, "PRIMARY SYNC COMPLETED SUCCESSFULLY\n", FILE_APPEND | LOCK_EX);
        // Clear any previous failure flags
        delete_option('medsoft_sync_failed');
    } else {
        file_put_contents($log_file, "PRIMARY SYNC FAILED: " . $result['message'] . "\n", FILE_APPEND | LOCK_EX);
        // Mark primary sync as failed
        update_option('medsoft_sync_primary_failed', time());
    }
}

// Backup sync function (6 AM)
function run_medsoft_sync_backup() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-sync-log.txt';

    // Only run if primary sync failed
    $primary_failed = get_option('medsoft_sync_primary_failed');
    if (!$primary_failed) {
        file_put_contents($log_file, "BACKUP SYNC SKIPPED - Primary sync was successful\n", FILE_APPEND | LOCK_EX);
        return;
    }

    file_put_contents($log_file, "\n=== BACKUP SYNC STARTED at " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND | LOCK_EX);

    $result = perform_medsoft_sync();

    if ($result['success']) {
        file_put_contents($log_file, "BACKUP SYNC COMPLETED SUCCESSFULLY\n", FILE_APPEND | LOCK_EX);
        // Clear failure flags
        delete_option('medsoft_sync_primary_failed');
        delete_option('medsoft_sync_failed');
    } else {
        file_put_contents($log_file, "BACKUP SYNC FAILED: " . $result['message'] . "\n", FILE_APPEND | LOCK_EX);
        // Mark both syncs as failed - trigger admin notification
        update_option('medsoft_sync_failed', time());
        show_admin_sync_notification();
    }
}

// Core sync function that does the actual work
function perform_medsoft_sync() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-sync-log.txt';

    try {
        file_put_contents($log_file, "Starting MedSoft sync process...\n", FILE_APPEND | LOCK_EX);

        // Check if WooCommerce is available
        if (!function_exists('wc_get_products') || !class_exists('WC_Product')) {
            throw new Exception('WooCommerce not available');
        }

        // Step 1: Sync Categories
        file_put_contents($log_file, "Step 1: Syncing categories...\n", FILE_APPEND | LOCK_EX);
        $categories_result = sync_medsoft_categories();
        if (!$categories_result['success']) {
            throw new Exception('Category sync failed: ' . $categories_result['message']);
        }
        file_put_contents($log_file, "Categories synced: " . $categories_result['count'] . "\n", FILE_APPEND | LOCK_EX);

        // Step 2: Sync Services/Products
        file_put_contents($log_file, "Step 2: Syncing services/products...\n", FILE_APPEND | LOCK_EX);
        $products_result = sync_medsoft_products();
        if (!$products_result['success']) {
            throw new Exception('Products sync failed: ' . $products_result['message']);
        }
        file_put_contents($log_file, "Products synced: " . $products_result['count'] . "\n", FILE_APPEND | LOCK_EX);

        // Step 2.5: Update products with duration from MedSoft
        file_put_contents($log_file, "Step 2.5: Updating products with duration...\n", FILE_APPEND | LOCK_EX);
        if (function_exists('update_products_with_duration')) {
            update_products_with_duration();
            file_put_contents($log_file, "Duration update completed\n", FILE_APPEND | LOCK_EX);
        } else {
            file_put_contents($log_file, "Duration update function not available\n", FILE_APPEND | LOCK_EX);
        }

        // Step 3: Clean up obsolete items
        file_put_contents($log_file, "Step 3: Cleaning up obsolete items...\n", FILE_APPEND | LOCK_EX);
        $cleanup_result = cleanup_obsolete_items($products_result['synced_ids'] ?? []);
        if (!$cleanup_result['success']) {
            throw new Exception('Cleanup failed: ' . $cleanup_result['message']);
        }
        file_put_contents($log_file, "Cleanup completed: " . $cleanup_result['count'] . " items removed\n", FILE_APPEND | LOCK_EX);

        // 4. Pre-cache time slots for next 7 days
        file_put_contents($log_file, "Starting time slots pre-caching...\n", FILE_APPEND | LOCK_EX);
        $slots_result = precache_time_slots();
        file_put_contents($log_file, "Time slots pre-caching completed: " . $slots_result['message'] . "\n", FILE_APPEND | LOCK_EX);

        // Update last sync time
        update_option('medsoft_last_sync', time());

        file_put_contents($log_file, "Sync completed successfully!\n", FILE_APPEND | LOCK_EX);

        return [
            'success' => true,
            'message' => 'Sync completed successfully',
            'categories' => $categories_result['count'],
            'products' => $products_result['count'],
            'cleaned' => $cleanup_result['count'],
            'slots_cached' => $slots_result['count']
        ];

    } catch (Exception $e) {
        file_put_contents($log_file, "SYNC ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Pre-cache time slots for next 7 days for all active services and locations
 */
function precache_time_slots() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    try {
        $cached_count = 0;
        $total_requests = 0;

        // Get all locations
        $locations = [
            ['id' => 1, 'name' => 'Iași ARCU'],
            ['id' => 2, 'name' => 'Iași CARPAȚI'],
            ['id' => 3, 'name' => 'București']
        ];

        // Get service IDs directly from database to avoid WooCommerce initialization
        global $wpdb;
        $service_ids = $wpdb->get_col("
            SELECT DISTINCT meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_medsoft_service_id'
            AND p.post_status = 'publish'
            AND p.post_type = 'product'
            AND pm.meta_value != ''
        ");

        file_put_contents($log_file, "Pre-caching slots for " . count($service_ids) . " services across " . count($locations) . " locations\n", FILE_APPEND | LOCK_EX);

        // Cache slots for next 7 days
        for ($day = 1; $day <= 7; $day++) {
            $date = date('Y-m-d', strtotime("+{$day} days"));

            foreach ($locations as $location) {
                foreach ($service_ids as $service_id) {
                    if (!$service_id) continue;

                    $total_requests++;

                    // Create cache key
                    $cache_key = "slots_{$location['id']}_{$service_id}_{$date}";

                    // Check if already cached
                    if (get_transient($cache_key) !== false) {
                        continue; // Skip if already cached
                    }

                    // Fetch slots from MedSoft API
                    $slots_data = fetch_medsoft_slots($location['id'], $service_id, $date);

                    if ($slots_data && !is_wp_error($slots_data)) {
                        // Cache until end of day
                        $end_of_day = strtotime('tomorrow') - time();
                        set_transient($cache_key, $slots_data, $end_of_day);
                        $cached_count++;

                        $slots_count = isset($slots_data['availableSlots']) ? count($slots_data['availableSlots']) : 0;
                        file_put_contents($log_file, "Cached {$slots_count} slots for location {$location['id']}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);
                    } else {
                        file_put_contents($log_file, "Failed to cache slots for location {$location['id']}, service {$service_id}, date {$date} - Error: " . json_encode($slots_data) . "\n", FILE_APPEND | LOCK_EX);
                    }

                    // Small delay to avoid overwhelming the API
                    usleep(100000); // 0.1 second delay
                }
            }
        }

        return [
            'success' => true,
            'message' => "Cached {$cached_count} slot sets out of {$total_requests} requests",
            'count' => $cached_count
        ];

    } catch (Exception $e) {
        file_put_contents($log_file, "SLOTS CACHE ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'count' => 0
        ];
    }
}

/**
 * Fetch time slots from MedSoft API for caching
 */
function fetch_medsoft_slots($location_id, $service_id, $date) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    try {
        // Use the existing get_available_slots logic but bypass the REST endpoint
        $api_key = 'b8a31b44-f42f-400f-9012-e335719c1607';
        $base_url = 'https://web.med-soft.ro/esthetique/api/integrations/programari-online/public/esthetique';

        // Step 1: Get location doctors
        file_put_contents($log_file, "Fetching doctors for location {$location_id}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);

        $doctors_url = $base_url . '/locationDoctors?locationId=' . $location_id;
        $doctors_response = wp_remote_get($doctors_url, [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($doctors_response)) {
            file_put_contents($log_file, "ERROR: Failed to fetch doctors - " . $doctors_response->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
            return false;
        }

        $response_body = wp_remote_retrieve_body($doctors_response);
        $response_code = wp_remote_retrieve_response_code($doctors_response);
        file_put_contents($log_file, "Doctors API Response Code: {$response_code}\n", FILE_APPEND | LOCK_EX);

        $doctors_data = json_decode($response_body, true);
        if (!$doctors_data) {
            file_put_contents($log_file, "ERROR: Invalid JSON response for doctors: " . substr($response_body, 0, 200) . "\n", FILE_APPEND | LOCK_EX);
            return false;
        }

        if ($doctors_data['Status'] !== 0) {
            file_put_contents($log_file, "ERROR: Doctors API returned error status: " . json_encode($doctors_data) . "\n", FILE_APPEND | LOCK_EX);
            return false;
        }

        $doctors = $doctors_data['ReturnData'];
        file_put_contents($log_file, "Found " . count($doctors) . " doctors for location {$location_id}\n", FILE_APPEND | LOCK_EX);
        $available_slots = [];

        // Step 2: Get schedule for each doctor
        foreach ($doctors as $doctor) {
            // Validate doctor data structure - MedSoft API uses DoctorId and Name fields
            if (!isset($doctor['DoctorId']) || !isset($doctor['Name'])) {
                file_put_contents($log_file, "Skipping doctor with missing DoctorId or Name: " . json_encode($doctor) . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }

            $schedule_url = $base_url . '/locationSchedule?locationId=' . $location_id .
                           '&doctorId=' . $doctor['DoctorId'] . '&date=' . $date;

            $schedule_response = wp_remote_get($schedule_url, [
                'headers' => [
                    'X-API-KEY' => $api_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 30
            ]);

            if (is_wp_error($schedule_response)) {
                file_put_contents($log_file, "ERROR: Failed to fetch schedule for doctor {$doctor['DoctorId']} - " . $schedule_response->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }

            $schedule_body = wp_remote_retrieve_body($schedule_response);
            $schedule_code = wp_remote_retrieve_response_code($schedule_response);

            $schedule_data = json_decode($schedule_body, true);
            if (!$schedule_data) {
                file_put_contents($log_file, "ERROR: Invalid JSON in schedule response for doctor {$doctor['DoctorId']}: " . substr($schedule_body, 0, 100) . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }

            if ($schedule_data['Status'] !== 0) {
                file_put_contents($log_file, "ERROR: Schedule API error for doctor {$doctor['DoctorId']}: " . json_encode($schedule_data) . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }

            $schedule_slots = $schedule_data['ReturnData'];
            file_put_contents($log_file, "Doctor {$doctor['DoctorId']} ({$doctor['Name']}) has " . count($schedule_slots) . " time slots\n", FILE_APPEND | LOCK_EX);

            // Filter slots for this specific service
            $service_slots_found = 0;
            foreach ($schedule_slots as $slot) {
                if (isset($slot['servicii']) && is_array($slot['servicii'])) {
                    foreach ($slot['servicii'] as $service) {
                        if (isset($service['cod']) && $service['cod'] == $service_id) {
                            $available_slots[] = [
                                'startDateTime' => $slot['startDateTime'],
                                'doctorId' => $doctor['DoctorId'],
                                'doctorName' => $doctor['Name'],
                                'serviceId' => $service_id
                            ];
                            $service_slots_found++;
                            break;
                        }
                    }
                }
            }
            file_put_contents($log_file, "Found {$service_slots_found} slots for service {$service_id} with doctor {$doctor['DoctorId']}\n", FILE_APPEND | LOCK_EX);
        }

        return [
            'availableSlots' => $available_slots,
            'message' => 'Slots fetched successfully',
            'slots' => $available_slots // For backward compatibility
        ];

    } catch (Exception $e) {
        file_put_contents($log_file, "FETCH SLOTS ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return false;
    }
}

// Sync MedSoft categories to WooCommerce product categories
function sync_medsoft_categories() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-sync-log.txt';

    try {
        // Get categories from MedSoft
        $medsoft_categories = get_medsoft_service_categories();
        if (empty($medsoft_categories)) {
            throw new Exception('No categories received from MedSoft');
        }

        $synced_count = 0;

        foreach ($medsoft_categories as $category_name => $category_data) {
            // Check if category already exists
            $existing_term = get_term_by('name', $category_name, 'product_cat');

            if (!$existing_term) {
                // Create new category
                $result = wp_insert_term(
                    $category_name,
                    'product_cat',
                    [
                        'description' => $category_data['description'] ?? '',
                        'slug' => sanitize_title($category_name)
                    ]
                );

                if (!is_wp_error($result)) {
                    // Add MedSoft metadata
                    update_term_meta($result['term_id'], '_medsoft_category', true);
                    update_term_meta($result['term_id'], '_medsoft_sync_date', time());
                    $synced_count++;
                    file_put_contents($log_file, "Created category: {$category_name}\n", FILE_APPEND | LOCK_EX);
                }
            } else {
                // Update existing category
                update_term_meta($existing_term->term_id, '_medsoft_sync_date', time());
                $synced_count++;
                file_put_contents($log_file, "Updated category: {$category_name}\n", FILE_APPEND | LOCK_EX);
            }
        }

        return [
            'success' => true,
            'count' => $synced_count
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// Sync MedSoft services to WooCommerce products
function sync_medsoft_products() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-sync-log.txt';

    try {
        // Get all services from MedSoft
        $all_services = medsoft_api_request('/priceList');
        if (is_wp_error($all_services)) {
            throw new Exception('Failed to get services from MedSoft: ' . $all_services->get_error_message());
        }

        if (empty($all_services)) {
            throw new Exception('No services received from MedSoft');
        }

        $synced_count = 0;
        $synced_ids = [];

        foreach ($all_services as $service) {
            // Skip excluded categories
            $excluded_categories = [
                'TRATAMENTE FACIALE',
                'DRENAJ (PRESOTERAPIE & TERMOTERAPIE)',
                'EPILARE DEFINITIVA LASER',
                'REMODELARE CORPORALA',
                'TERAPIE CRANIO-SACRALA'
            ];

            if (in_array($service->tip_serviciu, $excluded_categories)) {
                continue;
            }

            // Check if product already exists by SKU (using MedSoft service cod)
            $product_id = wc_get_product_id_by_sku('medsoft_' . $service->cod);

            if ($product_id) {
                // Update existing product
                $product = wc_get_product($product_id);
                if ($product) {
                    $product->set_name($service->denumire);
                    $product->set_regular_price($service->pret);
                    $product->set_description('Serviciu MedSoft: ' . $service->denumire);

                    // Update MedSoft metadata
                    $product->update_meta_data('_medsoft_service_id', $service->cod);
                    $product->update_meta_data('_medsoft_service_name', $service->denumire);
                    $product->update_meta_data('_medsoft_category', $service->tip_serviciu);
                    $product->update_meta_data('_medsoft_price', $service->pret);
                    $product->update_meta_data('_medsoft_sync_date', time());

                    if (isset($service->cod_utilizator)) {
                        $product->update_meta_data('_medsoft_assigned_doctor', $service->cod_utilizator);
                    }

                    $product->save();
                    $synced_count++;
                    $synced_ids[] = $product_id;
                    file_put_contents($log_file, "Updated product: {$service->denumire} (ID: {$product_id})\n", FILE_APPEND | LOCK_EX);
                }
            } else {
                // Create new product
                $product = new WC_Product();
                $product->set_name($service->denumire);
                $product->set_status('publish');
                $product->set_catalog_visibility('visible');
                $product->set_description('Serviciu MedSoft: ' . $service->denumire);
                $product->set_sku('medsoft_' . $service->cod);
                $product->set_regular_price($service->pret);
                $product->set_virtual(true); // Services are virtual

                // Set category
                $category_term = get_term_by('name', $service->tip_serviciu, 'product_cat');
                if ($category_term) {
                    $product->set_category_ids([$category_term->term_id]);
                }

                // Add MedSoft metadata
                $product->update_meta_data('_medsoft_service_id', $service->cod);
                $product->update_meta_data('_medsoft_service_name', $service->denumire);
                $product->update_meta_data('_medsoft_category', $service->tip_serviciu);
                $product->update_meta_data('_medsoft_price', $service->pret);
                $product->update_meta_data('_medsoft_sync_date', time());

                if (isset($service->cod_utilizator)) {
                    $product->update_meta_data('_medsoft_assigned_doctor', $service->cod_utilizator);
                }

                $product_id = $product->save();
                if ($product_id) {
                    $synced_count++;
                    $synced_ids[] = $product_id;
                    file_put_contents($log_file, "Created product: {$service->denumire} (ID: {$product_id})\n", FILE_APPEND | LOCK_EX);
                }
            }
        }

        return [
            'success' => true,
            'count' => $synced_count,
            'synced_ids' => $synced_ids
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// Clean up obsolete products and categories
function cleanup_obsolete_items($synced_ids = []) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-sync-log.txt';

    try {
        $cleaned_count = 0;

        // Skip cleanup if no products were synced (prevents deleting everything on API failure)
        if (empty($synced_ids)) {
            file_put_contents($log_file, "Skipping cleanup - no products were synced in this run\n", FILE_APPEND | LOCK_EX);
            return [
                'success' => true,
                'count' => 0
            ];
        }

        // Get all MedSoft products
        $args = [
            'status' => ['publish', 'private', 'draft'],
            'limit' => -1,
            'meta_query' => [
                [
                    'key' => '_medsoft_service_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ];

        $all_medsoft_products = wc_get_products($args);

        foreach ($all_medsoft_products as $product) {
            $product_id = $product->get_id();

            // If this product was NOT synced in the current run, it's obsolete
            if (!in_array($product_id, $synced_ids)) {
                // Move to trash instead of permanent delete
                $product->set_status('trash');
                $product->save();
                $cleaned_count++;
                file_put_contents($log_file, "Moved to trash: {$product->get_name()} (ID: {$product_id})\n", FILE_APPEND | LOCK_EX);
            }
        }

        return [
            'success' => true,
            'count' => $cleaned_count
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// Show admin notification when sync fails
function show_admin_sync_notification() {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>Atenție!</strong> Nu s-a actualizat site-ul cu MedSoft. Dacă s-au efectuat modificări în MedSoft în ultimele 24 ore, contactați administratorul.</p>';
        echo '</div>';
    });
}

// Manual sync function for admin endpoint
function manual_medsoft_sync($request) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-sync-log.txt';

    file_put_contents($log_file, "\n=== MANUAL SYNC STARTED at " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND | LOCK_EX);

    $result = perform_medsoft_sync();

    if ($result['success']) {
        file_put_contents($log_file, "MANUAL SYNC COMPLETED SUCCESSFULLY\n", FILE_APPEND | LOCK_EX);
        // Clear any failure flags
        delete_option('medsoft_sync_primary_failed');
        delete_option('medsoft_sync_failed');

        return [
            'success' => true,
            'message' => 'Sincronizarea a fost completată cu succes',
            'data' => $result
        ];
    } else {
        file_put_contents($log_file, "MANUAL SYNC FAILED: " . $result['message'] . "\n", FILE_APPEND | LOCK_EX);

        return new WP_Error('sync_failed', 'Sincronizarea a eșuat: ' . $result['message'], ['status' => 500]);
    }
}

function manual_cache_slots($request) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    // Clear the log to avoid confusion
    file_put_contents($log_file, "", LOCK_EX);
    file_put_contents($log_file, "=== Manual Cache Slots Started (Clean Log) ===\n", FILE_APPEND | LOCK_EX);

    // Call the isolated cache function directly
    $result = precache_time_slots_isolated();

    if ($result['success']) {
        return [
            'success' => true,
            'message' => 'Time slots cached successfully: ' . $result['message']
        ];
    } else {
        return new WP_Error('cache_failed', 'Caching failed: ' . $result['message'], ['status' => 500]);
    }
}

function clear_time_slots_cache($request) {
    global $wpdb;

    // Log the cache clearing attempt
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    file_put_contents($log_file, "=== CACHE CLEARING STARTED ===\n", FILE_APPEND | LOCK_EX);

    // First, let's see what cache entries exist
    $existing_cache = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_slots_%' LIMIT 10");
    file_put_contents($log_file, "Found " . count($existing_cache) . " cache entries to clear\n", FILE_APPEND | LOCK_EX);

    // Delete all time slots cache entries using WordPress functions
    $deleted = 0;
    $locations = [1, 2, 3];
    $service_ids = [202, 203, 204, 212, 213, 215, 216, 217, 218, 219, 240, 241, 242, 330, 340, 341, 342, 343, 351, 352, 356, 357, 358, 359, 363, 364, 366];

    for ($day = 1; $day <= 7; $day++) {
        $date = date('Y-m-d', strtotime("+{$day} days"));
        foreach ($locations as $location_id) {
            foreach ($service_ids as $service_id) {
                $cache_key = "slots_{$location_id}_{$service_id}_{$date}";
                if (delete_transient($cache_key)) {
                    $deleted++;
                }
            }
        }
    }

    // Also clear WordPress object cache if available
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    file_put_contents($log_file, "=== CACHE CLEARED: Deleted {$deleted} entries ===\n", FILE_APPEND | LOCK_EX);

    return [
        'success' => true,
        'message' => "Cleared {$deleted} cache entries"
    ];
}

function test_cache_entry($request) {
    // Test a specific cache entry to see what's stored
    $cache_key = "slots_1_202_2025-07-06";
    $cached_data = get_transient($cache_key);

    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    if ($cached_data !== false) {
        file_put_contents($log_file, "=== CACHE TEST: Found cached data for {$cache_key} ===\n", FILE_APPEND | LOCK_EX);
        file_put_contents($log_file, "Cached data: " . json_encode($cached_data) . "\n", FILE_APPEND | LOCK_EX);

        return [
            'success' => true,
            'message' => "Found cached data",
            'data' => $cached_data
        ];
    } else {
        file_put_contents($log_file, "=== CACHE TEST: No cached data found for {$cache_key} ===\n", FILE_APPEND | LOCK_EX);

        return [
            'success' => false,
            'message' => "No cached data found"
        ];
    }
}

/**
 * Isolated cache function that bypasses any WooCommerce dependencies
 */
function precache_time_slots_isolated() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    try {
        $cached_count = 0;
        $total_requests = 0;
        $total_processed = 0;

        // Get all locations
        $locations = [
            ['id' => 1, 'name' => 'Iași ARCU'],
            ['id' => 2, 'name' => 'Iași CARPAȚI'],
            ['id' => 3, 'name' => 'București']
        ];

        // Get service IDs directly from database to avoid any WooCommerce calls
        global $wpdb;
        $service_ids = $wpdb->get_col("
            SELECT DISTINCT meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_medsoft_service_id'
            AND p.post_status = 'publish'
            AND p.post_type = 'product'
            AND pm.meta_value != ''
        ");

        file_put_contents($log_file, "Pre-caching slots for " . count($service_ids) . " services across " . count($locations) . " locations\n", FILE_APPEND | LOCK_EX);

        // Cache slots for next 7 days
        for ($day = 1; $day <= 7; $day++) {
            $date = date('Y-m-d', strtotime("+{$day} days"));

            foreach ($locations as $location) {
                foreach ($service_ids as $service_id) {
                    if (!$service_id) continue;

                    $total_requests++;
                    $total_processed++;

                    // Create cache key
                    $cache_key = "slots_{$location['id']}_{$service_id}_{$date}";

                    // Check if already cached (force refresh for debugging)
                    $cached_data = get_transient($cache_key);

                    // For debugging: force refresh for first few entries
                    $force_refresh = ($total_processed < 3);

                    if ($cached_data !== false && !$force_refresh) {
                        file_put_contents($log_file, "SKIPPING: Already cached - location {$location['id']}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);
                        continue; // Skip if already cached
                    }

                    if ($force_refresh) {
                        file_put_contents($log_file, "FORCE REFRESH: Debugging first few entries - location {$location['id']}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);
                    } else {
                        file_put_contents($log_file, "NOT CACHED: Will fetch - location {$location['id']}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);
                    }

                    // Fetch slots from MedSoft API using isolated function
                    file_put_contents($log_file, "About to fetch slots for location {$location['id']}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);
                    $slots_data = fetch_medsoft_slots_isolated($location['id'], $service_id, $date);
                    file_put_contents($log_file, "Fetch completed. Result: " . (is_array($slots_data) ? 'array' : gettype($slots_data)) . "\n", FILE_APPEND | LOCK_EX);

                    if ($slots_data && !is_wp_error($slots_data)) {
                        // Cache until end of day
                        $end_of_day = strtotime('tomorrow') - time();
                        set_transient($cache_key, $slots_data, $end_of_day);
                        $cached_count++;

                        $slots_count = isset($slots_data['availableSlots']) ? count($slots_data['availableSlots']) : 0;
                        file_put_contents($log_file, "Cached {$slots_count} slots for location {$location['id']}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);
                    } else {
                        file_put_contents($log_file, "Failed to cache slots for location {$location['id']}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);
                    }

                    // Small delay to avoid overwhelming the API
                    usleep(100000); // 0.1 second delay
                }
            }
        }

        file_put_contents($log_file, "Cache operation completed. Cached {$cached_count} out of {$total_requests} requests\n", FILE_APPEND | LOCK_EX);

        return [
            'success' => true,
            'message' => "Cached {$cached_count} time slot sets out of {$total_requests} requests"
        ];

    } catch (Exception $e) {
        file_put_contents($log_file, "CACHE ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Isolated MedSoft API function with no external dependencies
 */
function fetch_medsoft_slots_isolated($location_id, $service_id, $date) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== FETCH FUNCTION CALLED: location {$location_id}, service {$service_id}, date {$date} ===\n", FILE_APPEND | LOCK_EX);

    try {
        $api_key = 'b8a31b44-f42f-400f-9012-e335719c1607';
        $base_url = 'https://web.med-soft.ro/esthetique/api/integrations/programari-online/public/esthetique';

        // Step 1: Get location doctors
        file_put_contents($log_file, "Fetching doctors for location {$location_id}, service {$service_id}, date {$date}\n", FILE_APPEND | LOCK_EX);

        $doctors_url = $base_url . '/locationDoctors?locationId=' . $location_id;
        $doctors_response = wp_remote_get($doctors_url, [
            'headers' => [
                'X-API-KEY' => $api_key,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($doctors_response)) {
            file_put_contents($log_file, "ERROR: Failed to fetch doctors - " . $doctors_response->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
            return false;
        }

        $response_body = wp_remote_retrieve_body($doctors_response);
        $response_code = wp_remote_retrieve_response_code($doctors_response);
        file_put_contents($log_file, "Doctors API Response Code: {$response_code}\n", FILE_APPEND | LOCK_EX);

        $doctors_data = json_decode($response_body, true);
        if (!$doctors_data) {
            file_put_contents($log_file, "ERROR: Invalid JSON response for doctors: " . substr($response_body, 0, 200) . "\n", FILE_APPEND | LOCK_EX);
            return false;
        }

        if ($doctors_data['Status'] !== 0) {
            file_put_contents($log_file, "ERROR: Doctors API returned error status: " . json_encode($doctors_data) . "\n", FILE_APPEND | LOCK_EX);
            return false;
        }

        $doctors = $doctors_data['ReturnData'];
        file_put_contents($log_file, "Found " . count($doctors) . " doctors for location {$location_id}\n", FILE_APPEND | LOCK_EX);
        $available_slots = [];

        // Step 2: Get schedule for each doctor
        foreach ($doctors as $doctor) {
            // Validate doctor data structure
            if (!isset($doctor['DoctorId']) || !isset($doctor['Name'])) {
                file_put_contents($log_file, "Skipping doctor with missing DoctorId or Name: " . json_encode($doctor) . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }

            $schedule_url = $base_url . '/locationSchedule?locationId=' . $location_id .
                           '&doctorId=' . $doctor['DoctorId'] . '&date=' . $date;

            $schedule_response = wp_remote_get($schedule_url, [
                'headers' => [
                    'X-API-KEY' => $api_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 30
            ]);

            if (is_wp_error($schedule_response)) {
                file_put_contents($log_file, "ERROR: Failed to fetch schedule for doctor {$doctor['DoctorId']} - " . $schedule_response->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }

            $schedule_body = wp_remote_retrieve_body($schedule_response);
            $schedule_code = wp_remote_retrieve_response_code($schedule_response);

            $schedule_data = json_decode($schedule_body, true);
            if (!$schedule_data) {
                file_put_contents($log_file, "ERROR: Invalid JSON in schedule response for doctor {$doctor['DoctorId']}: " . substr($schedule_body, 0, 100) . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }

            if ($schedule_data['Status'] !== 0) {
                file_put_contents($log_file, "ERROR: Schedule API error for doctor {$doctor['DoctorId']}: " . json_encode($schedule_data) . "\n", FILE_APPEND | LOCK_EX);
                continue;
            }

            $schedule_slots = $schedule_data['ReturnData'];
            file_put_contents($log_file, "Doctor {$doctor['DoctorId']} ({$doctor['Name']}) has " . count($schedule_slots) . " time slots\n", FILE_APPEND | LOCK_EX);

            // Debug: Show first slot structure
            if (!empty($schedule_slots)) {
                file_put_contents($log_file, "First slot structure: " . json_encode($schedule_slots[0]) . "\n", FILE_APPEND | LOCK_EX);
            }

            // Filter slots for this specific service
            $service_slots_found = 0;
            foreach ($schedule_slots as $slot) {
                if (isset($slot['servicii']) && is_array($slot['servicii'])) {
                    file_put_contents($log_file, "Slot has servicii: " . json_encode($slot['servicii']) . "\n", FILE_APPEND | LOCK_EX);
                    foreach ($slot['servicii'] as $service) {
                        if (isset($service['cod']) && $service['cod'] == $service_id) {
                            $available_slots[] = [
                                'startDateTime' => $slot['startDateTime'],
                                'doctorId' => $doctor['DoctorId'],
                                'doctorName' => $doctor['Name'],
                                'serviceId' => $service_id
                            ];
                            $service_slots_found++;
                            break;
                        }
                    }
                } else {
                    file_put_contents($log_file, "Slot missing servicii or not array: " . json_encode($slot) . "\n", FILE_APPEND | LOCK_EX);
                }
            }
            file_put_contents($log_file, "Found {$service_slots_found} slots for service {$service_id} with doctor {$doctor['DoctorId']}\n", FILE_APPEND | LOCK_EX);
        }

        return [
            'availableSlots' => $available_slots,
            'totalSlots' => count($available_slots)
        ];

    } catch (Exception $e) {
        file_put_contents($log_file, "FETCH ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return false;
    }
}

function manual_update_duration($request) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== Manual Duration Update Started ===\n", FILE_APPEND | LOCK_EX);

    // Check if WooCommerce is available
    if (!function_exists('wc_get_products')) {
        file_put_contents($log_file, "WooCommerce not available\n", FILE_APPEND | LOCK_EX);
        return new WP_Error('wc_not_available', 'WooCommerce not available', ['status' => 500]);
    }

    // Get all products with MedSoft service IDs
    $args = [
        'status' => 'publish',
        'limit' => -1,
        'meta_query' => [
            [
                'key' => '_medsoft_service_id',
                'compare' => 'EXISTS'
            ]
        ]
    ];

    $products = wc_get_products($args);
    file_put_contents($log_file, "Found " . count($products) . " products to check\n", FILE_APPEND | LOCK_EX);

    // Get appointment scopes for duration data
    $appointment_scopes = medsoft_api_request('/appointmentScop');
    if (is_wp_error($appointment_scopes)) {
        file_put_contents($log_file, "Failed to get appointment scopes: " . $appointment_scopes->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
        return $appointment_scopes;
    }

    file_put_contents($log_file, "Got " . count($appointment_scopes) . " appointment scopes\n", FILE_APPEND | LOCK_EX);

    $updated_count = 0;
    foreach ($products as $product) {
        $service_id = $product->get_meta('_medsoft_service_id');
        $current_duration = $product->get_meta('_medsoft_service_duration');

        file_put_contents($log_file, "Product: {$product->get_name()} (Service ID: {$service_id}) - Current duration: " . ($current_duration ?: 'NOT SET') . "\n", FILE_APPEND | LOCK_EX);

        // Always update duration (not just when missing)
        if ($service_id) {
            // Find duration in appointment scopes
            foreach ($appointment_scopes as $scope) {
                // Check both direct cod match and lista_servicii.cod match
                $scope_matches = false;
                $duration = null;

                if (isset($scope->cod) && $scope->cod == $service_id && isset($scope->durata)) {
                    // Direct match
                    $scope_matches = true;
                    $duration = intval($scope->durata);
                    file_put_contents($log_file, "DIRECT MATCH: Service {$service_id} matched scope {$scope->cod} with duration {$duration}\n", FILE_APPEND | LOCK_EX);
                } elseif (isset($scope->lista_servicii) && isset($scope->lista_servicii->cod) && intval($scope->lista_servicii->cod) == intval($service_id) && isset($scope->durata)) {
                    // Match through lista_servicii.cod
                    $scope_matches = true;
                    $duration = intval($scope->durata);
                    file_put_contents($log_file, "LISTA_SERVICII MATCH: Service {$service_id} matched lista_servicii.cod {$scope->lista_servicii->cod} in scope {$scope->cod} with duration {$duration}\n", FILE_APPEND | LOCK_EX);
                }

                if ($scope_matches && $duration) {
                    $product->update_meta_data('_medsoft_service_duration', $duration);
                    $product->save();
                    $updated_count++;
                    file_put_contents($log_file, "Updated product {$product->get_name()} (ID: {$service_id}) with duration: {$duration} minutes\n", FILE_APPEND | LOCK_EX);
                    break;
                }
            }
        }
    }

    file_put_contents($log_file, "Updated {$updated_count} products with duration\n", FILE_APPEND | LOCK_EX);

    return [
        'success' => true,
        'message' => "Updated {$updated_count} products with duration",
        'updated_count' => $updated_count
    ];
}

// Add admin menu for sync management
add_action('admin_menu', 'add_medsoft_sync_admin_menu');

function add_medsoft_sync_admin_menu() {
    add_options_page(
        'MedSoft Sync',
        'MedSoft Sync',
        'manage_options',
        'medsoft-sync',
        'medsoft_sync_admin_page'
    );
}

// Admin page for sync management
function medsoft_sync_admin_page() {
    $last_sync = get_option('medsoft_last_sync');
    $sync_failed = get_option('medsoft_sync_failed');

    echo '<div class="wrap">';
    echo '<h1>MedSoft Sincronizare</h1>';

    if ($sync_failed) {
        echo '<div class="notice notice-error"><p>Ultima sincronizare a eșuat la ' . date('Y-m-d H:i:s', $sync_failed) . '</p></div>';
    }

    if ($last_sync) {
        echo '<p>Ultima sincronizare reușită: ' . date('Y-m-d H:i:s', $last_sync) . '</p>';
    } else {
        echo '<p>Nu s-a efectuat încă o sincronizare.</p>';
    }

    echo '<button id="manual-sync" class="button button-primary">Sincronizare Manuală</button>';
    echo ' <button id="restore-products" class="button button-secondary">Restaurează Produse din Coș</button>';
    echo ' <button id="update-duration" class="button button-secondary">Update Service Durations</button>';
    echo ' <button id="cache-slots" class="button button-secondary">Cache Time Slots</button>';
    echo ' <button id="clear-cache" class="button button-secondary">Clear Cache</button>';
    echo ' <button id="test-cache" class="button button-secondary">Test Cache Entry</button>';
    echo '<div id="sync-result"></div>';

    // Add JavaScript for manual sync
    echo '<script>
    document.getElementById("manual-sync").addEventListener("click", function() {
        this.disabled = true;
        this.textContent = "Se sincronizează...";

        fetch("' . rest_url('mybooking/v1/sync-medsoft') . '", {
            method: "POST",
            headers: {
                "X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"
            }
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-success\"><p>" + data.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Sincronizare Manuală";
        })
        .catch(error => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-error\"><p>Eroare: " + error.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Sincronizare Manuală";
        });
    });

    // Restore products button
    document.getElementById("restore-products").addEventListener("click", function() {
        this.disabled = true;
        this.textContent = "Se restaurează...";

        fetch("' . rest_url('mybooking/v1/restore-products') . '", {
            method: "POST",
            headers: {
                "X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"
            }
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-success\"><p>" + data.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Restaurează Produse din Coș";
        })
        .catch(error => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-error\"><p>Eroare: " + error.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Restaurează Produse din Coș";
        });
    });

    // Duration update button
    document.getElementById("update-duration").addEventListener("click", function() {
        this.disabled = true;
        this.textContent = "Updating...";

        fetch("' . rest_url('mybooking/v1/update-duration') . '", {
            method: "POST",
            headers: {
                "X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"
            }
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-success\"><p>" + data.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Update Service Durations";
        })
        .catch(error => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-error\"><p>Error: " + error.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Update Service Durations";
        });
    });

    // Cache slots button
    document.getElementById("cache-slots").addEventListener("click", function() {
        this.disabled = true;
        this.textContent = "Se cache-ază...";

        fetch("' . rest_url('mybooking/v1/cache-slots') . '", {
            method: "POST",
            headers: {
                "X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error("HTTP " + response.status);
            }
            return response.json();
        })
        .then(data => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-success\"><p>" + data.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Cache Time Slots";
        })
        .catch(error => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-error\"><p>Error: " + error.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Cache Time Slots";
        });
    });

    // Clear cache button
    document.getElementById("clear-cache").addEventListener("click", function() {
        this.disabled = true;
        this.textContent = "Clearing...";

        fetch("' . rest_url('mybooking/v1/clear-cache') . '", {
            method: "POST",
            headers: {
                "X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error("HTTP " + response.status);
            }
            return response.json();
        })
        .then(data => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-success\"><p>" + data.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Clear Cache";
        })
        .catch(error => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-error\"><p>Error: " + error.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Clear Cache";
        });
    });

    // Test cache button
    document.getElementById("test-cache").addEventListener("click", function() {
        this.disabled = true;
        this.textContent = "Testing...";

        fetch("' . rest_url('mybooking/v1/test-cache') . '", {
            method: "POST",
            headers: {
                "X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error("HTTP " + response.status);
            }
            return response.json();
        })
        .then(data => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-success\"><p>" + data.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Test Cache Entry";
        })
        .catch(error => {
            document.getElementById("sync-result").innerHTML =
                "<div class=\"notice notice-error\"><p>Error: " + error.message + "</p></div>";
            this.disabled = false;
            this.textContent = "Test Cache Entry";
        });
    });
    </script>';

    echo '</div>';
}

// Temporary function to restore trashed products
function restore_trashed_medsoft_products($request) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-sync-log.txt';

    file_put_contents($log_file, "\n=== RESTORING TRASHED PRODUCTS at " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND | LOCK_EX);

    try {
        // Get all trashed MedSoft products
        $args = [
            'status' => 'trash',
            'limit' => -1,
            'meta_query' => [
                [
                    'key' => '_medsoft_service_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ];

        $trashed_products = wc_get_products($args);
        $restored_count = 0;

        foreach ($trashed_products as $product) {
            $product->set_status('publish');
            $product->save();
            $restored_count++;
            file_put_contents($log_file, "Restored product: {$product->get_name()} (ID: {$product->get_id()})\n", FILE_APPEND | LOCK_EX);
        }

        file_put_contents($log_file, "RESTORE COMPLETED - {$restored_count} products restored\n", FILE_APPEND | LOCK_EX);

        return [
            'success' => true,
            'message' => "Au fost restaurate {$restored_count} produse",
            'count' => $restored_count
        ];

    } catch (Exception $e) {
        file_put_contents($log_file, "RESTORE ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return new WP_Error('restore_failed', 'Restaurarea a eșuat: ' . $e->getMessage(), ['status' => 500]);
    }
}

// Clean up cron jobs when plugin is deactivated
register_deactivation_hook(__FILE__, 'cleanup_medsoft_sync_crons');

function cleanup_medsoft_sync_crons() {
    wp_clear_scheduled_hook('medsoft_daily_sync_primary');
    wp_clear_scheduled_hook('medsoft_daily_sync_backup');
}

// ===== WOOCOMMERCE-BASED API ENDPOINTS =====

/**
 * Get categories from WooCommerce (faster than MedSoft API)
 */
function get_woocommerce_categories($request) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    file_put_contents($log_file, "=== WooCommerce Categories Endpoint Called ===\n", FILE_APPEND | LOCK_EX);

    $location_id = $request->get_param('locationId');
    file_put_contents($log_file, "Location ID parameter: " . ($location_id ?: 'none') . "\n", FILE_APPEND | LOCK_EX);

    // NEW: If location is specified, use location-filtered categories
    if ($location_id) {
        file_put_contents($log_file, "Using location-filtered categories for location: " . $location_id . "\n", FILE_APPEND | LOCK_EX);
        $filtered_categories = get_booking_categories($request);

        // Convert associative array to simple array of category names
        $category_names = array_keys($filtered_categories);
        file_put_contents($log_file, "Filtered categories for location " . $location_id . ": " . json_encode($category_names) . "\n", FILE_APPEND | LOCK_EX);

        return $category_names;
    }

    try {
        // Get MedSoft categories from WooCommerce (original behavior)
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => '_medsoft_category',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        file_put_contents($log_file, "Found " . count($terms) . " WooCommerce categories\n", FILE_APPEND | LOCK_EX);

        if (is_wp_error($terms)) {
            return new WP_Error('categories_error', 'Failed to get categories', ['status' => 500]);
        }

        $categories = [];
        foreach ($terms as $term) {
            // Filter out Programari category
            if ($term->name !== 'Programari') {
                $categories[] = $term->name;
                file_put_contents($log_file, "Category: " . $term->name . "\n", FILE_APPEND | LOCK_EX);
            }
        }

        file_put_contents($log_file, "Returning categories: " . json_encode($categories) . "\n", FILE_APPEND | LOCK_EX);
        return $categories;

    } catch (Exception $e) {
        return new WP_Error('categories_error', $e->getMessage(), ['status' => 500]);
    }
}

/**
 * Get services for a category from WooCommerce (faster than MedSoft API)
 */
function get_woocommerce_category_services($request) {
    $category = $request['category'];
    $location_id = $request->get_param('locationId');

    error_log("=== get_woocommerce_category_services called for category: {$category}, location: {$location_id} ===");
    error_log("=== LOCATION FILTERING ENABLED - WILL FILTER SERVICES ===");

    try {
        // Get category term
        $category_term = get_term_by('name', urldecode($category), 'product_cat');
        if (!$category_term) {
            return new WP_Error('category_not_found', 'Category not found', ['status' => 404]);
        }

        // Get products in this category
        $args = [
            'status' => 'publish',
            'limit' => -1,
            'category' => [$category_term->slug],
            'meta_query' => [
                [
                    'key' => '_medsoft_service_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ];

        $products = wc_get_products($args);

        $services = [];
        foreach ($products as $product) {
            $service_id = $product->get_meta('_medsoft_service_id');
            $assigned_doctor = $product->get_meta('_medsoft_assigned_doctor');

            error_log("=== PROCESSING SERVICE {$service_id} ({$product->get_name()}) ===");

            // Apply location filtering using live API calls
            if ($location_id) {
                // Use the same strict filtering logic as category filtering
                $today = date('Y-m-d');
                $has_slots = check_service_has_slots($service_id, $location_id, $today);

                if (!$has_slots) {
                    error_log("✗ Service {$service_id} ({$product->get_name()}) - NO available slots at location {$location_id}");
                    continue; // Skip this service
                } else {
                    error_log("✓ Service {$service_id} ({$product->get_name()}) - HAS available slots at location {$location_id}");
                }
            }

            // Get duration from product meta (CACHE-ONLY MODE: no live API calls)
            $duration = $product->get_meta('_medsoft_service_duration');
            // CACHE-ONLY MODE: Don't fetch from live API, use default if not cached
            // if (!$duration) {
            //     // Fallback: get duration from MedSoft appointmentScop
            //     $appointment_scopes = medsoft_api_request('/appointmentScop');
            //     if (!is_wp_error($appointment_scopes)) {
            //         foreach ($appointment_scopes as $scope) {
            //             if (isset($scope->cod) && $scope->cod == $service_id && isset($scope->durata)) {
            //                 $duration = intval($scope->durata);
            //                 // Save to product meta for future use
            //                 $product->update_meta_data('_medsoft_service_duration', $duration);
            //                 $product->save();
            //                 break;
            //             }
            //         }
            //     }
            // }

            $services[] = [
                'cod' => $service_id,
                'denumire' => $product->get_name(),
                'pret' => $product->get_regular_price(),
                'tip_serviciu' => $category,
                'description' => $product->get_description(),
                'wc_product_id' => $product->get_id(),
                'duration' => $duration ? intval($duration) : 60 // Include duration field
            ];

            // Debug logging for duration
            error_log("Service {$product->get_name()} (ID: {$service_id}) - Duration meta: " . ($duration ?: 'NOT SET') . ", Final duration: " . ($duration ? intval($duration) : 60));
        }

        return $services;

    } catch (Exception $e) {
        return new WP_Error('services_error', $e->getMessage(), ['status' => 500]);
    }
}

/**
 * Check if a doctor works at a specific location
 */
function check_doctor_at_location($doctor_id, $location_id) {
    // Use existing MedSoft API to check doctor-location assignment
    $doctors_response = medsoft_api_request("/locationDoctors?locationId={$location_id}");

    if (is_wp_error($doctors_response)) {
        return true; // If we can't check, allow the service
    }

    foreach ($doctors_response as $doctor) {
        if ($doctor->DoctorId == $doctor_id) {
            return true;
        }
    }

    return false;
}

/**
 * Debug endpoint to check WooCommerce data
 */
function debug_woocommerce_data($request) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    try {
        $debug_info = [];

        // Check categories
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ]);

        $debug_info['total_categories'] = count($terms);
        $debug_info['categories'] = [];

        foreach ($terms as $term) {
            $is_medsoft = get_term_meta($term->term_id, '_medsoft_category', true);
            $debug_info['categories'][] = [
                'name' => $term->name,
                'slug' => $term->slug,
                'is_medsoft' => $is_medsoft ? 'yes' : 'no'
            ];
        }

        // Check products
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => 10
        ]);

        $debug_info['total_products'] = count($products);
        $debug_info['sample_products'] = [];

        foreach ($products as $product) {
            $medsoft_id = $product->get_meta('_medsoft_service_id');
            $debug_info['sample_products'][] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'medsoft_id' => $medsoft_id,
                'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names'])
            ];
        }

        // Check MedSoft products specifically
        $medsoft_products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'meta_query' => [
                [
                    'key' => '_medsoft_service_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        $debug_info['medsoft_products_count'] = count($medsoft_products);

        file_put_contents($log_file, "Debug WooCommerce data: " . json_encode($debug_info, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);

        return $debug_info;

    } catch (Exception $e) {
        return new WP_Error('debug_error', $e->getMessage(), ['status' => 500]);
    }
}

/**
 * Get cached available slots (24-hour cache)
 */
function get_cached_available_slots($request) {
    $location_id = $request->get_param('locationId');
    $service_id = $request->get_param('serviceId');
    $date = $request->get_param('date');

    if (!$location_id || !$service_id || !$date) {
        return new WP_Error('missing_params', 'Missing required parameters', ['status' => 400]);
    }

    // Create cache key
    $cache_key = "slots_{$location_id}_{$service_id}_{$date}";

    // Try to get from cache first
    $cached_slots = get_transient($cache_key);
    if ($cached_slots !== false) {
        error_log("Returning cached slots for {$cache_key}");
        return $cached_slots;
    }

    // CACHE-ONLY MODE: Return error instead of falling back to live API
    error_log("Cache miss for {$cache_key} - CACHE-ONLY MODE: returning empty slots");
    return ['message' => 'No cached data available', 'slots' => []];
}

/**
 * Shortcode for easy embedding of booking system
 */
function serenity_booking_shortcode($atts) {
    // Enqueue scripts and styles for this page
    wp_enqueue_style('serenity-booking-style', plugin_dir_url(__FILE__) . 'serenity-booking.css', [], '1.0');
    wp_enqueue_script('category-booking-script', plugin_dir_url(__FILE__) . 'category-booking.js', ['wp-api'], '40.0', true);

    // Localize script with API configuration including location data
    wp_localize_script('category-booking-script', 'bookingApiConfig', [
        'root' => esc_url_raw(rest_url('mybooking/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'locations' => [
            [
                'id' => '1',
                'name' => 'Serenity HeadSpa ARCU',
                'address' => 'Sos. Arcu nr. 79, Iași',
                'lat' => 47.17152,
                'lng' => 27.56374,
                'mapEmbedUrl' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2712.8!2d27.563716!3d47.171253!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x64a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20ARCU!5e0!3m2!1sen!2sro!4v1234567890'
            ],
            [
                'id' => '3',
                'name' => 'Serenity HeadSpa CARPAȚI',
                'address' => 'Strada Carpați nr. 9A, Iași',
                'lat' => 47.1435892186774,
                'lng' => 27.582183626983806,
                'mapEmbedUrl' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2713.2!2d27.582248!3d47.143363!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x58a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20CARPATI!5e0!3m2!1sen!2sro!4v1234567891'
            ],
            [
                'id' => '2',
                'name' => 'Serenity HeadSpa București',
                'address' => 'Strada Serghei Vasilievici Rahmaninov 38, interfon 05, București',
                'lat' => 44.468694785767745,
                'lng' => 26.106015899473665,
                'mapEmbedUrl' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2848.1!2d26.105458!3d44.468534!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x63a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20Bucuresti!5e0!3m2!1sen!2sro!4v1234567892'
            ]
        ]
    ]);

    // Return the HTML structure
    ob_start();
    ?>
    <div id="category-booking-container" class="serenity-booking">
        <!-- Step 1: Location Selection -->
        <div id="locations-section" class="step-container active">
            <div class="step-header">
                <div class="step-number">1</div>
                <h2 class="step-title">Selectați locația</h2>
            </div>
            <div id="geolocation-status" class="geolocation-status" style="display: none;"></div>
            <div id="locations-loading" class="loading-spinner">
                <div class="spinner"></div>
                Se detectează locația...
            </div>
            <div id="locations-error" class="error-message" style="display: none;"></div>
            <div id="locations-grid" class="location-grid"></div>
            <div id="location-confirm" style="display: none; text-align: center; margin-top: 20px;">
                <button id="confirm-location-btn" class="btn-primary">Confirmă locația selectată</button>
            </div>
        </div>

        <!-- Step 2: Category Selection -->
        <div id="categories-section" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">2</div>
                <h2 class="step-title">Selectați categoria de servicii</h2>
            </div>
            <div id="categories-loading" class="loading-spinner">
                <div class="spinner"></div>
                Se încarcă categoriile...
            </div>
            <div id="categories-error" class="error-message" style="display: none;"></div>
            <div id="categories-grid" class="categories-grid"></div>
        </div>

        <!-- Step 3: Service Selection -->
        <div id="services-section" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">3</div>
                <h2 class="step-title">Selectați serviciul</h2>
            </div>
            <div id="services-loading" class="loading-spinner">
                <div class="spinner"></div>
                Se încarcă serviciile...
            </div>
            <div id="services-error" class="error-message" style="display: none;"></div>
            <div id="services-grid" class="services-grid"></div>
        </div>

        <!-- Step 4: Date & Time Selection -->
        <div id="datetime-section" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">4</div>
                <h2 class="step-title">Selectați data și ora</h2>
            </div>
            <div id="datetime-loading" class="loading-spinner">
                <div class="spinner"></div>
                Se încarcă intervalele disponibile...
            </div>
            <div id="datetime-error" class="error-message" style="display: none;"></div>
            <div id="datetime-picker"></div>
        </div>

        <!-- Step 5: Customer Details -->
        <div id="details-section" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">5</div>
                <h2 class="step-title">Datele dvs. de contact</h2>
            </div>
            <div id="booking-summary"></div>
            <form id="customer-details-form">
                <div class="form-group">
                    <label for="customer-name">Nume complet *</label>
                    <input type="text" id="customer-name" name="customerName" required>
                </div>
                <div class="form-group">
                    <label for="customer-email">Email *</label>
                    <input type="email" id="customer-email" name="customerEmail" required>
                </div>
                <div class="form-group">
                    <label for="customer-phone">Telefon *</label>
                    <input type="tel" id="customer-phone" name="customerPhone" required>
                </div>
                <div class="form-group">
                    <label for="appointment-notes">Observații (opțional)</label>
                    <textarea id="appointment-notes" name="appointmentNotes" rows="3" placeholder="Adăugați orice observații sau cerințe speciale..."></textarea>
                </div>
                <button type="submit" id="proceed-to-payment" class="btn-primary">Continuă la plată</button>
            </form>
        </div>

        <!-- Loading overlay for payment redirect -->
        <div id="payment-loading" class="step-container" style="display: none;">
            <div class="step-header">
                <div class="step-number">✓</div>
                <h2 class="step-title">Se procesează programarea...</h2>
            </div>
            <div class="loading-spinner">
                <div class="spinner"></div>
                Vă redirecționăm către pagina de plată...
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Register the shortcode
add_shortcode('serenity_booking', 'serenity_booking_shortcode');