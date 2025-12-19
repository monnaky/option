<?php
// app/config/session.php
// Session configuration for the application

return [
    // Session name
    'name' => 'VTMOPTION_SESSION',
    
    // Session lifetime in seconds (8 hours)
    'lifetime' => 3600 * 8,
    
    // Paths for session storage (tried in order)
    'paths' => [
        __DIR__ . '/../../storage/sessions',
        sys_get_temp_dir() . '/vtmoption_sessions',
        session_save_path()
    ],
    
    // Cookie settings
    'cookie' => [
        'lifetime' => 3600 * 8, // 8 hours
        'path' => '/',
        'domain' => '',
        'secure' => ($_ENV['APP_ENV'] ?? 'development') === 'production',
        'httponly' => true,
        'samesite' => 'Lax'
    ],
    
    // Security settings
    'security' => [
        'regenerate_id' => 1800, // Regenerate every 30 minutes
        'use_strict_mode' => true,
        'use_trans_sid' => false,
        'cookie_httponly' => true,
        'cookie_secure' => ($_ENV['APP_ENV'] ?? 'development') === 'production'
    ],
    
    // Garbage collection probability (1/100)
    'gc' => [
        'probability' => 1,
        'divisor' => 100
    ]
];
