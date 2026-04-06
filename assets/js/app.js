/**
 * EasyNote - Frontend Application
 * Auto-save, encryption, read-only mode, markdown toggle, keyboard shortcuts
 * Uses window.LANG for i18n translations
 */
(function () {
    'use strict';

    // === i18n helper ===
    var L = window.LANG || {};
    function t(key) { return L[key] || key; }

    // === State ===
    var state = {
        noteName: '',
        baseUrl: '',
        isEncrypted: false,
        isReadonly: false,
        isMarkdown: false,
        readonlyUnlocked: false,
        readonlyPassword: null,
        password: null,
        markdownMode: false,
        saveTimer: null,
        lastSavedContent: '',
        saving: false,
        forceSaveFlag: false
    };

    // === DOM Elements ===
    var $editor, $preview, $saveStatus, $btnMarkdown, $btnLock, $btnCopy;
    var $modalOverlay, $modalTitle, $modalDesc, $passwordInput, $modalConfirm, $modalCancel;
    var $toast;
    var $iconLock, $iconUnlock;
    var $readonlyBanner;
    var $encryptedBanner;

    // === Lazy Loading Helpers ===
    var _loadCache = {};
    function loadScript(url) {
        if (_loadCache[url]) return _loadCache[url];
        _loadCache[url] = new Promise(function(resolve, reject) {
            var s = document.createElement('script');
            s.src = url;
            s.onload = resolve;
            s.onerror = function() { _loadCache[url] = null; reject(); };
            document.head.appendChild(s);
        });
        return _loadCache[url];
    }
    function loadCSS(url) {
        if (_loadCache[url]) return;
        _loadCache[url] = true;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
    }

    // === Content Detection ===
    function contentNeedsMermaid(text) {
        return /```mermaid/i.test(text);
    }
    function contentNeedsMath(text) {
        return /\$\$[\s\S]+?\$\$|\$[^\$\s][^\$\n]*?\$/.test(text);
    }

    // === Asset URL helper ===
    function assetUrl(path) {
        return state.baseUrl + '/' + path;
    }

    // === KaTeX Extension Registration (one-time) ===
    var katexExtRegistered = false;
    function registerKatexExtensions() {
        if (katexExtRegistered) return;
        if (typeof marked === 'undefined' || typeof katex === 'undefined') return;
        katexExtRegistered = true;

        var blockMath = {
            name: 'blockMath',
            level: 'block',
            start: function(src) { return src.indexOf('$$'); },
            tokenizer: function(src) {
                var match = src.match(/^\$\$([\s\S]+?)\$\$/);
                if (match) {
                    return { type: 'blockMath', raw: match[0], text: match[1].trim() };
                }
            },
            renderer: function(token) {
                try {
                    return '<div class="katex-display-wrapper">' + katex.renderToString(token.text, { displayMode: true, throwOnError: false }) + '</div>';
                } catch (e) {
                    return '<div class="katex-error">' + token.text + '</div>';
                }
            }
        };

        var inlineMath = {
            name: 'inlineMath',
            level: 'inline',
            start: function(src) { return src.indexOf('$'); },
            tokenizer: function(src) {
                var match = src.match(/^\$([^\$\n]+?)\$/);
                if (match) {
                    return { type: 'inlineMath', raw: match[0], text: match[1].trim() };
                }
            },
            renderer: function(token) {
                try {
                    return katex.renderToString(token.text, { displayMode: false, throwOnError: false });
                } catch (e) {
                    return '<span class="katex-error">' + token.text + '</span>';
                }
            }
        };

        marked.use({ extensions: [blockMath, inlineMath] });
    }

    // === Mermaid Post-Processing (DOM-based, no custom renderer needed) ===
    function postProcessMermaid() {
        if (typeof mermaid === 'undefined') return Promise.resolve();
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        mermaid.initialize({
            startOnLoad: false,
            theme: isDark ? 'dark' : 'default',
            securityLevel: 'loose',
            fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, sans-serif'
        });

        // Find code blocks with language-mermaid and convert them to mermaid divs
        var codeBlocks = $preview.querySelectorAll('pre code');
        var mermaidDivs = [];
        codeBlocks.forEach(function(codeEl) {
            var cls = codeEl.className || '';
            if (cls.indexOf('mermaid') !== -1 || cls.indexOf('language-mermaid') !== -1) {
                var pre = codeEl.parentElement;
                var div = document.createElement('div');
                div.className = 'mermaid';
                div.textContent = codeEl.textContent;
                div.setAttribute('id', 'mermaid-' + Date.now() + '-' + mermaidDivs.length);
                pre.parentNode.replaceChild(div, pre);
                mermaidDivs.push(div);
            }
        });

        if (mermaidDivs.length === 0) return Promise.resolve();

        return mermaid.run({ nodes: mermaidDivs }).catch(function(e) {
            console.warn('Mermaid render error:', e);
        });
    }

    // === Unified Markdown Render (async, lazy-loads libs as needed) ===
    function renderMarkdown(text) {
        if (typeof marked === 'undefined') {
            $preview.innerHTML = '<p style="color:var(--color-text-secondary)">' + t('md_not_loaded') + '</p>';
            return;
        }

        var needMath = contentNeedsMath(text);
        var needMermaid = contentNeedsMermaid(text);

        // Phase 1: Load KaTeX if needed (must load BEFORE parsing)
        var mathReady = Promise.resolve();
        if (needMath && typeof katex === 'undefined') {
            loadCSS(assetUrl('assets/css/katex.min.css'));
            mathReady = loadScript(assetUrl('assets/js/katex.min.js')).then(function() {
                registerKatexExtensions();
            }).catch(function() {
                console.warn('Failed to load KaTeX');
            });
        } else if (needMath) {
            registerKatexExtensions();
        }

        mathReady.then(function() {
            // Phase 2: Parse markdown
            marked.use({ breaks: true });
            $preview.innerHTML = marked.parse(text);
            addCodeCopyButtons();

            // Phase 3: Load Mermaid if needed (post-processing, AFTER parsing)
            if (needMermaid) {
                if (typeof mermaid === 'undefined') {
                    loadScript(assetUrl('assets/js/mermaid.min.js')).then(function() {
                        return postProcessMermaid();
                    }).catch(function() {
                        console.warn('Failed to load Mermaid');
                    });
                } else {
                    postProcessMermaid();
                }
            }
        });
    }

    // === Initialize ===
    function init() {
        state.noteName = document.getElementById('noteName').value;
        state.baseUrl = document.getElementById('baseUrl').value;
        state.isEncrypted = document.getElementById('isEncrypted').value === '1';
        state.isReadonly = document.getElementById('isReadonly').value === '1';
        state.isMarkdown = document.getElementById('isMarkdown').value === '1';

        $editor = document.getElementById('editor');
        $preview = document.getElementById('markdownPreview');
        $saveStatus = document.getElementById('saveStatus');
        $btnMarkdown = document.getElementById('btnMarkdown');
        $btnLock = document.getElementById('btnLock');
        $btnCopy = document.getElementById('btnCopy');
        $modalOverlay = document.getElementById('modalOverlay');
        $modalTitle = document.getElementById('modalTitle');
        $modalDesc = document.getElementById('modalDesc');
        $passwordInput = document.getElementById('passwordInput');
        $modalConfirm = document.getElementById('modalConfirm');
        $modalCancel = document.getElementById('modalCancel');
        $toast = document.getElementById('toast');
        $iconLock = $btnLock.querySelector('.icon-lock');
        $iconUnlock = $btnLock.querySelector('.icon-unlock');
        $readonlyBanner = document.getElementById('readonlyBanner');
        $encryptedBanner = document.getElementById('encryptedBanner');

        // Track initial content
        state.lastSavedContent = $editor.value;

        // Handle initial states
        if (state.isEncrypted) {
            updateLockIcon(true);
            $btnMarkdown.disabled = true;
            $btnMarkdown.style.opacity = '0.3';
            $btnMarkdown.style.pointerEvents = 'none';
            showDecryptPrompt();
        } else if (state.isReadonly) {
            updateLockIcon(true);
        }

        // Bind events
        bindEvents();

        // Set initial status
        if (!state.isReadonly && !state.isEncrypted && $editor.value.length > 0) {
            setStatus('saved', t('saved'));
        }

        // Auto-enter markdown preview if note is marked as markdown
        if (state.isMarkdown && !state.isEncrypted) {
            state.markdownMode = true;
            $btnMarkdown.classList.add('active');
            renderMarkdown($editor.value);
            $editor.style.display = 'none';
            $preview.style.display = 'block';
        }
    }

    // === Event Bindings ===
    function bindEvents() {
        // Auto-save on input
        $editor.addEventListener('input', function () {
            scheduleSave();
        });

        // Tab key support
        $editor.addEventListener('keydown', function (e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                var start = this.selectionStart;
                var end = this.selectionEnd;
                var value = this.value;
                this.value = value.substring(0, start) + '\t' + value.substring(end);
                this.selectionStart = this.selectionEnd = start + 1;
                scheduleSave();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                forceSave();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'm') {
                e.preventDefault();
                toggleMarkdown();
            }
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Markdown toggle
        $btnMarkdown.addEventListener('click', toggleMarkdown);

        // Lock button
        $btnLock.addEventListener('click', handleLockClick);

        // Copy URL
        $btnCopy.addEventListener('click', copyNoteUrl);

        // Modal
        $modalCancel.addEventListener('click', closeModal);
        $modalOverlay.addEventListener('click', function (e) {
            if (e.target === $modalOverlay) closeModal();
        });
        $passwordInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') $modalConfirm.click();
        });

        // Read-only banner click
        if ($readonlyBanner) {
            $readonlyBanner.style.cursor = 'pointer';
            $readonlyBanner.addEventListener('click', function () {
                showReadonlyUnlockPrompt();
            });
        }

        // Encrypted banner click
        if ($encryptedBanner) {
            $encryptedBanner.style.cursor = 'pointer';
            $encryptedBanner.addEventListener('click', function () {
                showDecryptPrompt();
            });
        }
    }

    // === Auto-Save ===
    function scheduleSave() {
        if (state.saveTimer) clearTimeout(state.saveTimer);
        setStatus('', '');
        state.saveTimer = setTimeout(function () {
            doSave();
        }, 1500);
    }

    function forceSave() {
        if (state.saveTimer) clearTimeout(state.saveTimer);
        doSave();
    }

    function doSave() {
        var content = $editor.value;
        if (content === state.lastSavedContent && !state.isEncrypted && !state.forceSaveFlag) return;
        if (state.saving) return;

        // Block save for readonly (not unlocked)
        if (state.isReadonly && !state.readonlyUnlocked) {
            showToast(t('readonly_save_blocked'));
            return;
        }

        state.saving = true;
        setStatus('saving', t('saving'));

        var body = { action: 'save', content: content };
        if (state.password) {
            body.password = state.password;
        }
        if (state.readonlyUnlocked && state.readonlyPassword) {
            body.readonly_password = state.readonlyPassword;
        }

        fetch(state.baseUrl + '/' + state.noteName, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                state.saving = false;
                if (data.status === 'ok') {
                    state.lastSavedContent = content;
                    state.forceSaveFlag = false;
                    setStatus('saved', t('saved'));
                } else if (data.error === 'readonly') {
                    setStatus('error', t('error'));
                    showToast(t('readonly_save_blocked'));
                } else {
                    setStatus('error', t('error'));
                    showToast(t('save_failed') + (data.error || t('unknown_error')));
                }
            })
            .catch(function (err) {
                state.saving = false;
                setStatus('error', t('error'));
                showToast(t('network_error'));
            });
    }

    // === Status Indicator ===
    function setStatus(cls, text) {
        $saveStatus.className = 'save-status' + (cls ? ' ' + cls : '');
        $saveStatus.querySelector('.status-text').textContent = text;
    }

    // === Code Block Copy Buttons ===
    function addCodeCopyButtons() {
        var blocks = $preview.querySelectorAll('pre');
        var copySvg = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
        var checkSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        for (var i = 0; i < blocks.length; i++) {
            (function (block) {
                var btn = document.createElement('button');
                btn.className = 'code-copy-btn';
                btn.innerHTML = copySvg;
                btn.setAttribute('aria-label', 'Copy code');
                btn.addEventListener('click', function () {
                    var code = block.querySelector('code');
                    var text = code ? code.textContent : block.textContent;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function () {
                            btn.innerHTML = checkSvg;
                            btn.classList.add('copied');
                            setTimeout(function () {
                                btn.innerHTML = copySvg;
                                btn.classList.remove('copied');
                            }, 2000);
                        });
                    }
                });
                block.appendChild(btn);
            })(blocks[i]);
        }
    }

    // === Markdown Toggle ===
    function toggleMarkdown() {
        state.markdownMode = !state.markdownMode;
        $btnMarkdown.classList.toggle('active', state.markdownMode);

        if (state.markdownMode) {
            renderMarkdown($editor.value);
            $editor.style.display = 'none';
            $preview.style.display = 'block';
        } else {
            $editor.style.display = 'block';
            $preview.style.display = 'none';
            $editor.focus();
        }

        // Determine if we can persist the markdown mode change
        var canPersist = false;
        if (state.isReadonly && !state.readonlyUnlocked) {
            // Readonly visitor: local toggle only, no persistence
            return;
        } else {
            canPersist = true;
        }

        if (canPersist) {
            // Persist markdown mode to backend
            var action = state.markdownMode ? 'set_markdown' : 'remove_markdown';
            var body = { action: action };
            if (state.readonlyUnlocked && state.readonlyPassword) {
                body.readonly_password = state.readonlyPassword;
            }
            fetch(state.baseUrl + '/' + state.noteName, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === 'ok') {
                        state.isMarkdown = state.markdownMode;
                        showToast(state.markdownMode ? t('md_mode_on') : t('md_mode_off'));
                    }
                })
                .catch(function () {
                    showToast(t('network_error'));
                });
        }
    }

    // === Lock / Protection ===
    function handleLockClick() {
        if (state.isEncrypted && state.password) {
            // Encrypted and unlocked — remove encryption
            showModal(
                t('remove_encrypt'),
                t('remove_encrypt_desc'),
                function (pwd) {
                    state.password = null;
                    state.isEncrypted = false;
                    state.forceSaveFlag = true;
                    updateLockIcon(false);
                    closeModal();
                    forceSave();
                    showToast(t('encrypt_removed'));
                },
                true
            );
        } else if (state.isReadonly && state.readonlyUnlocked) {
            // Read-only and unlocked — remove readonly
            showModal(
                t('remove_readonly'),
                t('remove_readonly_desc'),
                function (pwd) {
                    removeReadonly();
                },
                true
            );
        } else if (state.isReadonly && !state.readonlyUnlocked) {
            // Read-only and locked — unlock prompt
            showReadonlyUnlockPrompt();
        } else if (!state.isEncrypted && !state.isReadonly) {
            // Unprotected — show protection choice
            showProtectionChoice();
        }
    }

    // === Protection Choice Modal ===
    function showProtectionChoice() {
        $modalTitle.textContent = t('choose_protection');
        $modalDesc.textContent = t('choose_protection_desc');
        $passwordInput.style.display = 'none';

        // Create choice buttons
        var choiceHtml = '<div class="protection-choices">' +
            '<button class="protection-choice-btn" id="choiceEncrypt">' +
            '<span class="choice-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>' +
            '<span class="choice-title">' + t('choice_encrypt') + '</span>' +
            '<span class="choice-desc">' + t('choice_encrypt_desc') + '</span>' +
            '</button>' +
            '<button class="protection-choice-btn" id="choiceReadonly">' +
            '<span class="choice-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></span>' +
            '<span class="choice-title">' + t('choice_readonly') + '</span>' +
            '<span class="choice-desc">' + t('choice_readonly_desc') + '</span>' +
            '</button>' +
            '</div>';

        $modalDesc.innerHTML = t('choose_protection_desc') + choiceHtml;

        // Hide default actions
        document.querySelector('.modal-actions').style.display = 'none';
        $modalOverlay.style.display = 'flex';

        // Bind choice buttons
        setTimeout(function () {
            var $choiceEncrypt = document.getElementById('choiceEncrypt');
            var $choiceReadonly = document.getElementById('choiceReadonly');
            if ($choiceEncrypt) {
                $choiceEncrypt.addEventListener('click', function () {
                    document.querySelector('.modal-actions').style.display = '';
                    showSetEncryptPassword();
                });
            }
            if ($choiceReadonly) {
                $choiceReadonly.addEventListener('click', function () {
                    document.querySelector('.modal-actions').style.display = '';
                    showSetReadonlyPassword();
                });
            }
        }, 50);
    }

    function showSetEncryptPassword() {
        showModal(
            t('set_password'),
            t('set_password_desc'),
            function (pwd) {
                if (!pwd) {
                    showToast(t('pwd_empty'));
                    return;
                }
                state.password = pwd;
                state.isEncrypted = true;
                updateLockIcon(true);
                closeModal();
                forceSave();
                showToast(t('note_encrypted'));
            }
        );
    }

    function showSetReadonlyPassword() {
        showModal(
            t('set_readonly'),
            t('set_readonly_desc'),
            function (pwd) {
                if (!pwd) {
                    showToast(t('pwd_empty'));
                    return;
                }
                setReadonly(pwd);
            }
        );
    }

    // === Read-Only Functions ===
    function setReadonly(password) {
        fetch(state.baseUrl + '/' + state.noteName, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'set_readonly', password: password })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'ok') {
                    state.isReadonly = true;
                    state.readonlyUnlocked = true;
                    state.readonlyPassword = password;
                    updateLockIcon(true);
                    closeModal();
                    showToast(t('readonly_set'));
                    // Don't disable editor since owner just set it
                } else {
                    showToast(t('save_failed') + (data.error || t('unknown_error')));
                }
            })
            .catch(function () {
                showToast(t('network_error'));
            });
    }

    function showReadonlyUnlockPrompt() {
        showModal(
            t('unlock_readonly'),
            t('unlock_readonly_desc'),
            function (pwd) {
                if (!pwd) {
                    showToast(t('pwd_empty'));
                    return;
                }
                verifyReadonly(pwd);
            }
        );
    }

    function verifyReadonly(password) {
        fetch(state.baseUrl + '/' + state.noteName, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verify_readonly', password: password })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.verified) {
                    state.readonlyUnlocked = true;
                    state.readonlyPassword = password;
                    $editor.removeAttribute('readonly');
                    if ($readonlyBanner) {
                        $readonlyBanner.style.display = 'none';
                    }
                    closeModal();
                    setStatus('saved', t('saved'));
                    showToast(t('readonly_unlocked'));
                    $editor.focus();
                } else {
                    showToast(t('pwd_invalid'));
                    $passwordInput.value = '';
                    $passwordInput.focus();
                }
            })
            .catch(function () {
                showToast(t('network_error'));
            });
    }

    function removeReadonly() {
        fetch(state.baseUrl + '/' + state.noteName, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove_readonly', password: state.readonlyPassword })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'ok') {
                    state.isReadonly = false;
                    state.readonlyUnlocked = false;
                    state.readonlyPassword = null;
                    updateLockIcon(false);
                    $editor.removeAttribute('readonly');
                    if ($readonlyBanner) {
                        $readonlyBanner.style.display = 'none';
                    }
                    closeModal();
                    showToast(t('readonly_removed'));
                } else {
                    showToast(t('pwd_invalid'));
                }
            })
            .catch(function () {
                showToast(t('network_error'));
            });
    }

    // === Encryption ===
    function showDecryptPrompt() {
        $editor.disabled = true;
        $editor.value = '';
        $editor.placeholder = t('placeholder_encrypted');

        showModal(
            t('unlock_note'),
            t('unlock_desc'),
            function (pwd) {
                if (!pwd) {
                    showToast(t('pwd_empty'));
                    return;
                }
                fetch(state.baseUrl + '/' + state.noteName, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'decrypt', password: pwd })
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.error) {
                            showToast(t('pwd_invalid'));
                            $passwordInput.value = '';
                            $passwordInput.focus();
                        } else {
                            state.password = pwd;
                            $editor.disabled = false;
                            $editor.value = data.content;
                            $editor.placeholder = t('placeholder');
                            state.lastSavedContent = data.content;
                            // Hide encrypted banner
                            if ($encryptedBanner) {
                                $encryptedBanner.style.display = 'none';
                            }
                            // Re-enable markdown button
                            $btnMarkdown.disabled = false;
                            $btnMarkdown.style.opacity = '';
                            $btnMarkdown.style.pointerEvents = '';
                            closeModal();
                            setStatus('saved', t('saved'));
                            // Auto-enter markdown preview if note is marked as markdown
                            if (state.isMarkdown) {
                                state.markdownMode = true;
                                $btnMarkdown.classList.add('active');
                                renderMarkdown($editor.value);
                                $editor.style.display = 'none';
                                $preview.style.display = 'block';
                            } else {
                                $editor.focus();
                            }
                        }
                    })
                    .catch(function () {
                        showToast(t('network_error'));
                    });
            }
        );
    }

    function updateLockIcon(locked) {
        if (locked) {
            $iconLock.style.display = '';
            $iconUnlock.style.display = 'none';
        } else {
            $iconLock.style.display = 'none';
            $iconUnlock.style.display = '';
        }
    }

    // === Modal ===
    function showModal(title, desc, onConfirm, noPassword) {
        $modalTitle.textContent = title;
        $modalDesc.textContent = desc;
        $passwordInput.value = '';
        document.querySelector('.modal-actions').style.display = '';

        if (noPassword) {
            $passwordInput.style.display = 'none';
        } else {
            $passwordInput.style.display = '';
            $passwordInput.placeholder = t('enter_password');
        }

        $modalOverlay.style.display = 'flex';

        if (!noPassword) {
            setTimeout(function () { $passwordInput.focus(); }, 100);
        }

        $modalConfirm.onclick = function () {
            onConfirm($passwordInput.value);
        };
    }

    function closeModal() {
        $modalOverlay.style.display = 'none';
        document.querySelector('.modal-actions').style.display = '';
    }

    // === Copy URL ===
    function copyNoteUrl() {
        var url = window.location.origin + window.location.pathname;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                showToast(t('url_copied'));
            }).catch(function () {
                fallbackCopy(url);
            });
        } else {
            fallbackCopy(url);
        }
    }

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showToast(t('url_copied'));
        } catch (e) {
            showToast(t('copy_failed'));
        }
        document.body.removeChild(textarea);
    }

    // === Toast ===
    function showToast(message) {
        $toast.textContent = message;
        $toast.classList.add('show');
        setTimeout(function () {
            $toast.classList.remove('show');
        }, 2500);
    }

    // === Boot ===
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // === Theme Toggle (Editor page) ===
    (function() {
        var btn = document.getElementById('btnThemeEditor');
        if (!btn) return;
        btn.addEventListener('click', function() {
            var html = document.documentElement;
            var isDark = html.getAttribute('data-theme') === 'dark';
            if (isDark) {
                html.removeAttribute('data-theme');
                localStorage.setItem('easynote_theme', 'light');
                var m = document.getElementById('metaThemeColor');
                if (m) m.setAttribute('content', '#F2F2F7');
            } else {
                html.setAttribute('data-theme', 'dark');
                localStorage.setItem('easynote_theme', 'dark');
                var m = document.getElementById('metaThemeColor');
                if (m) m.setAttribute('content', '#1C1C1E');
            }
        });
    })();
})();
