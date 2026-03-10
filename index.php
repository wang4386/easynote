<?php
/**
 * EasyNote - Minimalist Web Notepad
 * Main entry point handling all requests.
 */

require_once __DIR__ . '/config.php';

// === Language Detection ===
// Priority: ?lang= param > cookie > config default
$supported_langs = ['en', 'zh'];
$lang = $default_lang;

if (isset($_GET['lang']) && in_array($_GET['lang'], $supported_langs)) {
    $lang = $_GET['lang'];
    setcookie('easynote_lang', $lang, time() + 86400 * 30, '/');
} elseif (isset($_COOKIE['easynote_lang']) && in_array($_COOKIE['easynote_lang'], $supported_langs)) {
    $lang = $_COOKIE['easynote_lang'];
}

$t = require __DIR__ . '/assets/lang/' . $lang . '.php';

// Ensure data directory exists
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0755, true);
}

// Handle /?random — redirect to a random note name
if (isset($_GET['random'])) {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $rand_name = '';
    for ($i = 0; $i < 8; $i++) {
        $rand_name .= $chars[random_int(0, strlen($chars) - 1)];
    }
    header('Location: ' . $base_url . '/' . $rand_name);
    exit;
}

// Get note name from query
$note = isset($_GET['note']) ? trim($_GET['note'], '/') : '';

// Sanitize note name: allow alphanumeric, hyphens, underscores
$note = preg_replace('/[^a-zA-Z0-9_\-]/', '', $note);

// Check if this is an API request
$is_api = false;
if (strpos($note, 'api') === 0) {
    $note = preg_replace('/^api\/?/', '', $note);
    $is_api = true;
}

// If no note name, show home page
if (empty($note)) {
    if ($is_api) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No note name specified. Use /api/{note-name}'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Show home page
    showHomePage();
    exit;
}

// Note file path
$note_file = $data_dir . $note . '.txt';
$meta_file = $data_dir . $note . '.meta';

// Handle API requests
if ($is_api) {
    if (!$allow_api) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'API access is disabled'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    handleApiRequest($note, $note_file, $meta_file);
    exit;
}

// Handle AJAX save (POST with JSON body)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleSaveRequest($note, $note_file, $meta_file);
    exit;
}

// Handle note page (GET)
showNotePage($note, $note_file, $meta_file);

// ========== Functions ==========

/**
 * Handle API requests for AI/programmatic access
 */
function handleApiRequest($note, $note_file, $meta_file) {
    global $cipher;
    
    // CORS headers for API
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Password');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Save note via API
        $input = file_get_contents('php://input');
        $json = json_decode($input, true);
        
        $content = '';
        $password = '';
        
        if ($json !== null) {
            $content = isset($json['content']) ? $json['content'] : '';
            $password = isset($json['password']) ? $json['password'] : '';
        } else {
            // Fallback: raw body as content
            $content = $input;
            $password = isset($_SERVER['HTTP_X_PASSWORD']) ? $_SERVER['HTTP_X_PASSWORD'] : '';
        }
        
        if (!empty($password)) {
            $content = encryptContent($content, $password);
        }
        
        $result = file_put_contents($note_file, $content);
        
        header('Content-Type: application/json; charset=utf-8');
        if ($result !== false) {
            echo json_encode([
                'status' => 'ok',
                'note' => $note,
                'length' => strlen($content)
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save note'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    
    // GET: Read note
    if (!file_exists($note_file)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'note' => $note,
            'content' => '',
            'exists' => false
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $content = file_get_contents($note_file);
    $encrypted = isEncrypted($content);
    
    if ($encrypted) {
        $password = isset($_SERVER['HTTP_X_PASSWORD']) ? $_SERVER['HTTP_X_PASSWORD'] : 
                    (isset($_GET['password']) ? $_GET['password'] : '');
        
        if (empty($password)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode([
                'note' => $note,
                'encrypted' => true,
                'error' => 'This note is encrypted. Provide password via X-Password header or ?password= parameter.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $decrypted = decryptContent($content, $password);
        if ($decrypted === false) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'note' => $note,
                'encrypted' => true,
                'error' => 'Invalid password.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $content = $decrypted;
    }
    
    // Check Accept header for raw text
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
    $raw = isset($_GET['raw']);
    
    if ($raw || strpos($accept, 'text/plain') !== false) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'note' => $note,
            'content' => $content,
            'exists' => true,
            'encrypted' => $encrypted,
            'length' => strlen($content),
            'modified' => date('c', filemtime($note_file))
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Handle AJAX save request from frontend
 */
function handleSaveRequest($note, $note_file, $meta_file) {
    global $cipher;
    
    $input = file_get_contents('php://input');
    $json = json_decode($input, true);
    
    header('Content-Type: application/json; charset=utf-8');
    
    if ($json === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $action = isset($json['action']) ? $json['action'] : 'save';
    
    switch ($action) {
        case 'save':
            // Block save if note is read-only and no readonly_password provided
            $meta = getNoteMeta($meta_file);
            if (!empty($meta['readonly'])) {
                $roPwd = isset($json['readonly_password']) ? $json['readonly_password'] : '';
                if (empty($roPwd) || !password_verify($roPwd, $meta['password_hash'])) {
                    http_response_code(403);
                    echo json_encode(['error' => 'readonly'], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            $content = isset($json['content']) ? $json['content'] : '';
            $password = isset($json['password']) ? $json['password'] : '';
            
            if (!empty($password)) {
                $content = encryptContent($content, $password);
            }
            
            $result = file_put_contents($note_file, $content);
            if ($result !== false) {
                echo json_encode(['status' => 'ok', 'length' => strlen($content)], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to save'], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'decrypt':
            $password = isset($json['password']) ? $json['password'] : '';
            if (!file_exists($note_file)) {
                echo json_encode(['error' => 'Note not found'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $content = file_get_contents($note_file);
            if (!isEncrypted($content)) {
                echo json_encode(['content' => $content, 'encrypted' => false], JSON_UNESCAPED_UNICODE);
                break;
            }
            $decrypted = decryptContent($content, $password);
            if ($decrypted === false) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid password'], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['content' => $decrypted, 'encrypted' => true], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'check':
            // Check if note exists and if encrypted
            if (!file_exists($note_file)) {
                echo json_encode(['exists' => false, 'encrypted' => false], JSON_UNESCAPED_UNICODE);
            } else {
                $content = file_get_contents($note_file);
                $encrypted = isEncrypted($content);
                $resp = ['exists' => true, 'encrypted' => $encrypted];
                if (!$encrypted) {
                    $resp['content'] = $content;
                }
                echo json_encode($resp, JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'set_readonly':
            $password = isset($json['password']) ? $json['password'] : '';
            if (empty($password)) {
                echo json_encode(['error' => 'Password required'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            saveNoteMeta($meta_file, ['readonly' => true, 'password_hash' => $hash]);
            echo json_encode(['status' => 'ok', 'readonly' => true], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'verify_readonly':
            $password = isset($json['password']) ? $json['password'] : '';
            $meta = getNoteMeta($meta_file);
            if (empty($meta['readonly'])) {
                echo json_encode(['status' => 'ok', 'readonly' => false], JSON_UNESCAPED_UNICODE);
                break;
            }
            if (password_verify($password, $meta['password_hash'])) {
                echo json_encode(['status' => 'ok', 'verified' => true], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid password'], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'remove_readonly':
            $password = isset($json['password']) ? $json['password'] : '';
            $meta = getNoteMeta($meta_file);
            if (!empty($meta['readonly']) && password_verify($password, $meta['password_hash'])) {
                @unlink($meta_file);
                echo json_encode(['status' => 'ok', 'readonly' => false], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid password'], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Encrypt content with AES-256-CBC
 */
function encryptContent($content, $password) {
    global $cipher;
    $key = hash('sha256', $password, true);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher));
    $encrypted = openssl_encrypt($content, $cipher, $key, 0, $iv);
    return 'ENCRYPTED:' . base64_encode($iv) . ':' . $encrypted;
}

/**
 * Decrypt content
 */
function decryptContent($content, $password) {
    global $cipher;
    $parts = explode(':', $content, 3);
    if (count($parts) !== 3 || $parts[0] !== 'ENCRYPTED') {
        return false;
    }
    $iv = base64_decode($parts[1]);
    $encrypted = $parts[2];
    $key = hash('sha256', $password, true);
    $decrypted = openssl_decrypt($encrypted, $cipher, $key, 0, $iv);
    return $decrypted;
}

/**
 * Check if content is encrypted
 */
function isEncrypted($content) {
    return strpos($content, 'ENCRYPTED:') === 0;
}

/**
 * Get note metadata (readonly settings)
 */
function getNoteMeta($meta_file) {
    if (file_exists($meta_file)) {
        $data = json_decode(file_get_contents($meta_file), true);
        if ($data) return $data;
    }
    return [];
}

/**
 * Save note metadata
 */
function saveNoteMeta($meta_file, $data) {
    file_put_contents($meta_file, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Show the home page
 */
function showHomePage() {
    global $site_title, $base_url;
    renderPage('', '', false, true);
}

/**
 * Show the note editor page
 */
function showNotePage($note, $note_file, $meta_file) {
    $content = '';
    $encrypted = false;
    $readonly = false;
    
    if (file_exists($note_file)) {
        $raw = file_get_contents($note_file);
        $encrypted = isEncrypted($raw);
        if (!$encrypted) {
            $content = $raw;
        }
    }
    
    // Check for read-only mode
    $meta = getNoteMeta($meta_file);
    if (!empty($meta['readonly'])) {
        $readonly = true;
    }
    
    renderPage($note, $content, $encrypted, false, $readonly);
}

/**
 * Build lang switch URL preserving current path
 */
function langSwitchUrl($target_lang) {
    global $base_url;
    $note_name = isset($_GET['note']) ? trim($_GET['note'], '/') : '';
    $path = $note_name ? '/' . $note_name : '/';
    return $base_url . $path . '?lang=' . $target_lang;
}

/**
 * Render the HTML page
 */
function renderPage($note, $content, $encrypted, $is_home, $readonly = false) {
    global $site_title, $base_url, $t, $lang;
    
    $page_title = $is_home ? $site_title : htmlspecialchars($note) . ' - ' . $site_title;
    $content_escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    $note_escaped = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
    $base = $base_url;
    $switch_lang = ($lang === 'zh') ? 'en' : 'zh';
    $switch_url = langSwitchUrl($switch_lang);
    $current_lang_name = ($lang === 'zh') ? '中文' : 'EN';
    $flag_svg = ($lang === 'zh') ? 'cn.svg' : 'us.svg';
    
    // Build JS translations object
    $js_lang_keys = ['saved','saving','error','save_failed','unknown_error','network_error',
                     'url_copied','copy_failed','pwd_empty','pwd_invalid','encrypt_removed',
                     'note_encrypted','md_not_loaded','set_password','set_password_desc',
                     'unlock_note','unlock_desc','remove_encrypt','remove_encrypt_desc',
                     'placeholder','placeholder_encrypted','enter_password',
                     'choose_protection','choose_protection_desc',
                     'choice_encrypt','choice_encrypt_desc','choice_readonly','choice_readonly_desc',
                     'set_readonly','set_readonly_desc','unlock_readonly','unlock_readonly_desc',
                     'remove_readonly','remove_readonly_desc','readonly_banner',
                     'readonly_set','readonly_unlocked','readonly_removed','readonly_save_blocked',
                     'cancel','confirm'];
    $js_translations = [];
    foreach ($js_lang_keys as $key) {
        $js_translations[$key] = $t[$key];
    }
    
?><!DOCTYPE html>
<html lang="<?php echo $t['lang_html']; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($t['meta_description']); ?>">
    <meta name="theme-color" content="#F2F2F7">
    <title><?php echo $page_title; ?></title>
    <!-- Preload critical fonts -->
    <link rel="preload" href="<?php echo $base; ?>/assets/fonts/Inter-Regular.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="preload" href="<?php echo $base; ?>/assets/fonts/Inter-Bold.ttf" as="font" type="font/ttf" crossorigin>
    <!-- Inline critical CSS to eliminate render-blocking -->
    <style>
    @font-face{font-family:'Inter';font-style:normal;font-weight:400;font-display:swap;src:url('<?php echo $base; ?>/assets/fonts/Inter-Regular.ttf') format('truetype')}
    @font-face{font-family:'Inter';font-style:normal;font-weight:700;font-display:swap;src:url('<?php echo $base; ?>/assets/fonts/Inter-Bold.ttf') format('truetype')}
    :root{--bg-base:#F2F2F7;--bg-white:rgba(255,255,255,0.55);--border-inner:rgba(255,255,255,0.6);--border-outer:rgba(229,229,234,0.4);--shadow-float:0 24px 48px -12px rgba(0,0,0,0.08);--color-text:#1C1C1E;--color-text-secondary:#636366;--color-text-tertiary:#8E8E93;--font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    html{font-size:16px;height:100%;height:100dvh}body{font-family:var(--font-family);background-color:var(--bg-base);color:var(--color-text);height:100%;line-height:1.6;overflow:hidden}
    .home-container{display:flex;align-items:center;justify-content:center;height:100%;padding:24px;overflow:hidden}
    .glass-panel{background:var(--bg-white);backdrop-filter:blur(50px);-webkit-backdrop-filter:blur(50px);border:1px solid var(--border-inner);box-shadow:var(--shadow-float),inset 0 1px 0 rgba(255,255,255,0.5);position:relative}
    .home-card{width:100%;max-width:520px;padding:48px 40px;border-radius:50px;text-align:center;animation:cardIn .6s cubic-bezier(.16,1,.3,1) both;position:relative}
    @keyframes cardIn{from{opacity:0;transform:translateY(30px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
    .note-container{display:flex;flex-direction:column;height:100%;padding:16px;gap:12px;max-width:960px;margin:0 auto;overflow:hidden}
    .editor-wrapper{flex:1;display:flex;flex-direction:column;min-height:0;overflow:hidden}
    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    </style>
    <!-- Async load full stylesheet -->
    <link rel="preload" href="<?php echo $base; ?>/assets/css/style.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo $base; ?>/assets/css/style.css"></noscript>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📝</text></svg>">
</head>
<body>
    <?php if ($is_home): ?>
    <!-- Home Page -->
    <main class="home-container">
        <div class="home-card glass-panel">
            <a href="<?php echo htmlspecialchars($switch_url); ?>" class="btn-lang" title="<?php echo $t['lang_switch']; ?>" aria-label="<?php echo $t['lang_switch']; ?>">
                <img src="<?php echo $base; ?>/assets/svg/<?php echo $flag_svg; ?>" alt="" class="lang-flag" aria-hidden="true">
                <span><?php echo $current_lang_name; ?></span>
            </a>
            <div class="home-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
            </div>
            <h1 class="home-title"><?php echo $site_title; ?></h1>
            <p class="home-subtitle"><?php echo $t['subtitle']; ?></p>
            <p class="home-desc"><?php echo $t['home_desc']; ?></p>
            <form class="home-form" id="homeForm" onsubmit="return goToNote()">
                <div class="input-group">
                    <label for="noteNameInput" class="sr-only"><?php echo $t['label_note_name']; ?></label>
                    <span class="input-prefix"><?php echo $_SERVER['HTTP_HOST'] . $base; ?>/</span>
                    <input type="text" id="noteNameInput" class="note-name-input" placeholder="my-note" autofocus autocomplete="off" spellcheck="false" pattern="[a-zA-Z0-9_\-]+" title="<?php echo $t['input_title']; ?>">
                    <a href="<?php echo $base; ?>/?random" class="btn-input-icon" title="<?php echo $t['random_btn']; ?>" tabindex="-1">🎲</a>
                </div>
                <button type="submit" class="btn-primary" id="goBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    <span><?php echo $t['open_note']; ?></span>
                </button>
            </form>
            <div class="home-hints-container">
                <div class="hint-block">
                    <span class="label-industrial">API</span>
                    <code>GET /api/{note-name}</code>
                </div>
                <div class="hint-block">
                    <span class="label-industrial label-secondary">TIP</span>
                    <span class="hint-text">Ctrl+D <?php echo $t['random_tip']; ?></span>
                </div>
            </div>
        </div>
    </main>

    <script>
    function goToNote() {
        var name = document.getElementById('noteNameInput').value.trim();
        if (name) {
            name = name.replace(/[^a-zA-Z0-9_\-]/g, '');
            if (name) {
                window.location.href = '<?php echo $base; ?>/' + name;
            }
        }
        return false;
    }
    // Ctrl+D: change URL to /?random for bookmarking, then restore
    (function() {
        var origUrl = location.href;
        var origTitle = document.title;
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')) {
                history.replaceState(null, '', '<?php echo $base; ?>/?random');
                document.title = '<?php echo $site_title; ?> - Random';
                setTimeout(function() {
                    history.replaceState(null, '', origUrl);
                    document.title = origTitle;
                }, 3000);
            }
        }, true);
    })();
    </script>

    <?php else: ?>
    <!-- Note Editor Page -->
    <input type="hidden" id="noteName" value="<?php echo $note_escaped; ?>">
    <input type="hidden" id="isEncrypted" value="<?php echo $encrypted ? '1' : '0'; ?>">
    <input type="hidden" id="isReadonly" value="<?php echo $readonly ? '1' : '0'; ?>">
    <input type="hidden" id="baseUrl" value="<?php echo $base; ?>">
    <script>var LANG = <?php echo json_encode($js_translations, JSON_UNESCAPED_UNICODE); ?>;</script>
    
    <main class="note-container">
        <!-- Header Bar -->
        <header class="note-header glass-panel" role="banner">
            <div class="header-left">
                <a href="<?php echo $base; ?>/" class="logo-link" title="Home" aria-label="<?php echo $t['btn_home']; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
                </a>
                <span class="note-name-display"><?php echo $note_escaped; ?></span>
            </div>
            <div class="header-actions">
                <span class="save-status" id="saveStatus" role="status" aria-live="polite">
                    <span class="status-dot"></span>
                    <span class="status-text"></span>
                </span>
                
                <button class="btn-icon" id="btnMarkdown" title="<?php echo $t['btn_markdown']; ?>" aria-label="<?php echo $t['btn_markdown']; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M13 8H7"/><path d="M17 12H7"/></svg>
                </button>
                
                <button class="btn-icon" id="btnLock" title="<?php echo $t['btn_lock']; ?>" aria-label="<?php echo $t['btn_lock']; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="icon-unlock" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="icon-lock" style="display:none" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </button>
                
                <button class="btn-icon" id="btnCopy" title="<?php echo $t['btn_copy']; ?>" aria-label="<?php echo $t['btn_copy']; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                </button>

                <a href="<?php echo htmlspecialchars($switch_url); ?>" class="btn-icon btn-lang-editor" title="<?php echo $t['lang_switch']; ?>" aria-label="<?php echo $t['lang_switch']; ?>">
                    <img src="<?php echo $base; ?>/assets/svg/<?php echo $flag_svg; ?>" alt="" class="lang-flag" aria-hidden="true">
                </a>
            </div>
        </header>
        
        <?php if ($readonly): ?>
        <!-- Read-only Banner -->
        <div class="readonly-banner" id="readonlyBanner">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <span><?php echo $t['readonly_banner']; ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Editor Area -->
        <div class="editor-wrapper glass-panel">
            <label for="editor" class="sr-only"><?php echo $t['label_editor']; ?></label>
            <textarea id="editor" class="editor" placeholder="<?php echo $t['placeholder']; ?>" spellcheck="false"<?php if ($readonly) echo ' readonly'; ?>><?php echo $content_escaped; ?></textarea>
            <div id="markdownPreview" class="markdown-preview" style="display:none"></div>
        </div>
    </main>
    
    <!-- Password Modal -->
    <div class="modal-overlay" id="modalOverlay" style="display:none">
        <div class="modal glass-panel" id="passwordModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <h3 class="modal-title" id="modalTitle"><?php echo $t['set_password']; ?></h3>
            <p class="modal-desc" id="modalDesc"><?php echo $t['set_password_desc']; ?></p>
            <label for="passwordInput" class="sr-only"><?php echo $t['label_password']; ?></label>
            <input type="password" id="passwordInput" class="modal-input" placeholder="<?php echo $t['enter_password']; ?>" autocomplete="off">
            <div class="modal-actions">
                <button class="btn-secondary" id="modalCancel"><?php echo $t['cancel']; ?></button>
                <button class="btn-primary btn-sm" id="modalConfirm"><?php echo $t['confirm']; ?></button>
            </div>
        </div>
    </div>
    
    <!-- Toast -->
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
    
    <script src="<?php echo $base; ?>/assets/js/marked.min.js" defer></script>
    <script src="<?php echo $base; ?>/assets/js/app.js" defer></script>
    
    <?php endif; ?>
</body>
</html>
<?php
}
