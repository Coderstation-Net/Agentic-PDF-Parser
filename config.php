<?php
/**
 * Configuration file for PDF Layout Parser
 * Handles security settings, performance tuning, and feature flags
 */

declare(strict_types=1);

// ============================================================================
// SECURITY SETTINGS
// ============================================================================

// Maximum file size in bytes (default: 100MB)
define('MAX_FILE_SIZE', 100 * 1024 * 1024);

// Allowed MIME types
define('ALLOWED_MIME_TYPES', [
    'application/pdf' => '.pdf',
]);

// File extension whitelist
define('ALLOWED_EXTENSIONS', ['.pdf']);

// Maximum upload timeout in seconds
define('UPLOAD_TIMEOUT', 300);

// ============================================================================
// PERFORMANCE SETTINGS
// ============================================================================

// DPI for PDF conversion (higher = better quality but slower)
define('PDF_DPI', 200);

// Maximum pages to process (0 = unlimited)
define('MAX_PAGES_PER_PDF', 0);

// ============================================================================
// DIRECTORY SETTINGS
// ============================================================================

define('UPLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads');
define('CONTEXT_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'contexts');

// Ensure directories exist
foreach ([UPLOAD_DIR, CONTEXT_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ============================================================================
// API SETTINGS
// ============================================================================

// Enable RESTful API endpoints
define('API_ENABLED', true);

// API rate limiting (requests per minute per IP)
define('API_RATE_LIMIT', 60);

// API response timeout in seconds
define('API_TIMEOUT', 120);

// ============================================================================
// FEATURE FLAGS
// ============================================================================

define('ENABLE_BATCH_PROCESSING', true);
define('ENABLE_EXPORT_CSV', true);
define('ENABLE_EXPORT_XML', true);
define('ENABLE_TEXT_SEARCH', true);
define('ENABLE_OCR', false); // Requires Tesseract

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Validate file upload
 */
function validate_file_upload(array $file): array
{
    $errors = [];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error (code ' . $file['error'] . ')';
        return $errors;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        $errors[] = 'File size exceeds ' . format_bytes(MAX_FILE_SIZE);
        return $errors;
    }

    $filename = basename($file['name']);
    $extension = strtolower(strrchr($filename, '.'));
    
    if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
        $errors[] = 'File type not allowed. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS);
        return $errors;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, array_keys(ALLOWED_MIME_TYPES), true)) {
        $errors[] = 'Invalid file MIME type: ' . $mime;
        return $errors;
    }

    return $errors;
}

/**
 * Format bytes to human-readable string
 */
function format_bytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Sanitize filename
 */
function sanitize_filename(string $filename): string
{
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return trim($filename, '_');
}

/**
 * Get Python executable path
 */
function get_python_executable(): string
{
    $venvPython = __DIR__ . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    
    if (file_exists($venvPython)) {
        return $venvPython;
    }
    
    return 'python';
}
