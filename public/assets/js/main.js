// ============================================
// Session Management for VTM Option
// ============================================

// Session manager class
class SessionManager {
    constructor() {
        this.keepAliveInterval = null;
        this.keepAliveUrl = '/api/ping.php';
        this.checkInterval = 300000; // 5 minutes (300000ms)
        this.sessionWarningTime = 300000; // 5 minutes before expiry
        this.isSessionExpired = false;
        this.ajaxQueue = [];
        this.init();
    }
    
    init() {
        console.log('SessionManager initialized');
        this.setupAjaxHandlers();
        this.startKeepAlive();
        this.setupActivityListeners();
    }
    
    // Start keep-alive ping
    startKeepAlive() {
        if (this.keepAliveInterval) {
            clearInterval(this.keepAliveInterval);
        }
        
        this.keepAliveInterval = setInterval(() => {
            this.pingServer();
        }, this.checkInterval);
        
        // Initial ping
        setTimeout(() => this.pingServer(), 1000);
    }
    
    // Stop keep-alive
    stopKeepAlive() {
        if (this.keepAliveInterval) {
            clearInterval(this.keepAliveInterval);
            this.keepAliveInterval = null;
        }
    }
    
    // Ping server to keep session alive
    pingServer() {
        fetch(this.keepAliveUrl, {
            method: 'GET',
            credentials: 'include', // Include cookies
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'authenticated') {
                console.debug('Session active for user:', data.user_id);
                this.isSessionExpired = false;
            } else if (data.status === 'not_authenticated') {
                console.debug('No active session');
                this.isSessionExpired = false;
            }
        })
        .catch(error => {
            console.debug('Keep-alive ping failed:', error);
        });
    }
    
    // Setup activity listeners to reset timeout
    setupActivityListeners() {
        const activityEvents = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'];
        
        activityEvents.forEach(event => {
            document.addEventListener(event, () => {
                this.resetInactivityTimer();
            }, { passive: true });
        });
        
        // Also ping on visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.pingServer();
            }
        });
    }
    
    resetInactivityTimer() {
        // Optional: You can implement inactivity warning here
    }
    
    // Setup global AJAX error handlers
    setupAjaxHandlers() {
        // jQuery AJAX error handling
        if (typeof $ !== 'undefined') {
            $(document).ajaxError((event, jqxhr, settings) => {
                if (jqxhr.status === 401) {
                    this.handleUnauthorized(jqxhr, settings);
                }
            });
            
            // Queue requests when session expires
            const originalAjax = $.ajax;
            $.ajax = function(options) {
                if (window.sessionManager && window.sessionManager.isSessionExpired) {
                    return new Promise((resolve, reject) => {
                        window.sessionManager.queueRequest({
                            options: options,
                            resolve: resolve,
                            reject: reject
                        });
                    });
                }
                return originalAjax.call(this, options);
            };
        }
        
        // Fetch API error handling
        if (window.fetch) {
            const originalFetch = window.fetch;
            window.fetch = (...args) => {
                if (window.sessionManager && window.sessionManager.isSessionExpired) {
                    return Promise.reject(new Error('Session expired - requests queued'));
                }
                
                return originalFetch.apply(this, args)
                    .then(response => {
                        if (response.status === 401) {
                            return response.json().then(data => {
                                this.handleUnauthorized({ 
                                    status: 401, 
                                    responseJSON: data 
                                });
                                return Promise.reject(new Error('Unauthorized'));
                            });
                        }
                        return response;
                    });
            };
        }
    }
    
    // Handle 401 unauthorized responses
    handleUnauthorized(jqxhr, settings = {}) {
        const response = jqxhr.responseJSON || {};
        
        // Check if it's a session expiry
        if (response.code === 'SESSION_EXPIRED' || response.requires_login || 
            response.error === 'Authentication required') {
            
            // Only show once
            if (!this.isSessionExpired) {
                this.isSessionExpired = true;
                this.showSessionExpiredMessage(response);
            }
            
            // Store the failed request if it's a non-GET request
            if (settings.type && settings.type.toUpperCase() !== 'GET') {
                this.queueRequest({
                    options: settings,
                    response: response
                });
            }
        } else {
            // Other auth error
            console.error('Authentication error:', response);
            this.showErrorModal('Authentication Error', response.error || 'Please login again.');
        }
    }
    
    // Queue requests when session expires
    queueRequest(request) {
        this.ajaxQueue.push(request);
        console.log('Request queued due to session expiry:', request.options);
    }
    
    // Retry queued requests after login
    retryQueuedRequests() {
        if (this.ajaxQueue.length === 0) return;
        
        console.log('Retrying', this.ajaxQueue.length, 'queued requests');
        
        // Retry all queued requests
        this.ajaxQueue.forEach(request => {
            if (request.resolve && request.reject) {
                // This is a promise-based request
                $.ajax(request.options)
                    .then(request.resolve)
                    .catch(request.reject);
            } else if (request.options) {
                // Regular AJAX request
                $.ajax(request.options);
            }
        });
        
        // Clear queue
        this.ajaxQueue = [];
    }
    
    // Show session expired message
    showSessionExpiredMessage(response) {
        // Remove any existing modal
        const existingModal = document.getElementById('session-expired-modal');
        if (existingModal) existingModal.remove();
        
        // Create modal
        const modal = document.createElement('div');
        modal.id = 'session-expired-modal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        `;
        
        const modalContent = document.createElement('div');
        modalContent.style.cssText = `
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 40px;
            border-radius: 16px;
            text-align: center;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            border: 1px solid rgba(255,255,255,0.1);
            color: white;
        `;
        
        modalContent.innerHTML = `
            <div style="margin-bottom: 25px;">
                <div style="
                    width: 80px;
                    height: 80px;
                    margin: 0 auto 20px;
                    background: rgba(220, 53, 69, 0.2);
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                ">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2">
                        <path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h2 style="margin: 0 0 10px 0; color: #fff; font-size: 24px;">Session Expired</h2>
                <p style="color: #aaa; margin: 0; line-height: 1.5;">
                    ${response.message || 'Your session has expired due to inactivity.'}
                </p>
            </div>
            
            <div style="margin-top: 30px; display: flex; gap: 15px; justify-content: center;">
                <button id="session-login-btn" style="
                    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
                    color: white;
                    padding: 14px 32px;
                    border: none;
                    border-radius: 8px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    min-width: 140px;
                " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 20px rgba(0,123,255,0.3)';" 
                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                    Login Again
                </button>
                
                <button id="session-refresh-btn" style="
                    background: rgba(255,255,255,0.1);
                    color: white;
                    padding: 14px 32px;
                    border: 1px solid rgba(255,255,255,0.2);
                    border-radius: 8px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    min-width: 140px;
                " onmouseover="this.style.background='rgba(255,255,255,0.2)';" 
                onmouseout="this.style.background='rgba(255,255,255,0.1)';">
                    Refresh Page
                </button>
            </div>
            
            <p style="margin-top: 25px; color: #666; font-size: 14px;">
                You will be redirected automatically in <span id="countdown">15</span> seconds
            </p>
        `;
        
        modal.appendChild(modalContent);
        document.body.appendChild(modal);
        
        // Prevent body scrolling
        document.body.style.overflow = 'hidden';
        
        // Add event listeners
        document.getElementById('session-login-btn').addEventListener('click', () => {
            window.location.href = response.redirect || '/login.php';
        });
        
        document.getElementById('session-refresh-btn').addEventListener('click', () => {
            location.reload();
        });
        
        // Auto-redirect countdown
        let countdown = 15;
        const countdownElement = document.getElementById('countdown');
        const countdownInterval = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(countdownInterval);
                window.location.href = response.redirect || '/login.php';
            }
        }, 1000);
        
        // Cleanup function
        modal.cleanup = function() {
            clearInterval(countdownInterval);
            document.body.style.overflow = '';
            if (modal.parentNode) {
                modal.parentNode.removeChild(modal);
            }
        };
        
        // Store reference
        this.currentModal = modal;
    }
    
    // Show error modal
    showErrorModal(title, message) {
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #dc3545;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 9999;
            max-width: 400px;
            animation: slideIn 0.3s ease;
        `;
        
        modal.innerHTML = `
            <strong>${title}</strong>
            <p style="margin: 5px 0 0 0; font-size: 14px;">${message}</p>
        `;
        
        document.body.appendChild(modal);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (modal.parentNode) {
                modal.parentNode.removeChild(modal);
            }
        }, 5000);
    }
    
    // Close current modal
    closeModal() {
        if (this.currentModal && this.currentModal.cleanup) {
            this.currentModal.cleanup();
            this.currentModal = null;
            this.isSessionExpired = false;
        }
    }
}

// Initialize session manager
document.addEventListener('DOMContentLoaded', function() {
    // Create global session manager
    window.sessionManager = new SessionManager();
    
    // Add CSS for animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(style);
    
    // Cleanup on page unload
    window.addEventListener('beforeunload', function() {
        if (window.sessionManager) {
            window.sessionManager.stopKeepAlive();
        }
    });
    
    // After login, retry queued requests
    if (window.location.pathname.includes('login.php')) {
        // Check if we just logged in (you might want to add a specific check)
        setTimeout(() => {
            if (window.sessionManager) {
                window.sessionManager.retryQueuedRequests();
            }
        }, 1000);
    }
});

// Global helper function to check session
window.checkSession = function() {
    if (window.sessionManager) {
        window.sessionManager.pingServer();
    }
};

// Global helper to show session modal
window.showSessionExpired = function(message = 'Your session has expired.') {
    if (window.sessionManager) {
        window.sessionManager.showSessionExpiredMessage({
            message: message,
            redirect: '/login.php'
        });
    }
};
