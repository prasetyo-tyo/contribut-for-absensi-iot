<?php
/**
 * Developer Dashboard API
 *
 * Serves debug log data for the developer dashboard SPA.
 * Uses shared auth from apps/session (requires login).
 *
 * Endpoints:
 *   ?action=stats          → summary stats
 *   ?action=logs&page=N    → paginated debug logs
 *   ?action=karyawan       → employee list for cross-reference
 *   ?action=clear_all      → truncate debug_log
 *   ?action=clear_old&days=N → delete old logs
 */

session_start();
header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");

// ---- Auth: same session as main apps ----
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

require_once __DIR__ . '/../apps/config.php';

$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if (!$link) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {

    // ──────────────────────────────────────
    //  Stats summary
    // ──────────────────────────────────────
    case 'stats':
        $out = ["ok" => true];

        $q = mysqli_query($link, "SELECT COUNT(*) AS v FROM data_debug_log");
        $out['total'] = $q ? (int)mysqli_fetch_assoc($q)['v'] : 0;

        $q = mysqli_query($link, "SELECT COUNT(*) AS v FROM data_debug_log WHERE tanggal = CURDATE()");
        $out['today'] = $q ? (int)mysqli_fetch_assoc($q)['v'] : 0;

        $q = mysqli_query($link, "SELECT match_result, COUNT(*) AS v FROM data_debug_log GROUP BY match_result");
        $out['by_result'] = ["MATCH" => 0, "NO_MATCH" => 0, "ERROR" => 0];
        if ($q) while ($r = mysqli_fetch_assoc($q)) {
            $out['by_result'][$r['match_result']] = (int)$r['v'];
        }

        $q = mysqli_query($link, "SELECT COUNT(*) AS v FROM data_debug_log WHERE tanggal = CURDATE() AND match_result = 'NO_MATCH'");
        $out['no_match_today'] = $q ? (int)mysqli_fetch_assoc($q)['v'] : 0;

        $q = mysqli_query($link, "SELECT COUNT(*) AS v FROM data_karyawan");
        $out['total_karyawan'] = $q ? (int)mysqli_fetch_assoc($q)['v'] : 0;

        $q = mysqli_query($link, "SELECT COUNT(*) AS v FROM data_karyawan WHERE status_karyawan = 'AKTIF'");
        $out['aktif_karyawan'] = $q ? (int)mysqli_fetch_assoc($q)['v'] : 0;

        $q = mysqli_query($link, "SELECT COUNT(*) AS v FROM data_invalid");
        $out['total_invalid'] = $q ? (int)mysqli_fetch_assoc($q)['v'] : 0;

        $q = mysqli_query($link, "SELECT COUNT(*) AS v FROM data_invalid WHERE tanggal = CURDATE()");
        $out['invalid_today'] = $q ? (int)mysqli_fetch_assoc($q)['v'] : 0;

        // Avg duration
        $q = mysqli_query($link, "SELECT AVG(duration_ms) AS v FROM data_debug_log WHERE tanggal = CURDATE()");
        $out['avg_duration_ms'] = $q ? (int)(mysqli_fetch_assoc($q)['v'] ?? 0) : 0;

        // Timeline: last 24 hours, grouped by hour
        $q = mysqli_query($link, "
            SELECT HOUR(created_at) AS h, match_result, COUNT(*) AS v
            FROM data_debug_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY HOUR(created_at), match_result
            ORDER BY h
        ");
        $out['timeline'] = [];
        if ($q) while ($r = mysqli_fetch_assoc($q)) {
            $out['timeline'][(int)$r['h']][] = $r;
        }

        echo json_encode($out);
        break;

    // ──────────────────────────────────────
    //  Paginated debug logs
    // ──────────────────────────────────────
    case 'logs':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
        $offset = ($page - 1) * $per_page;
        $filter = $_GET['filter'] ?? 'all';
        $search = trim($_GET['search'] ?? '');

        $where = "1=1";
        $params = [];

        if (in_array($filter, ['MATCH', 'NO_MATCH', 'ERROR'])) {
            $where .= " AND match_result = ?";
            $params[] = $filter;
        }
        if ($search !== '') {
            $where .= " AND (normalized_input LIKE ? OR raw_input LIKE ? OR matched_nip LIKE ? OR matched_nama LIKE ?)";
            $like = '%' . $search . '%';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        // Count
        $countSql = "SELECT COUNT(*) AS v FROM data_debug_log WHERE {$where}";
        if (!empty($params)) {
            $stmt = mysqli_prepare($link, $countSql);
            $types = str_repeat('s', count($params));
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            $countResult = mysqli_stmt_get_result($stmt);
            $total = (int)mysqli_fetch_assoc($countResult)['v'];
            mysqli_stmt_close($stmt);
        } else {
            $total = (int)mysqli_fetch_assoc(mysqli_query($link, $countSql))['v'];
        }

        $sql = "SELECT id, tanggal, waktu, endpoint, raw_input, normalized_input,
                       match_result, matched_field, matched_id, matched_nip, matched_nama,
                       duration_ms, notes, ip_address, created_at
                FROM data_debug_log
                WHERE {$where}
                ORDER BY id DESC
                LIMIT ? OFFSET ?";

        $params[] = $per_page;
        $params[] = $offset;
        $types = str_repeat('s', count($params) - 2) . 'ii';

        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rows = [];
        if ($result) {
            while ($r = mysqli_fetch_assoc($result)) {
                $r['raw_input_hex'] = bin2hex($r['raw_input'] ?? '');
                $r['raw_input_display'] = substr($r['raw_input'] ?? '', 0, 100);
                $rows[] = $r;
            }
        }
        mysqli_stmt_close($stmt);

        echo json_encode([
            "ok" => true,
            "data" => $rows,
            "pagination" => [
                "page" => $page,
                "per_page" => $per_page,
                "total" => $total,
                "total_pages" => ceil($total / $per_page),
            ]
        ]);
        break;

    // ──────────────────────────────────────
    //  Employee list (for cross-reference)
    // ──────────────────────────────────────
    case 'karyawan':
        $search = trim($_GET['search'] ?? '');
        $sql = "SELECT id, nip, uid, uid_fisik, token_kartu, nama, status_karyawan
                FROM data_karyawan";
        $params = [];
        if ($search !== '') {
            $sql .= " WHERE nama LIKE ? OR nip LIKE ? OR uid LIKE ? OR token_kartu LIKE ? OR uid_fisik LIKE ?";
            $like = '%' . $search . '%';
            $params = [$like, $like, $like, $like, $like];
        }
        $sql .= " ORDER BY nama ASC LIMIT 200";

        if (!empty($params)) {
            $stmt = mysqli_prepare($link, $sql);
            $types = str_repeat('s', count($params));
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
        } else {
            $result = mysqli_query($link, $sql);
        }

        $rows = [];
        if ($result) while ($r = mysqli_fetch_assoc($result)) { $rows[] = $r; }

        echo json_encode(["ok" => true, "data" => $rows]);
        break;

    // ──────────────────────────────────────
    //  Detail single log entry
    // ──────────────────────────────────────
    case 'log_detail':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid id"]);
            break;
        }
        $stmt = mysqli_prepare($link, "SELECT * FROM data_debug_log WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($row) {
            $row['raw_input_hex'] = bin2hex($row['raw_input'] ?? '');
            echo json_encode(["ok" => true, "data" => $row]);
        } else {
            http_response_code(404);
            echo json_encode(["error" => "Not found"]);
        }
        break;

    // ──────────────────────────────────────
    //  Clear all logs
    // ──────────────────────────────────────
    case 'clear_all':
        mysqli_query($link, "TRUNCATE TABLE data_debug_log");
        echo json_encode(["ok" => true, "message" => "All logs cleared"]);
        break;

    // ──────────────────────────────────────
    //  Clear old logs
    // ──────────────────────────────────────
    case 'clear_old':
        $days = max(1, (int)($_GET['days'] ?? 30));
        $stmt = mysqli_prepare($link, "DELETE FROM data_debug_log WHERE tanggal < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
        mysqli_stmt_bind_param($stmt, "i", $days);
        mysqli_stmt_execute($stmt);
        $affected = mysqli_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(["ok" => true, "deleted" => $affected]);
        break;

    // ──────────────────────────────────────
    //  Invalid card logs
    // ──────────────────────────────────────
    case 'invalid_logs':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 50;
        $offset = ($page - 1) * $per_page;

        $q = mysqli_query($link, "SELECT COUNT(*) AS v FROM data_invalid");
        $total = $q ? (int)mysqli_fetch_assoc($q)['v'] : 0;

        $result = mysqli_query($link, "
            SELECT id, tanggal, waktu, uid, outlet_id, token_kartu, status, created_at
            FROM data_invalid
            ORDER BY id DESC
            LIMIT {$per_page} OFFSET {$offset}
        ");

        $rows = [];
        if ($result) while ($r = mysqli_fetch_assoc($result)) { $rows[] = $r; }

        echo json_encode([
            "ok" => true,
            "data" => $rows,
            "pagination" => [
                "page" => $page,
                "per_page" => $per_page,
                "total" => $total,
                "total_pages" => ceil($total / $per_page),
            ]
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Unknown action: {$action}"]);
        break;
}

mysqli_close($link);
?>
