<?php
/**
 * EasyNote Configuration
 */

// Data directory for storing notes
$data_dir = getenv('EASYNOTE_DATA_DIR') ?: __DIR__ . '/_notes/';

// Site title
$site_title = getenv('EASYNOTE_TITLE') ?: 'EasyNote';

// Default language ('en' or 'zh')
$default_lang = getenv('EASYNOTE_LANG') ?: 'zh';

// Enable/disable API access
$allow_api = getenv('EASYNOTE_API') !== false ? filter_var(getenv('EASYNOTE_API'), FILTER_VALIDATE_BOOLEAN) : true;

// Auto-detect base URL
$base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($base_url === '.' || $base_url === '\\') {
    $base_url = '';
}

// Default note (when accessing root URL)
$default_note = '';

// Encryption cipher
$cipher = 'aes-256-cbc';

