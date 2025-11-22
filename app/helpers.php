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

