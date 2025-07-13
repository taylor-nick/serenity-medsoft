// Category-based booking system JavaScript

// API configuration - will be set by WordPress
const bookingApi = {
    root: '/wp-json/mybooking/v1/',
    nonce: ''
};

// Category icons mapping - updated to match new HEADSPA categories
const categoryIcons = {
    'HEADSPA': '💆',
    'HEADSPA & DRENAJ': '💆💧',
    'HEADSPA & TERAPIE CRANIO-SACRALA': '💆🌿',
    'HEADSPA & TRATAMENTE FACIALE': '💆✨',
    'MASAJ & SPA': '🧘',
    'TERAPIE CRANIO-SACRALA': '🌿',
    'DERMATOLOGIE': '🏥',
    'COSMETICA MEDICALA': '💉'
};

// Global variables
let selectedLocation = null;
let selectedCategory = null;
let selectedService = null;
let selectedDateTime = null;
let categories = {};
let locations = [];
let availableSlots = {};
let currentStep = 'locations'; // locations, categories, services, datetime, confirmation
let userLocation = null;

// Force WooCommerce usage (set to false to use MedSoft API)
const USE_WOOCOMMERCE = true; // Enable WooCommerce for better performance

// LIVE API MODE: All data comes from live MedSoft API calls
const USE_CACHED_SLOTS = false; // Use live API slots - real-time API enabled
const CACHE_ONLY_MODE = false; // Use live API calls

// Location data with coordinates
const LOCATIONS_DATA = [
    {
        id: '1',
        name: 'Serenity HeadSpa ARCU',
        address: 'Sos. Arcu nr. 79, Iași',
        lat: 47.17152,
        lng: 27.56374,
        mapEmbedUrl: 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2712.8!2d27.563716!3d47.171253!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x40cafb8b7b8b7b8b%3A0x64a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20ARCU!5e0!3m2!1sen!2sro!4v1234567890'
    },
    {
        id: '3',
        name: 'Serenity HeadSpa CARPAȚI',
        address: 'Strada Carpați nr. 9A, Iași',
        lat: 47.1435892186774,
        lng: 27.582183626983806,
        mapEmbedUrl: 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2713.2!2d27.582248!3d47.143363!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x40cafb8b7b8b7b8b%3A0x58a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20CARPATI!5e0!3m2!1sen!2sro!4v1234567891'
    },
    {
        id: '2',
        name: 'Serenity HeadSpa București',
        address: 'Strada Serghei Vasilievici Rahmaninov 38, interfon 05, București',
        lat: 44.468694785767745,
        lng: 26.106015899473665,
        mapEmbedUrl: 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2848.1!2d26.105458!3d44.468534!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x40cafb8b7b8b7b8b%3A0x63a5c5e5e5e5e5e5!2sSerenity%20HeadSpa%20Bucuresti!5e0!3m2!1sen!2sro!4v1234567892'
    }
];

/**
 * Load and display categories
 */
async function loadCategories() {
    console.log('=== LOADING CATEGORIES FROM WOOCOMMERCE (v6.0) ===');
    console.log('API Root:', bookingApi.root);
    console.log('API Nonce:', bookingApi.nonce);

    try {
        // Use WooCommerce or MedSoft API based on flag
        const url = USE_WOOCOMMERCE ?
            bookingApi.root + 'wc-categories' :
            bookingApi.root + 'categories';
        console.log('Fetching from URL:', url, '(WooCommerce:', USE_WOOCOMMERCE, ')');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            }
        });

        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);

        if (!response.ok) {
            const errorText = await response.text();
            console.error('Response error text:', errorText);
            throw new Error(`Failed to load categories: ${response.status} - ${errorText}`);
        }

        const data = await response.json();
        console.log('WooCommerce categories response:', data);

        // Convert array response to object format expected by the rest of the code
        if (Array.isArray(data)) {
            categories = {};
            data.forEach(categoryName => {
                categories[categoryName] = {
                    name: categoryName,
                    description: `Servicii ${categoryName}`
                };
            });
        } else {
            categories = data;
        }

        console.log('Processed categories:', categories);
        console.log('Categories count:', Object.keys(categories).length);

        displayCategories();
    } catch (error) {
        console.error('Error loading categories from WooCommerce:', error);
        console.log('Falling back to MedSoft API...');

        // Fallback to original MedSoft API
        try {
            const fallbackUrl = bookingApi.root + 'categories';
            const fallbackResponse = await fetch(fallbackUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': bookingApi.nonce
                }
            });

            if (fallbackResponse.ok) {
                categories = await fallbackResponse.json();
                console.log('Fallback categories loaded:', categories);
                displayCategories();
                return;
            }
        } catch (fallbackError) {
            console.error('Fallback also failed:', fallbackError);
        }

        // If both fail, show error
        const categoriesSection = document.getElementById('categories-section');
        if (categoriesSection) {
            categoriesSection.innerHTML =
                '<div class="error">Eroare la încărcarea categoriilor: ' + error.message + '</div>';
        }
    }
}

/**
 * Display categories in the grid
 */
function displayCategories() {
    const categoriesSection = document.getElementById('categories-section');
    if (!categoriesSection) {
        console.error('Categories section not found');
        return;
    }

    let html = '';

    for (const [key, category] of Object.entries(categories)) {
        // Skip "Programari" category - not for user selection
        if (key === 'Programari' || key === 'Programări' || category.name === 'Programari' || category.name === 'Programări') {
            console.log(`Skipping Programari category: ${key}`);
            continue;
        }

        // Skip categories with 0 services
        const serviceCount = category.service_count || category.serviceCount || 0;
        if (serviceCount === 0) {
            console.log(`Skipping category ${key} - no services available`);
            continue;
        }

        const icon = category.icon || categoryIcons[key] || '🏥';
        html += `
            <div class="category-card" onclick="selectCategory('${key}')">
                <div class="category-icon">${icon}</div>
                <div class="category-title">${category.name}</div>
                <div class="category-description">${category.description}</div>
                <div style="font-size: 0.9rem; color: #666; margin-top: 10px;">
                    ${serviceCount} servicii disponibile
                </div>
            </div>
        `;
    }

    categoriesSection.innerHTML = html;
}

// Old location loading functions removed - now using static location data

// Add event listener for location change (if dropdown exists)
const locationSelect = document.getElementById('location');
if (locationSelect) {
    locationSelect.addEventListener('change', function() {
        if (this.value && selectedCategory) {
            loadCategoryServices(selectedCategory);
        }
    });
}

/**
 * Select a category and show the booking form
 */
function selectCategory(categoryKey) {
    console.log('Selecting category:', categoryKey);
    console.log('Available categories:', Object.keys(categories));

    selectedCategory = categoryKey;
    const category = categories[categoryKey];

    if (!category) {
        console.error('Category not found:', categoryKey);
        console.error('Available categories:', Object.keys(categories));
        return;
    }

    // Update the form title
    const titleElement = document.getElementById('selected-category-title');
    if (titleElement) {
        titleElement.textContent = category.name;
    }

    // Show the booking form and hide categories
    const categoriesSection = document.getElementById('categories-section');
    const bookingForm = document.getElementById('booking-form');

    if (categoriesSection) categoriesSection.style.display = 'none';
    if (bookingForm) bookingForm.style.display = 'block';

    // Load services for this category if location is selected
    const locationSelect = document.getElementById('location');
    if (locationSelect && locationSelect.value) {
        loadCategoryServices(categoryKey);
    }
}

/**
 * Load services for the selected category
 */
async function loadCategoryServices(categoryKey, locationId) {
    const servicesLoading = document.getElementById('services-loading');
    const servicesError = document.getElementById('services-error');
    const servicesGrid = document.getElementById('services-grid');

    // Show loading state
    servicesLoading.style.display = 'flex';
    servicesError.style.display = 'none';
    servicesGrid.innerHTML = '';

    try {
        const encodedCategory = encodeURIComponent(categoryKey);
        console.log('Loading services for category:', categoryKey, 'at location:', locationId);

        // Use WooCommerce or MedSoft API based on flag
        const baseEndpoint = USE_WOOCOMMERCE ? 'wc-category-services' : 'category-services';
        const url = `${bookingApi.root}${baseEndpoint}/${encodedCategory}?locationId=${locationId}`;
        console.log('Using endpoint:', baseEndpoint, '(WooCommerce:', USE_WOOCOMMERCE, ')');

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            }
        });

        if (!response.ok) {
            throw new Error(`Failed to load services: ${response.status}`);
        }

        const data = await response.json();
        console.log('WooCommerce services response:', data);

        // WooCommerce endpoint returns services directly, not wrapped in .services
        const services = Array.isArray(data) ? data : (data.services || []);

        servicesLoading.style.display = 'none';
        displayCategoryServices(services);
    } catch (error) {
        console.error('Error loading services from WooCommerce:', error);
        console.log('Falling back to MedSoft API...');

        // Fallback to original MedSoft API
        try {
            const fallbackUrl = locationId ?
                `${bookingApi.root}category-services/${encodedCategory}?locationId=${locationId}` :
                `${bookingApi.root}category-services/${encodedCategory}`;

            const fallbackResponse = await fetch(fallbackUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': bookingApi.nonce
                }
            });

            if (fallbackResponse.ok) {
                const fallbackData = await fallbackResponse.json();
                console.log('Fallback services loaded:', fallbackData);
                const fallbackServices = fallbackData.services || fallbackData;
                displayCategoryServices(fallbackServices);
                return;
            }
        } catch (fallbackError) {
            console.error('Fallback also failed:', fallbackError);
        }

        // If both fail, show error
        servicesLoading.style.display = 'none';
        servicesError.style.display = 'block';
        servicesError.textContent = 'Eroare la încărcarea serviciilor. Vă rugăm să încercați din nou.';
    }
}

/**
 * Display services for the selected category
 */
function displayCategoryServices(services) {
    const servicesGrid = document.getElementById('services-grid');

    if (!services || services.length === 0) {
        servicesGrid.innerHTML = '<div class="error-message">Nu au fost găsite servicii pentru această categorie la locația selectată.</div>';
        return;
    }

    servicesGrid.innerHTML = '';

    services.forEach(service => {
        const serviceName = (service.denumire || service.scop || 'Serviciu').replace(/'/g, "\\'");
        const duration = service.duration || service.durata || 60;
        const price = service.pret || 0;

        const serviceCard = document.createElement('div');
        serviceCard.className = 'service-card';
        serviceCard.setAttribute('data-service', service.cod);
        serviceCard.onclick = () => selectService(service.cod, serviceName, duration, price);

        serviceCard.innerHTML = `
            <div class="service-name">${service.denumire || service.scop}</div>
            <div class="service-price">${price} RON</div>
            <div class="service-duration">${duration} minute</div>
        `;

        servicesGrid.appendChild(serviceCard);
    });
}

/**
 * Select a service and proceed to date/time selection
 */
function selectService(serviceId, serviceName, duration, price) {
    console.log('Service selected:', serviceId, serviceName, duration, price);
    selectedService = {
        id: serviceId,
        name: serviceName,
        duration: duration || 60,
        price: price || 0
    };

    // Make it globally accessible for testing
    window.selectedService = selectedService;

    // Update UI to show selection
    document.querySelectorAll('.service-card').forEach(card => {
        card.classList.remove('selected');
    });

    // Find and mark selected card using data attribute
    const selectedCard = document.querySelector(`[data-service="${serviceId}"]`);
    if (selectedCard) {
        selectedCard.classList.add('selected');
        console.log(`Service card selected: ${serviceId}`);
    } else {
        console.warn(`Could not find service card for: ${serviceId}`);
    }

    console.log('Selected service object:', selectedService);

    // Proceed to date/time selection after a short delay
    setTimeout(() => {
        console.log('Proceeding to date/time step...');
        showDateTimeStep();
    }, 800);
}

/**
 * Show date/time selection step
 */
async function showDateTimeStep() {
    console.log('=== SHOWING DATE/TIME STEP ===');

    // Hide services section and show datetime
    document.getElementById('services-section').style.display = 'none';
    const datetimeSection = document.getElementById('datetime-section');
    datetimeSection.style.display = 'block';
    datetimeSection.classList.add('active');

    // Add back button
    addBackButton('datetime-section', 'Înapoi la servicii', () => {
        showServicesStep();
    });

    // Load available time slots
    await loadAvailableSlots();
}

/**
 * Load available time slots using cached or real-time data
 */
async function loadAvailableSlots() {
    const loadingDiv = document.getElementById('datetime-loading');
    const errorDiv = document.getElementById('datetime-error');
    const pickerDiv = document.getElementById('datetime-picker');

    // Check if elements exist
    if (!loadingDiv || !errorDiv || !pickerDiv) {
        console.error('Required datetime elements not found:', {
            loading: !!loadingDiv,
            error: !!errorDiv,
            picker: !!pickerDiv
        });
        return;
    }

    loadingDiv.style.display = 'flex';
    errorDiv.style.display = 'none';
    pickerDiv.innerHTML = '';

    try {
        // Get tomorrow's date as starting point
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const tomorrowStr = tomorrow.toISOString().split('T')[0];

        // Use cached or real-time slots based on flag
        const slotsEndpoint = USE_CACHED_SLOTS ? 'cached-slots' : 'available-slots';
        const slotsUrl = `${bookingApi.root}${slotsEndpoint}?locationId=${selectedLocation}&serviceId=${selectedService.id}&date=${tomorrowStr}`;

        console.log('Fetching slots from:', slotsUrl, '(Cached:', USE_CACHED_SLOTS, ')');

        const response = await fetch(slotsUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const slotsData = await response.json();
        console.log('Slots response:', slotsData);

        loadingDiv.style.display = 'none';
        displayTimeSlots(slotsData);

    } catch (error) {
        console.error('Error loading time slots:', error);
        loadingDiv.style.display = 'none';
        errorDiv.style.display = 'block';
        errorDiv.textContent = 'Eroare la încărcarea intervalelor disponibile: ' + error.message;
    }
}

/**
 * Display available time slots
 */
function displayTimeSlots(slotsData) {
    const pickerDiv = document.getElementById('datetime-picker');

    console.log('displayTimeSlots called with:', slotsData);

    // Handle both formats: {availableSlots: [...]} and {date: [...], date: [...]}
    let slotsByDate = {};

    if (slotsData && slotsData.availableSlots && Array.isArray(slotsData.availableSlots)) {
        // Format 1: {availableSlots: [{startDateTime: "..."}, ...]}
        console.log('Using availableSlots format');
        slotsData.availableSlots.forEach(slot => {
            const date = slot.startDateTime.split(' ')[0];
            if (!slotsByDate[date]) {
                slotsByDate[date] = [];
            }
            slotsByDate[date].push(slot);
        });
    } else if (slotsData && typeof slotsData === 'object') {
        // Format 2: {date: [...], date: [...]}
        console.log('Using date-keyed format');
        slotsByDate = slotsData;
    } else {
        console.log('No valid slots data found');
        pickerDiv.innerHTML = '<div class="error-message">Nu sunt disponibile intervale pentru data selectată.</div>';
        return;
    }

    // Check if we have any slots
    const totalSlots = Object.values(slotsByDate).reduce((total, slots) => total + slots.length, 0);
    console.log('Total slots found:', totalSlots);

    if (totalSlots === 0) {
        pickerDiv.innerHTML = '<div class="error-message">Nu sunt disponibile intervale pentru data selectată.</div>';
        return;
    }

    let html = '<div class="calendar-container">';
    let totalSlotsRendered = 0;

    Object.keys(slotsByDate).forEach(date => {
        const slots = slotsByDate[date];

        if (!Array.isArray(slots) || slots.length === 0) {
            return;
        }

        const dateObj = new Date(date);
        const formattedDate = dateObj.toLocaleDateString('ro-RO', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        html += `
            <div class="date-section">
                <h4>${formattedDate}</h4>
        `;

        // Group slots by doctor
        const slotsByDoctor = {};

        slots.forEach(slot => {
            // Handle different slot formats
            let startDateTime, time, doctorId, doctorName;

            if (typeof slot === 'object' && slot.start_time) {
                // Format: {start_time: "2025-06-30 09:00:00", time: "09:00", doctor_id: 17, ...}
                startDateTime = slot.start_time;
                time = slot.time || slot.start_time.split(' ')[1].substring(0, 5);
                doctorId = slot.doctor_id || null;
                doctorName = slot.doctor_name || 'Doctor disponibil';
            } else if (typeof slot === 'object' && slot.startDateTime) {
                // Format: {startDateTime: "2025-06-30 09:00:00", ...}
                startDateTime = slot.startDateTime;
                time = slot.startDateTime.split(' ')[1].substring(0, 5);
                doctorId = slot.doctorId || slot.DoctorId || null;
                doctorName = slot.doctorName || slot.DoctorName || 'Doctor disponibil';
            } else if (typeof slot === 'string') {
                // Format: "2025-06-30 09:00:00"
                startDateTime = slot;
                time = slot.split(' ')[1].substring(0, 5);
                doctorId = null;
                doctorName = 'Doctor disponibil';
            } else {
                console.warn('Unknown slot format:', slot);
                return;
            }

            const doctorKey = doctorId || 'unknown';
            if (!slotsByDoctor[doctorKey]) {
                slotsByDoctor[doctorKey] = {
                    name: doctorName,
                    slots: []
                };
            }

            slotsByDoctor[doctorKey].slots.push({
                startDateTime,
                time,
                doctorId,
                doctorName
            });
        });

        // Display slots grouped by doctor
        Object.keys(slotsByDoctor).forEach(doctorKey => {
            const doctor = slotsByDoctor[doctorKey];

            html += `
                <div class="doctor-section">
                    <h5 class="doctor-name">👨‍⚕️ ${doctor.name}</h5>
                    <div class="time-slots-grid">
            `;

            doctor.slots.forEach(slot => {
                html += `<div class="time-slot"
                              data-datetime="${slot.startDateTime}"
                              data-formatted-date="${formattedDate}"
                              data-time="${slot.time}"
                              data-doctor-id="${slot.doctorId || ''}"
                              data-doctor-name="${slot.doctorName || 'Doctor disponibil'}">${slot.time}</div>`;
                totalSlotsRendered++;
            });

            html += '</div></div>';
        });

        html += '</div>';
    });

    html += '</div>';

    console.log(`Total slots rendered: ${totalSlotsRendered}`);

    pickerDiv.innerHTML = html;

    // Add event delegation for time slot clicks
    pickerDiv.addEventListener('click', function(event) {
        if (event.target.classList.contains('time-slot')) {
            const slotData = {
                dateTime: event.target.dataset.datetime,
                formattedDate: event.target.dataset.formattedDate,
                time: event.target.dataset.time,
                doctorId: event.target.dataset.doctorId || null,
                doctorName: event.target.dataset.doctorName || 'Doctor disponibil'
            };
            selectTimeSlot(slotData);
        }
    });

    // Verify the HTML was actually inserted
    const timeSlotElements = pickerDiv.querySelectorAll('.time-slot').length;
    console.log(`Time slot elements found: ${timeSlotElements}`);

    if (timeSlotElements === 0 && totalSlotsRendered > 0) {
        console.error('❌ Slots were processed but no DOM elements created!');
        console.log('Sample HTML:', html.substring(0, 500) + '...');
    }
}

/**
 * Select a time slot and proceed to confirmation
 */
function selectTimeSlot(slotData) {
    console.log('Time slot selected:', slotData);

    selectedDateTime = {
        dateTime: slotData.dateTime,
        formattedDate: slotData.formattedDate,
        time: slotData.time,
        doctorId: slotData.doctorId,
        doctorName: slotData.doctorName
    };

    // Make it globally accessible for testing
    window.selectedDateTime = selectedDateTime;

    // Update UI to show selection
    document.querySelectorAll('.time-slot').forEach(slot => {
        slot.classList.remove('selected');
    });

    // Find and mark selected slot using data attributes
    const slots = document.querySelectorAll('.time-slot');
    slots.forEach(slot => {
        if (slot.dataset.datetime === slotData.dateTime) {
            slot.classList.add('selected');
        }
    });

    // Proceed to confirmation after a short delay
    setTimeout(() => {
        showConfirmationStep();
    }, 500);
}

/**
 * Show customer details step
 */
function showConfirmationStep() {
    console.log('=== SHOWING CUSTOMER DETAILS STEP ===');

    // Hide datetime section and show details
    document.getElementById('datetime-section').style.display = 'none';
    const detailsSection = document.getElementById('details-section');
    detailsSection.style.display = 'block';
    detailsSection.classList.add('active');

    // Add back button
    addBackButton('details-section', 'Înapoi la programare', () => {
        showDateTimeStep();
    });

    // Display booking summary
    displayBookingSummary();

    // Set up form submission
    setupCustomerDetailsForm();
}

/**
 * Display booking summary
 */
function displayBookingSummary() {
    const summaryDiv = document.getElementById('booking-summary');

    // Use locations from config if available, otherwise use default
    const locationsToUse = (window.bookingApiConfig && window.bookingApiConfig.locations) ?
        window.bookingApiConfig.locations : LOCATIONS_DATA;

    const locationName = locationsToUse.find(loc => loc.id === selectedLocation)?.name || 'Locație necunoscută';

    summaryDiv.innerHTML = `
        <div class="booking-summary" style="background: #e9ecef; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3 style="margin-top: 0;">Rezumatul programării</h3>
            <div class="summary-item" style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #dee2e6;">
                <strong>Serviciu:</strong> <span>${selectedService.name}</span>
            </div>
            <div class="summary-item" style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #dee2e6;">
                <strong>Locația:</strong> <span>${locationName}</span>
            </div>
            <div class="summary-item" style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #dee2e6;">
                <strong>Data și ora:</strong> <span>${selectedDateTime.formattedDate} la ${selectedDateTime.time}</span>
            </div>
            <div class="summary-item" style="display: flex; justify-content: space-between; padding: 8px 0; font-weight: bold;">
                <strong>Preț:</strong> <span>${selectedService.price} RON</span>
            </div>
        </div>
    `;
}

/**
 * Set up customer details form submission
 */
function setupCustomerDetailsForm() {
    const form = document.getElementById('customer-details-form');

    form.onsubmit = async (e) => {
        e.preventDefault();

        const formData = new FormData(form);
        const customerData = {
            name: formData.get('customerName'),
            email: formData.get('customerEmail'),
            phone: formData.get('customerPhone'),
            notes: formData.get('appointmentNotes') || ''
        };

        console.log('Customer data:', customerData);
        console.log('Booking details:', {
            location: selectedLocation,
            service: selectedService,
            dateTime: selectedDateTime
        });

        // Show loading screen
        showPaymentLoading();

        try {
            // Create the booking and redirect to payment
            await createBookingAndRedirectToPayment(customerData);
        } catch (error) {
            console.error('Error creating booking:', error);
            alert('Eroare la crearea programării: ' + error.message);
            hidePaymentLoading();
        }
    };
}

/**
 * Show payment loading screen
 */
function showPaymentLoading() {
    const detailsSection = document.getElementById('details-section');
    const paymentLoading = document.getElementById('payment-loading');

    if (detailsSection) detailsSection.style.display = 'none';
    if (paymentLoading) {
        paymentLoading.style.display = 'block';
    } else {
        console.warn('Payment loading element not found - continuing without loading screen');
    }
}

/**
 * Hide payment loading screen
 */
function hidePaymentLoading() {
    const detailsSection = document.getElementById('details-section');
    const paymentLoading = document.getElementById('payment-loading');

    if (paymentLoading) paymentLoading.style.display = 'none';
    if (detailsSection) detailsSection.style.display = 'block';
}

/**
 * Create WooCommerce order and redirect to checkout
 */
async function createBookingAndRedirectToPayment(customerData) {
    console.log('Creating WooCommerce order for payment...');

    // Calculate end time - handle timezone properly
    const startTimeStr = selectedDateTime.dateTime; // Keep original format: "2025-07-07 19:00:00"
    const [datePart, timePart] = startTimeStr.split(' ');
    const [year, month, day] = datePart.split('-');
    const [hour, minute] = timePart.split(':');

    // Create date in local timezone
    const startTime = new Date(year, month - 1, day, hour, minute, 0);
    const endTime = new Date(startTime.getTime() + (selectedService.duration * 60000));

    // Format back to MySQL datetime format
    const endDateTime = endTime.getFullYear() + '-' +
                       String(endTime.getMonth() + 1).padStart(2, '0') + '-' +
                       String(endTime.getDate()).padStart(2, '0') + ' ' +
                       String(endTime.getHours()).padStart(2, '0') + ':' +
                       String(endTime.getMinutes()).padStart(2, '0') + ':00';

    console.log('Time calculation debug:');
    console.log('- Start time string:', selectedDateTime.dateTime);
    console.log('- Start time parsed:', startTime);
    console.log('- Duration (minutes):', selectedService.duration);
    console.log('- End time calculated:', endTime);
    console.log('- End time formatted:', endDateTime);

    // Prepare booking data for WooCommerce integration
    const bookingData = {
        doctorId: selectedDateTime.doctorId || null,
        locationId: selectedLocation,
        startDateTime: selectedDateTime.dateTime,
        endDateTime: endDateTime,
        patientName: customerData.name,
        patientPhoneNumber: customerData.phone,
        appointmentDetails: `${selectedService.name} (${selectedService.duration} min) - ${selectedService.price} RON`,
        // Add service details for WooCommerce pricing
        serviceId: selectedService.id,
        serviceName: selectedService.name,
        servicePrice: parseFloat(selectedService.price),
        serviceDuration: selectedService.duration
    };

    // Add optional fields only if they have values
    if (customerData.email) {
        bookingData.patientEmail = customerData.email;
    }

    if (customerData.notes) {
        bookingData.appointmentNotes = customerData.notes;
    }

    console.log('Booking data to send:', bookingData);

    try {
        // Create WooCommerce order
        const response = await fetch(bookingApi.root + 'create-order', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            },
            body: JSON.stringify(bookingData)
        });

        if (!response.ok) {
            // Get error details from server
            let errorMessage = `HTTP error! status: ${response.status}`;
            try {
                const errorData = await response.json();
                console.log('Server error response:', errorData);
                errorMessage = errorData.message || errorData.data?.message || errorMessage;
            } catch (e) {
                console.log('Could not parse error response as JSON');
            }
            throw new Error(errorMessage);
        }

        const result = await response.json();
        console.log('WooCommerce order response:', result);

        // Handle successful order creation
        if (result.success && result.redirect_url) {
            // Redirect to WooCommerce checkout
            console.log('Redirecting to checkout:', result.redirect_url);
            window.location.href = result.redirect_url;
        } else if (result.success && result.checkout_url) {
            // Fallback for different response format
            console.log('Redirecting to checkout:', result.checkout_url);
            window.location.href = result.checkout_url;
        } else {
            throw new Error(result.message || 'Failed to create order');
        }

    } catch (error) {
        console.error('Error creating WooCommerce order:', error);
        hidePaymentLoading();
        alert('Eroare la crearea comenzii: ' + error.message);
    }
}



/**
 * Proceed to date and time selection
 */
function proceedToDateTime() {
    if (!selectedService) {
        alert('Vă rugăm selectați un serviciu.');
        return;
    }

    // Validate personal info and location
    const firstName = document.getElementById('firstName').value.trim();
    const lastName = document.getElementById('lastName').value.trim();
    const email = document.getElementById('email').value.trim();
    const phoneNumber = document.getElementById('phoneNumber').value.trim();
    const locationId = document.getElementById('location').value;

    if (!firstName || !lastName || !email || !phoneNumber || !locationId) {
        alert('Vă rugăm completați toate câmpurile obligatorii.');
        return;
    }

    // Show datetime section
    document.getElementById('datetime-section').style.display = 'block';
    document.getElementById('continue-to-datetime').style.display = 'none';

    // Load available slots
    loadAvailableSlots();
    currentStep = 'datetime';
}

/**
 * Load available time slots
 */
async function loadAvailableSlots() {
    const loadingDiv = document.getElementById('datetime-loading');
    const errorDiv = document.getElementById('datetime-error');
    const pickerDiv = document.getElementById('datetime-picker');

    // Check if elements exist
    if (!loadingDiv || !errorDiv || !pickerDiv) {
        console.error('Required datetime elements not found:', {
            loading: !!loadingDiv,
            error: !!errorDiv,
            picker: !!pickerDiv
        });
        return;
    }

    loadingDiv.style.display = 'flex';
    errorDiv.style.display = 'none';
    pickerDiv.innerHTML = '';

    try {
        const locationId = selectedLocation;
        const serviceId = selectedService.id;

        // Use tomorrow's date since MedSoft requires future dates
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const tomorrowStr = tomorrow.toISOString().split('T')[0];

        // Use cached or real-time slots based on flag
        const slotsEndpoint = USE_CACHED_SLOTS ? 'cached-slots' : 'available-slots';
        const slotsUrl = bookingApi.root + `${slotsEndpoint}?locationId=${locationId}&serviceId=${serviceId}&date=${tomorrowStr}`;

        console.log('Attempting to fetch slots from:', slotsUrl, '(Cached:', USE_CACHED_SLOTS, ')');

        let response = await fetch(slotsUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            }
        });

        // If main endpoint fails with 403, try alternative endpoint
        if (!response.ok && response.status === 403) {
            console.log('Main endpoint failed with 403, trying alternative endpoint');
            response = await fetch(bookingApi.root + `slots-available?locationId=${locationId}&serviceId=${serviceId}&date=${tomorrowStr}`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': bookingApi.nonce
                }
            });
        }

        // If still failing, try test endpoint to check if REST API is working
        if (!response.ok && response.status === 403) {
            console.log('Alternative endpoint also failed, testing REST API');
            const testResponse = await fetch(bookingApi.root + 'test', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': bookingApi.nonce
                }
            });
            console.log('Test endpoint response:', testResponse.status, await testResponse.text());
        }

        if (!response.ok) {
            // Try to get error details from response
            let errorMessage = `Failed to load slots: ${response.status}`;
            try {
                const errorData = await response.json();
                if (errorData.message) {
                    errorMessage = errorData.message;
                } else if (errorData.data && errorData.data.message) {
                    errorMessage = errorData.data.message;
                }
            } catch (e) {
                // If we can't parse the error, use the default message
            }
            throw new Error(errorMessage);
        }

        const slotsData = await response.json();
        console.log('Slots response:', slotsData);
        console.log('Available slots count:', slotsData.availableSlots ? slotsData.availableSlots.length : 0);
        console.log('Slots data structure:', {
            hasAvailableSlots: !!slotsData.availableSlots,
            isArray: Array.isArray(slotsData.availableSlots),
            firstSlot: slotsData.availableSlots ? slotsData.availableSlots[0] : null
        });

        loadingDiv.style.display = 'none';

        // Check if we have slots in either format
        const hasAvailableSlots = (slotsData.availableSlots && slotsData.availableSlots.length > 0);
        const hasDateKeyedSlots = (typeof slotsData === 'object' && !slotsData.availableSlots &&
                                  Object.keys(slotsData).some(key => Array.isArray(slotsData[key]) && slotsData[key].length > 0));

        if (!hasAvailableSlots && !hasDateKeyedSlots) {
            console.log('No cached slots found - cache only mode enabled');
            errorDiv.innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <h4>Nu sunt disponibile programări pentru această dată</h4>
                    <p>Vă rugăm să încercați o altă dată sau să contactați clinica direct.</p>
                    <p style="font-size: 0.9em; color: #666;">
                        Datele sunt actualizate zilnic la ora 3:00 AM.
                    </p>
                </div>
            `;
            errorDiv.style.display = 'block';
            loadingDiv.style.display = 'none';
        } else {
            console.log('Found cached slots, displaying...');
            displayTimeSlots(slotsData);
        }

    } catch (error) {
        console.error('Error loading cached time slots:', error);
        console.log('Cache-only mode: No fallback to real-time API');

        // Show error message instead of trying real-time API
        errorDiv.innerHTML = `
            <div style="text-align: center; padding: 20px;">
                <h4>Eroare la încărcarea programărilor</h4>
                <p>Nu s-au putut încărca programările disponibile.</p>
                <p style="font-size: 0.9em; color: #666;">
                    Vă rugăm să încercați din nou mai târziu sau să contactați clinica direct.
                </p>
            </div>
        `;
        errorDiv.style.display = 'block';
        loadingDiv.style.display = 'none';
    }
}

// REMOVED: tryRealTimeSlots function - now using cache-only mode
// Real-time API fallback has been disabled to improve performance and reduce API calls

// Old displayCalendar function removed - using displayTimeSlots instead

/**
 * Select a date and show time slots
 */
function selectDate(date) {
    // Update UI
    document.querySelectorAll('.calendar-day').forEach(day => {
        day.classList.remove('selected');
    });
    event.currentTarget.classList.add('selected');

    // Show time slots for this date
    const timeSlotsContainer = document.getElementById('time-slots-container');
    const selectedDateSpan = document.getElementById('selected-date');
    const timeSlotsGrid = document.getElementById('time-slots-grid');

    selectedDateSpan.textContent = new Date(date).toLocaleDateString('ro-RO', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    const slots = availableSlots[date] || [];
    let html = '';

    slots.forEach(slot => {
        const doctorInfo = slot.doctor_name ? `<br><small>${slot.doctor_name}</small>` : '';
        html += `
            <div class="time-slot" onclick="selectTimeSlot('${slot.start_time}', '${slot.end_time}', ${slot.doctor_id || 'null'}, '${slot.doctor_name || ''}')">
                ${slot.time}${doctorInfo}
            </div>
        `;
    });

    timeSlotsGrid.innerHTML = html;
    timeSlotsContainer.style.display = 'block';
}

// Old selectTimeSlot function removed - using new version with correct flow

// Old populateConfirmationSummary function removed - using displayBookingSummary instead

// Old showCategories function removed - using showCategoriesStep instead

/**
 * Proceed to payment
 */
function proceedToPayment() {
    if (!selectedService || !selectedDateTime) {
        alert('Vă rugăm selectați un serviciu și o dată/oră.');
        return;
    }

    // Create booking data in the format expected by working plugin.php
    const bookingData = {
        doctorId: selectedDateTime.doctorId,
        locationId: parseInt(document.getElementById('location').value),
        startDateTime: selectedDateTime.start,
        endDateTime: selectedDateTime.end,
        patientName: document.getElementById('firstName').value.trim() + ' ' + document.getElementById('lastName').value.trim(),
        patientPhoneNumber: document.getElementById('phoneNumber').value.trim(),
        appointmentDetails: selectedService.name,  // Use service name as appointmentDetails (like working js.js)

        // Additional service data for WooCommerce integration
        serviceId: selectedService.id,
        serviceName: selectedService.name,
        servicePrice: selectedService.price,
        serviceDuration: selectedService.duration,
        serviceCod: selectedService.cod || null,
        category: selectedCategory
    };

    // Add optional fields
    const email = document.getElementById('email').value.trim();
    if (email) {
        bookingData.patientEmail = email;
    }

    // Add notes as appointmentNotes if provided
    const notes = document.getElementById('notes').value.trim();
    if (notes) {
        bookingData.appointmentNotes = notes;
    }

    console.log('Booking data being sent (enhanced for WooCommerce):', bookingData);

    // Store booking data for WooCommerce integration
    sessionStorage.setItem('bookingData', JSON.stringify(bookingData));

    // Create WooCommerce product and proceed to checkout
    createWooCommerceProduct(bookingData);
}

/**
 * Proceed to WooCommerce with selected service (legacy function)
 */
function proceedToWooCommerce() {
    // Validate form
    const firstName = document.getElementById('firstName').value.trim();
    const lastName = document.getElementById('lastName').value.trim();
    const email = document.getElementById('email').value.trim();
    const phoneNumber = document.getElementById('phoneNumber').value.trim();
    const locationId = document.getElementById('location').value;
    
    if (!firstName || !lastName || !email || !phoneNumber || !locationId || !selectedService) {
        alert('Vă rugăm completați toate câmpurile obligatorii și selectați un serviciu.');
        return;
    }
    
    // Basic email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        alert('Vă rugăm introduceți o adresă de email validă.');
        return;
    }
    
    // Create booking data
    const bookingData = {
        firstName: firstName,
        lastName: lastName,
        email: email,
        phoneNumber: phoneNumber,
        locationId: locationId,
        serviceId: selectedService.id,
        serviceName: selectedService.name,
        serviceDuration: selectedService.duration,
        category: selectedCategory
    };
    
    // Store booking data for WooCommerce integration
    sessionStorage.setItem('bookingData', JSON.stringify(bookingData));
    
    // Redirect to WooCommerce or create product
    createWooCommerceProduct(bookingData);
}

/**
 * Create WooCommerce product and add to cart
 */
async function createWooCommerceProduct(bookingData) {
    try {
        // Show confirmation message
        const confirmMessage = `Confirmați programarea:\n\n` +
            `Serviciu: ${bookingData.serviceName}\n` +
            `Data și ora: ${new Date(bookingData.startDateTime).toLocaleString('ro-RO')}\n` +
            `Preț: ${bookingData.servicePrice} RON\n\n` +
            `Veți fi redirecționat către plată.`;

        if (!confirm(confirmMessage)) {
            return;
        }

        // WooCommerce integration re-enabled after fresh installation
        // Create WooCommerce order and proceed to checkout
        console.log('Creating WooCommerce order for booking:', bookingData);

        const response = await fetch(bookingApi.root + 'create-order', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            },
            body: JSON.stringify(bookingData)
        });

        if (!response.ok) {
            const errorText = await response.text();
            console.error('WooCommerce order creation failed:', response.status, errorText);
            throw new Error(`Failed to create WooCommerce order: ${response.status} - ${errorText}`);
        }

        const result = await response.json();
        console.log('WooCommerce order response:', result);

        if (result.success && result.redirect_url) {
            // Redirect to WooCommerce checkout
            window.location.href = result.redirect_url;
        } else {
            throw new Error(result.message || 'Failed to create WooCommerce order');
        }

    } catch (error) {
        console.error('Error creating WooCommerce product:', error);
        alert('Eroare la procesarea comenzii. Vă rugăm să încercați din nou.');
    }
}

/**
 * Create appointment in MedSoft after successful payment
 */
async function createAppointmentInMedSoft(bookingData) {
    try {
        const response = await fetch(bookingApi.root + 'create-appointment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            },
            body: JSON.stringify(bookingData)
        });

        if (!response.ok) {
            throw new Error(`Failed to create appointment: ${response.status}`);
        }

        const result = await response.json();
        console.log('MedSoft API response:', result);

        // Handle MedSoft API response format: [{"AppointmentId":18701}]
        if (Array.isArray(result) && result.length > 0 && result[0].AppointmentId) {
            const appointmentId = result[0].AppointmentId;
            alert(`Programarea a fost creată cu succes!\n\nID Programare: ${appointmentId}\n\nVeți primi un email de confirmare.`);

            // Reset form and go back to categories
            showCategories();

            // Clear form fields
            document.getElementById('firstName').value = '';
            document.getElementById('lastName').value = '';
            document.getElementById('email').value = '';
            document.getElementById('phoneNumber').value = '';
            document.getElementById('location').value = '';
            document.getElementById('notes').value = '';

        } else if (result.success) {
            // Fallback for other success formats
            alert(`Programarea a fost creată cu succes!\n\nID Programare: ${result.appointment_id || 'N/A'}\n\nVeți primi un email de confirmare.`);
            showCategories();
        } else {
            throw new Error(result.message || 'Failed to create appointment');
        }

    } catch (error) {
        console.error('Error creating appointment in MedSoft:', error);
        alert('Eroare la crearea programării în MedSoft: ' + error.message);
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('Serenity booking system initialized - v13.0');

    // Initialize API configuration
    if (typeof bookingApi === 'undefined') {
        window.bookingApi = {
            root: '',
            nonce: ''
        };
    }

    // Set API configuration if available from WordPress
    if (typeof window.bookingApiConfig !== 'undefined') {
        console.log('Found bookingApiConfig:', window.bookingApiConfig);
        bookingApi.root = window.bookingApiConfig.root;
        bookingApi.nonce = window.bookingApiConfig.nonce;
    } else {
        console.log('bookingApiConfig not found - using standalone mode');
        // For standalone testing, set default values
        bookingApi.root = '/wp-json/mybooking/v1/';
        bookingApi.nonce = 'standalone-mode';
    }

    console.log('Final API config:', bookingApi);

    // Debug WordPress integration
    console.log('WordPress environment check:');
    console.log('- Current URL:', window.location.href);
    console.log('- API Root:', bookingApi.root);
    console.log('- API Nonce:', bookingApi.nonce);
    console.log('- REST API available:', typeof wp !== 'undefined');

    // Test API accessibility
    testApiAccess();

    // Start with location selection (new flow)
    initializeLocationSelection();
});

/**
 * Test API access before starting
 */
async function testApiAccess() {
    try {
        console.log('Testing API access...');

        // Test basic WordPress REST API
        const testResponse = await fetch('/wp-json/', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        if (testResponse.ok) {
            console.log('✅ WordPress REST API accessible');
        } else {
            console.log('❌ WordPress REST API not accessible:', testResponse.status);
        }

        // Test our custom endpoint
        const customResponse = await fetch(bookingApi.root + 'wc-categories', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            }
        });

        if (customResponse.ok) {
            console.log('✅ Custom API endpoint accessible');
        } else {
            console.log('❌ Custom API endpoint not accessible:', customResponse.status, customResponse.statusText);
        }

    } catch (error) {
        console.log('❌ API test failed:', error);
    }
}

/**
 * Manual API test function - call from browser console
 */
window.testBookingAPI = async function() {
    console.log('🔍 Manual API Test Starting...');

    const tests = [
        {
            name: 'Categories',
            url: bookingApi.root + 'wc-categories'
        },
        {
            name: 'Services for HEADSPA at location 1',
            url: bookingApi.root + 'wc-category-services/HEADSPA?locationId=1'
        },
        {
            name: 'Cached slots for service 1 at location 1',
            url: bookingApi.root + 'cached-slots?locationId=1&serviceId=1&date=' + new Date().toISOString().split('T')[0]
        },
        // REMOVED: Real-time slots test - cache-only mode enabled
        // {
        //     name: 'Real-time slots for service 1 at location 1',
        //     url: bookingApi.root + 'available-slots?locationId=1&serviceId=1&date=' + new Date().toISOString().split('T')[0]
        // }
    ];

    for (const test of tests) {
        try {
            console.log(`\n📡 Testing: ${test.name}`);
            console.log(`URL: ${test.url}`);

            const response = await fetch(test.url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': bookingApi.nonce
                }
            });

            if (response.ok) {
                const data = await response.json();
                console.log(`✅ Success! Status: ${response.status}`);
                console.log('Response:', data);
            } else {
                console.log(`❌ Failed! Status: ${response.status} ${response.statusText}`);
                const errorText = await response.text();
                console.log('Error:', errorText);
            }
        } catch (error) {
            console.log(`❌ Error: ${error.message}`);
        }
    }

    console.log('\n🔍 Manual API Test Complete!');
    console.log('💡 To test with your current selection, use:');
    console.log(`testSpecificSlots('${selectedLocation}', '${selectedService ? selectedService.id : 'SERVICE_ID'}')`);
    console.log('💡 To inspect slot data structure, use:');
    console.log('inspectSlotData()');
};

/**
 * Test specific slots for current selection
 */
window.testSpecificSlots = async function(locationId, serviceId) {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = tomorrow.toISOString().split('T')[0];

    console.log(`\n🎯 Testing slots for Location: ${locationId}, Service: ${serviceId}, Date: ${tomorrowStr}`);

    const cachedUrl = `${bookingApi.root}cached-slots?locationId=${locationId}&serviceId=${serviceId}&date=${tomorrowStr}`;
    const realTimeUrl = `${bookingApi.root}available-slots?locationId=${locationId}&serviceId=${serviceId}&date=${tomorrowStr}`;

    try {
        console.log('📦 Testing cached slots...');
        const cachedResponse = await fetch(cachedUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            }
        });

        if (cachedResponse.ok) {
            const cachedData = await cachedResponse.json();
            console.log('✅ Cached slots response:', cachedData);
            console.log(`📊 Cached slots count: ${cachedData.availableSlots ? cachedData.availableSlots.length : 0}`);
        } else {
            console.log(`❌ Cached slots failed: ${cachedResponse.status}`);
        }

        console.log('\n⚡ Real-time slots testing disabled (cache-only mode)');
        // Real-time API testing removed to prevent unnecessary API calls
        // const realTimeResponse = await fetch(realTimeUrl, {
        //     method: 'GET',
        //     headers: {
        //         'Content-Type': 'application/json',
        //         'X-WP-Nonce': bookingApi.nonce
        //     }
        // });
        //
        // if (realTimeResponse.ok) {
        //     const realTimeData = await realTimeResponse.json();
        //     console.log('✅ Real-time slots response:', realTimeData);
        //     console.log(`📊 Real-time slots count: ${realTimeData.availableSlots ? realTimeData.availableSlots.length : 0}`);
        // } else {
        //     console.log(`❌ Real-time slots failed: ${realTimeResponse.status}`);
        // }

    } catch (error) {
        console.log(`❌ Error testing slots: ${error.message}`);
    }
};

/**
 * Inspect the current slot data structure
 */
window.inspectSlotData = async function() {
    if (!selectedLocation || !selectedService) {
        console.log('❌ Please complete the booking flow first to select location and service');
        return;
    }

    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = tomorrow.toISOString().split('T')[0];

    const url = `${bookingApi.root}cached-slots?locationId=${selectedLocation}&serviceId=${selectedService.id}&date=${tomorrowStr}`;

    try {
        console.log(`🔍 Fetching slot data from: ${url}`);

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            }
        });

        if (response.ok) {
            const data = await response.json();
            console.log('📊 Raw slot data:', data);
            console.log('📊 Data type:', typeof data);
            console.log('📊 Is array:', Array.isArray(data));
            console.log('📊 Object keys:', Object.keys(data));

            // Inspect each date
            Object.keys(data).forEach(date => {
                console.log(`📅 Date: ${date}`);
                console.log(`   Slots:`, data[date]);
                console.log(`   Slots type:`, typeof data[date]);
                console.log(`   Is array:`, Array.isArray(data[date]));
                console.log(`   Length:`, data[date].length);

                if (data[date].length > 0) {
                    console.log(`   First slot:`, data[date][0]);
                    console.log(`   First slot type:`, typeof data[date][0]);
                }
            });

        } else {
            console.log(`❌ Failed to fetch slot data: ${response.status}`);
        }

    } catch (error) {
        console.log(`❌ Error inspecting slot data: ${error.message}`);
    }
};

/**
 * Initialize location selection with geolocation
 */
async function initializeLocationSelection() {
    console.log('=== INITIALIZING LOCATION SELECTION ===');

    const statusDiv = document.getElementById('geolocation-status');
    const loadingDiv = document.getElementById('locations-loading');
    const gridDiv = document.getElementById('locations-grid');

    // Always show locations first, then try geolocation
    loadingDiv.style.display = 'none';

    // Check if we're in WordPress environment
    if (typeof window.bookingApiConfig === 'undefined') {
        console.error('❌ WordPress API configuration not found!');
        const statusDiv = document.getElementById('geolocation-status');
        if (statusDiv) {
            statusDiv.style.display = 'block';
            statusDiv.className = 'geolocation-status error';
            statusDiv.innerHTML = '❌ Eroare de configurare. Vă rugăm să reîncărcați pagina.';
        }
        return;
    }

    displayLocations();

    // Try geolocation in the background
    statusDiv.style.display = 'block';
    statusDiv.className = 'geolocation-status detecting';
    statusDiv.innerHTML = '📍 Se detectează locația dvs. pentru a găsi cea mai apropiată clinică...';

    try {
        // Try to get user's location with a timeout
        const locationPromise = detectUserLocation();
        const timeoutPromise = new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Timeout')), 5000)
        );

        await Promise.race([locationPromise, timeoutPromise]);

        // Show success message and update locations
        statusDiv.className = 'geolocation-status success';
        statusDiv.innerHTML = '✅ Locația detectată! Cea mai apropiată clinică este evidențiată.';

        // Re-display locations with distance info
        displayLocations();

    } catch (error) {
        console.log('Geolocation failed or timed out:', error);

        // Show error message but continue
        statusDiv.className = 'geolocation-status error';
        statusDiv.innerHTML = '⚠️ Nu s-a putut detecta locația. Vă rugăm să selectați manual locația dorită.';

        // Hide the status after a few seconds
        setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 3000);
    }
}

/**
 * Detect user's location using HTML5 Geolocation API
 */
function detectUserLocation() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation not supported'));
            return;
        }

        const options = {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 300000 // 5 minutes
        };

        navigator.geolocation.getCurrentPosition(
            (position) => {
                userLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                console.log('User location detected:', userLocation);
                resolve(userLocation);
            },
            (error) => {
                console.log('Geolocation error:', error);
                reject(error);
            },
            options
        );
    });
}

/**
 * Calculate distance between two points using Haversine formula
 */
function calculateDistance(lat1, lng1, lat2, lng2) {
    const R = 6371; // Earth's radius in kilometers
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLng/2) * Math.sin(dLng/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
}

/**
 * Display location cards with maps and distance info
 */
function displayLocations() {
    const gridDiv = document.getElementById('locations-grid');

    // Use locations from config if available, otherwise use default
    const locationsToUse = (window.bookingApiConfig && window.bookingApiConfig.locations) ?
        window.bookingApiConfig.locations : LOCATIONS_DATA;

    console.log('Using locations:', locationsToUse);

    // Calculate distances and sort by proximity if user location is available
    let locationsWithDistance = locationsToUse.map(location => {
        const locationCopy = { ...location };
        if (userLocation) {
            locationCopy.distance = calculateDistance(
                userLocation.lat, userLocation.lng,
                location.lat, location.lng
            );
        }
        return locationCopy;
    });

    // Sort by distance if available
    if (userLocation) {
        locationsWithDistance.sort((a, b) => a.distance - b.distance);
    }

    gridDiv.innerHTML = '';

    locationsWithDistance.forEach((location, index) => {
        const isClosest = userLocation && index === 0;

        const locationCard = document.createElement('div');
        locationCard.className = `location-card ${isClosest ? 'closest' : ''}`;
        locationCard.onclick = () => selectLocation(location.id);

        let distanceHtml = '';
        if (location.distance !== undefined) {
            distanceHtml = `<div class="location-distance">${location.distance.toFixed(1)} km distanță</div>`;
        }

        locationCard.innerHTML = `
            <div class="location-header">
                <div class="location-icon">${isClosest ? '🎯' : '📍'}</div>
                <h3 class="location-name">${location.name}</h3>
            </div>
            ${distanceHtml}
            <div class="location-address">${location.address}</div>
            <iframe class="location-map"
                    src="${location.mapEmbedUrl}"
                    allowfullscreen=""
                    loading="lazy">
            </iframe>
            ${isClosest ? '<div style="text-align: center; margin-top: 10px; font-weight: bold; color: white;">📍 Cea mai apropiată</div>' : ''}
        `;

        gridDiv.appendChild(locationCard);
    });

    // Auto-highlight closest location if available (but don't auto-proceed)
    if (userLocation && locationsWithDistance.length > 0) {
        setTimeout(() => {
            // Just highlight the closest location, don't auto-select
            const closestLocationId = locationsWithDistance[0].id;
            const cards = document.querySelectorAll('.location-card');
            cards.forEach(card => {
                if (card.onclick.toString().includes(closestLocationId)) {
                    card.classList.add('closest');
                }
            });
        }, 1000);
    }
}

/**
 * Select a location and show confirmation button
 */
function selectLocation(locationId) {
    console.log('Location selected:', locationId);
    selectedLocation = locationId;

    // Update UI to show selection
    document.querySelectorAll('.location-card').forEach(card => {
        card.classList.remove('selected');
    });

    // Find and mark selected card
    const cards = document.querySelectorAll('.location-card');
    cards.forEach(card => {
        if (card.onclick.toString().includes(locationId)) {
            card.classList.add('selected');
        }
    });

    // Show confirmation button
    const confirmDiv = document.getElementById('location-confirm');
    const confirmBtn = document.getElementById('confirm-location-btn');

    confirmDiv.style.display = 'block';

    // Add click handler for confirmation
    confirmBtn.onclick = () => {
        console.log('Location confirmed:', locationId);
        showCategoriesStep();
    };
}

/**
 * Add a back button to a section
 */
function addBackButton(sectionId, buttonText, clickHandler) {
    const section = document.getElementById(sectionId);
    if (!section) return;

    // Remove existing back button
    const existingButton = section.querySelector('.back-button');
    if (existingButton) {
        existingButton.remove();
    }

    // Create new back button
    const backButton = document.createElement('button');
    backButton.className = 'back-button btn-secondary';
    backButton.textContent = buttonText;
    backButton.onclick = clickHandler;

    // Insert at the beginning of the section
    const stepHeader = section.querySelector('.step-header');
    if (stepHeader) {
        stepHeader.appendChild(backButton);
    } else {
        section.insertBefore(backButton, section.firstChild);
    }
}

/**
 * Show location selection (go back to first step)
 */
function showLocationSelection() {
    console.log('=== RETURNING TO LOCATION SELECTION ===');

    // Hide all other sections
    document.getElementById('categories-section').style.display = 'none';
    document.getElementById('services-section').style.display = 'none';
    document.getElementById('datetime-section').style.display = 'none';
    document.getElementById('details-section').style.display = 'none';

    // Show locations section
    const locationsSection = document.getElementById('locations-section');
    locationsSection.style.display = 'block';
    locationsSection.classList.add('active');

    // Reset selections
    selectedLocation = null;
    selectedCategory = null;
    selectedService = null;
    selectedDateTime = null;
}

/**
 * Show categories step with location-aware service counts
 */
async function showCategoriesStep() {
    console.log('=== SHOWING CATEGORIES STEP ===');

    // Hide locations section and show categories
    document.getElementById('locations-section').style.display = 'none';
    const categoriesSection = document.getElementById('categories-section');
    categoriesSection.style.display = 'block';
    categoriesSection.classList.add('active');

    // Add back button
    addBackButton('categories-section', 'Înapoi la locații', () => {
        showLocationSelection();
    });

    // Load categories with service counts for selected location
    await loadCategoriesWithCounts();
}

/**
 * Load categories with service counts for the selected location
 * NEW: Uses location-filtered categories endpoint for better performance
 */
async function loadCategoriesWithCounts() {
    console.log('=== LOADING LOCATION-FILTERED CATEGORIES ===');
    console.log('Selected location:', selectedLocation);

    const loadingDiv = document.getElementById('categories-loading');
    const errorDiv = document.getElementById('categories-error');
    const gridDiv = document.getElementById('categories-grid');

    loadingDiv.style.display = 'flex';
    errorDiv.style.display = 'none';
    gridDiv.innerHTML = '';

    try {
        // NEW: Use location-filtered categories endpoint
        const url = USE_WOOCOMMERCE ?
            `${bookingApi.root}wc-categories?locationId=${selectedLocation}` :
            `${bookingApi.root}categories?locationId=${selectedLocation}`;

        console.log('Fetching location-filtered categories from:', url);

        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': bookingApi.nonce
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        console.log('Location-filtered categories response:', data);

        // Convert response to object format
        if (Array.isArray(data)) {
            categories = {};
            data.forEach(categoryName => {
                categories[categoryName] = {
                    name: categoryName,
                    description: `Servicii ${categoryName}`,
                    serviceCount: 1 // If it's returned, it has services
                };
            });
        } else {
            categories = data;
        }

        console.log('Processed categories for location', selectedLocation, ':', Object.keys(categories));
        console.log('Categories count:', Object.keys(categories).length);

        // Display categories immediately since they're already filtered
        loadingDiv.style.display = 'none';
        displayCategoriesWithCounts();

        // Optional: Load exact service counts in background for display
        if (Object.keys(categories).length > 0) {
            console.log('Loading exact service counts in background...');
            await loadServiceCountsForCategories();
            displayCategoriesWithCounts(); // Refresh with exact counts
        }

    } catch (error) {
        console.error('Error loading location-filtered categories:', error);
        console.log('Falling back to old method...');

        // Fallback to old method
        try {
            await loadCategoriesWithCountsFallback();
        } catch (fallbackError) {
            console.error('Fallback also failed:', fallbackError);
            loadingDiv.style.display = 'none';
            errorDiv.style.display = 'block';
            errorDiv.textContent = 'Eroare la încărcarea categoriilor: ' + error.message;
        }
    }
}

/**
 * Fallback method for loading categories (old approach)
 */
async function loadCategoriesWithCountsFallback() {
    console.log('Using fallback category loading method');

    const loadingDiv = document.getElementById('categories-loading');

    // Load all categories first
    const url = USE_WOOCOMMERCE ?
        bookingApi.root + 'wc-categories' :
        bookingApi.root + 'categories';

    const response = await fetch(url, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': bookingApi.nonce
        }
    });

    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }

    const data = await response.json();

    // Convert array response to object format
    if (Array.isArray(data)) {
        categories = {};
        data.forEach(categoryName => {
            categories[categoryName] = {
                name: categoryName,
                description: `Servicii ${categoryName}`
            };
        });
    } else {
        categories = data;
    }

    // Load service counts and filter
    await loadServiceCountsForCategories();

    loadingDiv.style.display = 'none';
    displayCategoriesWithCounts();
}

/**
 * Load service counts for each category at the selected location (parallel)
 */
async function loadServiceCountsForCategories() {
    const categoryNames = Object.keys(categories);

    // Create parallel requests for all categories
    const requests = categoryNames.map(async (categoryName) => {
        try {
            const encodedCategory = encodeURIComponent(categoryName);
            const url = USE_WOOCOMMERCE ?
                `${bookingApi.root}wc-category-services/${encodedCategory}?locationId=${selectedLocation}` :
                `${bookingApi.root}category-services/${encodedCategory}?locationId=${selectedLocation}`;

            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': bookingApi.nonce
                }
            });

            if (response.ok) {
                const data = await response.json();
                const services = Array.isArray(data) ? data : (data.services || []);
                categories[categoryName].serviceCount = services.length;
            } else {
                categories[categoryName].serviceCount = 0;
            }
        } catch (error) {
            console.error(`Error loading services for ${categoryName}:`, error);
            categories[categoryName].serviceCount = 0;
        }
    });

    // Wait for all requests to complete
    await Promise.all(requests);
    console.log('Service counts loaded for all categories');
}

/**
 * Display categories with service counts and icons
 */
function displayCategoriesWithCounts() {
    const gridDiv = document.getElementById('categories-grid');
    gridDiv.innerHTML = '';

    // Category icons mapping
    const categoryIcons = {
        'HEADSPA': '💆‍♀️',
        'HEADSPA & DRENAJ': '🌊',
        'HEADSPA & TERAPIE CRANIO-SACRALA': '🧘‍♀️',
        'HEADSPA & TRATAMENTE FACIALE': '✨',
        'MASAJ & SPA': '🌿'
    };

    Object.keys(categories).forEach(categoryKey => {
        const category = categories[categoryKey];

        // Skip "Programari" category - not for user selection
        if (categoryKey === 'Programari' || categoryKey === 'Programări' ||
            category.name === 'Programari' || category.name === 'Programări') {
            console.log(`Skipping Programari category: ${categoryKey}`);
            return;
        }

        // Skip categories with 0 services (only if count is loaded)
        const serviceCount = category.serviceCount;
        if (serviceCount !== undefined && serviceCount === 0) {
            console.log(`Skipping category ${categoryKey} - no services available (count: ${serviceCount})`);
            return;
        }

        const categoryCard = document.createElement('div');
        categoryCard.className = 'category-card';
        categoryCard.setAttribute('data-category', categoryKey);
        categoryCard.onclick = () => selectCategory(categoryKey);

        const icon = categoryIcons[categoryKey] || '💫';

        // Show loading or actual count
        const countText = serviceCount !== undefined ?
            `${serviceCount} servicii` :
            '<span class="loading-count">...</span>';

        categoryCard.innerHTML = `
            <div class="category-icon">${icon}</div>
            <div class="category-name">${category.name}</div>
            <div class="category-count">${countText}</div>
        `;

        gridDiv.appendChild(categoryCard);
    });
}

/**
 * Select a category and proceed to services
 */
function selectCategory(categoryKey) {
    console.log('Category selected:', categoryKey);
    selectedCategory = categoryKey;

    // Update UI to show selection
    document.querySelectorAll('.category-card').forEach(card => {
        card.classList.remove('selected');
    });

    // Find and mark selected card using data attribute
    const selectedCard = document.querySelector(`[data-category="${categoryKey}"]`);
    if (selectedCard) {
        selectedCard.classList.add('selected');
        console.log(`Category card selected: ${categoryKey}`);
    } else {
        console.warn(`Could not find category card for: ${categoryKey}`);
    }

    // Proceed to services after a short delay
    setTimeout(() => {
        showServicesStep();
    }, 500);
}

/**
 * Show services step for the selected category and location
 */
async function showServicesStep() {
    console.log('=== SHOWING SERVICES STEP ===');

    // Hide categories section and show services
    document.getElementById('categories-section').style.display = 'none';
    const servicesSection = document.getElementById('services-section');
    servicesSection.style.display = 'block';
    servicesSection.classList.add('active');

    // Add back button
    addBackButton('services-section', 'Înapoi la categorii', () => {
        showCategoriesStep();
    });

    // Load services for selected category and location
    await loadCategoryServices(selectedCategory, selectedLocation);
}
