<?php

/**
 * Helper Functions
 * 
 * Common utility functions for the application
 */

/**
 * Get base path for the application
 * 
 * @return string Base path (e.g., '/vtmoption' or '')
 */
function getBasePath() {
    // Get the script filename
    $scriptName = $_SERVER['SCRIPT_NAME'];
    
    // Get the directory of the script
    $scriptDir = dirname($scriptName);
    
    // Normalize path separators
    $scriptDir = str_replace('\\', '/', $scriptDir);
    
    // Remove leading slash if present
    $scriptDir = ltrim($scriptDir, '/');
    
    // If script is in root, base path is empty
    if ($scriptDir === '.' || $scriptDir === '') {
        return '';
    }
    
    // Get the project root by going up from script directory
    // For root files (index.php, login.php), scriptDir is '.'
    // For admin files (admin/dashboard.php), scriptDir is 'admin'
    // We want the base path to be the project folder name
    
    // Extract project folder from document root and script name
    $documentRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
    
    // Get the path relative to document root
    $relativePath = str_replace($documentRoot, '', dirname($scriptFile));
    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = trim($relativePath, '/');
    
    // Extract project folder (first segment)
    $segments = explode('/', $relativePath);
    if (!empty($segments[0])) {
        return '/' . $segments[0];
    }
    
    return '';
}

/**
 * Get asset URL
 * 
 * @param string $path Asset path relative to public/assets
 * @return string Full asset URL
 */
function asset($path) {
    $basePath = getBasePath();
    $assetPath = '/public/assets/' . ltrim($path, '/');
    $fullPath = $basePath . $assetPath;
    
    // Ensure path doesn't have double slashes
    $fullPath = str_replace('//', '/', $fullPath);
    
    return $fullPath;
}

/**
 * Get public URL (for pages, no /public/ prefix)
 * 
 * @param string $path Path relative to root
 * @return string Full URL
 */
function url($path = '') {
    $basePath = getBasePath();
    $urlPath = '/' . ltrim($path, '/');
    return $basePath . $urlPath;
}

/**
 * Signal storage helpers
 */
if (!function_exists('vtm_signal_root_path')) {
    function vtm_signal_root_path(): string {
        static $rootPath = null;
        if ($rootPath === null) {
            $rootPath = dirname(__DIR__);
        }
        return $rootPath;
    }
}

if (!function_exists('vtm_signal_primary_path')) {
    function vtm_signal_primary_path(): string {
        $envPath = getenv('SIGNAL_FILE_PRIMARY');
        if (!empty($envPath)) {
            return $envPath;
        }
        $tmpDir = sys_get_temp_dir();
        return rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vtm_getSignal.txt';
    }
}

if (!function_exists('vtm_signal_public_path')) {
    function vtm_signal_public_path(): string {
        $envPath = getenv('SIGNAL_FILE_PUBLIC');
        if (!empty($envPath)) {
            return $envPath;
        }
        return vtm_signal_root_path() . DIRECTORY_SEPARATOR . 'getSignal.txt';
    }
}

if (!function_exists('vtm_signal_ensure_paths')) {
    function vtm_signal_ensure_paths(): void {
        foreach ([vtm_signal_primary_path(), vtm_signal_public_path()] as $path) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!file_exists($path)) {
                @touch($path);
            }
            @chmod($path, 0666);
        }
    }
}

if (!function_exists('vtm_signal_sync_public')) {
    function vtm_signal_sync_public(string $content): bool {
        $publicPath = vtm_signal_public_path();
        $result = @file_put_contents($publicPath, $content, LOCK_EX);
        if ($result === false) {
            return false;
        }
        @chmod($publicPath, 0666);
        return true;
    }
}

if (!function_exists('vtm_signal_write')) {
    function vtm_signal_write(string $content): bool {
        vtm_signal_ensure_paths();
        $primaryPath = vtm_signal_primary_path();
        $result = @file_put_contents($primaryPath, $content, LOCK_EX);
        if ($result === false) {
            return false;
        }
        @chmod($primaryPath, 0666);
        vtm_signal_sync_public($content);
        return true;
    }
}

if (!function_exists('vtm_signal_read')) {
    function vtm_signal_read(): ?string {
        vtm_signal_ensure_paths();
        $primaryPath = vtm_signal_primary_path();
        $content = @file_get_contents($primaryPath);
        if ($content === false) {
            return null;
        }
        return $content;
    }
}

if (!function_exists('vtm_signal_clear')) {
    function vtm_signal_clear(): bool {
        return vtm_signal_write('');
    }
}

/**
 * Get API URL
 * 
 * @param string $endpoint API endpoint
 * @return string Full API URL
 */
function api($endpoint) {
    $basePath = getBasePath();
    $apiPath = '/api/' . ltrim($endpoint, '/');
    return $basePath . $apiPath;
}

