<?php
/**
 * MedSoft WooCommerce Integration
 * 
 * Handles the integration between the MedSoft booking system and WooCommerce
 */

if (!defined('ABSPATH')) exit; // Block direct access

// Make sure WooCommerce is active and properly loaded
if (!class_exists('WooCommerce')) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    file_put_contents($log_file, "WooCommerce class not found, integration disabled\n", FILE_APPEND | LOCK_EX);
    return;
}

// Check for critical WooCommerce functions that our integration needs
$required_functions = [
    'wc_get_cart_item_data_hash',
    'wc_get_product_id_by_sku',
    'wc_get_order'
];

$missing_functions = [];
foreach ($required_functions as $function) {
    if (!function_exists($function)) {
        $missing_functions[] = $function;
    }
}

if (!empty($missing_functions)) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    file_put_contents($log_file, "WooCommerce integration disabled - missing functions: " . implode(', ', $missing_functions) . "\n", FILE_APPEND | LOCK_EX);
    file_put_contents($log_file, "Please reinstall WooCommerce plugin to fix missing functions\n", FILE_APPEND | LOCK_EX);
    return;
}

// Log that WooCommerce integration is loading
$upload_dir = wp_upload_dir();
$log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
file_put_contents($log_file, "WooCommerce integration file loaded successfully\n", FILE_APPEND | LOCK_EX);

// Ensure WooCommerce is fully loaded
function ensure_woocommerce_loaded() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "Ensuring WooCommerce is loaded\n", FILE_APPEND | LOCK_EX);

    // Check if WooCommerce is active and loaded
    if (!function_exists('WC') || !class_exists('WooCommerce')) {
        file_put_contents($log_file, "WooCommerce not properly loaded\n", FILE_APPEND | LOCK_EX);
        return false;
    }

    // Check if WC is initialized
    if (!did_action('woocommerce_init')) {
        file_put_contents($log_file, "WooCommerce not yet initialized\n", FILE_APPEND | LOCK_EX);
        return false;
    }
    
    // Safely initialize WooCommerce components
    try {
        // Initialize the session if needed
        if (!WC()->session && class_exists('WC_Session_Handler')) {
            file_put_contents($log_file, "Initializing WC session\n", FILE_APPEND | LOCK_EX);
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
            if (class_exists($session_class)) {
                WC()->session = new $session_class();
                WC()->session->init();
            }
        }

        // Initialize customer if needed
        if (!WC()->customer && class_exists('WC_Customer')) {
            file_put_contents($log_file, "Initializing WC customer\n", FILE_APPEND | LOCK_EX);
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }

        // Initialize cart if needed (with additional safety checks)
        if (!WC()->cart && class_exists('WC_Cart') && function_exists('wc_get_cart_item_data_hash')) {
            file_put_contents($log_file, "Initializing WC cart\n", FILE_APPEND | LOCK_EX);
            WC()->cart = new WC_Cart();
        } elseif (!function_exists('wc_get_cart_item_data_hash')) {
            file_put_contents($log_file, "Skipping cart initialization - wc_get_cart_item_data_hash function missing\n", FILE_APPEND | LOCK_EX);
        }
    } catch (Exception $e) {
        file_put_contents($log_file, "Error initializing WC components: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return false;
    }
    
    file_put_contents($log_file, "WooCommerce components initialized\n", FILE_APPEND | LOCK_EX);
    return true;
}

// Use a later hook to avoid early cart initialization issues
add_action('woocommerce_loaded', 'ensure_woocommerce_loaded', 10);

// Fallback if woocommerce_loaded doesn't exist
if (!has_action('woocommerce_loaded')) {
    add_action('wp_loaded', 'ensure_woocommerce_loaded', 20);
}

/**
 * Register REST API endpoints
 */
add_action('rest_api_init', function () {
    // Create WooCommerce order from booking
    register_rest_route('mybooking/v1', '/create-order', [
        'methods' => 'POST',
        'callback' => 'create_woocommerce_order_from_booking',
        'permission_callback' => 'validate_booking_nonce'
    ]);

    // Sync MedSoft categories to WooCommerce
    register_rest_route('mybooking/v1', '/sync-categories', [
        'methods' => 'POST',
        'callback' => 'sync_categories_endpoint',
        'permission_callback' => 'validate_booking_nonce'
    ]);
});

/**
 * REST API endpoint to sync categories
 */
function sync_categories_endpoint($request) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== Manual category sync requested ===\n", FILE_APPEND | LOCK_EX);

    $synced_categories = sync_medsoft_categories_to_woocommerce();

    if ($synced_categories === false) {
        return new WP_Error('sync_failed', 'Failed to sync categories', ['status' => 500]);
    }

    return [
        'success' => true,
        'message' => 'Categories synced successfully',
        'synced_categories' => $synced_categories,
        'count' => count($synced_categories)
    ];
}


/**
 * Create a WooCommerce order from booking data
 */
function create_woocommerce_order_from_booking($request) {
    // Set up logging
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    // Early safety checks
    if (!function_exists('WC') || !class_exists('WooCommerce')) {
        file_put_contents($log_file, "WooCommerce not available in create_woocommerce_order_from_booking\n", FILE_APPEND | LOCK_EX);
        return new WP_Error('wc_not_available', 'WooCommerce is not available', ['status' => 500]);
    }

    try {
        file_put_contents($log_file, "=== Starting create_woocommerce_order_from_booking ===\n", FILE_APPEND | LOCK_EX);
        
        $booking_data = $request->get_json_params();
        file_put_contents($log_file, "Booking data received: " . json_encode($booking_data) . "\n", FILE_APPEND | LOCK_EX);
        
        // Validate required fields
        $required_fields = ['doctorId', 'locationId', 'startDateTime', 'endDateTime', 'patientName', 'patientPhoneNumber', 'appointmentDetails'];
        foreach ($required_fields as $field) {
            if (empty($booking_data[$field])) {
                file_put_contents($log_file, "Missing required field: {$field}\n", FILE_APPEND | LOCK_EX);
                return new WP_Error('missing_field', "Field '{$field}' is required", ['status' => 400]);
            }
        }
        
        // Store booking data in a transient for later use
        $booking_key = 'medsoft_booking_' . md5($booking_data['patientPhoneNumber'] . time());
        set_transient($booking_key, $booking_data, 24 * HOUR_IN_SECONDS); // Store for 24 hours
        file_put_contents($log_file, "Booking data stored in transient with key: {$booking_key}\n", FILE_APPEND | LOCK_EX);
        
        // Ensure WooCommerce is loaded
        ensure_woocommerce_loaded();
        file_put_contents($log_file, "WooCommerce loaded check: " . (function_exists('WC') ? 'Yes' : 'No') . "\n", FILE_APPEND | LOCK_EX);
        
        // Get service details from booking data
        $service_name = $booking_data['appointmentDetails'];
        $service_details = null;

        // Try to get additional service details if we have service ID
        if (isset($booking_data['serviceId'])) {
            $service_details = [
                'id' => $booking_data['serviceId'],
                'price' => isset($booking_data['servicePrice']) ? $booking_data['servicePrice'] : 0,
                'category' => isset($booking_data['category']) ? $booking_data['category'] : null,
                'cod' => isset($booking_data['serviceCod']) ? $booking_data['serviceCod'] : null
            ];
        }

        // Find or create a product for this service
        $product_id = find_or_create_service_product($service_name, $service_details);
        file_put_contents($log_file, "Product ID for service: {$product_id}\n", FILE_APPEND | LOCK_EX);
        
        if (!$product_id) {
            file_put_contents($log_file, "Failed to create or find product\n", FILE_APPEND | LOCK_EX);
            return new WP_Error('product_error', 'Could not create or find a product for this service', ['status' => 500]);
        }
        
        // Check if WC is available
        if (!function_exists('WC')) {
            file_put_contents($log_file, "WC function not available\n", FILE_APPEND | LOCK_EX);
            return new WP_Error('wc_not_loaded', 'WooCommerce is not properly loaded', ['status' => 500]);
        }
        
        // Initialize WooCommerce components in the correct order
        file_put_contents($log_file, "Initializing WooCommerce components\n", FILE_APPEND | LOCK_EX);
        
        // 1. Initialize session if needed
        if (!WC()->session) {
            file_put_contents($log_file, "Initializing WC session\n", FILE_APPEND | LOCK_EX);
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
            WC()->session = new $session_class();
            WC()->session->init();
        }
        
        // 2. Initialize customer if needed
        if (!WC()->customer) {
            file_put_contents($log_file, "Initializing WC customer\n", FILE_APPEND | LOCK_EX);
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }
        
        // 3. Initialize cart if needed (with safety check for missing function)
        if (!WC()->cart && function_exists('wc_get_cart_item_data_hash') && class_exists('WC_Cart')) {
            file_put_contents($log_file, "Initializing WC cart\n", FILE_APPEND | LOCK_EX);
            WC()->cart = new WC_Cart();
        } elseif (!function_exists('wc_get_cart_item_data_hash')) {
            file_put_contents($log_file, "Cannot initialize cart - wc_get_cart_item_data_hash function missing\n", FILE_APPEND | LOCK_EX);
        }
        
        // Empty the cart first
        file_put_contents($log_file, "Emptying cart\n", FILE_APPEND | LOCK_EX);
        WC()->cart->empty_cart();
        
        // Add product to cart with booking data as cart item data
        $cart_item_data = array(
            'medsoft_booking_key' => $booking_key
        );
        
        file_put_contents($log_file, "Adding product {$product_id} to cart with booking key {$booking_key}\n", FILE_APPEND | LOCK_EX);
        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_item_data);
        
        if (!$cart_item_key) {
            file_put_contents($log_file, "Failed to add product to cart\n", FILE_APPEND | LOCK_EX);
            return new WP_Error('cart_error', 'Could not add product to cart', ['status' => 500]);
        }
        
        file_put_contents($log_file, "Product added to cart with key: {$cart_item_key}\n", FILE_APPEND | LOCK_EX);
        
        // Make sure the cart is saved to session
        WC()->cart->set_session();
        file_put_contents($log_file, "Cart saved to session\n", FILE_APPEND | LOCK_EX);
        
        // Use direct checkout URL instead of wc_get_checkout_url()
        $site_url = site_url();
        $checkout_url = trailingslashit($site_url) . 'checkout/';
        file_put_contents($log_file, "Direct checkout URL: {$checkout_url}\n", FILE_APPEND | LOCK_EX);
        
        $response = [
            'success' => true,
            'message' => 'Product added to cart successfully',
            'redirect_url' => $checkout_url,
            'booking_key' => $booking_key,
            'product_id' => $product_id,
            'cart_item_key' => $cart_item_key
        ];
        
        file_put_contents($log_file, "Response: " . json_encode($response) . "\n", FILE_APPEND | LOCK_EX);
        file_put_contents($log_file, "=== Finished create_woocommerce_order_from_booking ===\n\n", FILE_APPEND | LOCK_EX);
        
        return $response;
    } catch (Exception $e) {
        // Log the error for debugging
        file_put_contents($log_file, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        file_put_contents($log_file, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);
        file_put_contents($log_file, "=== Error in create_woocommerce_order_from_booking ===\n\n", FILE_APPEND | LOCK_EX);
        
        // Return a friendly error message
        return new WP_Error(
            'order_creation_error',
            'An error occurred while creating your order: ' . $e->getMessage(),
            ['status' => 500]
        );
    }
}

/**
 * 1. CATEGORY MANAGEMENT - Check/Create WooCommerce categories from MedSoft
 */

/**
 * Get or create a WooCommerce category for a MedSoft service category
 */
function get_or_create_medsoft_category($medsoft_category) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== Getting/Creating category for: {$medsoft_category} ===\n", FILE_APPEND | LOCK_EX);

    // Sanitize category name for WooCommerce
    $category_slug = sanitize_title($medsoft_category);

    // Check if category already exists by slug
    $existing_category = get_term_by('slug', $category_slug, 'product_cat');

    if ($existing_category) {
        file_put_contents($log_file, "Found existing category by slug: {$category_slug}, ID: {$existing_category->term_id}\n", FILE_APPEND | LOCK_EX);
        return $existing_category->term_id;
    }

    // Check if category exists by name (case-insensitive)
    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'name' => $medsoft_category
    ]);

    if (!empty($categories)) {
        $category_id = $categories[0]->term_id;
        file_put_contents($log_file, "Found existing category by name: {$medsoft_category}, ID: {$category_id}\n", FILE_APPEND | LOCK_EX);
        return $category_id;
    }

    // Check by meta data (if we stored MedSoft category mapping)
    $categories_with_meta = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'meta_query' => [
            [
                'key' => '_medsoft_category',
                'value' => $medsoft_category,
                'compare' => '='
            ]
        ]
    ]);

    if (!empty($categories_with_meta)) {
        $category_id = $categories_with_meta[0]->term_id;
        file_put_contents($log_file, "Found existing category by meta: {$medsoft_category}, ID: {$category_id}\n", FILE_APPEND | LOCK_EX);
        return $category_id;
    }

    // Create new category
    file_put_contents($log_file, "Creating new category: {$medsoft_category}\n", FILE_APPEND | LOCK_EX);

    $category_data = wp_insert_term(
        $medsoft_category, // Category name
        'product_cat',     // Taxonomy
        [
            'slug' => $category_slug,
            'description' => get_medsoft_category_description($medsoft_category)
        ]
    );

    if (is_wp_error($category_data)) {
        file_put_contents($log_file, "Error creating category: " . $category_data->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
        return false;
    }

    $category_id = $category_data['term_id'];

    // Store MedSoft category mapping in term meta
    update_term_meta($category_id, '_medsoft_category', $medsoft_category);
    update_term_meta($category_id, '_medsoft_category_created', current_time('mysql'));

    file_put_contents($log_file, "Created new category: {$medsoft_category}, ID: {$category_id}\n", FILE_APPEND | LOCK_EX);

    return $category_id;
}

/**
 * Get description for MedSoft category
 */
function get_medsoft_category_description($category) {
    $descriptions = [
        'TRATAMENTE FACIALE' => 'Tratamente profesionale pentru îngrijirea și rejuvenarea feței',
        'REMODELARE CORPORALA' => 'Tratamente pentru conturarea și tonifierea corpului',
        'EPILARE DEFINITIVA LASER' => 'Epilare definitivă cu tehnologie laser avansată',
        'DRENAJ (PRESOTERAPIE & TERMOTERAPIE)' => 'Tratamente de drenaj și detoxifiere',
        'MASAJ & SPA' => 'Tratamente de relaxare și îngrijire holistică',
        'TERAPIE CRANIO-SACRALA' => 'Terapii specializate pentru echilibrul corpului'
    ];

    return isset($descriptions[$category]) ? $descriptions[$category] : 'Servicii specializate ' . strtolower($category);
}

/**
 * Sync all MedSoft categories to WooCommerce
 */
function sync_medsoft_categories_to_woocommerce() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== Syncing MedSoft categories to WooCommerce ===\n", FILE_APPEND | LOCK_EX);

    // Get MedSoft service categories
    $medsoft_categories = get_medsoft_service_categories();

    if (empty($medsoft_categories)) {
        file_put_contents($log_file, "No MedSoft categories found\n", FILE_APPEND | LOCK_EX);
        return false;
    }

    $created_categories = [];

    foreach ($medsoft_categories as $category_name => $category_data) {
        $category_id = get_or_create_medsoft_category($category_name);

        if ($category_id) {
            $created_categories[$category_name] = $category_id;
            file_put_contents($log_file, "Processed category: {$category_name} -> ID: {$category_id}\n", FILE_APPEND | LOCK_EX);
        }
    }

    file_put_contents($log_file, "Synced " . count($created_categories) . " categories\n", FILE_APPEND | LOCK_EX);
    file_put_contents($log_file, "=== Finished syncing categories ===\n\n", FILE_APPEND | LOCK_EX);

    return $created_categories;
}

/**
 * 2. PRODUCT MANAGEMENT - Find or create a WooCommerce product for a MedSoft service
 */
function find_or_create_service_product($service_name, $service_data = null) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== Finding/Creating product for service: {$service_name} ===\n", FILE_APPEND | LOCK_EX);
    
    // First, try to find by exact title match using WP_Query (more reliable)
    $products_query = new WP_Query([
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'title' => $service_name,
        'meta_query' => [
            [
                'key' => '_medsoft_service_name',
                'value' => $service_name,
                'compare' => '='
            ]
        ]
    ]);

    if ($products_query->have_posts()) {
        $product_id = $products_query->posts[0]->ID;
        file_put_contents($log_file, "Found existing product by title/meta match, ID: {$product_id}\n", FILE_APPEND | LOCK_EX);
        wp_reset_postdata();
        return $product_id;
    }
    wp_reset_postdata();
    
    // Next, try to find by SKU (if we're using service name as SKU)
    if (function_exists('wc_get_product_id_by_sku')) {
        $product_id = wc_get_product_id_by_sku($service_name);
        if ($product_id) {
            file_put_contents($log_file, "Found existing product by SKU, ID: {$product_id}\n", FILE_APPEND | LOCK_EX);
            return $product_id;
        }
    }
    
    // Try a more flexible search
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 10,
        's' => $service_name
    ];
    
    $products = get_posts($args);
    
    if (!empty($products)) {
        foreach ($products as $product) {
            // Case-insensitive comparison
            if (strtolower(trim($product->post_title)) === strtolower(trim($service_name))) {
                file_put_contents($log_file, "Found existing product with case-insensitive match, ID: {$product->ID}\n", FILE_APPEND | LOCK_EX);
                return $product->ID;
            }
            
            // Partial match (service name is contained in product title)
            if (stripos($product->post_title, $service_name) !== false) {
                file_put_contents($log_file, "Found existing product with partial match, ID: {$product->ID}\n", FILE_APPEND | LOCK_EX);
                return $product->ID;
            }
        }
    }
    
    // Check for products with meta data matching this service
    $meta_query_args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 5,
        'meta_query' => [
            [
                'key' => '_medsoft_service_name',
                'value' => $service_name,
                'compare' => '='
            ]
        ]
    ];
    
    $meta_products = get_posts($meta_query_args);
    
    if (!empty($meta_products)) {
        $product_id = $meta_products[0]->ID;
        file_put_contents($log_file, "Found existing product by meta data, ID: {$product_id}\n", FILE_APPEND | LOCK_EX);
        return $product_id;
    }
    
    file_put_contents($log_file, "No existing product found, creating new one\n", FILE_APPEND | LOCK_EX);
    
    // If no product exists, create one
    file_put_contents($log_file, "Creating new product for service: {$service_name}\n", FILE_APPEND | LOCK_EX);

    // Get service details from MedSoft if not provided
    if (!$service_data) {
        $service_data = get_medsoft_service_details($service_name);
    }

    // Get price and category from service data
    $price = isset($service_data['price']) ? $service_data['price'] : 0;
    $category = isset($service_data['category']) ? $service_data['category'] : null;
    $service_id = isset($service_data['id']) ? $service_data['id'] : null;
    $service_code = isset($service_data['cod']) ? $service_data['cod'] : null;

    file_put_contents($log_file, "Service details - Price: {$price}, Category: " . ($category ?: 'none') . ", ID: " . ($service_id ?: 'none') . "\n", FILE_APPEND | LOCK_EX);

    // Create WooCommerce category if needed
    $wc_category_id = null;
    if ($category) {
        $wc_category_id = get_or_create_medsoft_category($category);
        file_put_contents($log_file, "WooCommerce category ID: " . ($wc_category_id ?: 'failed to create') . "\n", FILE_APPEND | LOCK_EX);
    }

    // Create a new product
    $product = [
        'post_title' => $service_name,
        'post_content' => 'Programare: ' . $service_name . ($category ? ' din categoria ' . $category : ''),
        'post_status' => 'publish',
        'post_type' => 'product',
    ];

    $product_id = wp_insert_post($product);

    if (is_wp_error($product_id)) {
        file_put_contents($log_file, "Error creating product: " . $product_id->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
        return false;
    }

    file_put_contents($log_file, "Created new product with ID: {$product_id}\n", FILE_APPEND | LOCK_EX);

    // Set product meta
    update_post_meta($product_id, '_regular_price', $price);
    update_post_meta($product_id, '_price', $price);
    update_post_meta($product_id, '_virtual', 'yes'); // It's a virtual product
    update_post_meta($product_id, '_sold_individually', 'yes'); // Can only be purchased once per order
    update_post_meta($product_id, '_medsoft_service_name', $service_name);

    // Store MedSoft service details
    if ($service_id) {
        update_post_meta($product_id, '_medsoft_service_id', $service_id);
    }
    if ($service_code) {
        update_post_meta($product_id, '_medsoft_service_code', $service_code);
        update_post_meta($product_id, '_sku', $service_code); // Use MedSoft code as SKU
    }
    if ($category) {
        update_post_meta($product_id, '_medsoft_category', $category);
    }

    // Store service duration from service_data
    if (isset($service_data['duration']) && $service_data['duration']) {
        update_post_meta($product_id, '_medsoft_service_duration', $service_data['duration']);
        file_put_contents($log_file, "Saved service duration: {$service_data['duration']} minutes\n", FILE_APPEND | LOCK_EX);
    }

    // Set product categories
    $categories_to_assign = [];

    // Add MedSoft category if created
    if ($wc_category_id) {
        $categories_to_assign[] = $wc_category_id;
    }

    // REMOVED: Don't add "Programări" category to products
    // This category was being displayed in frontend but is not needed for user selection
    // $programari_cat = get_or_create_medsoft_category('Programări');
    // if ($programari_cat && !in_array($programari_cat, $categories_to_assign)) {
    //     $categories_to_assign[] = $programari_cat;
    // }

    if (!empty($categories_to_assign)) {
        wp_set_object_terms($product_id, $categories_to_assign, 'product_cat');
        file_put_contents($log_file, "Assigned categories: " . implode(', ', $categories_to_assign) . "\n", FILE_APPEND | LOCK_EX);
    }

    // Set product type to simple
    wp_set_object_terms($product_id, 'simple', 'product_type');

    file_put_contents($log_file, "Product created and configured successfully\n", FILE_APPEND | LOCK_EX);
    
    return $product_id;
}

/**
 * Get detailed information about a MedSoft service
 */
function get_medsoft_service_details($service_name) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "Getting details for service: {$service_name}\n", FILE_APPEND | LOCK_EX);

    // Get the price list from MedSoft
    $price_list = medsoft_api_request('/priceList');

    if (is_wp_error($price_list)) {
        file_put_contents($log_file, "Error getting price list: " . $price_list->get_error_message() . "\n", FILE_APPEND | LOCK_EX);
        return ['price' => 0, 'category' => null, 'id' => null, 'cod' => null];
    }

    $service_details = ['price' => 0, 'category' => null, 'id' => null, 'cod' => null, 'duration' => 60];

    // Find the service in the price list
    foreach ($price_list as $service) {
        if (isset($service->denumire) && $service->denumire === $service_name) {
            $service_details = [
                'price' => isset($service->pret) ? $service->pret : 0,
                'category' => isset($service->tip_serviciu) ? $service->tip_serviciu : null,
                'id' => isset($service->id) ? $service->id : null,
                'cod' => isset($service->cod) ? $service->cod : null,
                'duration' => 60 // Default, will be updated from appointmentScop
            ];
            break;
        }
    }

    // Get duration from appointmentScop endpoint (correct source for duration)
    if ($service_details['cod']) {
        $appointment_scopes = medsoft_api_request('/appointmentScop');
        if (!is_wp_error($appointment_scopes)) {
            foreach ($appointment_scopes as $scope) {
                if (isset($scope->cod) && $scope->cod == $service_details['cod'] && isset($scope->durata)) {
                    $service_details['duration'] = intval($scope->durata);
                    file_put_contents($log_file, "Found duration from appointmentScop: {$scope->durata} minutes\n", FILE_APPEND | LOCK_EX);
                    break;
                }
            }
        }
    }

    if ($service_details['cod'] === null) {
        file_put_contents($log_file, "Service not found in price list: {$service_name}\n", FILE_APPEND | LOCK_EX);
    }

    file_put_contents($log_file, "Final service details: " . json_encode($service_details) . "\n", FILE_APPEND | LOCK_EX);
    return $service_details;
}

/**
 * Get the price of a service from MedSoft (legacy function for backward compatibility)
 */
function get_service_price_from_medsoft($service_name) {
    $details = get_medsoft_service_details($service_name);
    return $details['price'];
}

/**
 * Store booking data in order meta when order is created
 */
if (function_exists('WC') && class_exists('WooCommerce')) {
    add_action('woocommerce_checkout_create_order_line_item', 'add_booking_data_to_order_item', 10, 4);
}
function add_booking_data_to_order_item($item, $cart_item_key, $values, $order) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    
    file_put_contents($log_file, "=== Processing order line item ===\n", FILE_APPEND | LOCK_EX);
    
    if (isset($values['medsoft_booking_key'])) {
        $booking_key = $values['medsoft_booking_key'];
        file_put_contents($log_file, "Found booking key in cart item: {$booking_key}\n", FILE_APPEND | LOCK_EX);
        
        // Add the booking key to the order item meta
        $item->add_meta_data('medsoft_booking_key', $booking_key);
        file_put_contents($log_file, "Added booking key to order item meta\n", FILE_APPEND | LOCK_EX);
        
        // Get the booking data from the transient
        $booking_data = get_transient($booking_key);
        
        if ($booking_data) {
            file_put_contents($log_file, "Found booking data in transient: " . json_encode($booking_data) . "\n", FILE_APPEND | LOCK_EX);
            
            // Add appointment details to the order item
            $appointment_date = date('d.m.Y H:i', strtotime($booking_data['startDateTime']));
            $appointment_details = isset($booking_data['appointmentDetails']) ? $booking_data['appointmentDetails'] : '';
            
            $item->add_meta_data('Programare', $appointment_details . ' - ' . $appointment_date, true);
            file_put_contents($log_file, "Added appointment details to order item meta\n", FILE_APPEND | LOCK_EX);
        } else {
            file_put_contents($log_file, "No booking data found in transient for key: {$booking_key}\n", FILE_APPEND | LOCK_EX);
        }
    } else {
        file_put_contents($log_file, "No booking key found in cart item\n", FILE_APPEND | LOCK_EX);
    }
    
    file_put_contents($log_file, "=== Finished processing order line item ===\n\n", FILE_APPEND | LOCK_EX);
}

/**
 * Store booking data in order meta when order is created
 */
if (function_exists('WC') && class_exists('WooCommerce')) {
    add_action('woocommerce_checkout_create_order', 'add_booking_data_to_order', 10, 2);
}
function add_booking_data_to_order($order, $data) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== Adding booking data to order (Order ID: " . $order->get_id() . ") ===\n", FILE_APPEND | LOCK_EX);
    file_put_contents($log_file, "Cart items count: " . count(WC()->cart->get_cart()) . "\n", FILE_APPEND | LOCK_EX);

    $found_booking = false;
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        file_put_contents($log_file, "Checking cart item: " . json_encode(array_keys($cart_item)) . "\n", FILE_APPEND | LOCK_EX);

        if (isset($cart_item['medsoft_booking_key'])) {
            $booking_key = $cart_item['medsoft_booking_key'];
            file_put_contents($log_file, "Found booking key in cart: {$booking_key}\n", FILE_APPEND | LOCK_EX);
            $found_booking = true;

            $booking_data = get_transient($booking_key);

            if ($booking_data) {
                file_put_contents($log_file, "Found booking data in transient: " . json_encode($booking_data) . "\n", FILE_APPEND | LOCK_EX);

                // Store booking data in order meta
                $order->update_meta_data('_medsoft_booking_data', $booking_data);
                $order->update_meta_data('_medsoft_booking_key', $booking_key);
                
                // Add customer details from booking
                if (isset($booking_data['patientName'])) {
                    $name_parts = explode(' ', $booking_data['patientName'], 2);
                    $first_name = $name_parts[0];
                    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
                    
                    $order->set_billing_first_name($first_name);
                    $order->set_billing_last_name($last_name);
                    file_put_contents($log_file, "Set customer name: {$first_name} {$last_name}\n", FILE_APPEND | LOCK_EX);
                }
                
                if (isset($booking_data['patientPhoneNumber'])) {
                    $order->set_billing_phone($booking_data['patientPhoneNumber']);
                    file_put_contents($log_file, "Set customer phone: {$booking_data['patientPhoneNumber']}\n", FILE_APPEND | LOCK_EX);
                }
                
                if (isset($booking_data['patientEmail'])) {
                    $order->set_billing_email($booking_data['patientEmail']);
                    file_put_contents($log_file, "Set customer email: {$booking_data['patientEmail']}\n", FILE_APPEND | LOCK_EX);
                }
                
                // Add appointment details as order note
                $appointment_date = date('d.m.Y H:i', strtotime($booking_data['startDateTime']));
                $order->add_order_note("Programare pentru {$booking_data['appointmentDetails']} în data de {$appointment_date}");
                file_put_contents($log_file, "Added appointment note to order\n", FILE_APPEND | LOCK_EX);
            } else {
                file_put_contents($log_file, "No booking data found in transient for key: {$booking_key}\n", FILE_APPEND | LOCK_EX);
            }
        }
    }

    if (!$found_booking) {
        file_put_contents($log_file, "WARNING: No booking data found in any cart items!\n", FILE_APPEND | LOCK_EX);
    }

    file_put_contents($log_file, "=== Finished adding booking data to order ===\n\n", FILE_APPEND | LOCK_EX);
}

/**
 * Update all existing WooCommerce products with correct duration from MedSoft
 */
function update_products_with_duration() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';

    file_put_contents($log_file, "=== Updating products with duration ===\n", FILE_APPEND | LOCK_EX);

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
    file_put_contents($log_file, "Found " . count($products) . " products to update\n", FILE_APPEND | LOCK_EX);

    // Get appointment scopes for duration data
    $appointment_scopes = medsoft_api_request('/appointmentScop');
    if (is_wp_error($appointment_scopes)) {
        file_put_contents($log_file, "Failed to get appointment scopes\n", FILE_APPEND | LOCK_EX);
        return;
    }

    $updated_count = 0;
    foreach ($products as $product) {
        $service_id = $product->get_meta('_medsoft_service_id');
        $current_duration = $product->get_meta('_medsoft_service_duration');

        if (!$current_duration && $service_id) {
            // Find duration in appointment scopes
            foreach ($appointment_scopes as $scope) {
                if (isset($scope->cod) && $scope->cod == $service_id && isset($scope->durata)) {
                    $duration = intval($scope->durata);
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
}

/**
 * Create MedSoft appointment when payment is complete
 */
if (function_exists('WC') && class_exists('WooCommerce')) {
    add_action('woocommerce_payment_complete', 'create_medsoft_appointment_after_payment', 10, 1);
}
function create_medsoft_appointment_after_payment($order_id) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    
    file_put_contents($log_file, "=== Starting create_medsoft_appointment_after_payment for order {$order_id} ===\n", FILE_APPEND | LOCK_EX);
    
    if (!function_exists('wc_get_order')) {
        file_put_contents($log_file, "wc_get_order function not available\n", FILE_APPEND | LOCK_EX);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        file_put_contents($log_file, "Failed to get order {$order_id}\n", FILE_APPEND | LOCK_EX);
        return;
    }

    $booking_data = $order->get_meta('_medsoft_booking_data');
    $booking_key = $order->get_meta('_medsoft_booking_key');
    
    file_put_contents($log_file, "Order meta - booking key: " . ($booking_key ? $booking_key : 'not found') . "\n", FILE_APPEND | LOCK_EX);
    file_put_contents($log_file, "Order meta - booking data: " . ($booking_data ? json_encode($booking_data) : 'not found') . "\n", FILE_APPEND | LOCK_EX);
    
    // If booking data is not in order meta, try to get it from transient
    if (!$booking_data && $booking_key) {
        file_put_contents($log_file, "Trying to get booking data from transient with key: {$booking_key}\n", FILE_APPEND | LOCK_EX);
        $booking_data = get_transient($booking_key);
        
        if ($booking_data) {
            file_put_contents($log_file, "Found booking data in transient: " . json_encode($booking_data) . "\n", FILE_APPEND | LOCK_EX);
            // Save it to order meta for future reference
            $order->update_meta_data('_medsoft_booking_data', $booking_data);
            $order->save();
        }
    }
    
    // If still no booking data, try to find it in cart items
    if (!$booking_data) {
        file_put_contents($log_file, "No booking data found in meta or transient, checking cart items\n", FILE_APPEND | LOCK_EX);
        
        foreach ($order->get_items() as $item) {
            $item_booking_key = $item->get_meta('medsoft_booking_key');
            
            if ($item_booking_key) {
                file_put_contents($log_file, "Found booking key in item meta: {$item_booking_key}\n", FILE_APPEND | LOCK_EX);
                $booking_key = $item_booking_key;
                $booking_data = get_transient($item_booking_key);
                
                if ($booking_data) {
                    file_put_contents($log_file, "Found booking data in transient: " . json_encode($booking_data) . "\n", FILE_APPEND | LOCK_EX);
                    // Save it to order meta for future reference
                    $order->update_meta_data('_medsoft_booking_data', $booking_data);
                    $order->update_meta_data('_medsoft_booking_key', $item_booking_key);
                    $order->save();
                    break;
                }
            }
        }
    }
    
    if (!$booking_data) {
        file_put_contents($log_file, "No booking data found for order {$order_id}\n", FILE_APPEND | LOCK_EX);
        $order->add_order_note('Nu s-au găsit date de programare pentru această comandă.');
        file_put_contents($log_file, "=== Finished create_medsoft_appointment_after_payment (no booking data) ===\n\n", FILE_APPEND | LOCK_EX);
        return;
    }
    
    // Create the appointment in MedSoft
    file_put_contents($log_file, "Creating appointment in MedSoft with data: " . json_encode($booking_data) . "\n", FILE_APPEND | LOCK_EX);
    
    // Make sure we have all required fields
    if (empty($booking_data['doctorId']) || empty($booking_data['locationId']) || 
        empty($booking_data['startDateTime']) || empty($booking_data['endDateTime']) || 
        empty($booking_data['patientName']) || empty($booking_data['patientPhoneNumber'])) {
        
        file_put_contents($log_file, "Missing required fields in booking data\n", FILE_APPEND | LOCK_EX);
        $order->add_order_note('Date incomplete pentru programare. Verificare manuală necesară.');
        file_put_contents($log_file, "=== Finished create_medsoft_appointment_after_payment (incomplete data) ===\n\n", FILE_APPEND | LOCK_EX);
        return;
    }
    
    // Prepare data for MedSoft API
    $api_data = array(
        'doctorId' => $booking_data['doctorId'],
        'locationId' => $booking_data['locationId'],
        'startDateTime' => $booking_data['startDateTime'],
        'endDateTime' => $booking_data['endDateTime'],
        'patientName' => $booking_data['patientName'],
        'patientEmail' => isset($booking_data['patientEmail']) ? $booking_data['patientEmail'] : '',
        'patientPhoneNumber' => $booking_data['patientPhoneNumber'],
        'appointmentDetails' => isset($booking_data['appointmentDetails']) ? $booking_data['appointmentDetails'] : '',
        'codPacient' => null // This is required by the API but will be filled by MedSoft
    );
    
    file_put_contents($log_file, "Sending data to MedSoft API: " . json_encode($api_data) . "\n", FILE_APPEND | LOCK_EX);
    
    // Call the MedSoft API to create the appointment
    $result = medsoft_api_request('/createAppointment', 'POST', $api_data);
    
    file_put_contents($log_file, "MedSoft API response: " . (is_wp_error($result) ? $result->get_error_message() : json_encode($result)) . "\n", FILE_APPEND | LOCK_EX);
    
    if (is_wp_error($result)) {
        $error_message = $result->get_error_message();
        $order->add_order_note("Eroare la crearea programării în MedSoft: {$error_message}");
        $order->update_status('on-hold', 'Programarea nu a putut fi creată automat. Verificare manuală necesară.');
        file_put_contents($log_file, "Error creating appointment: {$error_message}\n", FILE_APPEND | LOCK_EX);
    } else {
        // Store appointment ID in order meta
        if (isset($result->appointmentId)) {
            $order->update_meta_data('_medsoft_appointment_id', $result->appointmentId);
            $order->save();
            file_put_contents($log_file, "Appointment created with ID: {$result->appointmentId}\n", FILE_APPEND | LOCK_EX);
        }
        
        $appointment_date = date('d.m.Y H:i', strtotime($booking_data['startDateTime']));
        $order->add_order_note("Programare creată cu succes în MedSoft pentru data de {$appointment_date}");
        
        // Delete the transient as we no longer need it
        delete_transient($booking_key);
        file_put_contents($log_file, "Deleted transient with key: {$booking_key}\n", FILE_APPEND | LOCK_EX);
    }
    
    file_put_contents($log_file, "=== Finished create_medsoft_appointment_after_payment ===\n\n", FILE_APPEND | LOCK_EX);
}

/**
 * Cancel MedSoft appointment when order is cancelled
 */
if (function_exists('WC') && class_exists('WooCommerce')) {
    add_action('woocommerce_order_status_cancelled', 'cancel_medsoft_appointment', 10, 1);
}
function cancel_medsoft_appointment($order_id) {
    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $appointment_id = $order->get_meta('_medsoft_appointment_id');
    
    if (!$appointment_id) {
        return;
    }
    
    // Call MedSoft API to cancel appointment
    $result = medsoft_api_request('/cancelAppointment?appointmentId=' . $appointment_id, 'GET');
    
    if (is_wp_error($result)) {
        $error_message = $result->get_error_message();
        $order->add_order_note("Eroare la anularea programării în MedSoft: {$error_message}");
    } else {
        $order->add_order_note("Programare anulată cu succes în MedSoft.");
    }
}

/**
 * Add appointment details to thank you page
 */
if (function_exists('WC') && class_exists('WooCommerce')) {
    add_action('woocommerce_thankyou', 'add_appointment_details_to_thankyou', 10, 1);
}
function add_appointment_details_to_thankyou($order_id) {
    if (!function_exists('wc_get_order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $booking_data = $order->get_meta('_medsoft_booking_data');
    
    if (!$booking_data) {
        return;
    }
    
    $appointment_date = date('d.m.Y', strtotime($booking_data['startDateTime']));
    $appointment_time = date('H:i', strtotime($booking_data['startDateTime']));
    
    echo '<section class="woocommerce-appointment-details">';
    echo '<h2>Detalii programare</h2>';
    echo '<p><strong>Serviciu:</strong> ' . esc_html($booking_data['appointmentDetails']) . '</p>';
    echo '<p><strong>Data:</strong> ' . esc_html($appointment_date) . '</p>';
    echo '<p><strong>Ora:</strong> ' . esc_html($appointment_time) . '</p>';
    echo '</section>';
}

/**
 * Add appointment details to order emails
 */
if (function_exists('WC') && class_exists('WooCommerce')) {
    add_action('woocommerce_email_order_details', 'add_appointment_details_to_emails', 15, 4);
}
function add_appointment_details_to_emails($order, $sent_to_admin, $plain_text, $email) {
    $booking_data = $order->get_meta('_medsoft_booking_data');
    
    if (!$booking_data) {
        return;
    }
    
    $appointment_date = date('d.m.Y', strtotime($booking_data['startDateTime']));
    $appointment_time = date('H:i', strtotime($booking_data['startDateTime']));
    
    if ($plain_text) {
        echo "\n\n==========\n\n";
        echo "Detalii programare\n\n";
        echo "Serviciu: " . $booking_data['appointmentDetails'] . "\n";
        echo "Data: " . $appointment_date . "\n";
        echo "Ora: " . $appointment_time . "\n";
        echo "\n==========\n\n";
    } else {
        echo '<div style="margin-bottom: 40px;">';
        echo '<h2>Detalii programare</h2>';
        echo '<p><strong>Serviciu:</strong> ' . esc_html($booking_data['appointmentDetails']) . '</p>';
        echo '<p><strong>Data:</strong> ' . esc_html($appointment_date) . '</p>';
        echo '<p><strong>Ora:</strong> ' . esc_html($appointment_time) . '</p>';
        echo '</div>';
    }
}

/**
 * Modify the booking form to include WooCommerce integration
 */
add_action('wp_footer', 'modify_booking_form_for_woocommerce');
function modify_booking_form_for_woocommerce() {
    if (!is_page('programare')) {
        return;
    }
    
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        // Find the final submit button
        const finalSubmitBtn = document.querySelector('#final-submit-button');
        
        if (finalSubmitBtn) {
            // Replace the original click handler
            finalSubmitBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                
                // Get all the booking data
                const firstName = document.querySelector('#firstName').value;
                const lastName = document.querySelector('#lastName').value;
                const email = document.querySelector('#email').value;
                const phoneNumber = document.querySelector('#phoneNumber').value;
                const bookingLocationId = document.querySelector('#booking-location-id').value;
                const bookingDoctorId = document.querySelector('#booking-doctor-id').value;
                const startDateTime = document.querySelector('#booking-start-time').value;
                const endDateTime = document.querySelector('#booking-end-time').value;
                const scopeSelect = document.querySelector('#scope');
                const appointmentDetails = scopeSelect.options[scopeSelect.selectedIndex].text;
                
                // Validate data
                if (!firstName || !lastName || !phoneNumber || !bookingLocationId || 
                    !bookingDoctorId || !startDateTime || !endDateTime || !appointmentDetails) {
                    alert('Vă rugăm completați toate câmpurile obligatorii.');
                    return;
                }
                
                // Prepare payload for WooCommerce order creation
                const payload = {
                    doctorId: parseInt(bookingDoctorId),
                    locationId: parseInt(bookingLocationId),
                    startDateTime: startDateTime,
                    endDateTime: endDateTime,
                    patientName: `${lastName} ${firstName}`,
                    patientEmail: email,
                    patientPhoneNumber: phoneNumber,
                    appointmentDetails: appointmentDetails,
                };
                
                finalSubmitBtn.textContent = 'Se procesează...';
                finalSubmitBtn.disabled = true;
                
                try {
                    // Call our new endpoint to create a WooCommerce order
                    const response = await fetch(bookingApi.root + 'create-order', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': bookingApi.nonce
                        },
                        body: JSON.stringify(payload)
                    });
                    
                    const result = await response.json();
                    
                    if (result.success && result.redirect_url) {
                        // Redirect to checkout
                        window.location.href = result.redirect_url;
                    } else {
                        throw new Error(result.message || 'A apărut o eroare la crearea comenzii.');
                    }
                } catch (error) {
                    console.error('Error creating order:', error);
                    alert('A apărut o eroare: ' + error.message);
                    finalSubmitBtn.textContent = 'Trimite Programarea';
                    finalSubmitBtn.disabled = false;
                }
            });
        }
    });
    </script>
    <?php
}

/**
 * Log the current state of the WooCommerce cart
 */
function log_woocommerce_cart_state($message = 'Cart state') {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    
    if (!function_exists('WC') || !WC()->cart) {
        file_put_contents($log_file, $message . ': Cart not available' . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    
    $cart_contents = WC()->cart->get_cart();
    $cart_count = count($cart_contents);
    $cart_total = WC()->cart->get_cart_contents_total();
    
    file_put_contents($log_file, $message . ': Cart has ' . $cart_count . ' items, total: ' . $cart_total . "\n", FILE_APPEND | LOCK_EX);
    
    foreach ($cart_contents as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $product_name = get_the_title($product_id);
        $booking_key = isset($cart_item['medsoft_booking_key']) ? $cart_item['medsoft_booking_key'] : 'none';
        
        file_put_contents($log_file, "Cart item: {$product_name} (ID: {$product_id}), Booking key: {$booking_key}\n", FILE_APPEND | LOCK_EX);
    }
}

/**
 * Pre-fill checkout fields with booking data
 */
if (function_exists('WC') && class_exists('WooCommerce')) {
    add_filter('woocommerce_checkout_get_value', 'prefill_checkout_fields', 10, 2);
}
function prefill_checkout_fields($value, $input) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/medsoft-api-log.txt';
    
    // Only run this if we have a cart
    if (!WC()->cart || WC()->cart->is_empty()) {
        return $value;
    }
    
    // Check if we have a booking key in the cart
    $booking_key = null;
    $booking_data = null;
    
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (isset($cart_item['medsoft_booking_key'])) {
            $booking_key = $cart_item['medsoft_booking_key'];
            break;
        }
    }
    
    if (!$booking_key) {
        return $value;
    }
    
    // Get booking data from transient
    $booking_data = get_transient($booking_key);
    
    if (!$booking_data) {
        return $value;
    }
    
    file_put_contents($log_file, "Pre-filling checkout field: {$input}\n", FILE_APPEND | LOCK_EX);
    
    // Map booking data to checkout fields
    switch ($input) {
        case 'billing_first_name':
            if (isset($booking_data['patientName'])) {
                $name_parts = explode(' ', $booking_data['patientName'], 2);
                return $name_parts[0];
            }
            break;
            
        case 'billing_last_name':
            if (isset($booking_data['patientName'])) {
                $name_parts = explode(' ', $booking_data['patientName'], 2);
                return isset($name_parts[1]) ? $name_parts[1] : '';
            }
            break;
            
        case 'billing_email':
            return isset($booking_data['patientEmail']) ? $booking_data['patientEmail'] : '';
            
        case 'billing_phone':
            return isset($booking_data['patientPhoneNumber']) ? $booking_data['patientPhoneNumber'] : '';
    }
    
    return $value;
}

/**
 * Add JavaScript to ensure checkout fields are populated
 */
if (function_exists('WC') && class_exists('WooCommerce')) {
    add_action('woocommerce_before_checkout_form', 'ensure_checkout_fields_populated');
}


function ensure_checkout_fields_populated() {
    // Only run this if we have a cart
    if (!WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    
    // Check if we have a booking key in the cart
    $booking_key = null;
    $booking_data = null;
    
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (isset($cart_item['medsoft_booking_key'])) {
            $booking_key = $cart_item['medsoft_booking_key'];
            break;
        }
    }
    
    if (!$booking_key) {
        return;
    }
    
    // Get booking data from transient
    $booking_data = get_transient($booking_key);
    
    if (!$booking_data) {
        return;
    }
    
    // Extract customer data
    $first_name = '';
    $last_name = '';
    
    if (isset($booking_data['patientName'])) {
        $name_parts = explode(' ', $booking_data['patientName'], 2);
        $first_name = $name_parts[0];
        $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
    }
    
    $email = isset($booking_data['patientEmail']) ? $booking_data['patientEmail'] : '';
    $phone = isset($booking_data['patientPhoneNumber']) ? $booking_data['patientPhoneNumber'] : '';
    
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        // Function to fill checkout fields
        function fillCheckoutFields() {
            console.log('Attempting to fill checkout fields with booking data');
            
            // Pre-fill the checkout fields
            var billingFirstName = document.getElementById('billing_first_name');
            var billingLastName = document.getElementById('billing_last_name');
            var billingEmail = document.getElementById('billing_email');
            var billingPhone = document.getElementById('billing_phone');
            
            if (billingFirstName && (!billingFirstName.value || billingFirstName.value === '')) {
                billingFirstName.value = <?php echo json_encode($first_name); ?>;
                console.log('Set first name to:', <?php echo json_encode($first_name); ?>);
            }
            
            if (billingLastName && (!billingLastName.value || billingLastName.value === '')) {
                billingLastName.value = <?php echo json_encode($last_name); ?>;
                console.log('Set last name to:', <?php echo json_encode($last_name); ?>);
            }
            
            if (billingEmail && (!billingEmail.value || billingEmail.value === '')) {
                billingEmail.value = <?php echo json_encode($email); ?>;
                console.log('Set email to:', <?php echo json_encode($email); ?>);
            }
            
            if (billingPhone && (!billingPhone.value || billingPhone.value === '')) {
                billingPhone.value = <?php echo json_encode($phone); ?>;
                console.log('Set phone to:', <?php echo json_encode($phone); ?>);
            }
        }
        
        // Try immediately
        fillCheckoutFields();
        
        // Also try after a short delay to ensure fields are loaded
        setTimeout(fillCheckoutFields, 500);
        
        // And try once more after a longer delay
        setTimeout(fillCheckoutFields, 1500);
    });
    </script>
    <?php
}





























