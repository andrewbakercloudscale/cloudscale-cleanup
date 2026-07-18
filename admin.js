/* CloudScale Cleanup, Admin JS
 * Chunked processing engine: start → loop chunks → finish
 * Each operation sends small AJAX requests until done.
 */

// Global helpers, called from inline onclick attributes in PHP-rendered HTML.
// Must be window-scoped so they are reachable from HTML event attributes.
function cscToggle(track) {
    var isOn = track.getAttribute('data-on') === '1';
    var newOn = !isOn;
    track.setAttribute('data-on', newOn ? '1' : '0');
    track.style.background = newOn ? '#00a32a' : '#c3c4c7';
    track.querySelector('span').style.left = newOn ? '23px' : '3px';
    var row = track.parentNode;
    var hidden = row.querySelector('input[type="hidden"][data-csc-toggle]');
    if (hidden) { hidden.value = newOn ? '1' : '0'; }
}

var cscPillOff = 'display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #c3c4c7;font-size:12px;font-weight:700;cursor:pointer;background:#fff;color:#50575e;margin:0 4px 4px 0';
var cscPillOn  = 'display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0';
window.cscOrphanTypes = ['images','documents','video','audio'];
function cscOrphanToggle(el, type) {
    var idx = window.cscOrphanTypes.indexOf(type);
    if (idx === -1) {
        window.cscOrphanTypes.push(type);
        el.style.cssText = cscPillOn;
    } else {
        window.cscOrphanTypes.splice(idx, 1);
        el.style.cssText = cscPillOff;
    }
    var joined = window.cscOrphanTypes.join(',');
    var scanBtn = document.getElementById('btn-scan-orphan');
    var recycleBtn = document.getElementById('btn-recycle-orphan');
    if (scanBtn) { scanBtn.setAttribute('data-ftype', joined); }
    if (recycleBtn) { recycleBtn.setAttribute('data-ftype', joined); }
}

(function ($) {
    'use strict';

    $(document).ready(function () {

    console.log('[CSC] admin-v7.js loaded, build 2.1.0, ' + new Date().toISOString());
    // ── Tab switching ──────────────────────────────────────────────────────────

    $('.csc-tab').on('click', function () {
        var target = $(this).data('tab');
        $('.csc-tab').removeClass('active');
        $('.csc-tab-content').removeClass('active');
        $(this).addClass('active');
        $('#tab-' + target).addClass('active');
    });

    // Auto switch to tab from URL parameter (e.g. ?tab=png-to-jpeg)
    (function () {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');
        if (tab) {
            var $btn = $('.csc-tab[data-tab="' + tab + '"]');
            if ($btn.length) { $btn.trigger('click'); }
        }
    })();

    // ── Terminal helpers ───────────────────────────────────────────────────────

    function clearTerminal(id) {
        $('#' + id).empty();
    }

    // Parse "[OPTIMISE] ID 1041, name.ext (87 KB), flags"
    // or    "[UNUSED]   ID 1041, name.ext (87 KB)"
    // into column cells for a cleaner table layout.
    function parseTableLine(text) {
        var m = text.match(/^\s*\[(OPTIMISE|UNUSED)\]\s+ID\s+(\d+)\s+, \s+(.+?)\s+\(([^)]+)\)(?:\s+, \s+(.+))?$/);
        if (!m) { return null; }
        return { tag: m[1], id: m[2], name: m[3], size: m[4], flags: m[5] || '' };
    }

    function appendLine(id, line) {
        var $t  = $('#' + id);
        var cls = 'csc-log-item';
        switch (line.type) {
            case 'section':  cls = 'csc-log-section';  break;
            case 'deleted':  cls = 'csc-log-deleted';  break;
            case 'count':    cls = 'csc-log-count';    break;
            case 'success':  cls = 'csc-log-success';  break;
            case 'error':    cls = 'csc-log-error';    break;
            case 'info':     cls = 'csc-log-info';     break;
        }

        // Attempt columnar render for scan item lines
        if (line.type === 'item') {
            var parsed = parseTableLine(line.text);
            if (parsed) {
                // Ensure a table container exists
                if (!$t.find('.csc-log-table').length) {
                    $t.append('<div class="csc-log-table"></div>');
                }
                var tagColor = parsed.tag === 'OPTIMISE' ? '#ff7b72' : '#79c0ff';
                var html = '<div class="csc-log-row">'
                    + '<span class="csc-log-cell csc-log-cell-id" style="color:#6b7690">ID ' + esc(parsed.id) + '</span>'
                    + '<span class="csc-log-cell csc-log-cell-name" title="' + esc(parsed.name) + '">' + esc(parsed.name) + '</span>'
                    + '<span class="csc-log-cell csc-log-cell-size">' + esc(parsed.size) + '</span>'
                    + (parsed.flags ? '<span class="csc-log-cell csc-log-cell-flags" style="color:' + tagColor + '">' + esc(parsed.flags) + '</span>' : '')
                    + '</div>';
                $t.find('.csc-log-table').append(html);
                $t[0].scrollTop = $t[0].scrollHeight;
                return;
            }
        }

        // For non-table lines, close any open table first then write plain span
        $t.append('<span class="' + cls + '">' + esc(line.text) + '\n</span>');
        $t[0].scrollTop = $t[0].scrollHeight;
    }

    function appendLines(termId, lines) {
        if (!lines || !lines.length) return;
        $.each(lines, function (i, line) { appendLine(termId, line); });
    }

    function renderLines(termId, lines) {
        clearTerminal(termId);
        appendLines(termId, lines);
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Progress helpers ───────────────────────────────────────────────────────

    function showProgress(outerSelector, fillId, labelId, labelText) {
        $(outerSelector).show();
        $('#' + fillId).css('width', '0%');
        $('#' + labelId).text(labelText || 'Working…');
    }

    function updateProgress(fillId, labelId, done, total, extraLabel) {
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;
        $('#' + fillId).css('width', pct + '%');
        var label = pct + '%, ' + done + ' / ' + total + ' processed';
        if (extraLabel) { label += ' · ' + extraLabel; }
        $('#' + labelId).text(label);
    }

    function finishProgress(outerSelector, fillId, labelId, finalLabel) {
        $('#' + fillId).css('width', '100%');
        $('#' + labelId).text(finalLabel || 'Complete.');
        setTimeout(function () { $(outerSelector).fadeOut(600); }, 1800);
    }

    // ── Settings collection ────────────────────────────────────────────────────

    function collectSettings() {
        var data = {};
        $('.csc-setting, .csc-small-num').each(function () {
            data[$(this).attr('name')] = $(this).val();
        });
        // Hidden inputs backing the pure-div toggles
        $('input[type="hidden"][data-csc-toggle]').each(function () {
            data[$(this).attr('name')] = $(this).val();
        });
        $('input[type=checkbox][name^=cscc_]').not('[name$="[]"]').each(function () {
            data[$(this).attr('name')] = $(this).is(':checked') ? '1' : '0';
        });
        $('input[type=checkbox][name$="[]"]:checked').each(function () {
            var name = $(this).attr('name');
            if (!data[name]) { data[name] = []; }
            data[name].push($(this).val());
        });
        return data;
    }

    // ── Save settings ──────────────────────────────────────────────────────────

    $('.csc-save-btn').on('click', function () {
        var $btn = $(this);
        var origLabel = $btn.text();
        $btn.prop('disabled', true).text('Saving…');
        var payload = collectSettings();
        payload.action = 'cscc_save_settings';
        payload.nonce  = CSC.nonce;
        $.post(CSC.ajax_url, payload, function (resp) {
            $btn.prop('disabled', false).text(origLabel);
            notify(resp.success ? 'Settings saved.' : 'Error: ' + (resp.data || 'Unknown'), !resp.success);
        }).fail(function () {
            $btn.prop('disabled', false).text(origLabel);
            notify('Network error.', true);
        });
    });

    function notify(msg, isError) {
        var $n = $('#csc-save-notice');
        $n.text(msg).css('background', isError ? '#e74c3c' : '#27ae60');
        $n.fadeIn(200).delay(2200).fadeOut(400);
    }

    function cscShowModal(title, message) {
        var id = 'csc-warning-modal';
        $('#' + id).remove();
        var html = '<div id="' + id + '" style="position:fixed;inset:0;z-index:100002;background:rgba(0,0,0,0.55);display:flex;align-items:center;justify-content:center;padding:16px">'
            + '<div class="csc-modal">'
            +   '<div class="csc-modal-title">⚠️ ' + esc(title) + '</div>'
            +   '<div class="csc-modal-body"><p>' + esc(message) + '</p></div>'
            +   '<div class="csc-modal-footer">'
            +     '<button type="button" class="csc-btn csc-btn-cancel" id="' + id + '-close">Close</button>'
            +   '</div>'
            + '</div>'
            + '</div>';
        $('body').append(html);
        $('#' + id).on('click', function (e) { if (e.target === this) $(this).remove(); });
        $('#' + id + '-close').on('click', function () { $('#' + id).remove(); });
    }

    function cscConfirmModal(opts, onConfirm) {
        var id = 'csc-modal-' + Date.now();
        var confirmClass = opts.confirmClass || 'csc-btn-primary';
        var html = '<div id="' + id + '" style="position:fixed;inset:0;z-index:100002;background:rgba(0,0,0,0.55);display:flex;align-items:center;justify-content:center;padding:16px">'
            + '<div class="csc-modal">'
            +   '<div class="csc-modal-title">' + (opts.icon ? opts.icon + ' ' : '') + esc(opts.title) + '</div>'
            +   (opts.warning ? '<div class="csc-modal-warning">' + esc(opts.warning) + '</div>' : '')
            +   (opts.body ? '<div class="csc-modal-body">' + opts.body + '</div>' : '')
            +   '<div class="csc-modal-footer">'
            +     '<button type="button" class="csc-btn csc-btn-cancel" id="' + id + '-cancel">' + esc(opts.cancelLabel || 'Cancel') + '</button>'
            +     '<button type="button" class="csc-btn ' + esc(confirmClass) + '" id="' + id + '-confirm">' + esc(opts.confirmLabel || 'OK') + '</button>'
            +   '</div>'
            + '</div>'
            + '</div>';
        $('body').append(html);
        var $overlay = $('#' + id);
        $overlay.on('click', function (e) { if (e.target === this) $overlay.remove(); });
        $('#' + id + '-cancel').on('click', function () { $overlay.remove(); });
        $('#' + id + '-confirm').on('click', function () {
            $overlay.remove();
            if (typeof onConfirm === 'function') { onConfirm(); }
        });
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CHUNKED ENGINE
    // ─────────────────────────────────────────────────────────────────────────
    // runChunked({ startAction, chunkAction, finishAction,
    //              termId, progressOuter, progressFill, progressLabel,
    //              confirmMsg, $btn, restoreLabel })
    // ═════════════════════════════════════════════════════════════════════════

    function runChunked(opts) {
        if (opts.confirmOpts) {
            cscConfirmModal(opts.confirmOpts, function () { runChunked($.extend({}, opts, { confirmOpts: null })); });
            return;
        }

        var total = 0;
        var done  = 0;
        opts.$btn.prop('disabled', true).html('⏳ Starting…');
        clearTerminal(opts.termId);
        appendLine(opts.termId, { type: 'section', text: '=== ' + opts.startLabel + ' ===' });

        showProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Building queue…');

        // Step 1: start
        var startData = { action: opts.startAction, nonce: CSC.nonce };
        if (opts.toggleData) {
            $.extend(startData, opts.toggleData);
        }
        $.post(CSC.ajax_url, startData, function (resp) {
            if (!resp.success) {
                appendLine(opts.termId, { type: 'error', text: 'Error: ' + (resp.data || 'Unknown error') });
                opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Failed.');
                return;
            }

            appendLines(opts.termId, resp.data.lines);
            total = resp.data.total;
            done  = total - resp.data.remaining;
            updateProgress(opts.progressFill, opts.progressLabel, done, total);

            if (total === 0) {
                appendLine(opts.termId, { type: 'info', text: '  Nothing to process.' });
                $.post(CSC.ajax_url, { action: opts.finishAction, nonce: CSC.nonce }, function (resp) {
                    if (resp.success) { appendLines(opts.termId, resp.data.lines); }
                    opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                    finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Nothing to do.');
                }).fail(function () {
                    opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                    finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Nothing to do.');
                });
                return;
            }

            opts.$btn.html('⏳ Processing ' + total + ' items…');
            processNextChunk();
        }).fail(networkError);

        // Step 2: loop
        function processNextChunk() {
            var chunkPayload = { action: opts.chunkAction, nonce: CSC.nonce };
            if (opts.chunkExtra) { $.extend(chunkPayload, opts.chunkExtra); }

            $.post(CSC.ajax_url, chunkPayload, function (resp) {
                if (!resp.success) {
                    appendLine(opts.termId, { type: 'error', text: 'Error: ' + (resp.data || 'Unknown error') });
                    opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                    finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Error, see log.');
                    return;
                }

                appendLines(opts.termId, resp.data.lines);
                var remaining = resp.data.remaining;
                done = total - remaining;
                var extra = opts.progressExtra ? opts.progressExtra(resp.data) : null;
                updateProgress(opts.progressFill, opts.progressLabel, done, total, extra);

                if (remaining > 0) {
                    // Small delay to keep the browser responsive and let the
                    // terminal update paint before firing the next request.
                    setTimeout(processNextChunk, 150);
                } else {
                    finish();
                }
            }).fail(networkError);
        }

        // Step 3: finish
        function finish() {
            $.post(CSC.ajax_url, { action: opts.finishAction, nonce: CSC.nonce }, function (resp) {
                if (resp.success) { appendLines(opts.termId, resp.data.lines); }
                opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Complete.');
                if (typeof opts.onFinish === 'function') { try { opts.onFinish(resp); } catch(e) { console.error('CSC onFinish callback error:', e); } }
            }).fail(networkError);
        }

        function networkError() {
            appendLine(opts.termId, { type: 'error', text: '  Network error. Check your connection and try again.' });
            opts.$btn.prop('disabled', false).html(opts.restoreLabel);
            finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Network error.');
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // DATABASE CLEANUP
    // ═════════════════════════════════════════════════════════════════════════

    $('#btn-scan-db').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('db-terminal');
        appendLine('db-terminal', { type: 'section', text: '=== DRY RUN, Database Scan ===' });

        // Collect toggle states from track divs
        var postData = { action: 'cscc_scan_db', nonce: CSC.nonce };
        var tracked = 0;
        document.querySelectorAll('[data-csc-toggle-track]').forEach(function (track) {
            // Walk up to find the option row, then find the hidden input anywhere inside it
            var row = track.closest('.csc-option-row') || track.parentNode;
            var inputs = row.querySelectorAll('input[type="hidden"]');
            inputs.forEach(function (input) {
                if (input.name && input.name.indexOf('cscc_clean_') === 0) {
                    postData[input.name] = track.getAttribute('data-on') === '1' ? '1' : '0';
                    tracked++;
                }
            });
        });

        $.post(CSC.ajax_url, postData, function (resp) {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview');
            if (resp.success) {
                appendLines('db-terminal', resp.data);
                appendLine('db-terminal', { type: 'info', text: '\nDry run complete. No changes have been made. Review the output log above before running cleanup.' });
            } else {
                appendLine('db-terminal', { type: 'error', text: 'Server error: ' + (resp.data || 'Unknown, check PHP error log') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview');
            appendLine('db-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ', ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
            appendLine('db-terminal', { type: 'error', text: 'Response: ' + jqXHR.responseText.substring(0, 200) });
        });
    });

    $('#btn-run-db').on('click', function () {
        var toggleData = {};
        document.querySelectorAll('[data-csc-toggle-track]').forEach(function (track) {
            var row = track.closest('.csc-option-row') || track.parentNode;
            row.querySelectorAll('input[type="hidden"]').forEach(function (input) {
                if (input.name && input.name.indexOf('cscc_clean_') === 0) {
                    toggleData[input.name] = track.getAttribute('data-on') === '1' ? '1' : '0';
                }
            });
        });
        runChunked({
            startAction:   'cscc_db_start',
            chunkAction:   'cscc_db_chunk',
            finishAction:  'cscc_db_finish',
            startLabel:    'DATABASE CLEANUP RUNNING',
            termId:        'db-terminal',
            progressOuter: 'db-progress-outer',
            progressFill:  'db-progress-fill',
            progressLabel: 'db-progress-label',
            confirmOpts: {
                icon: '🗑️', title: 'Run Database Cleanup',
                warning: 'Selected items will be permanently deleted from the database.',
                body: '<p>Items identified in the dry run will be removed based on your toggle selections, revisions, transients, spam comments, and other selected data.</p><p>This action <strong>cannot be undone</strong>.</p>',
                cancelLabel: 'Cancel', confirmLabel: 'Run Cleanup', confirmClass: 'csc-btn-danger',
            },
            $btn:          $(this),
            restoreLabel:  '🗑 Run Cleanup Now',
            toggleData:    toggleData,
        });
    });

    // ═════════════════════════════════════════════════════════════════════════
    // AUTOLOADED OPTIONS
    // ═════════════════════════════════════════════════════════════════════════

    $('#btn-scan-autoload').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('autoload-terminal');
        appendLine('autoload-terminal', { type: 'section', text: '=== DRY RUN, Autoloaded Options ===' });
        $.post(CSC.ajax_url, { action: 'cscc_autoload_scan', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview');
            if (resp.success) {
                appendLines('autoload-terminal', resp.data);
                appendLine('autoload-terminal', { type: 'info', text: '\nDry run complete. No changes made. Press Clean Autoload Now to optimise.' });
            } else {
                appendLine('autoload-terminal', { type: 'error', text: 'Scan failed: ' + (resp.data || 'unknown error') });
            }
        }).fail(function (jqXHR) {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview');
            appendLine('autoload-terminal', { type: 'error', text: 'Request failed: ' + jqXHR.status });
        });
    });

    $('#btn-run-autoload').on('click', function () {
        runChunked({
            startAction:   'cscc_autoload_start',
            chunkAction:   'cscc_autoload_chunk',
            finishAction:  'cscc_autoload_finish',
            startLabel:    'AUTOLOAD CLEANUP RUNNING',
            termId:        'autoload-terminal',
            progressOuter: 'autoload-progress-outer',
            progressFill:  'autoload-progress-fill',
            progressLabel: 'autoload-progress-label',
            confirmOpts: {
                icon: '⚡', title: 'Clean Autoloaded Data',
                warning: 'Expired transients will be deleted and autoloading disabled for oversized rows.',
                body: '<p>This removes expired transient rows and sets <code>autoload = \'no\'</code> on oversized transient records in <code>wp_options</code>.</p><p>This action <strong>cannot be undone</strong>.</p>',
                cancelLabel: 'Cancel', confirmLabel: 'Clean Now', confirmClass: 'csc-btn-danger',
            },
            $btn:          $(this),
            restoreLabel:  '⚡ Clean Autoload Now',
            onFinish: function (resp) {
                if (!resp.success) { return; }
                var rag  = resp.data.new_rag;
                var size = resp.data.new_size_fmt;
                var bgs    = { green: '#2e7d32', amber: '#e65100', red: '#c62828' };
                var labels = { green: '✅ Healthy', amber: '⚠️ Warning', red: '🔴 Critical' };
                $('#autoload-rag-badge')
                    .text((labels[rag] || rag) + ', ' + size)
                    .css('background', bgs[rag] || '#555');
            },
        });
    });

    // ═════════════════════════════════════════════════════════════════════════
    // ORPHANED PLUGIN OPTIONS
    // ═════════════════════════════════════════════════════════════════════════

    function orphanUpdateBinBar(count, batch) {
        count = parseInt(count, 10) || 0;
        var hasItems = count > 0;
        $('#orphan-bin-label').html('♻️ Recycle Bin: <strong>' + count + '</strong> item' + (count === 1 ? '' : 's'));
        $('#orphan-bin-bar')
            .css('background', hasItems ? '#fff3e0' : '#f5f5f5')
            .css('border-color', hasItems ? '#ffb74d' : '#ddd');
        $('#btn-orphan-view-bin, #btn-orphan-undo, #btn-orphan-empty').prop('disabled', !hasItems);
        if (!hasItems) { $('#orphan-bin-list').hide().html(''); }
    }

    $('#btn-scan-orphans').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        $('#orphan-results').html('');
        $('#btn-run-orphans').hide();

        $.post(CSC.ajax_url, { action: 'cscc_orphan_scan', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Scan for Orphans');
            if (!resp.success) {
                $('#orphan-results').html('<p style="color:#c62828">Scan failed: ' + (resp.data || 'unknown error') + '</p>');
                return;
            }
            var rows = resp.data;
            if (!rows.length) {
                $('#orphan-results').html('<p style="color:#2e7d32;font-weight:600">✅ No orphaned options detected.</p>');
                return;
            }

            var totalSize = rows.reduce(function(s, r) { return s + r.size; }, 0);
            var knownCount = rows.filter(function(r) { return r.plugin !== 'Unknown plugin'; }).length;
            var html = '<p style="margin:0 0 8px;font-size:13px;color:#3c434a">'
                     + '<strong>' + rows.length + ' candidate' + (rows.length === 1 ? '' : 's') + '</strong>'
                     + ', <strong>' + formatBytes(totalSize) + '</strong> total'
                     + ' (' + knownCount + ' matched to a known plugin). Nothing pre-selected, review and check what you want to move.</p>';

            html += '<div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;align-items:center">'
                  + '<button id="orphan-select-all"    class="csc-btn csc-btn-secondary" style="font-size:11px;padding:3px 10px">Select All</button>'
                  + '<button id="orphan-deselect-all"  class="csc-btn csc-btn-secondary" style="font-size:11px;padding:3px 10px">Deselect All</button>'
                  + '<button id="orphan-select-known"  class="csc-btn csc-btn-secondary" style="font-size:11px;padding:3px 10px">Select Known Plugins</button>'
                  + '<div style="display:flex;gap:4px;align-items:center;margin-left:auto">'
                  + '<input type="text" id="orphan-search" placeholder="Search plugin name… (wildcards ok)" style="padding:3px 8px;font-size:11px;border:1px solid #c3c4c7;border-radius:4px;width:200px;outline:none">'
                  + '<button id="orphan-search-select" class="csc-btn csc-btn-secondary" style="font-size:11px;padding:3px 10px">Select Matching</button>'
                  + '</div>'
                  + '</div>';

            html += '<div style="border:1px solid #ddd;border-radius:6px;overflow:hidden">'
                  + '<table style="width:100%;border-collapse:collapse;font-size:12px">'
                  + '<thead><tr style="background:#f5f5f5">'
                  + '<th style="width:28px;padding:6px 8px"></th>'
                  + '<th style="text-align:left;padding:6px 8px;color:#555">Option Name</th>'
                  + '<th style="text-align:left;padding:6px 8px;color:#555">Likely Plugin</th>'
                  + '<th style="text-align:right;padding:6px 8px;color:#555">Size</th>'
                  + '</tr></thead><tbody>';

            $.each(rows, function (i, row) {
                var isKnown  = row.plugin !== 'Unknown plugin';
                var bg       = i % 2 === 0 ? '#fff' : '#fafafa';
                var pluginHtml = isKnown
                    ? $('<div>').text(row.plugin).html()
                    : '<span style="color:#999;font-style:italic">Unknown plugin</span>';
                html += '<tr style="background:' + bg + ';border-top:1px solid #eee">'
                      + '<td style="padding:5px 8px;text-align:center"><input type="checkbox" class="orphan-chk" data-name="' + $('<div>').text(row.name).html() + '"></td>'
                      + '<td style="padding:5px 8px;font-family:monospace;color:#1a1a2e;word-break:break-all">' + $('<div>').text(row.name).html() + '</td>'
                      + '<td style="padding:5px 8px;color:#555">' + pluginHtml + '</td>'
                      + '<td style="padding:5px 8px;text-align:right;color:#666">' + formatBytes(row.size) + '</td>'
                      + '</tr>';
            });

            html += '</tbody></table></div>';
            $('#orphan-results').html(html);
            $('#btn-run-orphans').show();

            $(document).on('click', '#orphan-select-all',   function () { $('.orphan-chk').prop('checked', true); });
            $(document).on('click', '#orphan-deselect-all', function () { $('.orphan-chk').prop('checked', false); });
            $(document).on('click', '#orphan-select-known', function () {
                $('.orphan-chk').each(function () {
                    var $row = $(this).closest('tr');
                    var plugin = $row.find('td:nth-child(3)').text().trim();
                    $(this).prop('checked', plugin !== 'Unknown plugin');
                });
            });

            // Wildcard search, matches option name or plugin name, supports * as wildcard
            function orphanMatchSearch(str, pattern) {
                if (!pattern) { return false; }
                // Convert wildcard pattern to regex: * → .*, ? → .
                var escaped = pattern.replace(/[-[\]{}()+^$.|\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.');
                return new RegExp(escaped, 'i').test(str);
            }
            $(document).on('click', '#orphan-search-select', function () {
                var q = $('#orphan-search').val().trim();
                if (!q) { return; }
                $('.orphan-chk').each(function () {
                    var $row   = $(this).closest('tr');
                    var plugin = $row.find('td:nth-child(3)').text().trim();
                    var name   = $row.find('td:nth-child(2)').text().trim();
                    if (orphanMatchSearch(plugin, q) || orphanMatchSearch(name, q)) {
                        $(this).prop('checked', true);
                    }
                });
            });
            $(document).on('keydown', '#orphan-search', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); $('#orphan-search-select').trigger('click'); }
            });
        }).fail(function (jqXHR) {
            $btn.prop('disabled', false).html('🔍 Scan for Orphans');
            $('#orphan-results').html('<p style="color:#c62828">Request failed: ' + jqXHR.status + '</p>');
        });
    });

    $('#btn-run-orphans').on('click', function () {
        var selected = [];
        $('.orphan-chk:checked').each(function () { selected.push($(this).data('name')); });
        if (!selected.length) { cscShowModal('Nothing Selected', 'Please check at least one option row before moving to the recycle bin.'); return; }

        var $btn = $(this).prop('disabled', true).html('⏳ Moving…');
        $.post(CSC.ajax_url, { action: 'cscc_orphan_delete', nonce: CSC.nonce, options: selected }, function (resp) {
            $btn.prop('disabled', false).html('♻️ Move to Recycle Bin');
            if (resp.success) {
                var n = resp.data.moved;
                $('#orphan-results').prepend('<p style="color:#e65100;font-weight:600;margin-bottom:8px">♻️ ' + n + ' option' + (n === 1 ? '' : 's') + ' moved to recycle bin.</p>');
                $('.orphan-chk:checked').closest('tr').remove();
                if (!$('.orphan-chk').length) { $btn.hide(); }
                orphanUpdateBinBar(resp.data.bin_count, resp.data.batch);
            } else {
                cscShowModal('Move Failed', 'Could not move the selected options to the recycle bin: ' + (resp.data || 'unknown error'));
            }
        }).fail(function () {
            $btn.prop('disabled', false).html('♻️ Move to Recycle Bin');
            cscShowModal('Network Error', 'The request failed. Please check your connection and try again.');
        });
    });

    // Restore All
    $(document).on('click', '#btn-orphan-undo', function () {
        var count = parseInt($('#orphan-bin-count').text(), 10) || 0;
        var $btn = $(this);
        cscConfirmModal({
            icon: '↩️', title: 'Restore Orphaned Options',
            warning: 'All ' + count + ' recycled option row' + (count === 1 ? '' : 's') + ' will be moved back to wp_options.',
            body: '<p>These rows will be restored to the active <code>wp_options</code> table and removed from the recycle bin. The site will use these options again immediately.</p>',
            cancelLabel: 'Cancel', confirmLabel: 'Restore All', confirmClass: 'csc-btn-primary',
        }, function () {
            $btn.prop('disabled', true).html('⏳ Restoring…');
            $.post(CSC.ajax_url, { action: 'cscc_orphan_restore', nonce: CSC.nonce }, function (resp) {
                $btn.prop('disabled', false).html('↩ Restore All');
                if (resp.success) {
                    orphanUpdateBinBar(resp.data.bin_count, null);
                    $('#orphan-bin-list').hide().html('');
                    $('#orphan-results').prepend('<p style="color:#2e7d32;font-weight:600;margin-bottom:8px">✅ Restored ' + resp.data.restored + ' option' + (resp.data.restored === 1 ? '' : 's') + '.</p>');
                } else {
                    cscShowModal('Restore Failed', 'Could not restore the options: ' + (resp.data || 'unknown error'));
                }
            });
        });
    });

    // View bin, with per-row restore buttons
    $(document).on('click', '#btn-orphan-view-bin', function () {
        var $list = $('#orphan-bin-list');
        if ($list.is(':visible')) { $list.hide(); return; }
        $list.html('<p style="font-size:12px;color:#666">Loading…</p>').show();
        $.post(CSC.ajax_url, { action: 'cscc_orphan_bin_list', nonce: CSC.nonce }, function (resp) {
            if (!resp.success || !resp.data.items.length) {
                $list.html('<p style="font-size:12px;color:#999">Bin is empty.</p>');
                return;
            }
            var html = '<div style="border:1px solid #ffb74d;border-radius:6px;overflow:hidden;font-size:12px">'
                     + '<table style="width:100%;border-collapse:collapse">'
                     + '<thead><tr style="background:#fff3e0">'
                     + '<th style="text-align:left;padding:5px 8px;color:#555">Option</th>'
                     + '<th style="text-align:right;padding:5px 8px;color:#555">Size</th>'
                     + '<th style="text-align:right;padding:5px 8px;color:#555">Deleted</th>'
                     + '<th style="padding:5px 8px"></th>'
                     + '</tr></thead><tbody>';
            $.each(resp.data.items, function (i, item) {
                var bg = i % 2 === 0 ? '#fffde7' : '#fff8e1';
                html += '<tr style="background:' + bg + ';border-top:1px solid #ffe082" data-name="' + $('<div>').text(item.name).html() + '">'
                      + '<td style="padding:4px 8px;font-family:monospace;word-break:break-all">' + $('<div>').text(item.name).html() + '</td>'
                      + '<td style="padding:4px 8px;text-align:right;color:#666">' + formatBytes(item.size) + '</td>'
                      + '<td style="padding:4px 8px;text-align:right;color:#999">' + $('<div>').text(item.deleted_at).html() + '</td>'
                      + '<td style="padding:4px 8px;text-align:right"><button class="csc-btn csc-btn-secondary orphan-restore-one" style="font-size:10px;padding:2px 8px">↩ Restore</button></td>'
                      + '</tr>';
            });
            html += '</tbody></table></div>';
            $list.html(html);
        });
    });

    // Per-row restore
    $(document).on('click', '.orphan-restore-one', function () {
        var $row  = $(this).closest('tr');
        var name  = $row.data('name');
        var $btn  = $(this).prop('disabled', true).html('⏳');
        $.post(CSC.ajax_url, { action: 'cscc_orphan_restore', nonce: CSC.nonce, name: name }, function (resp) {
            if (resp.success && resp.data.restored > 0) {
                $row.fadeOut(300, function () { $(this).remove(); });
                orphanUpdateBinBar(resp.data.bin_count, null);
            } else {
                $btn.prop('disabled', false).html('↩ Restore');
                cscShowModal('Restore Failed', 'Could not restore this item. Please try again or refresh the page.');
            }
        });
    });

    // Empty bin permanently
    $(document).on('click', '#btn-orphan-empty', function () {
        var count = parseInt($('#orphan-bin-count').text(), 10) || 0;
        var $btn = $(this);
        cscConfirmModal({
            icon: '🗑️', title: 'Empty Options Recycle Bin',
            warning: 'All ' + count + ' item' + (count === 1 ? '' : 's') + ' will be permanently deleted from the database.',
            body: '<p>Recycled option rows will be permanently removed. They cannot be restored afterwards.</p><p>This action <strong>cannot be undone</strong>.</p>',
            cancelLabel: 'Cancel', confirmLabel: 'Empty Bin', confirmClass: 'csc-btn-danger',
        }, function () {
            $btn.prop('disabled', true).html('⏳ Emptying…');
            $.post(CSC.ajax_url, { action: 'cscc_orphan_empty', nonce: CSC.nonce }, function (resp) {
                $btn.prop('disabled', false).html('🗑 Empty Bin');
                if (resp.success) {
                    orphanUpdateBinBar(0, null);
                    $('#orphan-bin-list').hide().html('');
                } else {
                    cscShowModal('Empty Bin Failed', 'Could not empty the recycle bin: ' + (resp.data || 'unknown error'));
                }
            });
        });
    });

    function formatBytes(bytes) {
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1) + ' MB'; }
        if (bytes >= 1024)    { return (bytes / 1024).toFixed(1) + ' KB'; }
        return bytes + ' B';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TABLE OVERHEAD REPAIR
    // ═════════════════════════════════════════════════════════════════════════

    $('#btn-scan-tables').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('table-terminal');
        appendLine('table-terminal', { type: 'section', text: '=== DRY RUN, Table Overhead ===' });
        $.post(CSC.ajax_url, { action: 'cscc_table_scan', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview');
            if (resp.success) {
                appendLines('table-terminal', resp.data);
                appendLine('table-terminal', { type: 'info', text: '\nDry run complete. Press Repair Tables to optimise.' });
            } else {
                appendLine('table-terminal', { type: 'error', text: 'Scan failed: ' + (resp.data || 'unknown error') });
            }
        }).fail(function (jqXHR) {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview');
            appendLine('table-terminal', { type: 'error', text: 'Request failed: ' + jqXHR.status });
        });
    });

    $('#btn-run-tables').on('click', function () {
        runChunked({
            startAction:   'cscc_table_start',
            chunkAction:   'cscc_table_chunk',
            finishAction:  'cscc_table_finish',
            startLabel:    'TABLE OPTIMISATION RUNNING',
            termId:        'table-terminal',
            progressOuter: 'table-progress-outer',
            progressFill:  'table-progress-fill',
            progressLabel: 'table-progress-label',
            confirmOpts: {
                icon: '🔧', title: 'Repair Table Overhead',
                warning: 'OPTIMIZE TABLE will run on all tables with significant overhead.',
                body: '<p>Safe on InnoDB, no table locks on MySQL 5.6+. This may take a few minutes on large databases.</p><p><strong>No data will be deleted.</strong></p>',
                cancelLabel: 'Cancel', confirmLabel: 'Run Repair', confirmClass: 'csc-btn-primary',
            },
            $btn:          $(this),
            restoreLabel:  '🔧 Repair Tables',
            onFinish: function (resp) {
                if (!resp.success) { return; }
                var rag    = resp.data.new_rag;
                var size   = formatBytes(resp.data.new_overhead);
                var bgs    = { green: '#2e7d32', amber: '#e65100', red: '#c62828' };
                var labels = { green: '✅ Healthy', amber: '⚠️ Warning', red: '🔴 Critical' };
                $('#table-rag-badge')
                    .text((labels[rag] || rag) + ', ' + size + ' overhead')
                    .css('background', bgs[rag] || '#555');
            },
        });
    });

    // ═════════════════════════════════════════════════════════════════════════
    // IMAGE CLEANUP
    // ═════════════════════════════════════════════════════════════════════════

    // ── Media recycle bin status helper ──
    function mediaUpdateRecycleBin( count ) {
        $('#media-recycle-count').text( count > 0 ? ',  ' + count + ' attachment(s) in recycle bin' : ',  recycle bin is empty' );
    }

    // Check media recycle bin on page load
    $.post( CSC.ajax_url, { action: 'cscc_media_recycle_status', nonce: CSC.nonce }, function( resp ) {
        mediaUpdateRecycleBin( resp.success ? resp.data.recycle : 0 );
    });

    var imgUnusedCount = 0;

    $('#btn-scan-img').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('img-terminal');
        $('#img-dry-run-summary').hide();
        appendLine('img-terminal', { type: 'section', text: '=== DRY RUN, Unused Media Scan ===' });

        $.post(CSC.ajax_url, { action: 'cscc_scan_images', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview');
            if (resp.success) {
                appendLines('img-terminal', resp.data);
                appendLine('img-terminal', { type: 'info', text: '\nDry run complete. No files moved or deleted. Review the output log below before moving to recycle.' });
                // Extract unused count and show summary banner
                imgUnusedCount = 0;
                $.each(resp.data, function (_, line) {
                    if (line.type === 'count') {
                        var m = line.text.match(/Total unused:\s*(\d+)/);
                        if (m) { imgUnusedCount = parseInt(m[1], 10); }
                    }
                });
                var label = imgUnusedCount === 1 ? '1 unused attachment found' : imgUnusedCount + ' unused attachments found';
                $('#img-dry-run-msg').text(label);
                $('#img-dry-run-summary').show();
                // Scroll to the output log
                var $terminal = $('#img-terminal');
                if ($terminal.length) {
                    $('html,body').animate({ scrollTop: $terminal.offset().top - 80 }, 400);
                }
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function () {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview');
            appendLine('img-terminal', { type: 'error', text: 'Network error.' });
        });
    });

    $('#btn-run-img').on('click', function () {
        var msg = imgUnusedCount > 0
            ? imgUnusedCount + ' unused attachment' + (imgUnusedCount === 1 ? '' : 's') + ' will be moved to the recycle bin. You can restore or permanently delete them afterwards.'
            : 'Unused attachments found in the dry run will be moved to the recycle bin. You can restore or permanently delete them afterwards.';
        $('#csc-img-move-msg').text(msg);
        $('#csc-img-move-modal').css('display', 'flex');
    });
    $('#btn-recycle-cancel').on('click', function () {
        $('#csc-img-move-modal').hide();
    });
    $('#csc-img-move-modal').on('click', function (e) {
        if (e.target === this) $(this).hide();
    });
    $('#btn-recycle-confirm').on('click', function () {
        $('#csc-img-move-modal').hide();
        $('#img-dry-run-summary').hide();
        runChunked({
            startAction:   'cscc_img_start',
            chunkAction:   'cscc_img_chunk',
            finishAction:  'cscc_img_finish',
            startLabel:    'MOVING UNUSED MEDIA TO RECYCLE',
            termId:        'img-terminal',
            progressOuter: 'img-progress-outer',
            progressFill:  'img-progress-fill',
            progressLabel: 'img-progress-label',
            $btn:          $('#btn-run-img'),
            restoreLabel:  '♻️ Move to Recycle',
            onFinish:      function( resp ) {
                if ( resp && resp.data && typeof resp.data.recycle !== 'undefined' ) {
                    mediaUpdateRecycleBin( resp.data.recycle );
                }
            },
        });
    });

    // ── Media Recycle: Restore All ──
    $('#btn-restore-media').on('click', function () {
        var $btn = $(this);
        cscConfirmModal({
            icon: '↩️', title: 'Restore All Media',
            warning: 'All media in the recycle bin will be moved back to the Media Library.',
            body: '<p>Attachment files will be moved from the recycle folder back to their original upload locations and re-added to the WordPress Media Library as attachment records.</p>',
            cancelLabel: 'Cancel', confirmLabel: 'Restore All', confirmClass: 'csc-btn-primary',
        }, function () {
            $btn.prop('disabled', true).html('⏳ Restoring…');
            clearTerminal('img-terminal');

            $.post(CSC.ajax_url, { action: 'cscc_media_restore', nonce: CSC.nonce }, function (resp) {
                $btn.prop('disabled', false).html('↩️ Restore All');
                if (resp.success) {
                    appendLines('img-terminal', resp.data.lines);
                    mediaUpdateRecycleBin( resp.data.recycle );
                } else {
                    appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                $btn.prop('disabled', false).html('↩️ Restore All');
                appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ', ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
            });
        });
    });

    // ── Media Recycle: Permanently Delete ──
    $('#btn-purge-media').on('click', function () {
        var $btn = $(this);
        cscConfirmModal({
            icon: '🗑️', title: 'Permanently Delete All Media',
            warning: 'All media in the recycle bin will be permanently deleted from disk.',
            body: '<p>All attachment files and their database records will be removed permanently. Cloud copies (S3, Google Drive, Dropbox) are <strong>not affected</strong>.</p><p>This action <strong>cannot be undone</strong>.</p>',
            cancelLabel: 'Cancel', confirmLabel: 'Delete All', confirmClass: 'csc-btn-danger',
        }, function () {
            $btn.prop('disabled', true).html('⏳ Deleting…');
            clearTerminal('img-terminal');

            $.post(CSC.ajax_url, { action: 'cscc_media_purge', nonce: CSC.nonce }, function (resp) {
                $btn.prop('disabled', false).html('🗑 Permanently Delete');
                if (resp.success) {
                    appendLines('img-terminal', resp.data.lines);
                    mediaUpdateRecycleBin( resp.data.recycle );
                } else {
                    appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                $btn.prop('disabled', false).html('🗑 Permanently Delete');
                appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ', ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
            });
        });
    });

    // ── Media Recycle: Browse Modal ──
    $('#btn-browse-media-recycle').on('click', function () {
        var $modal = $('#csc-media-recycle-modal').show();
        var $list  = $('#media-recycle-modal-list').html('<div style="padding:30px;text-align:center;color:#999">Loading media recycle bin…</div>');

        $.post(CSC.ajax_url, { action: 'cscc_media_recycle_browse', nonce: CSC.nonce }, function (res) {
            if (!res.success || !res.data.files.length) {
                $list.html('<div style="padding:30px;text-align:center;color:#999">Recycle bin is empty.</div>');
                $('#media-recycle-modal-summary').text('0 attachments');
                return;
            }
            $('#media-recycle-modal-summary').text(res.data.total + ' attachment(s) · ' + res.data.total_size);

            $list.empty();
            res.data.files.forEach(function (file) {
                var $row = $('<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #eee">' +
                    '<div style="flex:1;min-width:0">' +
                        '<div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">ID ' + file.id + ', ' + $('<span>').text(file.name).html() + '</div>' +
                        '<div style="font-size:11px;color:#888;margin-top:2px">' + file.file_count + ' file(s) · ' + file.size_fmt + (file.recycled ? ' · recycled ' + file.recycled : '') + '</div>' +
                    '</div>' +
                    '<button class="csc-media-restore-single" data-att-id="' + file.id + '" style="background:#43a047;color:#fff;border:none;border-radius:5px;padding:5px 12px;font-size:11px;font-weight:600;cursor:pointer;margin-left:10px;white-space:nowrap">↩️ Restore</button>' +
                '</div>');

                $row.find('.csc-media-restore-single').on('click', function () {
                    var $b = $(this);
                    $b.prop('disabled', true).text('⏳…');
                    $.post(CSC.ajax_url, { action: 'cscc_media_restore_single', nonce: CSC.nonce, att_id: file.id }, function (res) {
                        if (res.success) {
                            $row.fadeOut(300, function () { $(this).remove(); });
                            $('#media-recycle-modal-summary').text(res.data.remaining + ' attachment(s) remaining');
                            mediaUpdateRecycleBin( res.data.remaining );
                        } else {
                            $b.prop('disabled', false).text('↩️ Restore');
                            cscShowModal('Restore Failed', 'Could not restore this attachment: ' + (res.data || 'Unknown error'));
                        }
                    }).fail(function () { $b.prop('disabled', false).text('↩️ Restore'); cscShowModal('Network Error', 'The request failed. Please check your connection and try again.'); });
                });

                $list.append($row);
            });
        }).fail(function () {
            $list.html('<div style="padding:30px;text-align:center;color:#c00">Failed to load recycle bin.</div>');
        });
    });

    $('#btn-media-recycle-modal-close').on('click', function () { $('#csc-media-recycle-modal').hide(); });
    $('#csc-media-recycle-modal').on('click', function (e) { if (e.target === this) $(this).hide(); });

    // ── Orphan files recycle workflow ─────────────────────────────────────────
    // File types stored as data-ftype on buttons, set by inline onclick pill toggles

    function orphanUpdateRecycleBin( count ) {
        $('#orphan-recycle-count').text( count > 0 ? ',  ' + count + ' file(s) in recycle bin' : ',  recycle bin is empty' );
    }

    // Check recycle bin status on page load
    $.post( CSC.ajax_url, { action: 'cscc_recycle_status', nonce: CSC.nonce }, function( resp ) {
        orphanUpdateRecycleBin( resp.success ? resp.data.recycle : 0 );
    });

    // Scan
    $('#btn-scan-orphan').on('click', function () {
        var fileType = (window.cscOrphanTypes && window.cscOrphanTypes.length) ? window.cscOrphanTypes.join(',') : '';
        if ( !fileType ) {
            cscShowModal('No File Types Selected', 'Please select at least one file type (Images, Documents, Video, or Audio) before scanning for orphaned files.');
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('img-terminal');
        appendLine('img-terminal', { type: 'section', text: '=== UNREGISTERED FILE SCAN ===' });

        $.post(CSC.ajax_url, { action: 'cscc_scan_orphan_files', nonce: CSC.nonce, file_type: fileType }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Scan Unregistered Files');
            if (resp.success) {
                appendLines('img-terminal', resp.data.lines);
                orphanUpdateRecycleBin( resp.data.recycle );
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('🔍 Scan Unregistered Files');
            appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ', ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
        });
    });

    // Move to recycle
    $('#btn-recycle-orphan').on('click', function () {
        var fileType = (window.cscOrphanTypes && window.cscOrphanTypes.length) ? window.cscOrphanTypes.join(',') : '';
        if ( !fileType ) {
            cscShowModal('No File Types Selected', 'Please select at least one file type (Images, Documents, Video, or Audio) before moving files to recycle.');
            return;
        }
        var $btn = $(this);
        cscConfirmModal({
            icon: '♻️', title: 'Move Orphaned Files to Recycle',
            warning: 'All scanned orphaned files will be moved to the recycle bin.',
            body: '<p>Files are <strong>moved, not deleted</strong>, they are placed in a protected recycle folder on disk. You can restore or permanently delete them afterwards.</p>',
            cancelLabel: 'Cancel', confirmLabel: 'Move to Recycle', confirmClass: 'csc-btn-primary',
        }, function () {
            $btn.prop('disabled', true).html('⏳ Moving…');
            clearTerminal('img-terminal');

            $.post(CSC.ajax_url, { action: 'cscc_recycle_orphan_files', nonce: CSC.nonce, file_type: fileType }, function (resp) {
                $btn.prop('disabled', false).html('♻️ Move to Recycle');
                if (resp.success) {
                    appendLines('img-terminal', resp.data.lines);
                    orphanUpdateRecycleBin( resp.data.recycle );
                } else {
                    appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                $btn.prop('disabled', false).html('♻️ Move to Recycle');
                appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ', ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
            });
        });
    });

    // Restore
    $('#btn-restore-orphan').on('click', function () {
        var $btn = $(this);
        cscConfirmModal({
            icon: '↩️', title: 'Restore All Orphan Files',
            warning: 'All files in the recycle bin will be restored to their original locations.',
            body: '<p>Files will be moved back from the recycle folder to where they were originally found on disk.</p>',
            cancelLabel: 'Cancel', confirmLabel: 'Restore All', confirmClass: 'csc-btn-primary',
        }, function () {
            $btn.prop('disabled', true).html('⏳ Restoring…');
            clearTerminal('img-terminal');

            $.post(CSC.ajax_url, { action: 'cscc_restore_orphan_files', nonce: CSC.nonce }, function (resp) {
                $btn.prop('disabled', false).html('↩️ Restore All');
                if (resp.success) {
                    appendLines('img-terminal', resp.data.lines);
                    orphanUpdateRecycleBin( resp.data.recycle );
                } else {
                    appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                $btn.prop('disabled', false).html('↩️ Restore All');
                appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ', ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
            });
        });
    });

    // Permanently delete
    $('#btn-purge-orphan').on('click', function () {
        var $btn = $(this);
        cscConfirmModal({
            icon: '🗑️', title: 'Permanently Delete All Orphan Files',
            warning: 'All files in the recycle bin will be permanently deleted from disk.',
            body: '<p>All orphaned files will be removed from the server permanently.</p><p>This action <strong>cannot be undone</strong>.</p>',
            cancelLabel: 'Cancel', confirmLabel: 'Delete All', confirmClass: 'csc-btn-danger',
        }, function () {
            $btn.prop('disabled', true).html('⏳ Deleting…');
            clearTerminal('img-terminal');

            $.post(CSC.ajax_url, { action: 'cscc_purge_orphan_files', nonce: CSC.nonce }, function (resp) {
                $btn.prop('disabled', false).html('🗑 Permanently Delete');
                if (resp.success) {
                    appendLines('img-terminal', resp.data.lines);
                    orphanUpdateRecycleBin( resp.data.recycle );
                } else {
                    appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                $btn.prop('disabled', false).html('🗑 Permanently Delete');
                appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ', ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
            });
        });
    });

    // ═════════════════════════════════════════════════════════════════════════
    // IMAGE OPTIMISATION
    // ═════════════════════════════════════════════════════════════════════════

    $('#btn-scan-optimise').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('optimise-terminal');
        appendLine('optimise-terminal', { type: 'section', text: '=== DRY RUN, Image Optimisation Preview ===' });

        $.post(CSC.ajax_url, { action: 'cscc_scan_optimise', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview Savings');
            if (resp.success) {
                appendLines('optimise-terminal', resp.data);
                appendLine('optimise-terminal', { type: 'info', text: '\nDry run complete. No files modified. Review the output log above before optimising.' });
            } else {
                appendLine('optimise-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function () {
            $btn.prop('disabled', false).html('🔍 Dry Run, Preview Savings');
            appendLine('optimise-terminal', { type: 'error', text: 'Network error.' });
        });
    });

    $('#btn-run-optimise').on('click', function () {
        runChunked({
            startAction:   'cscc_optimise_start',
            chunkAction:   'cscc_optimise_chunk',
            finishAction:  'cscc_optimise_finish',
            startLabel:    'IMAGE OPTIMISATION RUNNING',
            termId:        'optimise-terminal',
            progressOuter: 'opt-progress-outer',
            progressFill:  'opt-progress-fill',
            progressLabel: 'opt-progress-label',
            confirmOpts: {
                icon: '🖼️', title: 'Optimise Images',
                warning: 'Original image files on disk will be permanently modified.',
                body: '<p>Images will be re-compressed in place. <strong>Take a backup before proceeding.</strong></p><p>This action <strong>cannot be undone</strong>.</p>',
                cancelLabel: 'Cancel', confirmLabel: 'Optimise Now', confirmClass: 'csc-btn-danger',
            },
            $btn:          $(this),
            restoreLabel:  '⚡ Optimise Images Now',
            // Show running disk savings in the progress label
            progressExtra: function (data) {
                if (data.total_saved && data.total_saved > 0) {
                    return formatBytes(data.total_saved) + ' saved so far';
                }
                return null;
            },
        });
    });

    // ── Byte formatter (mirrors PHP size_format) ───────────────────────────────

    function formatBytes(bytes) {
        if (bytes < 1024)           { return bytes + ' B'; }
        if (bytes < 1048576)        { return (bytes / 1024).toFixed(1) + ' KB'; }
        if (bytes < 1073741824)     { return (bytes / 1048576).toFixed(1) + ' MB'; }
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }



// ═════════════════════════════════════════════════════════════════════════════
// EXPLAIN MODALS
// ═════════════════════════════════════════════════════════════════════════════

    // Force explain button styles, WordPress admin CSS overrides inline styles
    document.querySelectorAll('[id^="csc-explain-btn-"]').forEach(function(btn) {
        var header = btn.parentElement;
        header.style.setProperty('display', 'flex', 'important');
        header.style.setProperty('align-items', 'center', 'important');
        header.style.setProperty('justify-content', 'space-between', 'important');
        btn.style.setProperty('margin-left', 'auto', 'important');
        btn.style.setProperty('flex-shrink', '0', 'important');
        // Restore color from data attribute set by PHP
        var color = btn.getAttribute('data-color');
        if (color) {
            btn.style.setProperty('background', color, 'important');
        }
    });

    // Force-hide native checkboxes inside slider labels
    // WordPress admin CSS can override our hidden-checkbox styles, so we apply
    // inline styles directly via JS after DOM ready, these always win.
    function initSliders() {
        document.querySelectorAll('.csc-slider-label input[type="checkbox"]').forEach(function (cb) {
            cb.style.cssText = [
                'position:absolute',
                'opacity:0',
                'width:1px',
                'height:1px',
                'margin:-1px',
                'padding:0',
                'border:0',
                'overflow:hidden',
                'clip:rect(0,0,0,0)',
                'white-space:nowrap',
                'pointer-events:none',
                '-webkit-appearance:none',
                'appearance:none',
                'z-index:-1'
            ].join('!important;') + '!important';
        });
    }
    initSliders();

    // ═══════════════════════════════════════════════════════════════════════════
    // BROKEN IMAGE LINK SCANNER
    // ═══════════════════════════════════════════════════════════════════════════

    $('#btn-scan-broken-images').on('click', function () {
        console.log('[CSC] Broken images scan clicked. AJAX URL:', CSC.ajax_url, 'Nonce:', CSC.nonce ? CSC.nonce.substring(0,4) + '…' : 'MISSING');
        var $btn  = $(this).prop('disabled', true).text('Scanning…');
        var $term = $('#img-terminal').empty();
        var allBroken = [];

        function termLine(type, text) {
            var color = type === 'error' ? '#ff5252' : type === 'success' ? '#69f0ae' : type === 'section' ? '#00e5ff' : '#b0bec5';
            $term.append('<div style="color:' + color + '">' + $('<span>').text(text).html() + '</div>');
            $term.scrollTop($term[0].scrollHeight);
        }

        termLine('section', '=== BROKEN IMAGE LINK SCAN ===');

        function scanBatch(offset) {
            $.post(CSC.ajax_url, {
                action: 'cscc_scan_broken_images',
                nonce: CSC.nonce,
                offset: offset
            }, function (res) {
                if (!res || !res.success) {
                    termLine('error', 'Scan failed: ' + ((res && res.data) || 'Unknown error'));
                    $btn.prop('disabled', false).text('🔍 Scan for Broken Images');
                    return;
                }
                var d = res.data;
                allBroken = allBroken.concat(d.broken);
                var scanned = Math.min(d.offset, d.total);
                termLine('info', '  Scanned ' + scanned + ' / ' + d.total + ' posts… (' + allBroken.length + ' broken so far)');

                for (var i = 0; i < d.broken.length; i++) {
                    var b = d.broken[i];
                    var fname = b.image_url.split('/').pop();
                    termLine('error', '  [BROKEN] ' + fname);
                    termLine('info', '    Post: ' + b.post_title + ' (ID ' + b.post_id + ')');
                    termLine('info', '    ' + b.image_url);
                }

                if (d.has_more) {
                    scanBatch(d.offset);
                } else {
                    if (allBroken.length === 0) {
                        termLine('success', '  ✓ No broken image links found. All ' + d.total + ' posts with images checked.');
                    } else {
                        termLine('section', '  Found ' + allBroken.length + ' broken image link(s) across ' + d.total + ' posts.');
                        $('#btn-copy-broken-images').show().data('broken', allBroken);
                    }
                    $btn.prop('disabled', false).text('🔍 Scan for Broken Images');
                }
            }).fail(function () {
                termLine('error', 'Network error during scan.');
                $btn.prop('disabled', false).text('🔍 Scan for Broken Images');
            });
        }

        scanBatch(0);
    });

    // Copy broken images results to clipboard
    $('#btn-copy-broken-images').on('click', function () {
        var broken = $(this).data('broken') || [];
        var text = broken.map(function (b) {
            return '✗ ' + b.image_url.split('/').pop() + '\n' +
                   'Post: ' + b.post_title + ' (ID ' + b.post_id + ')\n' +
                   b.image_url;
        }).join('\n\n');
        navigator.clipboard.writeText(text).then(function () {
            var $b = $('#btn-copy-broken-images');
            $b.text('✓ Copied!');
            setTimeout(function () { $b.text('📋 Copy Results'); }, 2000);
        });
    });

    // ── Generate Missing Thumbnails ───────────────────────────────────────────

    var regenThumbTotal = 0;

    $('#btn-scan-regen-thumb').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        $('#btn-run-regen-thumb').hide();
        $('#regen-thumb-summary').hide();
        $('#regen-thumb-results').hide().html('');
        $('#regen-thumb-progress-outer').hide();

        $.post(CSC.ajax_url, { action: 'cscc_regen_thumb_scan', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Scan for Missing Sizes');
            if (!resp.success) {
                $('#regen-thumb-msg').text('Scan failed: ' + (resp.data || 'Unknown error'));
                $('#regen-thumb-summary').show();
                return;
            }
            var d = resp.data;
            regenThumbTotal = d.total;

            if (d.missing === 0) {
                $('#regen-thumb-msg').text('✔ All ' + d.total + ' images have their thumbnail sizes, nothing to regenerate.');
                $('#regen-thumb-summary').css('border-left-color', '#2e7d32').show();
                $('#btn-run-regen-thumb').hide();
                return;
            }

            // Show summary banner
            $('#regen-thumb-msg').text('⚠ ' + d.missing + ' of ' + d.total + ' images are missing one or more thumbnail sizes.');
            $('#regen-thumb-summary').css('border-left-color', '#e65100').css('background', '#fff3e0').show();
            $('#btn-run-regen-thumb').show();

            // Build results list: featured images first, others after
            var used   = d.images.filter(function (i) { return i.used; });
            var other  = d.images.filter(function (i) { return !i.used; });
            var html   = '';

            if (used.length) {
                html += '<div style="font-weight:700;color:#8c2020;margin-bottom:4px">⚠ Active featured images (' + used.length + '), these affect article display right now:</div>';
                html += '<div style="margin-bottom:10px">' + used.map(function (i) {
                    return '<span style="display:inline-block;background:#fdf0f0;border:1px solid #f5c6c6;border-radius:4px;padding:2px 8px;margin:2px;font-size:11px">'
                        + '<strong style="color:#8c2020">[Featured]</strong> ' + esc(i.title || 'ID ' + i.id) + '</span>';
                }).join('') + '</div>';
            }
            if (other.length) {
                var showOther = other.slice(0, 30);
                html += '<div style="color:#555;margin-bottom:4px">Other images with missing sizes (' + other.length + '):</div>';
                html += '<div>' + showOther.map(function (i) {
                    return '<span style="display:inline-block;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:2px 8px;margin:2px;font-size:11px">'
                        + esc(i.title || 'ID ' + i.id) + '</span>';
                }).join('') + (other.length > 30 ? '<span style="font-size:11px;color:#888"> … and ' + (other.length - 30) + ' more</span>' : '') + '</div>';
            }

            $('#regen-thumb-results').html(html).show();
        }).fail(function () {
            $btn.prop('disabled', false).html('🔍 Scan for Missing Sizes');
            $('#regen-thumb-msg').text('Network error. Check your connection.');
            $('#regen-thumb-summary').show();
        });
    });

    $('#btn-run-regen-thumb').on('click', function () {
        var $btn = $(this);
        if (!confirm('This will regenerate missing thumbnail sizes for all affected images. It may take a minute or two. Continue?')) { return; }

        $btn.prop('disabled', true).html('⚙️ Regenerating…');
        $('#btn-scan-regen-thumb').prop('disabled', true);
        showProgress('#regen-thumb-progress-outer', 'regen-thumb-progress-fill', 'regen-thumb-progress-label', 'Starting…');
        $('#regen-thumb-summary').hide();

        var processed    = 0;
        var regenerated  = 0;
        var errors       = 0;
        var totalImages  = regenThumbTotal || 0;
        var nextOffset   = 0;
        var inFlight     = 0;
        var BATCH        = 5;
        var THREADS      = 2;

        function onAllDone() {
            $btn.prop('disabled', false).html('⚙️ Regenerate All Missing').hide();
            $('#btn-scan-regen-thumb').prop('disabled', false);
            finishProgress('#regen-thumb-progress-outer', 'regen-thumb-progress-fill', 'regen-thumb-progress-label',
                '✔ Done, ' + regenerated + ' images regenerated');
            var doneMsg = '✔ Done, ' + regenerated + ' thumbnail size' + (regenerated === 1 ? '' : 's') + ' regenerated.';
            if (regenerated > 0) { doneMsg += ' Refresh the page or view a post to confirm the article images display correctly.'; }
            if (errors)          { doneMsg += ' ' + errors + ' image(s) could not be processed (file missing on disk).'; }
            $('#regen-thumb-msg').text(doneMsg);
            $('#regen-thumb-summary').css('border-left-color', '#2e7d32').css('background', '#e8f5e9').show();
            setTimeout(function () { $('#btn-scan-regen-thumb').trigger('click'); }, 1200);
        }

        function dispatch() {
            while (inFlight < THREADS) {
                if (totalImages > 0 && nextOffset >= totalImages) { break; }
                var offset = nextOffset;
                nextOffset += BATCH;
                inFlight++;
                (function (off) {
                    $.post(CSC.ajax_url, { action: 'cscc_regen_thumb_batch', nonce: CSC.nonce, offset: off, total: totalImages }, function (resp) {
                        inFlight--;
                        if (!resp.success) {
                            errors++;
                        } else {
                            var d = resp.data;
                            if (!totalImages && d.total) { totalImages = d.total; }
                            $.each(d.batch || [], function (_, item) {
                                processed++;
                                if (item.regenerated) { regenerated++; }
                                else if (!item.ok)    { errors++; }
                            });
                            updateProgress('regen-thumb-progress-fill', 'regen-thumb-progress-label', processed, totalImages,
                                regenerated + ' regenerated' + (errors ? ', ' + errors + ' errors' : ''));
                        }
                        if (inFlight === 0 && totalImages > 0 && nextOffset >= totalImages) {
                            onAllDone();
                        } else {
                            dispatch();
                        }
                    }).fail(function () {
                        inFlight--;
                        errors++;
                        if (inFlight === 0 && totalImages > 0 && nextOffset >= totalImages) {
                            onAllDone();
                        } else {
                            dispatch();
                        }
                    });
                }(offset));
            }
        }

        dispatch();
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // RECYCLE BIN BROWSER
    // ═══════════════════════════════════════════════════════════════════════════

    var recycleFiles = [];

    $('#btn-browse-recycle').on('click', function () {
        var $modal = $('#csc-recycle-modal').show();
        var $list  = $('#recycle-modal-list').html('<div style="padding:30px;text-align:center;color:#999">Loading recycle bin…</div>');
        $('#recycle-search').val('');

        $.post(CSC.ajax_url, { action: 'cscc_recycle_browse', nonce: CSC.nonce }, function (res) {
            if (!res || !res.success) {
                $list.html('<div style="padding:30px;text-align:center;color:#d32f2f">Failed to load recycle bin.</div>');
                return;
            }
            recycleFiles = res.data.files;
            $('#recycle-modal-summary').text(res.data.total + ' file(s) · ' + res.data.total_size);
            renderRecycleList('');
        });
    });

    function renderRecycleList(filter) {
        var $list = $('#recycle-modal-list').empty();
        var lf = filter.toLowerCase();
        var shown = 0;
        for (var i = 0; i < recycleFiles.length; i++) {
            var f = recycleFiles[i];
            if (lf && f.name.toLowerCase().indexOf(lf) === -1 && f.rel.toLowerCase().indexOf(lf) === -1) continue;
            shown++;
            (function (file, idx) {
                var $row = $('<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f0f0f0">' +
                    '<div style="flex:1;min-width:0">' +
                        '<div style="font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + $('<span>').text(file.name).html() + '">' + $('<span>').text(file.name).html() + '</div>' +
                        '<div style="font-size:11px;color:#999">' + $('<span>').text(file.date).html() + ' · ' + file.size_fmt + '</div>' +
                    '</div>' +
                    '<button class="csc-recycle-restore-btn" style="background:#43a047;color:#fff;border:none;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">↩️ Restore</button>' +
                '</div>');
                $row.find('.csc-recycle-restore-btn').on('click', function () {
                    var $b = $(this).prop('disabled', true).text('Restoring…');
                    $.post(CSC.ajax_url, { action: 'cscc_recycle_restore_single', nonce: CSC.nonce, rel: file.rel }, function (res) {
                        if (res && res.success) {
                            $b.closest('div[style*="display:flex"]').css({ background: '#e8f5e9', borderRadius: '6px', padding: '10px' });
                            $b.text('✓ Restored').css({ background: '#a5d6a7', color: '#1b5e20' });
                            recycleFiles.splice(idx, 1);
                            $('#recycle-modal-summary').text(res.data.remaining + ' file(s) remaining');
                            // Update recycle count in main UI
                            $('#orphan-recycle-count').text(res.data.remaining > 0 ? res.data.remaining + ' file(s)' : 'Empty');
                        } else {
                            $b.prop('disabled', false).text('↩️ Restore').css('background', '#ef5350');
                            cscShowModal('Restore Failed', 'Could not restore this file: ' + ((res && res.data) || 'Unknown error'));
                        }
                    });
                });
                $list.append($row);
            })(f, i);
        }
        if (shown === 0) {
            $list.html('<div style="padding:30px;text-align:center;color:#999">' + (lf ? 'No files matching "' + $('<span>').text(lf).html() + '"' : 'Recycle bin is empty.') + '</div>');
        }
    }

    $('#recycle-search').on('input', function () { renderRecycleList($(this).val()); });
    $('#btn-recycle-modal-close').on('click', function () { $('#csc-recycle-modal').hide(); });
    $('#csc-recycle-modal').on('click', function (e) { if (e.target === this) $(this).hide(); });

    // Copy all recycle bin contents to clipboard
    $('#btn-recycle-copy-all').on('click', function () {
        var text = recycleFiles.map(function (f) { return f.name + '\t' + f.size_fmt + '\t' + f.date; }).join('\n');
        navigator.clipboard.writeText(text).then(function () {
            var $b = $('#btn-recycle-copy-all');
            $b.text('✓ Copied!');
            setTimeout(function () { $b.text('📋 Copy All to Clipboard'); }, 2000);
        });
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // PNG TO JPEG CONVERTER
    // ═══════════════════════════════════════════════════════════════════════════

    if (typeof CSC === 'undefined') {
        window.CSC = {};
        console.error('[CSPJ] CSC localize object is missing. wp_localize_script may not have run. Check that the plugin directory is named "cloudscale-cleanup" and that no nested folders exist.');
    }

    var cspjFiles          = [];
    var cspjConvertedToday = 0;
    var cspjChunkMb        = parseFloat( CSC.cspj_chunk_mb ) || 1.5;
    var cspjServerMaxMb    = parseInt( CSC.cspj_server_max_mb, 10 ) || 0;
    var cspjMaxTotalMb     = parseInt( CSC.cspj_max_total_mb, 10 ) || 200;
    var cspjPendingLibrary = null;
    var cspjDebugLog       = [];

    function cspjDbg( tag, msg ) {
        var ts   = new Date();
        var time = cspjPad2(ts.getHours()) + ':' + cspjPad2(ts.getMinutes()) + ':' + cspjPad2(ts.getSeconds()) + '.' + cspjPad3(ts.getMilliseconds());
        cspjDebugLog.push({ time: time, tag: tag, msg: msg });

        var $log = $('#cspj-debug-log');
        if ( !$log.length ) return;

        var tagClass = 'cspj-tag-info';
        if (tag === 'OK')    tagClass = 'cspj-tag-ok';
        if (tag === 'WARN')  tagClass = 'cspj-tag-warn';
        if (tag === 'ERROR') tagClass = 'cspj-tag-error';
        if (tag === 'REQ')   tagClass = 'cspj-tag-req';
        if (tag === 'RESP')  tagClass = 'cspj-tag-resp';

        $log.append(
            '<div class="cspj-log-entry">' +
                '<span class="cspj-log-ts">' + time + '</span>' +
                '<span class="cspj-log-tag ' + tagClass + '">' + esc(tag) + '</span>' +
                '<span class="cspj-log-msg">' + msg + '</span>' +
            '</div>'
        );
        $log[0].scrollTop = $log[0].scrollHeight;
        console.log('[CSPJ][' + tag + '] ' + msg.replace(/<[^>]+>/g, ''));
    }

    function cspjPad2(n) { return n < 10 ? '0' + n : '' + n; }
    function cspjPad3(n) { return n < 10 ? '00' + n : (n < 100 ? '0' + n : '' + n); }

    // Init debug env
    (function() {
        var $env = $('#cspj-debug-env');
        if (!$env.length) return;
        var lines = [];
        lines.push('<strong>Plugin:</strong> CSC v' + (CSC.version || 'unknown') + ' (PNG to JPEG)');
        lines.push('<strong>AJAX URL:</strong> ' + (CSC.ajax_url || '<span style="color:var(--csc-red)">NOT SET</span>'));
        lines.push('<strong>Nonce:</strong> ' + (CSC.nonce ? CSC.nonce.substring(0, 4) + '…' : '<span style="color:var(--csc-red)">NOT SET</span>'));
        lines.push('<strong>Chunk size:</strong> ' + cspjChunkMb + ' MB');
        lines.push('<strong>Server max:</strong> ' + cspjServerMaxMb + ' MB');
        $env.html(lines.join(' &bull; '));
    })();

    cspjDbg('INFO', 'PNG to JPEG converter initialised');

    // Quality slider
    $('#cspj-quality').on('input', function () {
        $('#cspj-quality-val').text($(this).val());
    });

    // Size dropdown
    $('#cspj-size').on('change', function () {
        $('#cspj-custom-size').toggle($(this).val() === 'custom');
    });

    // Chunk size save
    $('#cspj-save-chunkmb').on('click', function () {
        var mb = parseFloat($('#cspj-chunk-mb').val());
        if (!mb || mb < 0.25) { $('#cspj-save-status').text('Enter a valid number.').css('color', 'var(--csc-red)'); return; }
        var $btn = $(this).prop('disabled', true).text('Saving…');
        $.post(CSC.ajax_url, { action: 'cscc_pj_save_settings', nonce: CSC.nonce, chunk_mb: mb }, function (res) {
            $btn.prop('disabled', false).text('Save');
            if (res.success) {
                cspjChunkMb = res.data.chunk_mb;
                $('#cspj-save-status').text('Saved, ' + cspjChunkMb + ' MB').css('color', 'var(--csc-green)');
                cspjDbg('OK', 'Chunk size saved: <span class="cspj-log-val">' + cspjChunkMb + ' MB</span>');
            } else {
                $('#cspj-save-status').text('Error').css('color', 'var(--csc-red)');
                cspjDbg('ERROR', 'Save failed: <span class="cspj-log-err">' + esc(res.data) + '</span>');
            }
            setTimeout(function () { $('#cspj-save-status').text(''); }, 4000);
        }).fail(function () {
            $btn.prop('disabled', false).text('Save');
            $('#cspj-save-status').text('Request failed.').css('color', 'var(--csc-red)');
        });
    });

    // Browse button
    var cspjBrowse = document.getElementById('cspj-browse-btn');
    var cspjInput  = document.getElementById('cspj-file-input');
    if (cspjBrowse && cspjInput) {
        cspjBrowse.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            cspjInput.click();
        });
    }

    // Drop zone
    $('#cspj-drop-zone').on('click', function (e) {
        if (e.target.id === 'cspj-browse-btn') return;
        cspjInput.click();
    }).on('dragover dragenter', function (e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave drop', function (e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        if (e.type === 'drop') cspjAddFiles(e.originalEvent.dataTransfer.files);
    });

    $('#cspj-file-input').on('change', function () {
        cspjAddFiles(this.files);
        this.value = '';
    });

    function cspjUpdateConvertBtn() {
        var hasPending = cspjFiles.some(function (f) { return f.status === 'pending'; });
        $('#cspj-convert-all').prop('disabled', !hasPending);
    }

    function cspjAddFiles(fileList) {
        $.each(fileList, function (i, f) {
            if (f.type !== 'image/png') {
                cspjDbg('WARN', 'Rejected non PNG: <span class="cspj-log-err">' + esc(f.name) + '</span>');
                cscShowModal('File Skipped', f.name + ' is not a PNG file and was skipped. Only PNG files can be converted to WebP.');
                return;
            }
            var id = 'cspj-' + Date.now() + '-' + i;
            cspjFiles.push({ id: id, file: f, status: 'pending' });
            cspjRenderFileItem(id, f);
            cspjDbg('OK', 'Queued: <span class="cspj-log-val">' + esc(f.name) + '</span> (' + cspjFmtSize(f.size) + ')');
        });
        if (cspjFiles.length > 0) {
            $('#cspj-queue-card').show();
            cspjUpdateConvertBtn();
        }
    }

    function cspjRenderFileItem(id, f) {
        $('#cspj-file-list').append(
            '<div class="cspj-file-row" id="row-' + id + '">' +
                '<div class="cspj-file-item" id="item-' + id + '">' +
                    '<div class="cspj-file-thumb-wrap"><img class="cspj-file-thumb" src="" alt=""></div>' +
                    '<div class="cspj-file-info">' +
                        '<div class="cspj-file-name">' + esc(f.name) + '</div>' +
                        '<div class="cspj-file-meta">' + cspjFmtSize(f.size) + '</div>' +
                    '</div>' +
                    '<span class="cspj-badge cspj-badge-pending">Pending</span>' +
                    '<button class="cspj-file-remove" data-id="' + id + '" title="Remove">&times;</button>' +
                '</div>' +
                '<div class="cspj-error-detail" id="err-' + id + '">' +
                    '<div style="font-size:16px">⚠</div>' +
                    '<div id="errtxt-' + id + '"></div>' +
                '</div>' +
            '</div>'
        );
        var reader = new FileReader();
        reader.onload = function (e) {
            $('#item-' + id + ' .cspj-file-thumb').attr('src', e.target.result);
        };
        reader.readAsDataURL(f);
    }

    $(document).on('click', '.cspj-file-remove', function () {
        var id = $(this).data('id');
        cspjFiles = cspjFiles.filter(function (f) { return f.id !== id; });
        $('#row-' + id).remove();
        if (cspjFiles.length === 0) $('#cspj-queue-card').hide();
        cspjUpdateConvertBtn();
    });

    // Convert all
    $('#cspj-convert-all').on('click', function () {
        if (!CSC.ajax_url || !CSC.nonce) {
            cscShowModal('Configuration Error', 'The AJAX URL or security nonce is missing. This usually means the plugin assets are not loading correctly. Please deactivate and reactivate the plugin, clear your browser cache, and ensure the plugin folder is named "cloudscale-cleanup" with no nested subfolders.');
            cspjDbg('ERROR', '<span class="cspj-log-err">Cannot start: CSC.ajax_url=' + (CSC.ajax_url || 'EMPTY') + ' CSC.nonce=' + (CSC.nonce || 'EMPTY') + '</span>');
            return;
        }
        var pending = cspjFiles.filter(function (f) { return f.status === 'pending'; });
        if (!pending.length) { cscShowModal('Nothing to Convert', 'There are no pending PNG files to convert. Drop PNG files into the upload area first.'); return; }
        cspjDbg('INFO', 'Starting batch: <span class="cspj-log-val">' + pending.length + ' file(s)</span>');
        $('#cspj-results').show();
        cspjConvertNext(pending, 0);
    });

    function cspjConvertNext(pending, index) {
        if (index >= pending.length) {
            cspjDbg('OK', '<span class="cspj-log-grn">Batch complete.</span>');
            return;
        }
        var item = pending[index];
        item.status = 'converting';
        var file = item.file;
        var chunkBytes  = Math.floor(cspjChunkMb * 1048576);
        var totalChunks = Math.max(1, Math.ceil(file.size / chunkBytes));

        cspjDbg('INFO', 'Processing [' + (index+1) + '/' + pending.length + ']: <span class="cspj-log-val">' + esc(file.name) + '</span>');
        cspjDbg('INFO', 'File size: ' + cspjFmtSize(file.size) + ' | Chunk size: ' + cspjChunkMb + ' MB (' + chunkBytes + ' bytes) | Total chunks: ' + totalChunks);
        cspjDbg('INFO', 'AJAX URL: <span class="cspj-log-val">' + esc(CSC.ajax_url || 'EMPTY') + '</span>');
        cspjDbg('INFO', 'Nonce: <span class="cspj-log-val">' + (CSC.nonce ? CSC.nonce.substring(0, 6) + '...' : 'EMPTY') + '</span>');
        cspjDbg('INFO', 'Action: <span class="cspj-log-val">cscc_pj_chunk_start</span>');

        cspjSetBadge(item.id, 'loading', 'Uploading…');

        if (!CSC.ajax_url) {
            cspjDbg('ERROR', '<span class="cspj-log-err">FATAL: CSC.ajax_url is empty. wp_localize_script did not fire and inline fallback is missing. The plugin PHP file is outdated.</span>');
            cspjSetError(item.id, 'Config error: AJAX URL not set. See debug console.');
            item.status = 'error';
            return cspjConvertNext(pending, index + 1);
        }
        if (!CSC.nonce) {
            cspjDbg('ERROR', '<span class="cspj-log-err">FATAL: CSC.nonce is empty. Security token not available.</span>');
            cspjSetError(item.id, 'Config error: Nonce not set. See debug console.');
            item.status = 'error';
            return cspjConvertNext(pending, index + 1);
        }

        var startPayload = {
            action: 'cscc_pj_chunk_start',
            nonce: CSC.nonce,
            filename: file.name,
            total_size: file.size,
            total_chunks: totalChunks
        };
        cspjDbg('REQ', 'POST ' + esc(CSC.ajax_url) + ' payload=' + esc(JSON.stringify(startPayload)).substring(0, 300));

        $.post(CSC.ajax_url, startPayload, function (startRes) {
            cspjDbg('RESP', 'HTTP OK | success=' + (startRes && startRes.success) + ' | data=' + esc(JSON.stringify(startRes && startRes.data)).substring(0, 300));
            if (!startRes || !startRes.success) {
                item.status = 'error';
                var errMsg = 'Server returned no data.';
                if (startRes && startRes.data) {
                    errMsg = typeof startRes.data === 'string' ? startRes.data : JSON.stringify(startRes.data);
                }
                cspjSetError(item.id, errMsg);
                cspjDbg('ERROR', 'chunk_start rejected: <span class="cspj-log-err">' + esc(errMsg) + '</span>');
                return cspjConvertNext(pending, index + 1);
            }
            var uploadId = startRes.data.upload_id;
            cspjDbg('OK', 'Upload session: <span class="cspj-log-val">' + esc(uploadId) + '</span>');
            var i = 0;

            function uploadOne() {
                if (i >= totalChunks) {
                    cspjSetBadge(item.id, 'loading', 'Converting…');
                    cspjDbg('INFO', 'All chunks uploaded. Calling cscc_pj_chunk_finish...');
                    return finish();
                }
                var startByte = i * chunkBytes;
                var endByte   = Math.min(file.size, startByte + chunkBytes);
                var blob      = file.slice(startByte, endByte);
                if (totalChunks > 1) {
                    cspjSetBadge(item.id, 'loading', 'Uploading… ' + Math.round(((i+1)/totalChunks)*100) + '%');
                }

                cspjDbg('REQ', 'Chunk ' + (i+1) + '/' + totalChunks + ': bytes ' + startByte + '-' + endByte + ' (' + cspjFmtSize(blob.size) + ')');

                var fd = new FormData();
                fd.append('action', 'cscc_pj_chunk_upload');
                fd.append('nonce', CSC.nonce);
                fd.append('upload_id', uploadId);
                fd.append('chunk_index', String(i));
                fd.append('total_chunks', String(totalChunks));
                fd.append('chunk', blob, file.name + '.part');

                $.ajax({
                    url: CSC.ajax_url, type: 'POST', data: fd,
                    processData: false, contentType: false,
                    success: function (upRes) {
                        cspjDbg('RESP', 'chunk_upload [' + (i+1) + '/' + totalChunks + ']: success=' + (upRes && upRes.success));
                        if (upRes && upRes.success) { i++; uploadOne(); }
                        else {
                            item.status = 'error';
                            var errMsg = (upRes && upRes.data) ? String(upRes.data) : 'Chunk upload rejected by server.';
                            cspjSetError(item.id, errMsg);
                            cspjDbg('ERROR', 'chunk_upload rejected: <span class="cspj-log-err">' + esc(errMsg) + '</span>');
                            return cspjConvertNext(pending, index + 1);
                        }
                    },
                    error: function (xhr) {
                        item.status = 'error';
                        var body = '';
                        try { body = xhr.responseText || ''; } catch(e) {}
                        var msg = xhr.status === 0
                            ? 'Network error or request blocked (status 0). Possible causes: server timeout, mod_security, or file too large for server.'
                            : ('HTTP ' + xhr.status + ' ' + (xhr.statusText || ''));
                        cspjDbg('ERROR', 'chunk_upload FAILED: <span class="cspj-log-err">' + esc(msg) + '</span>');
                        if (body) { cspjDbg('ERROR', 'Response body: <span class="cspj-log-err">' + esc(body.substring(0, 500)) + '</span>'); }
                        cspjSetError(item.id, msg);
                        return cspjConvertNext(pending, index + 1);
                    }
                });
            }

            function finish() {
                var finPayload = {
                    action: 'cscc_pj_chunk_finish', nonce: CSC.nonce, upload_id: uploadId,
                    quality: $('#cspj-quality').val(), size: $('#cspj-size').val(),
                    custom_w: $('#cspj-custom-w').val() || '0', custom_h: $('#cspj-custom-h').val() || '0',
                    constrain: $('#cspj-constrain').is(':checked') ? '1' : '0'
                };
                cspjDbg('REQ', 'POST cscc_pj_chunk_finish: quality=' + finPayload.quality + ' size=' + esc(finPayload.size));

                $.post(CSC.ajax_url, finPayload, function (finRes) {
                    cspjDbg('RESP', 'chunk_finish: success=' + (finRes && finRes.success) + ' data=' + esc(JSON.stringify(finRes && finRes.data)).substring(0, 300));
                    if (finRes && finRes.success) {
                        item.status = 'done';
                        item.result = finRes.data;
                        cspjSetBadge(item.id, 'done', 'Done');
                        cspjRenderResult(item);
                        cspjConvertedToday++;
                        cspjDbg('OK', '<span class="cspj-log-grn">Converted:</span> <span class="cspj-log-val">' + esc(finRes.data.name) + '</span> (' + finRes.data.width + 'x' + finRes.data.height + ', ' + esc(finRes.data.size) + ')');
                    } else {
                        item.status = 'error';
                        var errMsg = (finRes && finRes.data) ? String(finRes.data) : 'Conversion failed on server.';
                        cspjSetError(item.id, errMsg);
                        cspjDbg('ERROR', 'chunk_finish rejected: <span class="cspj-log-err">' + esc(errMsg) + '</span>');
                    }
                    return cspjConvertNext(pending, index + 1);
                }).fail(function (xhr) {
                    item.status = 'error';
                    var body = '';
                    try { body = xhr.responseText || ''; } catch(e) {}
                    var msg = 'HTTP ' + xhr.status + ' ' + (xhr.statusText || '');
                    cspjDbg('ERROR', 'chunk_finish FAILED: <span class="cspj-log-err">' + esc(msg) + '</span>');
                    if (body) { cspjDbg('ERROR', 'Response body: <span class="cspj-log-err">' + esc(body.substring(0, 500)) + '</span>'); }
                    cspjSetError(item.id, msg);
                    return cspjConvertNext(pending, index + 1);
                });
            }

            uploadOne();
        }).fail(function (xhr) {
            item.status = 'error';
            var body = '';
            try { body = xhr.responseText || ''; } catch(e) {}
            var msg = xhr.status === 0
                ? 'Network error (status 0). AJAX URL may be wrong or server unreachable.'
                : ('HTTP ' + xhr.status + ' ' + (xhr.statusText || ''));
            cspjDbg('ERROR', 'chunk_start FAILED: <span class="cspj-log-err">' + esc(msg) + '</span>');
            if (body) { cspjDbg('ERROR', 'Response body: <span class="cspj-log-err">' + esc(body.substring(0, 500)) + '</span>'); }
            cspjSetError(item.id, msg);
            return cspjConvertNext(pending, index + 1);
        });
    }

    function cspjSetBadge(id, type, label) {
        $('#item-' + id).find('.cspj-badge')
            .removeClass('cspj-badge-pending cspj-badge-loading cspj-badge-done cspj-badge-error')
            .addClass('cspj-badge-' + type)
            .html(label);
    }

    function cspjSetError(id, msg) {
        cspjSetBadge(id, 'error', '⚠ Error');
        var $err = $('#err-' + id);
        $('#errtxt-' + id).html('<strong style="color:#991b1b;display:block;margin-bottom:4px">Error:</strong>' + esc(msg));
        $err.css('display', 'flex');
    }

    function cspjRenderResult(item) {
        var r = item.result;
        $('#cspj-results-list').append(
            '<div class="cspj-result-item" id="result-' + item.id + '">' +
                '<img class="cspj-result-thumb" src="' + r.url + '" alt="">' +
                '<div class="cspj-result-info">' +
                    '<div class="cspj-result-name" id="rname-' + item.id + '">' + esc(r.name) + '</div>' +
                    '<div class="cspj-result-meta">' + r.width + ' × ' + r.height + ' · ' + r.size + '</div>' +
                '</div>' +
                '<div class="cspj-result-btns">' +
                    '<a href="' + r.url + '" download="' + esc(r.name) + '" class="cspj-btn-download">↓ Download</a>' +
                    '<button class="cspj-btn-library" data-path="' + esc(r.path) + '" data-url="' + r.url + '" data-orig="' + esc(r.name.replace(/\.jpe?g$/i, '')) + '" data-rid="' + item.id + '">💾 Add to Media</button>' +
                    '<button class="cspj-btn-remove-result" data-rid="' + item.id + '" data-path="' + esc(r.path) + '" title="Remove from list" style="background:none;border:none;font-size:18px;color:var(--csc-muted);cursor:pointer;padding:0 4px;line-height:1">&times;</button>' +
                '</div>' +
            '</div>'
        );
    }

    // Rename + add to library
    $(document).on('click', '.cspj-btn-library', function () {
        var $btn = $(this);
        cspjPendingLibrary = {
            path: $btn.data('path'), url: $btn.data('url'),
            orig: $btn.data('orig'), resultId: $btn.data('rid'), $btn: $btn
        };
        $('#cspj-rename-input').val(cspjPendingLibrary.orig);
        $('#cspj-rename-error').hide().text('');
        $('#cspj-rename-confirm').prop('disabled', false).html('💾 Add to Library');
        $('#cspj-popup-rename').css('display', 'flex');
        setTimeout(function () { $('#cspj-rename-input').select(); }, 150);
    });

    $(document).on('click', '#cspj-rename-cancel, #cspj-rename-cancel-2', function () {
        $('#cspj-popup-rename').hide();
        cspjPendingLibrary = null;
    });

    $(document).on('click', '#cspj-popup-rename', function (e) {
        if ($(e.target).is('#cspj-popup-rename')) { $(this).hide(); cspjPendingLibrary = null; }
    });

    $(document).on('click', '#cspj-rename-confirm', function () {
        if (!cspjPendingLibrary) return;
        var rawName = $('#cspj-rename-input').val().trim();
        if (!rawName) { $('#cspj-rename-error').text('Please enter a filename.').show(); return; }

        $('#cspj-rename-confirm').prop('disabled', true).text('Saving…');
        $.post(CSC.ajax_url, {
            action: 'cscc_pj_add_to_library', nonce: CSC.nonce,
            path: cspjPendingLibrary.path, url: cspjPendingLibrary.url, new_name: rawName
        }, function (res) {
            if (res.success) {
                $('#cspj-popup-rename').hide();
                var $btn = cspjPendingLibrary.$btn;
                var rid  = cspjPendingLibrary.resultId;
                cspjPendingLibrary = null;
                $('#rname-' + rid).text(res.data.final_name);
                $btn.replaceWith('<a href="' + res.data.edit_url + '" class="cspj-library-link" target="_blank">✓ View in Library</a>');
                cspjDbg('OK', 'Added to library: <span class="cspj-log-val">' + esc(res.data.final_name) + '</span>');
            } else {
                $('#cspj-rename-error').text(res.data || 'Unknown error.').show();
                $('#cspj-rename-confirm').prop('disabled', false).html('💾 Add to Library');
            }
        }).fail(function () {
            $('#cspj-rename-error').text('Request failed.').show();
            $('#cspj-rename-confirm').prop('disabled', false).html('💾 Add to Library');
        });
    });

    $(document).on('keydown', '#cspj-rename-input', function (e) {
        if (e.key === 'Enter') $('#cspj-rename-confirm').trigger('click');
    });

    // Clear queue
    $('#cspj-clear-all').on('click', function () {
        cspjFiles = [];
        $('#cspj-file-list').empty();
        $('#cspj-queue-card').hide();
        $('#cspj-results').hide();
        $('#cspj-results-list').empty();
        cspjDbg('INFO', 'Queue cleared');
    });

    // Delete individual result from converted files list
    $(document).on('click', '.cspj-btn-remove-result', function () {
        var rid  = $(this).data('rid');
        var path = $(this).data('path');
        var $row = $('#result-' + rid);
        // Delete the converted file from server
        $.post(CSC.ajax_url, {
            action: 'cscc_pj_delete_converted', nonce: CSC.nonce, path: path
        }, function (res) {
            if (res.success) {
                cspjDbg('OK', 'Deleted converted file from disk');
            } else {
                cspjDbg('WARN', 'Could not delete file from disk: <span class="cspj-log-err">' + esc(res.data) + '</span>');
            }
        });
        // Remove from DOM immediately
        $row.fadeOut(200, function () {
            $(this).remove();
            if ($('#cspj-results-list').children().length === 0) {
                $('#cspj-results').hide();
            }
        });
        // Remove from files array
        cspjFiles = cspjFiles.filter(function (f) { return f.id !== rid; });
    });

    // Debug console controls
    $(document).on('click', '#cspj-debug-toggle', function () {
        var $log = $('#cspj-debug-log');
        var $env = $('#cspj-debug-env');
        if ($log.is(':visible')) { $log.hide(); $env.hide(); $(this).text('▶'); }
        else { $log.show(); $env.show(); $(this).text('▼'); }
    });
    $(document).on('click', '#cspj-debug-clear', function () {
        cspjDebugLog = [];
        $('#cspj-debug-log').empty();
        cspjDbg('INFO', 'Console cleared');
    });
    $(document).on('click', '#cspj-debug-copy', function () {
        var text = cspjDebugLog.map(function (e) {
            return e.time + ' [' + e.tag + '] ' + e.msg.replace(/<[^>]+>/g, '');
        }).join('\n');
        text = '=== CSPJ Debug Log ===\n' + $('#cspj-debug-env').text() + '\n\n' + text;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                cspjDbg('OK', 'Log copied (' + cspjDebugLog.length + ' entries)');
            });
        }
    });

    function cspjFmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // SITE HEALTH
    // ═════════════════════════════════════════════════════════════════════════

    function healthFormatBytes(b) {
        if (b < 0) return '·';
        if (b === 0) return '0 B';
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }

    function healthRenderData(d) {
        // RAG bar
        var ragColors = { red: '#c62828', amber: '#e65100', green: '#2e7d32', grey: '#78909c' };
        var ragBgs    = { red: '#ffebee', amber: '#fff3e0', green: '#e8f5e9', grey: '#eceff1' };
        var ragLabels = {
            red:   'Critical, Less than 3 months of storage remaining',
            amber: 'Warning, Less than 6 months of storage remaining',
            green: 'Healthy, More than 6 months of storage remaining',
            grey:  'Collecting data, need at least 2 weekly snapshots'
        };

        var rag = d.disk_rag || 'grey';
        $('#csc-health-rag-bar').css('background', ragBgs[rag]);
        $('#csc-health-rag-dot').css('background', ragColors[rag]);
        $('#csc-health-rag-label').text(rag === 'grey' ? 'Collecting Data' : rag.charAt(0).toUpperCase() + rag.slice(1)).css('color', ragColors[rag]);
        $('#csc-health-rag-detail').text(ragLabels[rag]).css('color', ragColors[rag]);

        // Disk metrics
        $('#hm-disk-used').text(healthFormatBytes(d.disk_used));
        $('#hm-disk-free').text(healthFormatBytes(d.disk_free));
        $('#hm-disk-total').text(healthFormatBytes(d.disk_total));
        $('#hm-db-size').text(healthFormatBytes(d.db_size));
        $('#hm-growth').text(d.growth_per_week > 0 ? healthFormatBytes(d.growth_per_week) + '/wk' : (d.weekly_count >= 2 ? 'Stable / shrinking' : 'Collecting…'));
        if (d.weeks_remaining > 104) {
            $('#hm-weeks-left').text('>> 2 Years').css('color', '#2e7d32');
        } else if (d.weeks_remaining > 0) {
            var wk = Math.round(d.weeks_remaining);
            var wlColor = d.disk_rag === 'red' ? '#c62828' : (d.disk_rag === 'amber' ? '#e65100' : '#2e7d32');
            $('#hm-weeks-left').text(wk + ' weeks').css('color', wlColor);
        } else if (d.growth_per_week <= 0 && d.weekly_count >= 2) {
            $('#hm-weeks-left').text('Stable').css('color', '#2e7d32');
        } else {
            $('#hm-weeks-left').text('·').css('color', '');
        }

        // CPU, show percentage with load average in parentheses
        var cpuNow = d.cpu_pct_now >= 0 ? d.cpu_pct_now + '%' : '·';
        if (d.cpu_load_now >= 0) { cpuNow += ' (load ' + d.cpu_load_now.toFixed(2) + ')'; }
        $('#hm-cpu-now').text(cpuNow);
        $('#hm-cpu-24h').text(d.cpu_pct_max_24h >= 0 ? d.cpu_pct_max_24h + '%' : (d.cpu_max_24h >= 0 ? d.cpu_max_24h.toFixed(2) + ' load' : '·'));
        $('#hm-cpu-7d').text(d.cpu_pct_max_7d >= 0 ? d.cpu_pct_max_7d + '%' : (d.cpu_max_7d >= 0 ? d.cpu_max_7d.toFixed(2) + ' load' : '·'));

        // Memory, show percentage with bytes in parentheses
        var memNow = d.mem_pct_now >= 0 ? d.mem_pct_now + '%' : '·';
        if (d.mem_used_now >= 0 && d.mem_total > 0) { memNow += ' (' + healthFormatBytes(d.mem_used_now) + ' / ' + healthFormatBytes(d.mem_total) + ')'; }
        $('#hm-mem-now').text(memNow);
        $('#hm-mem-24h').text(d.mem_pct_max_24h >= 0 ? d.mem_pct_max_24h + '%' : (d.mem_max_24h >= 0 ? healthFormatBytes(d.mem_max_24h) : '·'));
        $('#hm-mem-7d').text(d.mem_pct_max_7d >= 0 ? d.mem_pct_max_7d + '%' : (d.mem_max_7d >= 0 ? healthFormatBytes(d.mem_max_7d) : '·'));

        // Max resource, render as 3 equal cards matching the row above
        if (d.max_resource_now !== undefined) {
            var $grid = $('#hm-mem-7d').closest('[style*="grid"]');
            if ($grid.length && !$('#hm-maxres-now').length) {
                $grid.after('<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px">' +
                    '<div class="csc-health-metric"><div class="csc-health-metric-label">Max Resource (now)</div><div class="csc-health-metric-value" id="hm-maxres-now">&middot;</div></div>' +
                    '<div class="csc-health-metric"><div class="csc-health-metric-label">Max Resource (24h)</div><div class="csc-health-metric-value" id="hm-maxres-24h">&middot;</div></div>' +
                    '<div class="csc-health-metric"><div class="csc-health-metric-label">Max Resource (7d)</div><div class="csc-health-metric-value" id="hm-maxres-7d">&middot;</div></div>' +
                '</div>');
            }
            if (d.max_resource_now >= 0) $('#hm-maxres-now').text(d.max_resource_now + '%');
            if (d.max_resource_24h >= 0) $('#hm-maxres-24h').text(d.max_resource_24h + '%');
            if (d.max_resource_7d >= 0) $('#hm-maxres-7d').text(d.max_resource_7d + '%');
        }

        // Data status
        $('#hm-hourly-count').text(d.hourly_count);
        $('#hm-weekly-count').text(d.weekly_count);
        $('#hm-last-hourly').text(d.last_hourly || 'Never');
        $('#hm-last-weekly').text(d.last_weekly || 'Never');
        $('#hm-data-span').text(d.weeks_of_data > 0 ? d.weeks_of_data : '0');

        $('#csc-health-loading').hide();
        $('#csc-health-content').show();
    }

    function healthLoad() {
        $.post(CSC.ajax_url, { action: 'cscc_health_get', nonce: CSC.nonce }, function(resp) {
            if (resp.success) { healthRenderData(resp.data); }
            else { $('#csc-health-loading').text('Failed to load health data.'); }
        }).fail(function() { $('#csc-health-loading').text('Network error loading health data.'); });
    }

    // Load health data when tab is shown
    var healthLoaded = false;
    $(document).on('click', '.csc-tab[data-tab="site-health"]', function() {
        if (!healthLoaded) { healthLoad(); healthLoaded = true; }
    });

    // Load on page load: site-health is now the default tab, or if URL specifies it
    if (window.location.search.indexOf('tab=site-health') !== -1 || !window.location.search.match(/tab=/)) {
        healthLoad(); healthLoaded = true;
    }

    $('#btn-health-refresh').on('click', function() {
        $(this).prop('disabled', true).html('⏳ Loading…');
        $.post(CSC.ajax_url, { action: 'cscc_health_get', nonce: CSC.nonce }, function(resp) {
            $('#btn-health-refresh').prop('disabled', false).html('🔄 Refresh');
            if (resp.success) { healthRenderData(resp.data); }
        }).fail(function() { $('#btn-health-refresh').prop('disabled', false).html('🔄 Refresh'); });
    });

    $('#btn-health-collect').on('click', function() {
        $('#csc-collect-modal').css('display', 'flex');
    });
    $('#btn-collect-cancel').on('click', function() {
        $('#csc-collect-modal').hide();
    });
    $('#csc-collect-modal').on('click', function(e) {
        if (e.target === this) $(this).hide();
    });
    $('#btn-collect-confirm').on('click', function() {
        $('#csc-collect-modal').hide();
        var $b = $('#btn-health-collect').prop('disabled', true).html('⏳ Collecting…');
        $.post(CSC.ajax_url, { action: 'cscc_health_collect_now', nonce: CSC.nonce }, function(resp) {
            $b.prop('disabled', false).html('📊 Collect Metrics Now');
            if (resp.success && resp.data.health) { healthRenderData(resp.data.health); }
        }).fail(function() { $b.prop('disabled', false).html('📊 Collect Metrics Now'); });
    });


    // Sysstat test
    $('#btn-sysstat-test').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Testing...');
        var $box = $('#csc-sysstat-status');
        $box.show();
        $('#csc-sysstat-label').text('Testing sysstat...');
        $('#csc-sysstat-icon').text('⏳');
        $('#csc-sysstat-detail').text('');
        $('#csc-sysstat-instructions').hide();

        $.post(CSC.ajax_url, { action: 'cscc_health_sysstat_test', nonce: CSC.nonce }, function(resp) {
            $btn.prop('disabled', false).html('🔧 Test Sysstat');
            if (!resp.success) {
                $('#csc-sysstat-icon').text('❌');
                $('#csc-sysstat-label').text('Test failed');
                $box.css({ background: '#fef2f2', borderColor: '#fecaca' });
                return;
            }
            var d = resp.data;
            if (!d.exec_available) {
                $('#csc-sysstat-icon').text('❌');
                $('#csc-sysstat-label').text('exec() disabled');
                $box.css({ background: '#fef2f2', borderColor: '#fecaca' });
            } else if (!d.sar_installed) {
                $('#csc-sysstat-icon').text('❌');
                $('#csc-sysstat-label').text('sysstat not installed');
                $box.css({ background: '#fef2f2', borderColor: '#fecaca' });
            } else if (!d.sysstat_active) {
                $('#csc-sysstat-icon').text('⚠️');
                $('#csc-sysstat-label').text('sysstat installed but service inactive');
                $('#csc-sysstat-detail').text(d.sar_version + ' at ' + d.sar_path);
                $box.css({ background: '#fffbeb', borderColor: '#fde68a' });
            } else if (!d.sar_has_data) {
                $('#csc-sysstat-icon').text('⚠️');
                $('#csc-sysstat-label').text('sysstat active, no data yet');
                $('#csc-sysstat-detail').text(d.sar_version + ', wait 10 minutes for first samples');
                $box.css({ background: '#fffbeb', borderColor: '#fde68a' });
            } else {
                $('#csc-sysstat-icon').text('✅');
                $('#csc-sysstat-label').text('sysstat working');
                $('#csc-sysstat-detail').text(d.sar_version + ' | ' + d.sar_samples + ' samples/hr | CPU ' + d.cpu_pct_now + '% | Mem ' + d.mem_pct_now + '%');
                $box.css({ background: '#f0fdf4', borderColor: '#bbf7d0' });
            }
            if (d.instructions) {
                $('#csc-sysstat-instructions').text(d.instructions).show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('🔧 Test Sysstat');
            $('#csc-sysstat-icon').text('❌');
            $('#csc-sysstat-label').text('Network error');
            $box.css({ background: '#fef2f2', borderColor: '#fecaca' });
        });
    });

    // Copy log to clipboard
    $(document).on('click', '.btn-copy-log', function() {
        var $btn = $(this);
        var text = $btn.closest('.csc-card').find('.csc-terminal').text();
        navigator.clipboard.writeText(text).then(function() {
            $btn.html('✅ Copied!');
            setTimeout(function() { $btn.html('&#128203; Copy'); }, 2000);
        }).catch(function() {
            $btn.html('❌ Failed');
            setTimeout(function() { $btn.html('&#128203; Copy'); }, 2000);
        });
    });

    // ── Cron Management ────────────────────────────────────────────────────────

    var ROW_COLORS = [
        '#2196F3','#4CAF50','#FF9800','#9C27B0','#F44336',
        '#00BCD4','#795548','#607D8B','#E91E63','#009688',
        '#3F51B5','#8BC34A','#FF5722','#FFC107','#673AB7'
    ];


    /**
     * Render the 24-hour cron timeline.
     * Labels are HTML (selectable text + trash buttons); canvas draws chart only.
     * @param {Array}  events     - event objects from AJAX response
     * @param {number} serverTime - Unix timestamp for "now"
     * @param {Array}  congestion - congestion bucket objects
     */
    function cscFmtDuration(ms) {
        if (ms >= 60000) { return Math.round(ms / 60000) + 'm ' + Math.round((ms % 60000) / 1000) + 's'; }
        if (ms >= 1000)  { return (ms / 1000).toFixed(1) + 's'; }
        return ms + 'ms';
    }

    function cscRenderCronTimeline(events, serverTime, congestion) {
        var canvas   = document.getElementById('csc-cron-timeline');
        var labelsEl = document.getElementById('csc-cron-timeline-labels');
        if (!canvas || !canvas.getContext) { return; }

        // Filter to events that actually fire in the next 24h
        var visible = events.filter(function(e) { return e.occurrences && e.occurrences.length > 0; });
        if (visible.length === 0) {
            canvas.style.display  = 'none';
            if (labelsEl) { labelsEl.style.display = 'none'; }
            return;
        }
        canvas.style.display = 'block';
        if (labelsEl) { labelsEl.style.display = ''; }

        var dpr        = window.devicePixelRatio || 1;
        var LABEL_W    = 260;
        var hasPlugins = visible.some(function(e) { return e.plugin_name; });
        var HEADER_H   = 30;
        var ROW_H      = hasPlugins ? 36 : 26;
        var FOOT_H     = 4;
        var wrap       = canvas.parentElement;
        var totalW     = wrap.clientWidth || 700;
        var cssW       = Math.max(totalW - LABEL_W, 200); // canvas = chart area only
        var cssH       = HEADER_H + visible.length * ROW_H + FOOT_H;

        // ── HTML Labels div ─────────────────────────────────────────────────
        if (labelsEl) {
            var dotColorMap = { core: '#1976d2', active: '#2e7d32', inactive: '#e65100', not_installed: '#b71c1c', unknown: '#78909c' };
            var rows = '<div class="csc-tl-header"></div>';
            visible.forEach(function(ev, i) {
                var bg = i % 2 === 0 ? 'rgba(0,0,0,0.025)' : 'transparent';
                var dotColor = dotColorMap[ev.plugin_status] || dotColorMap.unknown;
                var plugLine = '';
                if (hasPlugins) {
                    plugLine = '<div class="csc-tl-plugin">'
                        + '<span class="csc-tl-dot" style="background:' + dotColor + '"></span>'
                        + $('<span>').text(ev.plugin_name || 'Unknown').html()
                        + '</div>';
                }
                var hookEsc = $('<span>').text(ev.hook).html();
                rows += '<div class="csc-tl-row" style="height:' + ROW_H + 'px;background:' + bg + '">'
                    + '<div class="csc-tl-row-text">'
                    +   '<div class="csc-tl-hook" title="' + hookEsc + '">' + hookEsc + '</div>'
                    +   plugLine
                    + '</div>'
                    + '<button class="csc-cron-del-btn" data-hook="' + hookEsc + '" title="Move to recycle bin">&#128465;</button>'
                    + '</div>';
            });
            labelsEl.innerHTML = rows;
        }

        // ── Canvas (chart area only, x=0 is left edge of chart) ────────────
        canvas.width  = cssW * dpr;
        canvas.height = cssH * dpr;
        canvas.style.width  = cssW + 'px';
        canvas.style.height = cssH + 'px';

        var ctx   = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        var chartW    = cssW;
        var nowTs     = serverTime;
        var windowSec = 86400;

        // Background
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, cssW, cssH);

        // Congestion zone highlights
        congestion.forEach(function(zone) {
            var x = (zone.offset_seconds / windowSec) * chartW;
            var w = Math.max((300 / windowSec) * chartW, 4);
            ctx.fillStyle = 'rgba(220, 53, 69, 0.13)';
            ctx.fillRect(x - w * 0.5, HEADER_H, w * 2, cssH - HEADER_H);
        });

        // "Now" marker at x=0, line only, no label (avoids clash with first hour tick)
        ctx.textBaseline = 'middle';
        ctx.strokeStyle  = '#bbb';
        ctx.lineWidth    = 1.5;
        ctx.beginPath();
        ctx.moveTo(0, HEADER_H - 6);
        ctx.lineTo(0, cssH);
        ctx.stroke();

        // Hour grid lines snapped to clock hours (HH:00)
        var firstHourTs = Math.ceil(nowTs / 3600) * 3600; // next whole hour >= now
        for (var h = 0; ; h++) {
            var hourTs = firstHourTs + h * 3600;
            if (hourTs > nowTs + windowSec) { break; }
            var gx = ((hourTs - nowTs) / windowSec) * chartW;
            ctx.strokeStyle = '#ebebeb';
            ctx.lineWidth   = 1;
            ctx.beginPath();
            ctx.moveTo(gx, HEADER_H - 6);
            ctx.lineTo(gx, cssH);
            ctx.stroke();

            ctx.fillStyle = '#888';
            ctx.font      = '10px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';
            ctx.textAlign = 'center';
            var tickDate  = new Date(hourTs * 1000);
            var hh = tickDate.getHours();
            ctx.fillText((hh < 10 ? '0' : '') + hh + ':00', gx, HEADER_H / 2);
        }

        // Separator below header
        ctx.strokeStyle = '#ddd';
        ctx.lineWidth   = 1;
        ctx.beginPath();
        ctx.moveTo(0, HEADER_H - 0.5);
        ctx.lineTo(cssW, HEADER_H - 0.5);
        ctx.stroke();

        // Rows
        visible.forEach(function(ev, i) {
            var rowY  = HEADER_H + i * ROW_H;
            var midY  = rowY + ROW_H / 2;
            var color = ROW_COLORS[i % ROW_COLORS.length];
            var occs  = ev.occurrences;

            // Alternating row bg
            if (i % 2 === 0) {
                ctx.fillStyle = 'rgba(0,0,0,0.025)';
                ctx.fillRect(0, rowY, cssW, ROW_H);
            }

            // Duration bars
            var BAR_H  = ROW_H * 0.55;
            var estSec = ev.interval > 0 ? Math.min(ev.interval / 2, 300) : 60;
            var barW   = Math.max((estSec / windowSec) * chartW, 8);

            // Dashed spine
            if (occs.length > 1) {
                var x0 = ((occs[0] - nowTs) / windowSec) * chartW;
                var x1 = ((occs[occs.length - 1] - nowTs) / windowSec) * chartW;
                ctx.strokeStyle = color;
                ctx.globalAlpha = 0.25;
                ctx.lineWidth   = 1;
                ctx.setLineDash([3, 3]);
                ctx.beginPath();
                ctx.moveTo(x0, midY);
                ctx.lineTo(x1, midY);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.globalAlpha = 1;
            }

            occs.forEach(function(t) {
                var bx  = ((t - nowTs) / windowSec) * chartW;
                var r   = 3;
                var bx0 = bx - barW / 2, by0 = midY - BAR_H / 2, bw = barW, bh = BAR_H;
                ctx.fillStyle   = color;
                ctx.globalAlpha = 0.75;
                ctx.beginPath();
                ctx.moveTo(bx0 + r, by0);
                ctx.lineTo(bx0 + bw - r, by0);
                ctx.arcTo(bx0 + bw, by0, bx0 + bw, by0 + r, r);
                ctx.lineTo(bx0 + bw, by0 + bh - r);
                ctx.arcTo(bx0 + bw, by0 + bh, bx0 + bw - r, by0 + bh, r);
                ctx.lineTo(bx0 + r, by0 + bh);
                ctx.arcTo(bx0, by0 + bh, bx0, by0 + bh - r, r);
                ctx.lineTo(bx0, by0 + r);
                ctx.arcTo(bx0, by0, bx0 + r, by0, r);
                ctx.closePath();
                ctx.fill();
                ctx.globalAlpha = 1;
            });

            // Row border
            ctx.strokeStyle = '#f0f0f0';
            ctx.lineWidth   = 0.5;
            ctx.beginPath();
            ctx.moveTo(0, rowY + ROW_H);
            ctx.lineTo(cssW, rowY + ROW_H);
            ctx.stroke();
        });
    }

    /**
     * Format a Unix timestamp as a human-readable relative or absolute string.
     */
    function cscFmtCronTime(ts, now) {
        var diff = ts - now;
        if (diff < 0) {
            var ago = Math.abs(diff);
            if (ago < 3600)  { return Math.round(ago / 60) + ' min overdue'; }
            if (ago < 86400) { return Math.round(ago / 3600) + 'h overdue'; }
            return Math.round(ago / 86400) + 'd overdue';
        }
        if (diff < 60)    { return 'In < 1 min'; }
        if (diff < 3600)  { return 'In ' + Math.round(diff / 60) + ' min'; }
        if (diff < 86400) { return 'In ' + Math.round(diff / 3600) + 'h'; }
        return 'In ' + Math.round(diff / 86400) + 'd';
    }

    /**
     * Populate the events table from the AJAX response.
     * @param {Array}  events  - cron event objects
     * @param {number} now     - server Unix timestamp
     * @param {Object} runLog  - map of hook => {last_run, duration_ms}
     */
    // Plugin status config
    var PLUGIN_STATUS = {
        'core':          { label: 'Core',          color: '#1976d2', bg: '#e3f2fd' },
        'active':        { label: 'Active',         color: '#2e7d32', bg: '#e8f5e9' },
        'inactive':      { label: 'Installed',      color: '#e65100', bg: '#fff3e0' },
        'not_installed': { label: 'Not installed',  color: '#b71c1c', bg: '#ffebee' },
        'unknown':       { label: '?',              color: '#78909c', bg: '#f5f5f5' }
    };

    function cscPluginBadge(name, status) {
        var cfg = PLUGIN_STATUS[status] || PLUGIN_STATUS['unknown'];
        var dot = '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' + cfg.color + ';margin-right:4px;vertical-align:middle"></span>';
        var badge = '<span style="font-size:10px;padding:1px 5px;border-radius:3px;background:' + cfg.bg + ';color:' + cfg.color + ';white-space:nowrap">' + cfg.label + '</span>';
        if (!name) { return '<span style="color:#aaa;font-size:11px">&middot;</span>'; }
        return '<span style="font-size:11px">' + dot + $('<span>').text(name).html() + '</span><br>' + badge;
    }

    /**
     * Populate the events table from the AJAX response.
     * @param {Array}  events  - cron event objects
     * @param {number} now     - server Unix timestamp
     * @param {Object} runLog  - map of hook => {last_run, duration_ms}
     */
    function cscRenderCronTable(events, now, runLog) {
        var $tbody = $('#csc-cron-events-body');
        if (!events || events.length === 0) {
            $tbody.html('<tr><td colspan="7" style="text-align:center;padding:12px;color:#666">No scheduled events found.</td></tr>');
            return;
        }
        var rows = events.map(function(ev) {
            var overdueCls = ev.overdue ? ' csc-cron-row-overdue' : '';
            var statusHtml = ev.overdue
                ? '<span class="csc-cron-badge csc-cron-badge-red">Overdue</span>'
                : '<span class="csc-cron-badge csc-cron-badge-green">OK</span>';
            var timeHtml = cscFmtCronTime(ev.next_run, now);

            var lastRunHtml = '&middot;';
            if (runLog && runLog[ev.hook]) {
                var lr = runLog[ev.hook];
                var ms = lr.duration_ms;
                var durStr = ms >= 60000
                    ? Math.round(ms / 60000) + 'm ' + Math.round((ms % 60000) / 1000) + 's'
                    : ms >= 1000 ? (ms / 1000).toFixed(1) + 's' : ms + 'ms';
                var diffSec = now - lr.last_run;
                var agoStr = diffSec < 3600
                    ? Math.round(diffSec / 60) + 'm ago'
                    : diffSec < 86400 ? Math.round(diffSec / 3600) + 'h ago'
                    : Math.round(diffSec / 86400) + 'd ago';
                lastRunHtml = '<span title="Last ran ' + agoStr + '" style="font-size:11px">' + durStr + '<br><span style="color:#888;font-size:10px">' + agoStr + '</span></span>';
            }

            var hookHtml  = $('<span>').text(ev.hook).html();
            var pluginHtml = cscPluginBadge(ev.plugin_name || null, ev.plugin_status || 'unknown');
            var deleteBtn  = '<button class="csc-cron-del-btn" data-hook="' + hookHtml + '" title="Move to recycle bin">&#128465;</button>';
            return '<tr class="' + overdueCls + '">'
                + '<td class="csc-cron-hook-cell">' + hookHtml + '</td>'
                + '<td style="white-space:nowrap">' + pluginHtml + '</td>'
                + '<td>' + $('<span>').text(ev.schedule).html() + '</td>'
                + '<td>' + timeHtml + '</td>'
                + '<td>' + lastRunHtml + '</td>'
                + '<td>' + statusHtml + '</td>'
                + '<td style="text-align:center">' + deleteBtn + '</td>'
                + '</tr>';
        });
        $tbody.html(rows.join(''));
    }

    /**
     * Render the WP-Cron health banner.
     */
    function cscRenderCronHealth(data) {
        var $banner = $('#csc-cron-health-banner');
        var disabled    = data.wp_cron_disabled;
        var overdue     = data.overdue_count || 0;
        var congested   = data.congestion && data.congestion.length > 0;

        var icon, cls, msg;
        if (disabled) {
            icon = '&#9989;'; cls = 'csc-cron-health-ok';
            msg = 'DISABLE_WP_CRON is set, pseudo-cron is off. Ensure a real server cron is running.';
        } else if (overdue > 3 || congested) {
            icon = '&#9888;'; cls = 'csc-cron-health-warn';
            var parts = [];
            if (overdue > 0) { parts.push(overdue + ' overdue event' + (overdue > 1 ? 's' : '')); }
            if (congested)   { parts.push(data.congestion.length + ' congestion zone' + (data.congestion.length > 1 ? 's' : '')); }
            msg = 'WP-Cron is running (pseudo-cron). Issues detected: ' + parts.join(', ') + '.';
        } else {
            icon = '&#9989;'; cls = 'csc-cron-health-ok';
            msg  = 'WP-Cron is running (pseudo-cron). ' + (overdue === 0 ? 'No overdue events.' : overdue + ' overdue event(s).');
        }

        $banner
            .removeClass('csc-cron-health-loading csc-cron-health-ok csc-cron-health-warn csc-cron-health-error')
            .addClass(cls)
            .html('<span class="csc-cron-health-icon">' + icon + '</span><span>' + msg + '</span>');
    }

    /**
     * Render the congestion warning box.
     */
    function cscRenderCongestion(congestion, now) {
        var $warn = $('#csc-cron-congestion-warn');
        if (!congestion || congestion.length === 0) {
            $warn.hide();
            return;
        }
        var lines = congestion.map(function(zone) {
            var zoneDate = new Date((now + zone.offset_seconds) * 1000);
            var hh = zoneDate.getHours(), mm = zoneDate.getMinutes();
            var timeLabel = (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
            var hooks = zone.hooks.slice(0, 4).join(', ') + (zone.hooks.length > 4 ? ', ...' : '');
            return '<li><strong>' + zone.count + ' jobs</strong> at ' + timeLabel + ': ' + hooks + '</li>';
        });
        $warn.html(
            '<strong>&#9888; Cron Congestion Detected</strong>, multiple jobs firing in the same 5-minute window may cause CPU spikes and slow page responses.' +
            '<ul style="margin:6px 0 0 16px;padding:0">' + lines.join('') + '</ul>'
        ).show();
    }

    /** Load cron status from the server and render all components. */
    function cscLoadCronStatus() {
        $('#csc-cron-health-banner')
            .removeClass('csc-cron-health-ok csc-cron-health-warn csc-cron-health-error')
            .addClass('csc-cron-health-loading')
            .html('<span class="csc-cron-spinner"></span> Loading cron status&hellip;');
        $('#csc-cron-events-body').html('<tr><td colspan="4" style="text-align:center;padding:12px;color:#666">Loading&hellip;</td></tr>');

        $.post(CSC.ajax_url, { action: 'cscc_cron_status', nonce: CSC.nonce }, function(resp) {
            if (!resp.success) {
                $('#csc-cron-health-banner').addClass('csc-cron-health-error').html('&#10060; Failed to load cron data.');
                return;
            }
            var d = resp.data;
            cscRenderCronHealth(d);
            cscRenderCronTable(d.events, d.server_time, d.run_log || {});
            cscRenderCongestion(d.congestion, d.server_time);
            cscRenderRecycleBin(d.recycle_bin || []);
            // Slight delay to ensure the card is visible and has rendered width
            setTimeout(function() {
                cscRenderCronTimeline(d.events, d.server_time, d.congestion);
            }, 50);
        }).fail(function() {
            $('#csc-cron-health-banner').addClass('csc-cron-health-error').html('&#10060; Network error loading cron data.');
        });
    }

    // Load when Cron tab is shown
    var cronLoaded = false;
    $(document).on('click', '.csc-tab[data-tab="cron"]', function() {
        if (!cronLoaded) { cscLoadCronStatus(); cronLoaded = true; }
    });
    // Also load if Cron is the active tab on page load
    if ($('.csc-tab[data-tab="cron"]').hasClass('csc-tab-active') ||
        window.location.search.indexOf('tab=cron') !== -1) {
        cscLoadCronStatus(); cronLoaded = true;
    }

    // Refresh button
    $('#btn-cron-refresh').on('click', function() {
        cronLoaded = false;
        cscLoadCronStatus();
        cronLoaded = true;
    });

    // Copy crontab command
    $('#btn-copy-cron-cmd').on('click', function() {
        var $btn = $(this);
        var raw  = $('#csc-cron-cmd').text();
        navigator.clipboard.writeText(raw).then(function() {
            $btn.html('&#10003; Copied!');
            setTimeout(function() { $btn.html('Copy'); }, 2000);
        }).catch(function() {
            $btn.html('&#10007; Failed');
            setTimeout(function() { $btn.html('Copy'); }, 2000);
        });
    });

    // Re-render timeline on window resize
    var cronResizeTimer;
    $(window).on('resize', function() {
        clearTimeout(cronResizeTimer);
        cronResizeTimer = setTimeout(function() {
            if (!cronLoaded) { return; }
            $.post(CSC.ajax_url, { action: 'cscc_cron_status', nonce: CSC.nonce }, function(resp) {
                if (resp.success) {
                    var d = resp.data;
                    cscRenderCronTimeline(d.events, d.server_time, d.congestion);
                }
            });
        }, 250);
    });

    // Manual trigger buttons
    $(document).on('click', '#btn-cron-run-db, #btn-cron-run-img', function() {
        var $btn  = $(this);
        var hook  = $btn.data('hook');
        var $res  = $('#csc-cron-run-result');
        $btn.prop('disabled', true).prepend('&#9203; ');
        $res.hide();

        $.post(CSC.ajax_url, { action: 'cscc_cron_run_now', nonce: CSC.nonce, hook: hook }, function(resp) {
            $btn.prop('disabled', false).find('span').remove();
            // Restore button text
            $btn.html($btn.data('hook') === 'cscc_scheduled_db_cleanup'
                ? '&#9654; Run DB Cleanup Now'
                : '&#9654; Run Media Cleanup Now');
            if (resp.success) {
                $res.css({ background: '#f0fdf4', border: '1px solid #86efac', color: '#166534' })
                    .html('&#10003; Hook <code>' + hook + '</code> fired successfully.')
                    .show();
            } else {
                $res.css({ background: '#fef2f2', border: '1px solid #fca5a5', color: '#991b1b' })
                    .html('&#10007; ' + (resp.data || 'Failed to run hook.'))
                    .show();
            }
            setTimeout(function() { $res.hide(); }, 5000);
        }).fail(function() {
            $btn.prop('disabled', false);
            $res.css({ background: '#fef2f2', border: '1px solid #fca5a5', color: '#991b1b' })
                .html('&#10007; Network error.').show();
            setTimeout(function() { $res.hide(); }, 4000);
        });
    });

    /**
     * Render the recycle bin table.
     * @param {Array} bin - array of recycle bin entry objects
     */
    function cscRenderRecycleBin(bin) {
        var $tbody = $('#csc-cron-recycle-body');
        if (!bin || bin.length === 0) {
            $tbody.html('<tr><td colspan="5" style="text-align:center;padding:12px;color:#aaa;font-style:italic">Recycle bin is empty.</td></tr>');
            return;
        }
        var rows = bin.map(function(entry) {
            var hookHtml    = $('<span>').text(entry.hook).html();
            var schedHtml   = $('<span>').text(entry.schedule || 'one-time').html();
            var wasDue      = entry.next_run ? new Date(entry.next_run * 1000).toLocaleString() : '&middot;';
            var deletedAt   = entry.deleted_at ? new Date(entry.deleted_at * 1000).toLocaleString() : '&middot;';
            var id          = $('<span>').text(entry.id).html();
            return '<tr>'
                + '<td class="csc-cron-hook-cell">' + hookHtml + '</td>'
                + '<td>' + schedHtml + '</td>'
                + '<td style="white-space:nowrap;font-size:11px">' + wasDue + '</td>'
                + '<td style="white-space:nowrap;font-size:11px">' + deletedAt + '</td>'
                + '<td style="white-space:nowrap;text-align:right">'
                +   '<button class="csc-btn csc-btn-secondary csc-btn-sm csc-cron-restore-btn" data-id="' + id + '" style="margin-right:4px">&#10226; Restore</button>'
                +   '<button class="csc-btn csc-btn-sm csc-cron-purge-btn" data-id="' + id + '" style="background:#c62828;color:#fff;border-color:#c62828">&#128465; Delete Forever</button>'
                + '</td>'
                + '</tr>';
        });
        $tbody.html(rows.join(''));
    }

    /** Send delete-to-bin request for a cron hook. */
    function cscDeleteCronJob(hook) {
        if (!hook) { return; }
        if (!window.confirm('Move "' + hook + '" to the cron recycle bin?\n\nYou can restore it from the bin below.')) { return; }
        $.post(CSC.ajax_url, { action: 'cscc_cron_delete', nonce: CSC.nonce, hook: hook }, function(resp) {
            if (resp.success) {
                cronLoaded = false;
                cscLoadCronStatus();
                cronLoaded = true;
            } else {
                alert('Could not delete: ' + (resp.data || 'Unknown error'));
            }
        }).fail(function() { alert('Network error deleting cron job.'); });
    }

    // Delete button in the events table
    $(document).on('click', '.csc-cron-del-btn', function() {
        cscDeleteCronJob($(this).data('hook'));
    });

    // Restore button in recycle bin
    $(document).on('click', '.csc-cron-restore-btn', function() {
        var id   = $(this).data('id');
        var $res = $('#csc-cron-recycle-result');
        $.post(CSC.ajax_url, { action: 'cscc_cron_restore', nonce: CSC.nonce, id: id }, function(resp) {
            if (resp.success) {
                cronLoaded = false;
                cscLoadCronStatus();
                cronLoaded = true;
                $res.css({ background: '#f0fdf4', border: '1px solid #86efac', color: '#166534' })
                    .html('&#10003; Restored <code>' + resp.data.restored + '</code>.').show();
                setTimeout(function() { $res.hide(); }, 4000);
            } else {
                $res.css({ background: '#fef2f2', border: '1px solid #fca5a5', color: '#991b1b' })
                    .html('&#10007; ' + (resp.data || 'Restore failed.')).show();
            }
        }).fail(function() {
            $res.css({ background: '#fef2f2', border: '1px solid #fca5a5', color: '#991b1b' })
                .html('&#10007; Network error.').show();
        });
    });

    // Permanently delete button in recycle bin
    $(document).on('click', '.csc-cron-purge-btn', function() {
        if (!window.confirm('Permanently delete this cron job? This cannot be undone.')) { return; }
        var id   = $(this).data('id');
        var $res = $('#csc-cron-recycle-result');
        $.post(CSC.ajax_url, { action: 'cscc_cron_purge_bin', nonce: CSC.nonce, id: id }, function(resp) {
            if (resp.success) {
                cronLoaded = false;
                cscLoadCronStatus();
                cronLoaded = true;
            } else {
                $res.css({ background: '#fef2f2', border: '1px solid #fca5a5', color: '#991b1b' })
                    .html('&#10007; ' + (resp.data || 'Delete failed.')).show();
            }
        }).fail(function() {
            $res.css({ background: '#fef2f2', border: '1px solid #fca5a5', color: '#991b1b' })
                .html('&#10007; Network error.').show();
        });
    });

    // ── End Cron Management ────────────────────────────────────────────────────

    // ── Space Report ──────────────────────────────────────────────────────────

    var cscSpacePath = '';
    var cscSpaceTotal = 0;

    function cscFmtBytes(b) {
        if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
        if (b >= 1048576)    return (b / 1048576).toFixed(1)    + ' MB';
        if (b >= 1024)       return (b / 1024).toFixed(0)       + ' KB';
        return b + ' B';
    }

    function cscSpaceScan(path) {
        cscSpacePath = path || '';
        $('#space-report-loading').show();
        $('#space-report-content, #space-report-error').hide();
        $.post(CSC.ajax_url, { action: 'cscc_space_scan', nonce: CSC.nonce, path: cscSpacePath }, function(resp) {
            $('#space-report-loading').hide();
            if (!resp.success) {
                $('#space-report-error').text(resp.data || 'Scan failed.').show();
                return;
            }
            var d = resp.data;
            if (cscSpacePath === '') { cscSpaceTotal = d.total; }

            // Insight cards (root level only)
            var $ins = $('#space-insights').empty();
            if (d.insights && d.insights.length && !d.rel) {
                var insColors = {
                    error:   { bg: '#fff3f3', border: '#e53935', icon: '🔴', badge: '#e53935', badgeTxt: 'Issue' },
                    warning: { bg: '#fffbf0', border: '#fb8c00', icon: '🟡', badge: '#fb8c00', badgeTxt: 'Waste' },
                    info:    { bg: '#f0f7ff', border: '#1976d2', icon: '🔵', badge: '#1976d2', badgeTxt: 'Info'  },
                };
                $.each(d.insights, function(i, ins) {
                    var c = insColors[ins.level] || insColors.info;
                    var $card = $('<div>').css({
                        background: c.bg,
                        border: '1px solid ' + c.border,
                        'border-left': '4px solid ' + c.border,
                        'border-radius': '6px',
                        padding: '12px 14px',
                    });
                    var $header = $('<div>').css({ display: 'flex', 'align-items': 'center', gap: '8px', 'margin-bottom': '5px', 'flex-wrap': 'wrap' })
                        .append($('<strong>').css({ 'font-size': '13px' }).text(ins.title))
                        .append($('<span>').css({ background: c.badge, color: '#fff', 'font-size': '10px', 'font-weight': '700', padding: '2px 7px', 'border-radius': '3px' }).text(c.badgeTxt));
                    if (ins.savings > 0) {
                        $header.append($('<span>').css({ color: '#2e7d32', 'font-size': '11px', 'font-weight': '700', 'margin-left': 'auto', 'white-space': 'nowrap' }).text('~' + cscFmtBytes(ins.savings) + ' recoverable'));
                    }
                    $card.append($header);
                    $card.append($('<p>').css({ margin: '0 0 6px', 'font-size': '12px', color: '#444', 'line-height': '1.5' }).text(ins.detail));
                    if (ins.drills && ins.drills.length) {
                        var $drills = $('<div>').css({ display: 'flex', gap: '6px', 'flex-wrap': 'wrap' });
                        $.each(ins.drills, function(j, drill) {
                            var rel = drill.rel;
                            $drills.append($('<button>').css({
                                background: 'transparent', border: '1px solid ' + c.border, 'border-radius': '4px',
                                padding: '3px 10px', 'font-size': '12px', color: c.border, cursor: 'pointer'
                            }).text('View ' + drill.label).on('click', function() { cscSpaceScan(rel); }));
                        });
                        $card.append($drills);
                    }
                    $ins.append($card);
                });
                $ins.css('display', 'flex').show();
            } else {
                $ins.hide();
            }

            // Breadcrumb
            var $bc = $('#space-breadcrumb').empty();
            $bc.append($('<a href="#">uploads</a>').css({ color: '#1565c0', cursor: 'pointer', 'text-decoration': 'none' }).on('click', function(e) { e.preventDefault(); cscSpaceScan(''); }));
            if (d.rel) {
                var parts = d.rel.split('/');
                var accum = '';
                $.each(parts, function(i, p) {
                    accum = accum ? accum + '/' + p : p;
                    $bc.append($('<span>').text(' / '));
                    if (i < parts.length - 1) {
                        var rel = accum;
                        $bc.append($('<a href="#">').text(p).css({ color: '#1565c0', cursor: 'pointer', 'text-decoration': 'none' }).on('click', function(e) { e.preventDefault(); cscSpaceScan(rel); }));
                    } else {
                        $bc.append($('<strong>').text(p));
                    }
                });
            }

            // Summary
            var summaryParts = [];
            if (d.dirs.length)  summaryParts.push(d.dirs.length + ' folder' + (d.dirs.length !== 1 ? 's' : ''));
            if (d.files.length) summaryParts.push(d.files.length + ' file' + (d.files.length !== 1 ? 's' : ''));
            summaryParts.push('Total: ' + cscFmtBytes(d.total));
            if (cscSpaceTotal > 0 && d.rel) summaryParts.push(Math.round(d.total / cscSpaceTotal * 100) + '% of uploads');

            var $sum = $('#space-summary').empty().css({ display: 'flex', 'flex-wrap': 'wrap', gap: '6px', 'align-items': 'flex-start' });
            var $text = $('<div>').css('flex', '1 1 100%').text(summaryParts.join('   |   '));
            $sum.append($text);

            // Type breakdown (drill-down only)
            if (d.type_breakdown && (d.type_breakdown.scaled || Object.keys(d.type_breakdown.by_ext || {}).length)) {
                var tb = d.type_breakdown;
                var $breakdown = $('<div>').css({ display: 'flex', gap: '8px', 'flex-wrap': 'wrap', 'font-size': '12px', 'margin-top': '4px' });

                // Image scaled vs original pill
                if (tb.scaled > 0 || tb.original > 0) {
                    var total_imgs = tb.scaled + tb.original;
                    var scaledPct = total_imgs > 0 ? Math.round(tb.scaled / total_imgs * 100) : 0;
                    $breakdown.append($('<span>').css({
                        background: scaledPct > 60 ? '#fff3cd' : '#e8f5e9',
                        border: '1px solid ' + (scaledPct > 60 ? '#fb8c00' : '#43a047'),
                        'border-radius': '4px', padding: '2px 8px', 'white-space': 'nowrap',
                    }).html('<strong>' + scaledPct + '% scaled copies</strong> (' + tb.scaled.toLocaleString() + ' scaled / ' + tb.original.toLocaleString() + ' originals)'));
                }

                // Top file types
                if (tb.by_ext) {
                    $.each(tb.by_ext, function(ext, bytes) {
                        $breakdown.append($('<span>').css({
                            background: '#f0f4ff', border: '1px solid #c5cae9',
                            'border-radius': '4px', padding: '2px 8px', 'white-space': 'nowrap', 'font-family': 'monospace',
                        }).text('.' + ext + ': ' + cscFmtBytes(bytes)));
                    });
                }
                $sum.append($breakdown);
            }

            // Dirs table
            var $body = $('#space-dirs-body').empty();
            if (!d.dirs.length) {
                $body.append('<tr><td colspan="5" style="padding:12px;color:#888;text-align:center">No subdirectories</td></tr>');
            }
            $.each(d.dirs, function(i, dir) {
                var pct = d.total > 0 ? Math.round(dir.size / d.total * 100) : 0;
                var $row = $('<tr>').css({ 'border-bottom': '1px solid #f0f0f0', cursor: 'pointer' })
                    .hover(function() { $(this).css('background', '#f0f4ff'); }, function() { $(this).css('background', ''); });
                $row.append($('<td>').css({ padding: '9px 12px', 'white-space': 'nowrap' }).html(
                    '<span style="color:#1565c0;font-weight:600">&#128194; ' + $('<span>').text(dir.name).html() + '</span>'
                ));
                $row.append($('<td>').css({ padding: '9px 12px', 'text-align': 'right', 'white-space': 'nowrap', 'font-family': 'monospace' }).text(cscFmtBytes(dir.size)));
                $row.append($('<td>').css({ padding: '9px 12px', 'text-align': 'right', color: '#666' }).text(dir.count.toLocaleString()));
                var $bar = $('<div>').css({ display: 'flex', 'align-items': 'center', gap: '6px' })
                    .append($('<div>').css({ height: '8px', width: pct + '%', 'max-width': '100%', 'min-width': '2px', background: pct > 50 ? '#e53935' : pct > 20 ? '#fb8c00' : '#43a047', 'border-radius': '4px' }))
                    .append($('<span>').css({ 'font-size': '11px', color: '#666', 'white-space': 'nowrap' }).text(pct + '%'));
                $row.append($('<td>').css({ padding: '9px 12px', 'text-align': 'right', 'min-width': '120px' }).append($bar));
                (function($r, d_ref) {
                    var $trash = $('<button>').css({
                        background: 'transparent', border: '1px solid #d32f2f', 'border-radius': '4px',
                        padding: '3px 7px', 'font-size': '13px', cursor: 'pointer', color: '#d32f2f', 'line-height': '1'
                    }).html('&#128465;').attr('title', 'Move to Storage Recycle Bin').on('click', function(e) {
                        e.stopPropagation();
                        if (!window.confirm('Move "' + d_ref.name + '" to the Storage Recycle Bin?\n\nYou can restore it from the bin at any time.')) { return; }
                        var $btn = $(this);
                        $btn.prop('disabled', true).text('…');
                        $.post(CSC.ajax_url, { action: 'cscc_storage_recycle_folder', nonce: CSC.nonce, path: d_ref.rel }, function(res) {
                            if (res.success) {
                                $r.fadeOut(200, function() { $(this).remove(); });
                                cscLoadStorageBin();
                            } else {
                                alert(res.data || 'Failed to move to recycle bin.');
                                $btn.prop('disabled', false).html('&#128465;');
                            }
                        }).fail(function() {
                            alert('Network error.');
                            $btn.prop('disabled', false).html('&#128465;');
                        });
                    });
                    $r.append($('<td>').css({ padding: '9px 12px', 'text-align': 'center' }).append($trash));
                })($row, dir);
                $row.on('click', function() { cscSpaceScan(dir.rel); });
                $body.append($row);
            });

            // Largest files table (recursive, drill-down only)
            if (d.top_files && d.top_files.length) {
                var $tf = $('#space-top-files-body').empty();
                $.each(d.top_files, function(i, f) {
                    var isScaled = /\-\d+x\d+\.[a-z]+$/i.test(f.name);
                    var $row = $('<tr>').css('border-bottom', '1px solid #f0f0f0');
                    $row.append($('<td>').css({ padding: '6px 12px', 'word-break': 'break-all', 'font-size': '12px', color: isScaled ? '#888' : '#222' }).text(f.path));
                    $row.append($('<td>').css({ padding: '6px 12px', 'text-align': 'right', 'white-space': 'nowrap', 'font-family': 'monospace', 'font-size': '12px' }).text(cscFmtBytes(f.size)));
                    $row.append($('<td>').css({ padding: '6px 12px', color: '#888', 'font-size': '12px' }).text(f.ext || '·'));
                    $tf.append($row);
                });
                $('#space-top-files-wrap').show();
            } else {
                $('#space-top-files-wrap').hide();
            }

            $('#space-report-content').show();
        }).fail(function() {
            $('#space-report-loading').hide();
            $('#space-report-error').text('Network error, scan failed.').show();
        });
    }

    $('#btn-space-scan').on('click', function() { cscSpaceScan(''); });

    // Auto-scan + load bin when tab is activated.
    $(document).on('click', '.csc-tab[data-tab="space-report"]', function() {
        if ($('#space-report-content').is(':hidden') && $('#space-report-loading').is(':hidden')) {
            cscSpaceScan('');
        }
        cscLoadStorageBin();
    });

    function cscLoadStorageBin() {
        $.post(CSC.ajax_url, { action: 'cscc_storage_recycle_list', nonce: CSC.nonce }, function(res) {
            var items = (res.success && res.data) ? res.data : [];
            var $body = $('#storage-bin-body').empty();
            if (!items.length) {
                $('#storage-bin-list-wrap').hide();
                $('#storage-bin-empty-msg').show();
                return;
            }
            $('#storage-bin-empty-msg').hide();
            $.each(items, function(i, item) {
                var $row = $('<tr>').css('border-bottom', '1px solid #f0f0f0');
                $row.append($('<td>').css({ padding: '8px 12px', 'font-weight': '600', 'white-space': 'nowrap' }).text(item.original_name));
                $row.append($('<td>').css({ padding: '8px 12px', color: '#666', 'word-break': 'break-all', 'font-size': '12px' }).text(item.original_rel));
                $row.append($('<td>').css({ padding: '8px 12px', 'text-align': 'right', 'font-family': 'monospace', 'font-size': '12px', 'white-space': 'nowrap' }).text(cscFmtBytes(item.size || 0)));
                var d = new Date(item.recycled_at * 1000);
                var dateStr = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
                $row.append($('<td>').css({ padding: '8px 12px', 'text-align': 'center', color: '#888', 'font-size': '12px', 'white-space': 'nowrap' }).text(dateStr));
                (function($r, it) {
                    var $restore = $('<button>').css({
                        background: '#e8f5e9', border: '1px solid #43a047', 'border-radius': '4px',
                        padding: '4px 8px', 'font-size': '12px', cursor: 'pointer', color: '#2e7d32', 'margin-right': '6px', 'white-space': 'nowrap'
                    }).text('↩ Restore').on('click', function() {
                        var $btn = $(this);
                        $btn.prop('disabled', true).text('…');
                        $.post(CSC.ajax_url, { action: 'cscc_storage_recycle_restore', nonce: CSC.nonce, id: it.id }, function(res) {
                            if (res.success) {
                                $r.fadeOut(200, function() { $(this).remove(); cscLoadStorageBin(); });
                                cscSpaceScan(cscSpacePath);
                            } else {
                                alert(res.data || 'Restore failed.');
                                $btn.prop('disabled', false).text('↩ Restore');
                            }
                        }).fail(function() { alert('Network error.'); $btn.prop('disabled', false).text('↩ Restore'); });
                    });
                    var $purge = $('<button>').css({
                        background: '#ffebee', border: '1px solid #e53935', 'border-radius': '4px',
                        padding: '4px 8px', 'font-size': '12px', cursor: 'pointer', color: '#c62828', 'white-space': 'nowrap'
                    }).text('🗑 Delete Forever').on('click', function() {
                        if (!window.confirm('Permanently delete "' + it.original_name + '"?\n\nThis cannot be undone.')) { return; }
                        var $btn = $(this);
                        $btn.prop('disabled', true).text('…');
                        $.post(CSC.ajax_url, { action: 'cscc_storage_recycle_purge', nonce: CSC.nonce, id: it.id }, function(res) {
                            if (res.success) {
                                $r.fadeOut(200, function() { $(this).remove(); cscLoadStorageBin(); });
                            } else {
                                alert(res.data || 'Delete failed.');
                                $btn.prop('disabled', false).text('🗑 Delete Forever');
                            }
                        }).fail(function() { alert('Network error.'); $btn.prop('disabled', false).text('🗑 Delete Forever'); });
                    });
                    $r.append($('<td>').css({ padding: '8px 12px', 'text-align': 'center', 'white-space': 'nowrap' }).append($restore).append($purge));
                })($row, item);
                $body.append($row);
            });
            $('#storage-bin-list-wrap').show();
        });
    }

    $('#btn-storage-bin-empty').on('click', function() {
        if (!window.confirm('Permanently delete ALL items in the Storage Recycle Bin?\n\nThis cannot be undone.')) { return; }
        var $btn = $(this);
        $btn.prop('disabled', true).text('…');
        $.post(CSC.ajax_url, { action: 'cscc_storage_recycle_purge', nonce: CSC.nonce, id: '' }, function(res) {
            $btn.prop('disabled', false).text('Empty Bin');
            if (res.success) { cscLoadStorageBin(); } else { alert(res.data || 'Failed to empty bin.'); }
        }).fail(function() { $btn.prop('disabled', false).text('Empty Bin'); });
    });

    // ── End Space Report ──────────────────────────────────────────────────────

    }); // document.ready

}(jQuery));

(function() {
    var el = document.getElementById('hm-weeks-left');
    if (!el) return;
    var obs = new MutationObserver(function() {
        var t = el.textContent || '';
        if (t.match(/\d{4,}.*wk/i) || t.match(/~\d+.*mo/i)) {
            el.textContent = '>> 2 Years';
            el.style.color = '#2e7d32';
        }
    });
    obs.observe(el, { childList: true, characterData: true, subtree: true });
})();
(function() {
    var target = document.getElementById('tab-site-health');
    if (!target) return;
    var obs = new MutationObserver(function() {
        var bad = document.querySelectorAll('[style*="grid-column"]');
        bad.forEach(function(el) {
            if (el.textContent && el.textContent.indexOf('Max Resource') >= 0) {
                el.remove();
            }
        });
    });
    obs.observe(target, { childList: true, subtree: true });
})();
jQuery(function($) {
    var fmt = function(b) { if (b >= 1073741824) return (b/1073741824).toFixed(2)+' GB'; if (b >= 1048576) return (b/1048576).toFixed(1)+' MB'; return (b/1024).toFixed(0)+' KB'; };
    var ragColors = {green:'#2e7d32',amber:'#e65100',red:'#c62828',grey:'#78909c'};
    var ragBgs = {green:'#e8f5e9',amber:'#fff3e0',red:'#ffebee',grey:'#f5f5f5'};
    var ragLabels = {green:'6+ months of disk space remaining',amber:'3 to 6 months of disk space remaining',red:'Less than 3 months of disk space remaining',grey:'Collecting weekly data to calculate trend'};

    function cscHealthRender(d) {
        var rag = d.disk_rag || 'grey';
        $('#csc-health-rag-bar').css('background', ragBgs[rag]);
        $('#csc-health-rag-dot').css('background', ragColors[rag]);
        $('#csc-health-rag-label').text(rag === 'grey' ? 'Collecting Data' : rag.charAt(0).toUpperCase()+rag.slice(1)).css('color', ragColors[rag]);
        $('#csc-health-rag-detail').text(ragLabels[rag] || '').css('color', ragColors[rag]);
        $('#hm-disk-used').text(fmt(d.disk_used));
        $('#hm-disk-free').text(fmt(d.disk_free));
        $('#hm-disk-total').text(fmt(d.disk_total));
        $('#hm-db-size').text(fmt(d.db_size));
        $('#hm-growth').text(d.growth_per_week > 0 ? fmt(d.growth_per_week)+'/wk' : (d.weekly_count >= 2 ? 'Stable' : 'Collecting…'));
        if (d.weeks_remaining > 104) {
            $('#hm-weeks-left').text('>> 2 Years').css('color', '#2e7d32');
        } else if (d.weeks_remaining > 0) {
            var wl = Math.round(d.weeks_remaining);
            var wlColor = d.disk_rag === 'red' ? '#c62828' : (d.disk_rag === 'amber' ? '#e65100' : '#2e7d32');
            $('#hm-weeks-left').text(wl + ' weeks').css('color', wlColor);
        } else if (d.growth_per_week <= 0 && d.weekly_count >= 2) {
            $('#hm-weeks-left').text('Stable').css('color', '#2e7d32');
        } else { $('#hm-weeks-left').text('—').css('color',''); }
        var cpuNow = d.cpu_pct_now >= 0 ? d.cpu_pct_now+'%' : '—';
        if (d.cpu_load_now >= 0) cpuNow += ' (load '+d.cpu_load_now.toFixed(2)+')';
        $('#hm-cpu-now').text(cpuNow);
        $('#hm-cpu-24h').text(d.cpu_pct_max_24h >= 0 ? d.cpu_pct_max_24h+'%' : '—');
        $('#hm-cpu-7d').text(d.cpu_pct_max_7d >= 0 ? d.cpu_pct_max_7d+'%' : '—');
        var memNow = d.mem_pct_now >= 0 ? d.mem_pct_now+'%' : '—';
        if (d.mem_used_now >= 0 && d.mem_total > 0) memNow += ' ('+fmt(d.mem_used_now)+' / '+fmt(d.mem_total)+')';
        $('#hm-mem-now').text(memNow);
        $('#hm-mem-24h').text(d.mem_pct_max_24h >= 0 ? d.mem_pct_max_24h+'%' : '—');
        $('#hm-mem-7d').text(d.mem_pct_max_7d >= 0 ? d.mem_pct_max_7d+'%' : '—');

        if (d.max_resource_now !== undefined) {
            $('[style*="grid-column:1/-1"]').filter(function(){ return $(this).text().indexOf('Max Resource') >= 0; }).remove();
            var $memGrid = $('#hm-mem-7d').closest('[style*="grid"]');
            if ($memGrid.length && !$('#hm-maxres-now').length) {
                $memGrid.after('<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px">' +
                    '<div class="csc-health-metric"><div class="csc-health-metric-label">Max Resource (now)</div><div class="csc-health-metric-value" id="hm-maxres-now">&middot;</div></div>' +
                    '<div class="csc-health-metric"><div class="csc-health-metric-label">Max Resource (24h)</div><div class="csc-health-metric-value" id="hm-maxres-24h">&middot;</div></div>' +
                    '<div class="csc-health-metric"><div class="csc-health-metric-label">Max Resource (7d)</div><div class="csc-health-metric-value" id="hm-maxres-7d">&middot;</div></div>' +
                '</div>');
            }
            if (d.max_resource_now >= 0) $('#hm-maxres-now').text(d.max_resource_now + '%');
            if (d.max_resource_24h >= 0) $('#hm-maxres-24h').text(d.max_resource_24h + '%');
            if (d.max_resource_7d >= 0) $('#hm-maxres-7d').text(d.max_resource_7d + '%');
        }

        $('#hm-hourly-count').text(d.hourly_count);
        $('#hm-weekly-count').text(d.weekly_count);
        $('#hm-last-hourly').text(d.last_hourly || 'Never');
        $('#hm-last-weekly').text(d.last_weekly || 'Never');
        $('#hm-data-span').text(d.weeks_of_data > 0 ? d.weeks_of_data : '0');
        $('#csc-health-loading').hide();
        $('#csc-health-content').show();
    }

    if ($('#csc-health-loading').is(':visible')) {
        $.post(CSC.ajax_url, { action: 'cscc_health_get', nonce: CSC.nonce }, function(resp) {
            if (resp.success) cscHealthRender(resp.data);
        });
    }

    $(document).on('click', '#btn-health-refresh', function() {
        var $b = $(this).prop('disabled',true).html('⏳ Loading…');
        $.post(CSC.ajax_url, { action: 'cscc_health_get', nonce: CSC.nonce }, function(resp) {
            $b.prop('disabled',false).html('🔄 Refresh');
            if (resp.success) cscHealthRender(resp.data);
        }).fail(function(){ $b.prop('disabled',false).html('🔄 Refresh'); });
    });

    $(document).on('click', '#btn-sysstat-test', function() {
        var $b = $(this).prop('disabled',true).html('⏳ Testing...');
        var blue = {background:'#e3f2fd',borderColor:'#90caf9'};
        var $box = $('#csc-sysstat-status').show().css(blue);
        $('#csc-sysstat-label').text('Testing sysstat...').css('color','#1565c0');
        $('#csc-sysstat-icon').text('⏳');
        $('#csc-sysstat-detail').text('').css('color','#1565c0');
        $('#csc-sysstat-instructions').hide();
        $.post(CSC.ajax_url, { action: 'cscc_health_sysstat_test', nonce: CSC.nonce }, function(resp) {
            $b.prop('disabled',false).html('🔧 Test Metrics');
            $box.css(blue);
            if (!resp.success) { $('#csc-sysstat-icon').text('❌'); $('#csc-sysstat-label').text('Test failed'); return; }
            var d = resp.data;
            $('#csc-sysstat-icon').text('✅');
            $('#csc-sysstat-label').text('PHP snapshot metrics active');
            $('#csc-sysstat-detail').text(d.cpu_count+' CPU core(s) | CPU '+d.cpu_pct_now+'% | Mem '+d.mem_pct_now+'%');
        }).fail(function(){ $b.prop('disabled',false).html('🔧 Test Metrics'); $('#csc-sysstat-icon').text('❌'); $('#csc-sysstat-label').text('Network error'); });
    });
});
