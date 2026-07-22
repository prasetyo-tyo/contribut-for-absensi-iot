/**
 * Developer Dashboard — SPA Core
 * Pure vanilla JS, no framework dependencies.
 */

(function () {
  'use strict';

  // ─── Config ────────────────────────────────
  const API_BASE = 'api.php';
  const REFRESH_INTERVAL = 15000; // 15s

  // ─── State ─────────────────────────────────
  let currentView = 'overview';
  let autoRefreshTimer = null;
  let autoRefreshEnabled = true;
  let logsPage = 1;
  let logsFilter = 'all';
  let logsSearch = '';
  let invalidPage = 1;
  let karyawanSearch = '';

  // ─── Init ──────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    bindNav();
    loadView('overview');
    startAutoRefresh();
  });

  // ─── Navigation ────────────────────────────
  function bindNav() {
    document.querySelectorAll('.sidebar-nav a[data-view]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var view = this.getAttribute('data-view');
        document.querySelectorAll('.sidebar-nav a').forEach(function (l) { l.classList.remove('active'); });
        this.classList.add('active');
        loadView(view);
      });
    });
  }

  function loadView(view) {
    currentView = view;
    var titles = {
      overview: 'Overview',
      logs: 'API Request Logs',
      invalid: 'Invalid Card Logs',
      karyawan: 'Employee Database',
      diagnostics: 'Card Diagnostics'
    };
    document.getElementById('pageTitle').textContent = titles[view] || view;
    loadCurrentView();
  }

  // Make global for topbar button
  window.loadCurrentView = function () {
    switch (currentView) {
      case 'overview': loadOverview(); break;
      case 'logs': loadLogs(); break;
      case 'invalid': loadInvalidLogs(); break;
      case 'karyawan': loadKaryawan(); break;
      case 'diagnostics': loadDiagnostics(); break;
    }
    document.getElementById('lastUpdate').textContent = 'Updated ' + new Date().toLocaleTimeString('id-ID');
  };

  // ─── Auto Refresh ──────────────────────────
  function startAutoRefresh() {
    stopAutoRefresh();
    autoRefreshTimer = setInterval(function () {
      if (autoRefreshEnabled) loadCurrentView();
    }, REFRESH_INTERVAL);
  }

  function stopAutoRefresh() {
    if (autoRefreshTimer) { clearInterval(autoRefreshTimer); autoRefreshTimer = null; }
  }

  window.toggleAutoRefresh = function () {
    autoRefreshEnabled = !autoRefreshEnabled;
    var btn = document.getElementById('autoRefreshBtn');
    var dot = document.getElementById('liveIndicator');
    if (autoRefreshEnabled) {
      btn.textContent = '⏸ Pause';
      dot.classList.remove('paused');
    } else {
      btn.textContent = '▶ Resume';
      dot.classList.add('paused');
    }
  };

  // ─── API Helper ────────────────────────────
  function api(action, params) {
    var url = API_BASE + '?action=' + encodeURIComponent(action);
    if (params) {
      Object.keys(params).forEach(function (k) {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
          url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }
      });
    }
    return fetch(url, { credentials: 'same-origin' })
      .then(function (res) { return res.json(); });
  }

  // ─── OVERVIEW ──────────────────────────────
  function loadOverview() {
    var el = document.getElementById('appContent');
    el.innerHTML = skeleton(6);

    api('stats').then(function (d) {
      if (!d.ok) { el.innerHTML = errorBox('API Error'); return; }

      var html = '';

      // Stats Grid
      html += '<div class="stats-grid">';
      html += statCard('Total API Calls', fmt(d.total), 'all time', 'indigo');
      html += statCard('Today', fmt(d.today), 'requests', 'blue');
      html += statCard('Match', fmt(d.by_result.MATCH), pct(d.total, d.by_result.MATCH), 'green');
      html += statCard('No Match', fmt(d.by_result.NO_MATCH), pct(d.total, d.by_result.NO_MATCH) + (d.no_match_today > 0 ? ' · ' + d.no_match_today + ' today' : ''), 'red');
      html += statCard('Errors', fmt(d.by_result.ERROR), '', 'amber');
      html += statCard('Avg Duration', fmt(d.avg_duration_ms) + 'ms', 'today', 'indigo');
      html += statCard('Employees', fmt(d.aktif_karyawan) + ' / ' + fmt(d.total_karyawan), 'active / total', 'blue');
      html += statCard('Invalid Cards', fmt(d.total_invalid), d.invalid_today + ' today', 'red');
      html += '</div>';

      // Timeline
      html += '<div class="card" style="margin-bottom:24px">';
      html += '<div class="card-header"><h3>24-Hour Activity</h3></div>';
      html += '<div style="padding:20px">';
      html += renderTimeline(d.timeline);
      html += '</div></div>';

      // Quick: Recent NO_MATCH
      html += '<div class="card">';
      html += '<div class="card-header"><h3>Recent No-Match</h3>';
      html += '<button class="btn btn-sm" onclick="loadView(\'logs\'); logsFilter=\'NO_MATCH\';">View All →</button></div>';
      html += '<div id="recentNoMatch"><div class="empty-state"><p>Loading...</p></div></div>';
      html += '</div>';

      el.innerHTML = html;

      // Load recent no-match
      api('logs', { filter: 'NO_MATCH', per_page: 5 }).then(function (res) {
        var box = document.getElementById('recentNoMatch');
        if (!res.ok || res.data.length === 0) {
          box.innerHTML = '<div class="empty-state"><div class="icon">✅</div><p>No recent no-match entries</p></div>';
          return;
        }
        box.innerHTML = '<div class="table-wrap"><table><thead><tr><th>Time</th><th>Normalized</th><th>Hex</th><th>Notes</th></tr></thead><tbody>' +
          res.data.map(function (r) {
            return '<tr class="clickable" onclick="showLogDetail(' + r.id + ')">' +
              '<td class="small">' + r.waktu + '</td>' +
              '<td class="mono">' + esc(r.normalized_input) + '</td>' +
              '<td class="mono hex-preview">' + formatHex(r.raw_input_hex) + '</td>' +
              '<td class="small muted">' + esc(trunc(r.notes, 60)) + '</td></tr>';
          }).join('') +
          '</tbody></table></div>';
      });
    }).catch(function (err) {
      el.innerHTML = errorBox(err.message);
    });
  }

  function renderTimeline(timeline) {
    var hours = [];
    for (var h = 0; h < 24; h++) { hours.push(h); }

    var maxVal = 1;
    hours.forEach(function (h) {
      var entries = timeline[h] || [];
      entries.forEach(function (e) { maxVal = Math.max(maxVal, parseInt(e.v)); });
    });

    var barScale = 70 / maxVal;

    var barsHtml = '<div class="chart-bars">';
    var labelsHtml = '<div class="chart-labels">';
    hours.forEach(function (h) {
      var entries = timeline[h] || [];
      var mCount = 0, nCount = 0, eCount = 0;
      entries.forEach(function (e) {
        if (e.match_result === 'MATCH') mCount = parseInt(e.v);
        else if (e.match_result === 'NO_MATCH') nCount = parseInt(e.v);
        else eCount = parseInt(e.v);
      });
      var total = mCount + nCount + eCount;
      var barH = Math.max(2, total * barScale);
      var cls = nCount > mCount ? 'no-match' : (eCount > 0 ? 'error' : 'match');

      barsHtml += '<div class="bar ' + cls + '" style="height:' + barH + 'px" title="' + h + ':00 — M:' + mCount + ' N:' + nCount + ' E:' + eCount + '"></div>';
      labelsHtml += '<span>' + (h % 3 === 0 ? pad(h) : '') + '</span>';
    });
    barsHtml += '</div>';
    labelsHtml += '</div>';

    return barsHtml + labelsHtml;
  }

  // ─── API LOGS ──────────────────────────────
  function loadLogs() {
    var el = document.getElementById('appContent');

    var html = '<div class="card">';
    html += '<div class="card-header">';
    html += '<h3>API Request Logs</h3>';
    html += '<div class="card-toolbar">';

    // Filter buttons
    html += '<div class="btn-group">';
    ['all', 'MATCH', 'NO_MATCH', 'ERROR'].forEach(function (f) {
      var label = f === 'all' ? 'All' : f === 'NO_MATCH' ? 'No Match' : f;
      var isActive = logsFilter === f ? ' active' : '';
      html += '<button class="btn btn-sm' + isActive + '" onclick="setLogsFilter(\'' + f + '\')">' + label + '</button>';
    });
    html += '</div>';

    // Search
    html += '<input type="text" class="input" placeholder="Search UID / NIP / Name..." value="' + esc(logsSearch) + '" oninput="setLogsSearch(this.value)">';

    html += '</div></div>';

    html += '<div id="logsTableBody"><div class="empty-state"><p>Loading...</p></div></div>';
    html += '</div>';

    el.innerHTML = html;

    // Load data
    var params = { page: logsPage, per_page: 50, filter: logsFilter, search: logsSearch };
    api('logs', params).then(function (res) {
      var box = document.getElementById('logsTableBody');
      if (!res.ok) { box.innerHTML = errorBox('API Error'); return; }
      if (res.data.length === 0) {
        box.innerHTML = '<div class="empty-state"><div class="icon">📋</div><p>No logs found</p></div>';
        return;
      }

      var tHtml = '<div class="table-wrap"><table><thead><tr>';
      tHtml += '<th>#</th><th>Time</th><th>Result</th><th>Field</th><th>Raw Input</th><th>Normalized</th><th>Matched</th><th>ms</th><th>Notes</th>';
      tHtml += '</tr></thead><tbody>';

      res.data.forEach(function (r) {
        tHtml += '<tr class="clickable" onclick="showLogDetail(' + r.id + ')">';
        tHtml += '<td class="muted">' + r.id + '</td>';
        tHtml += '<td class="small">' + r.tanggal + ' ' + r.waktu + '</td>';
        tHtml += '<td>' + badge(r.match_result) + '</td>';
        tHtml += '<td><code class="mono small">' + esc(r.matched_field || '—') + '</code></td>';
        tHtml += '<td class="mono hex-preview">' + formatHex(r.raw_input_hex) + '</td>';
        tHtml += '<td class="mono small">' + esc(r.normalized_input || '—') + '</td>';
        tHtml += '<td class="small">' + (r.matched_nip ? '<strong>' + esc(r.matched_nip) + '</strong><br>' + esc(trunc(r.matched_nama, 25)) : '—') + '</td>';
        tHtml += '<td class="small muted">' + (r.duration_ms || '—') + '</td>';
        tHtml += '<td class="small muted">' + esc(trunc(r.notes, 50)) + '</td>';
        tHtml += '</tr>';
      });

      tHtml += '</tbody></table></div>';

      // Pagination
      var p = res.pagination;
      tHtml += '<div class="pagination">';
      tHtml += '<span>Page ' + p.page + ' of ' + p.total_pages + ' (' + fmt(p.total) + ' total)</span>';
      tHtml += '<div class="pages">';
      if (p.page > 1) tHtml += '<button class="btn btn-sm" onclick="goLogsPage(' + (p.page - 1) + ')">←</button>';
      for (var i = Math.max(1, p.page - 2); i <= Math.min(p.total_pages, p.page + 2); i++) {
        tHtml += '<button class="btn btn-sm' + (i === p.page ? ' active' : '') + '" onclick="goLogsPage(' + i + ')">' + i + '</button>';
      }
      if (p.page < p.total_pages) tHtml += '<button class="btn btn-sm" onclick="goLogsPage(' + (p.page + 1) + ')">→</button>';
      tHtml += '</div></div>';

      box.innerHTML = tHtml;
    });
  }

  // Make log controls global
  window.setLogsFilter = function (f) { logsFilter = f; logsPage = 1; loadLogs(); };
  window.setLogsSearch = function (v) { logsSearch = v; logsPage = 1; clearTimeout(window._logSearchTimer); window._logSearchTimer = setTimeout(loadLogs, 400); };
  window.goLogsPage = function (p) { logsPage = p; loadLogs(); };

  // ─── INVALID LOGS ──────────────────────────
  function loadInvalidLogs() {
    var el = document.getElementById('appContent');
    el.innerHTML = '<div class="card"><div id="invalidBody"><div class="empty-state"><p>Loading...</p></div></div></div>';

    api('invalid_logs', { page: invalidPage }).then(function (res) {
      var box = document.getElementById('invalidBody');
      if (!res.ok) { box.innerHTML = errorBox('API Error'); return; }
      if (res.data.length === 0) {
        box.innerHTML = '<div class="empty-state"><div class="icon">✅</div><p>No invalid card logs</p></div>';
        return;
      }

      var tHtml = '<div class="table-wrap"><table><thead><tr>';
      tHtml += '<th>#</th><th>Date</th><th>Time</th><th>UID</th><th>Token</th><th>Outlet</th><th>Status</th>';
      tHtml += '</tr></thead><tbody>';

      res.data.forEach(function (r) {
        tHtml += '<tr>';
        tHtml += '<td class="muted">' + r.id + '</td>';
        tHtml += '<td class="small">' + r.tanggal + '</td>';
        tHtml += '<td class="small">' + r.waktu + '</td>';
        tHtml += '<td class="mono">' + esc(r.uid) + '</td>';
        tHtml += '<td class="mono small">' + esc(r.token_kartu || '—') + '</td>';
        tHtml += '<td>' + (r.outlet_id || '—') + '</td>';
        tHtml += '<td>' + badge(r.status) + '</td>';
        tHtml += '</tr>';
      });
      tHtml += '</tbody></table></div>';

      var p = res.pagination;
      tHtml += '<div class="pagination"><span>Page ' + p.page + ' of ' + p.total_pages + '</span><div class="pages">';
      if (p.page > 1) tHtml += '<button class="btn btn-sm" onclick="invalidPage=' + (p.page - 1) + ';loadInvalidLogs()">←</button>';
      tHtml += '<button class="btn btn-sm active">' + p.page + '</button>';
      if (p.page < p.total_pages) tHtml += '<button class="btn btn-sm" onclick="invalidPage=' + (p.page + 1) + ';loadInvalidLogs()">→</button>';
      tHtml += '</div></div>';

      box.innerHTML = tHtml;
    });
  }

  // ─── KARYAWAN ──────────────────────────────
  function loadKaryawan() {
    var el = document.getElementById('appContent');

    var html = '<div class="card">';
    html += '<div class="card-header"><h3>Employee Database</h3>';
    html += '<div class="card-toolbar">';
    html += '<input type="text" class="input" id="karSearch" placeholder="Search name / NIP / UID..." value="' + esc(karyawanSearch) + '" oninput="searchKaryawan()">';
    html += '</div></div>';
    html += '<div id="karyawanBody"><div class="empty-state"><p>Loading...</p></div></div>';
    html += '</div>';
    el.innerHTML = html;

    fetchKaryawan();
  }

  function fetchKaryawan() {
    api('karyawan', { search: karyawanSearch }).then(function (res) {
      var box = document.getElementById('karyawanBody');
      if (!res.ok || res.data.length === 0) {
        box.innerHTML = '<div class="empty-state"><div class="icon">👥</div><p>No employees found</p></div>';
        return;
      }

      var tHtml = '<div class="table-wrap"><table><thead><tr>';
      tHtml += '<th>NIP</th><th>Nama</th><th>UID (hash)</th><th>UID Fisik</th><th>Token</th><th>Status</th>';
      tHtml += '</tr></thead><tbody>';

      res.data.forEach(function (r) {
        tHtml += '<tr>';
        tHtml += '<td class="mono">' + esc(r.nip) + '</td>';
        tHtml += '<td>' + esc(r.nama) + '</td>';
        tHtml += '<td class="mono small muted" title="' + esc(r.uid) + '">' + esc(trunc(r.uid, 16)) + '…</td>';
        tHtml += '<td class="mono">' + esc(r.uid_fisik || '—') + '</td>';
        tHtml += '<td class="mono">' + esc(r.token_kartu || '—') + '</td>';
        tHtml += '<td>' + badge(r.status_karyawan) + '</td>';
        tHtml += '</tr>';
      });
      tHtml += '</tbody></table></div>';

      box.innerHTML = tHtml;
    });
  }

  window.searchKaryawan = function () {
    clearTimeout(window._karTimer);
    window._karTimer = setTimeout(function () {
      karyawanSearch = document.getElementById('karSearch').value;
      fetchKaryawan();
    }, 350);
  };

  // ─── DIAGNOSTICS ───────────────────────────
  function loadDiagnostics() {
    var el = document.getElementById('appContent');
    var html = '';

    // Info box
    html += '<div class="diagnostics-section">';
    html += '<h3>🔍 How RFID Card Registration Works</h3>';
    html += '<div class="info-box">';
    html += '<strong>The flow:</strong><br>';
    html += '1. Employee is registered in the web app with <code>UID Fisik</code> and <code>Token Kartu</code>.<br>';
    html += '2. The web app computes a SHA-256 hash: <code>uid = SHA256(UID_Fisik|Token|Secret)</code> and stores it in the <code>uid</code> column.<br>';
    html += '3. Firmware reads <strong>Block 2</strong> (16 bytes) from the MIFARE card and sends it as <code>?uid=<Block2Data></code>.<br>';
    html += '4. The API matches the incoming value against <code>uid</code>, <code>token_kartu</code>, or a LIKE variant.';
    html += '</div></div>';

    // Checklist
    html += '<div class="diagnostics-section">';
    html += '<h3>✅ Troubleshooting Checklist</h3>';
    html += '<div class="card">';
    html += '<ul class="checklist">';

    var steps = [
      'Open Serial Monitor in Arduino IDE (9600 baud) while card is scanned. Look for <code>Last data in RFID:2 → ...</code> to see the raw Block 2 data.',
      'Compare the raw data with the <code>token_kartu</code> value in the database. They should match (after cleaning null bytes and whitespace).',
      'Check the hex output in Serial Monitor. If you see many <code>00</code> bytes, the Block 2 data contains null padding — the API tries to strip these.',
      'If Block 2 shows <code>FFFFFFFFFFFFFFFF</code> (all F\'s), the block has never been written. You need a separate firmware to write the token to Block 2.',
      'Verify the card registration: go to Karyawan page and check the <code>UID Fisik</code> and <code>Token Kartu</code> columns for the employee.',
      'After registration, check the Developer Dashboard logs — the <code>normalized_input</code> column shows what the API received and tried to match.'
    ];

    steps.forEach(function (s, i) {
      html += '<li><span class="step-num">' + (i + 1) + '</span><span>' + s + '</span></li>';
    });

    html += '</ul></div></div>';

    // Quick action
    html += '<div class="diagnostics-section">';
    html += '<h3>⚡ Quick Actions</h3>';
    html += '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    html += '<button class="btn btn-danger" onclick="if(confirm(\'Clear ALL debug logs?\')){apiAction(\'clear_all\').then(loadCurrentView)}">🗑 Clear All Debug Logs</button>';
    html += '<button class="btn" onclick="if(confirm(\'Clear logs older than 30 days?\')){apiAction(\'clear_old\',{days:30}).then(loadCurrentView)}">🧹 Clear Logs >30 days</button>';
    html += '<button class="btn" onclick="if(confirm(\'Clear logs older than 7 days?\')){apiAction(\'clear_old\',{days:7}).then(loadCurrentView)}">🧹 Clear Logs >7 days</button>';
    html += '</div></div>';

    el.innerHTML = html;
  }

  // ─── Actions ───────────────────────────────
  window.apiAction = function (action, params) {
    return api(action, params).then(function (d) {
      if (d.ok) {
        showToast(d.message || action + ' completed');
      } else {
        showToast('Error: ' + (d.error || 'Unknown'));
      }
      return d;
    });
  };

  // ─── Log Detail Modal ──────────────────────
  window.showLogDetail = function (id) {
    api('log_detail', { id: id }).then(function (d) {
      if (!d.ok) return;
      var r = d.data;

      document.getElementById('modalTitle').textContent = 'Log #' + r.id;

      var html = '<dl class="detail-grid">';
      dt('ID', r.id);
      dt('Date', r.tanggal);
      dt('Time', r.waktu);
      dtResult('Result', r.match_result);
      dt('Endpoint', r.endpoint);
      dtRaw('Raw Input', r.raw_input, r.raw_input_hex);
      dt('Normalized', r.normalized_input, true);
      dt('Matched Field', r.matched_field || '—');
      dt('Matched NIP', r.matched_nip || '—');
      dt('Matched Name', r.matched_nama || '—');
      dt('Duration', (r.duration_ms || '—') + ' ms');
      dt('IP Address', r.ip_address || '—');
      dt('User Agent', r.user_agent || '—');
      dt('Request Params', r.request_params || '—', true);
      dt('Notes', r.notes || '—', true);
      dt('Created At', r.created_at);
      html += '</dl>';

      document.getElementById('modalBody').innerHTML = html;
      document.getElementById('modalOverlay').classList.add('open');

      function dt(label, value, isCode) {
        html += '<dt>' + label + '</dt>';
        html += '<dd' + (isCode ? ' class="code"' : '') + '>' + esc(String(value)) + '</dd>';
      }

      function dtResult(label, value) {
        html += '<dt>' + label + '</dt><dd>' + badge(value) + '</dd>';
      }

      function dtRaw(label, raw, hex) {
        html += '<dt>' + label + '</dt>';
        html += '<dd class="code">Display: ' + esc(raw || '') + '<br>Hex: ' + formatHex(hex || '') + '<br>Length: ' + (raw ? raw.length : 0) + ' chars</dd>';
      }
    });
  };

  window.closeModal = function () {
    document.getElementById('modalOverlay').classList.remove('open');
  };

  // Escape the reference — the original used `modalModal` but element is `modalOverlay`
  // Fix: bind close to overlay
  document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('modalOverlay');
    if (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
      });
    }
  });

  // ─── Helpers ───────────────────────────────
  function fmt(n) { return n == null ? '0' : Number(n).toLocaleString('id-ID'); }

  function pct(total, val) {
    if (!total) return '0%';
    return Math.round((val / total) * 100) + '%';
  }

  function esc(s) {
    if (!s) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(s));
    return div.innerHTML;
  }

  function trunc(s, len) { return s && s.length > len ? s.substring(0, len) : s; }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function badge(result) {
    var cls = (result || '').toLowerCase().replace(/[^a-z]/g, '');
    return '<span class="badge badge-' + cls + '">' + esc(result || '—') + '</span>';
  }

  function formatHex(hex) {
    if (!hex) return '<span class="muted">empty</span>';
    var pairs = hex.match(/.{1,2}/g);
    if (!pairs) return '<span class="muted">empty</span>';
    return pairs.map(function (b) {
      var highlight = (b === '00') ? ' highlight' : '';
      return '<span class="' + highlight + '">' + b + '</span>';
    }).join(' ');
  }

  function statCard(label, value, sub, color) {
    return '<div class="stat-card ' + color + '">' +
      '<div class="stat-label">' + label + '</div>' +
      '<div class="stat-value">' + value + '</div>' +
      (sub ? '<div class="stat-sub">' + sub + '</div>' : '') +
      '</div>';
  }

  function skeleton(n) {
    var html = '<div class="stats-grid">';
    for (var i = 0; i < 6; i++) html += '<div class="stat-card"><div class="skeleton" style="width:60px;margin-bottom:8px"></div><div class="skeleton" style="width:80px;height:28px"></div></div>';
    html += '</div>';
    html += '<div class="skeleton skeleton-block"></div>';
    html += '<div class="skeleton skeleton-block"></div>';
    return html;
  }

  function errorBox(msg) {
    return '<div class="empty-state"><div class="icon">⚠️</div><p>' + esc(msg) + '</p></div>';
  }

  function showToast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:8px;font-size:.82rem;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:opacity .3s';
    document.body.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 300); }, 2500);
  }

})();
