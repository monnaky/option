/**
 * API Helper Functions
 * 
 * Provides common API call functionality
 */

/**
 * Make an API call
 * 
 * @param {string} url - API endpoint URL
 * @param {object} options - Fetch options (method, body, headers, etc.)
 * @returns {Promise<object>} Response data
 */
async function apiCall(url, options = {}) {
    const defaultOptions = {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin', // Include cookies/session
    };
    
    let mergedOptions = {
        ...defaultOptions,
        ...options,
        headers: {
            ...defaultOptions.headers,
            ...(options.headers || {}),
        },
    };

    // If the caller passed a plain object as body, JSON-encode it so
    // PHP json_decode(file_get_contents('php://input'), true) works.
    if (mergedOptions.body && typeof mergedOptions.body === 'object') {
        mergedOptions = {
            ...mergedOptions,
            body: JSON.stringify(mergedOptions.body),
            headers: {
                ...mergedOptions.headers,
                'Content-Type': 'application/json',
            },
        };
    }
    
    try {
        const response = await fetch(url, mergedOptions);
        
        // Check if response is OK
        if (!response.ok) {
            // Handle 401 Unauthorized - redirect to login
            if (response.status === 401) {
                console.warn('Authentication expired - redirecting to login');
                window.location.href = '/login.php';
                return;
            }
            
            const errorData = await response.json().catch(() => ({ error: 'Unknown error' }));
            throw new Error(errorData.error || `HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Parse JSON response
        const data = await response.json();
        return data;
        
    } catch (error) {
        // Handle network errors
        if (error instanceof TypeError && error.message.includes('fetch')) {
            throw new Error('Network error: Unable to connect to server');
        }
        
        // Re-throw other errors
        throw error;
    }
}

// Export for use in other scripts
if (typeof window !== 'undefined') {
    window.apiCall = apiCall;
}

