<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();

ob_start();

$cache_ttl = 300;
function cache_path(string $key, string $suffix = 'json'): string {
    return sys_get_temp_dir() . '/rekon_cache_' . md5($key) . '.' . $suffix;
}
function cache_get(string $key, int $ttl): ?array {
    $path = cache_path($key, 'json');
    if (!file_exists($path)) return null;
    if (time() - filemtime($path) > $ttl) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
function cache_set(string $key, array $data): void {
    $path = cache_path($key, 'json');
    @file_put_contents($path, json_encode($data));
}
function cache_get_html(string $key, int $ttl): ?string {
    $path = cache_path($key, 'html');
    if (!file_exists($path)) return null;
    if (time() - filemtime($path) > $ttl) return null;
    $raw = file_get_contents($path);
    return ($raw === false) ? null : $raw;
}
function cache_set_html(string $key, string $html): void {
    $path = cache_path($key, 'html');
    @file_put_contents($path, $html);
}

$pdo = db();
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS perbandingan_penjelasan (
            id BIGSERIAL PRIMARY KEY,
            kode_kabupaten VARCHAR(10) NOT NULL,
            nama_kabupaten VARCHAR(120) NULL,
            komoditas VARCHAR(160) NOT NULL,
            bulan VARCHAR(20) NOT NULL,
            tahun INT NOT NULL,
            penjelasan TEXT NULL,
            updated_by VARCHAR(120) NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE (kode_kabupaten, komoditas, bulan, tahun)
        )
    ");
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_penjelasan') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = trim((string)($_POST['kode_kabupaten'] ?? ''));
    $nama = trim((string)($_POST['nama_kabupaten'] ?? ''));
    $komoditas_post = trim((string)($_POST['komoditas'] ?? ''));
    $bulan_post = strtolower(trim((string)($_POST['bulan'] ?? '')));
    $tahun_post = trim((string)($_POST['tahun'] ?? ''));
    $penjelasan_post = trim((string)($_POST['penjelasan'] ?? ''));

    if ($kode === '' || $komoditas_post === '' || $bulan_post === '' || !ctype_digit($tahun_post)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Parameter tidak lengkap.']);
        exit;
    }
    if (!can_edit_penjelasan($user, $kode)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Anda tidak memiliki akses untuk mengubah penjelasan ini.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO perbandingan_penjelasan (kode_kabupaten, nama_kabupaten, komoditas, bulan, tahun, penjelasan, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (kode_kabupaten, komoditas, bulan, tahun)
            DO UPDATE SET
                nama_kabupaten = EXCLUDED.nama_kabupaten,
                penjelasan = EXCLUDED.penjelasan,
                updated_by = EXCLUDED.updated_by,
                updated_at = NOW()
        ");
        $stmt->execute([
            $kode,
            $nama,
            $komoditas_post,
            $bulan_post,
            (int)$tahun_post,
            $penjelasan_post,
            (string)($user['email'] ?? ''),
        ]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan penjelasan.']);
    }
    exit;
}

$komoditas_list = [];
$komoditas_map = [];
$komoditas_seen = [];
$tables = ['shk', 'hpb', 'hd', 'hkd'];
$existing_tables = [];
foreach ($tables as $t) {
    if (table_exists($pdo, $t)) {
        $existing_tables[] = $t;
    }
}
$parts = [];
foreach ($existing_tables as $t) {
    $parts[] = "SELECT DISTINCT komoditas FROM {$t}";
}
$sql = $parts ? (implode(' UNION ', $parts) . ' ORDER BY komoditas ASC') : '';
if ($sql) {
    $cache_key = 'komoditas_list';
    $cached = cache_get($cache_key, $cache_ttl);
    if ($cached) {
        $komoditas_list = $cached['list'] ?? [];
        $komoditas_map = $cached['map'] ?? [];
    } else {
        $res = $pdo->query($sql);
        foreach ($res as $row) {
            $k = trim((string)$row['komoditas']);
            if ($k !== '') {
                $key = strtolower($k);
                if (!isset($komoditas_seen[$key])) {
                    $komoditas_seen[$key] = true;
                    $komoditas_map[$key] = $k;
                } else {
                    $current = $komoditas_map[$key] ?? '';
                    if ($current !== '' && $current === strtolower($current) && $k !== strtolower($k)) {
                        $komoditas_map[$key] = $k;
                    }
                }
            }
        }
        $komoditas_list = array_keys($komoditas_map);
        sort($komoditas_list, SORT_STRING);
        cache_set($cache_key, ['list' => $komoditas_list, 'map' => $komoditas_map]);
    }
}

$komoditas_selected = isset($_GET['komoditas']) ? trim($_GET['komoditas']) : '';
$komoditas_selected_key = strtolower(trim($komoditas_selected));
if ($komoditas_selected_key === '') {
    $komoditas_selected_key = 'beras';
}
$komoditas_filter = $komoditas_selected_key;
$bulan = isset($_GET['bulan']) ? trim($_GET['bulan']) : '';
$tahun = isset($_GET['tahun']) ? trim($_GET['tahun']) : '';

if ($bulan === '' || $tahun === '') {
    $lastMonth = new DateTime('first day of last month');
    $map = [
        'january' => 'januari',
        'february' => 'februari',
        'march' => 'maret',
        'april' => 'april',
        'may' => 'mei',
        'june' => 'juni',
        'july' => 'juli',
        'august' => 'agustus',
        'september' => 'september',
        'october' => 'oktober',
        'november' => 'november',
        'december' => 'desember',
    ];
    if ($bulan === '') {
        $bulan = $map[strtolower($lastMonth->format('F'))] ?? strtolower($lastMonth->format('F'));
    }
    if ($tahun === '') {
        $tahun = $lastMonth->format('Y');
    }
}

$display_komoditas = $komoditas_map[$komoditas_selected_key] ?? ($komoditas_selected_key !== '' ? ucfirst($komoditas_selected_key) : 'Semua');

$avg_map = [
    'HK' => null,
    'HPB' => null,
    'HD' => null,
    'HKD' => null,
];
$table_map = [
    'HK' => 'shk',
    'HPB' => 'hpb',
    'HD' => 'hd',
    'HKD' => 'hkd',
];
foreach ($table_map as $label => $tbl) {
    if (!in_array($tbl, $existing_tables, true)) {
        continue;
    }
    $sql = "SELECT AVG(NULLIF(perubahan,0)) AS avg_perubahan FROM {$tbl}";
    $where = [];
    $params = [];
    if ($komoditas_selected_key !== '') {
        $where[] = 'TRIM(LOWER(komoditas)) = ?';
        $params[] = $komoditas_filter;
    }
    if ($bulan !== '') {
        $where[] = 'TRIM(LOWER(bulan)) = ?';
        $params[] = strtolower(trim($bulan));
    }
    if ($tahun !== '' && ctype_digit($tahun)) {
        $where[] = 'tahun = ?';
        $params[] = (int)$tahun;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $cache_key = 'avg_' . $label . '_' . md5(json_encode([$komoditas_filter, $bulan, $tahun]));
    $cached = cache_get($cache_key, $cache_ttl);
    if ($cached && array_key_exists('avg', $cached)) {
        $avg_map[$label] = $cached['avg'];
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            $avg_map[$label] = $row['avg_perubahan'];
        }
        cache_set($cache_key, ['avg' => $avg_map[$label]]);
    }
}

$chart_labels = [];
$chart_data = [
    'HK' => [],
    'HPB' => [],
    'HD' => [],
    'HKD' => [],
];
 $chart_names = [];
 $global_max_abs = 0;
$label_set = [];
$chart_cache_key = 'chart_' . md5(json_encode([$komoditas_filter, $bulan, $tahun]));
$chart_cached = cache_get($chart_cache_key, $cache_ttl);
if ($chart_cached) {
    $chart_labels = $chart_cached['labels'] ?? [];
    $chart_data = $chart_cached['data'] ?? $chart_data;
    $chart_names = $chart_cached['names'] ?? [];
    $global_max_abs = $chart_cached['max'] ?? $global_max_abs;
} else {
foreach ($existing_tables as $tbl) {
    $sql = "SELECT DISTINCT kode_kabupaten, nama_kabupaten FROM {$tbl}";
    $where = [];
    $params = [];
    if ($komoditas_selected_key !== '') {
        $where[] = 'TRIM(LOWER(komoditas)) = ?';
        $params[] = $komoditas_filter;
    }
    if ($bulan !== '') {
        $where[] = 'TRIM(LOWER(bulan)) = ?';
        $params[] = strtolower(trim($bulan));
    }
    if ($tahun !== '' && ctype_digit($tahun)) {
        $where[] = 'tahun = ?';
        $params[] = (int)$tahun;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        $k = (string)$row['kode_kabupaten'];
        if ($k !== '') {
            $label_set[$k] = true;
            if (!isset($chart_names[$k])) {
                $chart_names[$k] = (string)$row['nama_kabupaten'];
            }
        }
    }
}
$chart_labels = array_keys($label_set);
usort($chart_labels, function ($a, $b) { return (int)$a <=> (int)$b; });

foreach ($table_map as $label => $tbl) {
    if (!in_array($tbl, $existing_tables, true)) {
        $chart_data[$label] = array_fill(0, count($chart_labels), null);
        continue;
    }
    $sql = "SELECT kode_kabupaten, AVG(NULLIF(perubahan,0)) AS avg_perubahan FROM {$tbl}";
    $where = [];
    $params = [];
    if ($komoditas_selected_key !== '') {
        $where[] = 'TRIM(LOWER(komoditas)) = ?';
        $params[] = $komoditas_filter;
    }
    if ($bulan !== '') {
        $where[] = 'TRIM(LOWER(bulan)) = ?';
        $params[] = strtolower(trim($bulan));
    }
    if ($tahun !== '' && ctype_digit($tahun)) {
        $where[] = 'tahun = ?';
        $params[] = (int)$tahun;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY kode_kabupaten';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data_map = [];
    while ($row = $stmt->fetch()) {
        $data_map[(string)$row['kode_kabupaten']] = $row['avg_perubahan'];
    }
    $series = [];
    foreach ($chart_labels as $k) {
        $val = array_key_exists($k, $data_map) ? $data_map[$k] : null;
        $series[] = $val;
        if ($val !== null) {
            $global_max_abs = max($global_max_abs, abs((float)$val));
        }
    }
    $chart_data[$label] = $series;
}
if ($global_max_abs == 0) {
    $global_max_abs = 1;
}
    cache_set($chart_cache_key, [
        'labels' => $chart_labels,
        'data' => $chart_data,
        'names' => $chart_names,
        'max' => $global_max_abs,
    ]);
}

$perbandingan_penjelasan_map = [];
if ($komoditas_filter !== '' && $bulan !== '' && $tahun !== '' && ctype_digit((string)$tahun)) {
    try {
        $stmt_penjelasan = $pdo->prepare("
            SELECT kode_kabupaten, penjelasan
            FROM perbandingan_penjelasan
            WHERE TRIM(LOWER(komoditas)) = ?
              AND TRIM(LOWER(bulan)) = ?
              AND tahun = ?
        ");
        $stmt_penjelasan->execute([
            strtolower(trim($komoditas_filter)),
            strtolower(trim($bulan)),
            (int)$tahun,
        ]);
        while ($row_penjelasan = $stmt_penjelasan->fetch()) {
            $perbandingan_penjelasan_map[(string)$row_penjelasan['kode_kabupaten']] = (string)($row_penjelasan['penjelasan'] ?? '');
        }
    } catch (Throwable $e) {
        $perbandingan_penjelasan_map = [];
    }
}

$kabupaten_status_rows = [];
$status_summary = [
    'aligned' => 0,
    'mixed' => 0,
    'partial' => 0,
    'empty' => 0,
];

foreach ($chart_labels as $idx => $kode) {
    $row_values = [];
    $row_non_zero = [];
    foreach (array_keys($table_map) as $label) {
        $value = $chart_data[$label][$idx] ?? null;
        $row_values[$label] = $value;
        if ($value === null || (float)$value == 0.0) continue;
        $row_non_zero[$label] = (float)$value;
    }

    $has_pos = false;
    $has_neg = false;
    foreach ($row_non_zero as $value) {
        if ($value > 0) $has_pos = true;
        if ($value < 0) $has_neg = true;
    }

    $status_key = 'empty';
    $status_label = 'Tidak ada data';
    if ($has_pos && $has_neg) {
        $status_key = 'mixed';
        $status_label = 'Tidak searah';
    } elseif (count($row_non_zero) >= 2) {
        $status_key = 'aligned';
        $status_label = 'Searah';
    } elseif (count($row_non_zero) === 1) {
        $status_key = 'partial';
        $status_label = 'Data parsial';
    }
    $status_summary[$status_key]++;

    $intensity = 0.0;
    foreach ($row_non_zero as $value) {
        $intensity = max($intensity, abs($value));
    }
    $kabupaten_status_rows[] = [
        'kode' => $kode,
        'nama' => $chart_names[$kode] ?? $kode,
        'status_key' => $status_key,
        'status_label' => $status_label,
        'values' => $row_values,
        'active_count' => count($row_non_zero),
        'intensity' => $intensity,
        'penjelasan' => $perbandingan_penjelasan_map[$kode] ?? '',
        'can_edit_penjelasan' => can_edit_penjelasan($user, (string)$kode),
    ];
}

$top_attention = array_values(array_filter($kabupaten_status_rows, function ($row) {
    return $row['status_key'] === 'mixed';
}));
usort($top_attention, function ($a, $b) {
    if ($a['active_count'] === $b['active_count']) {
        return $b['intensity'] <=> $a['intensity'];
    }
    return $b['active_count'] <=> $a['active_count'];
});
$top_attention = array_slice($top_attention, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Perbandingan Harga</title>
    <link rel="stylesheet" href="assets/vendors/mdi/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="assets/vendors/ti-icons/css/themify-icons.css">
    <link rel="stylesheet" href="assets/vendors/css/vendor.bundle.base.css">
    <link rel="stylesheet" href="assets/vendors/font-awesome/css/font-awesome.min.css">
    <link rel="shortcut icon" href="assets/images/rh-icon.png" />
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');

      :root {
        --bg: #f7f5fb;
        --bg-2: #f0ecf6;
        --card: #ffffff;
        --ink: #2f3441;
        --muted: #8b90a3;
        --accent: #f28b2b;
        --accent-2: #f6b7c8;
        --shadow: 0 20px 50px rgba(56, 65, 80, 0.12);
        --pill: 16px;
        --radius: 18px;
        --sidebar: #ffffff;
        --sidebar-border: #eef0f4;
      }

      * { box-sizing: border-box; }
      body {
        margin: 0;
        font-family: "Poppins", sans-serif;
        color: var(--ink);
        background: radial-gradient(1200px 600px at 30% 0%, #f7f0f3 0%, var(--bg) 50%, var(--bg-2) 100%);
      }

      .app {
        min-height: 100vh;
        display: grid;
        grid-template-columns: 84px 1fr;
        gap: 18px;
        padding: 22px;
      }

      .sidebar {
        background: var(--sidebar);
        border-radius: 22px;
        border: 1px solid var(--sidebar-border);
        padding: 16px 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 14px;
        box-shadow: var(--shadow);
      }

      .logo {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #fff;
        font-weight: 700;
        font-size: 14px;
      }

      .nav-dot {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        background: #f4f6f9;
        display: grid;
        place-items: center;
        color: #7b8794;
        text-decoration: none;
        transition: all .2s ease;
      }
      .nav-text { font-size: 12px; font-weight: 700; color: inherit; letter-spacing: 0.5px; }
      .nav-dot.active, .nav-dot:hover {
        background: #fff;
        color: var(--accent);
        box-shadow: 0 12px 24px rgba(242, 139, 43, 0.2);
      }

      .main {
        background: transparent;
        padding-right: 8px;
      }

      .topbar {
        display: grid;
        grid-template-columns: 1fr auto auto auto auto auto;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
      }

      .hello {
        font-size: 24px;
        font-weight: 700;
      }
      .subhello { color: var(--muted); font-size: 12px; }

      .pill {
        background: var(--card);
        border-radius: var(--pill);
        padding: 8px 12px;
        border: 1px solid #eef0f4;
        box-shadow: 0 12px 24px rgba(56, 65, 80, 0.08);
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: var(--muted);
      }

      .pill select, .pill input {
        border: 0;
        outline: none;
        background: transparent;
        font-size: 12px;
        color: var(--ink);
      }

      .actions {
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .filters {
        display: flex;
        align-items: center;
        gap: 10px;
      }
      .filters button {
        border-radius: 10px; padding: 8px 14px; font-size: 12px; font-weight: 600; border: none;
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #fff;
        box-shadow: 0 10px 22px rgba(242, 139, 43, 0.25);
      }
      .icon-btn {
        width: 36px;
        height: 36px;
        border-radius: 12px;
        background: var(--card);
        border: 1px solid #eef0f4;
        display: grid;
        place-items: center;
        box-shadow: 0 10px 22px rgba(56, 65, 80, 0.08);
      }

      .cards {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 24px;
      }
      .subinfo {
        margin: 12px 0 18px;
        color: #3a4a5a;
        font-size: 13px;
        font-weight: 700;
        background: #ffffff;
        border: 1px solid #eef0f4;
        border-radius: 14px;
        padding: 8px 14px;
        box-shadow: 0 10px 22px rgba(56, 65, 80, 0.08);
        display: inline-flex;
        gap: 8px;
      }
      .card {
        background: #ffffff;
        border-radius: 30px;
        padding: 18px 22px;
        box-shadow: 0 12px 28px rgba(56, 65, 80, 0.10);
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: 14px;
      }
      .card h4 {
        margin: 0;
        font-size: 18px;
        color: #9aa3ad;
        font-weight: 700;
        letter-spacing: 0.5px;
      }
      .metric {
        font-size: 36px;
        font-weight: 800;
        letter-spacing: 0.5px;
        color: #4a5a6a;
      }
      .metric-pos { color: #41B38A !important; }
      .metric-neg { color: #ffb36b !important; }
      .metric-wrap { display: flex; align-items: center; gap: 12px; }
      .trend {
        font-size: 28px;
        font-weight: 900;
        display: inline-flex;
        align-items: center;
        line-height: 1;
      }
      .trend-up { color: #22c55e; }
      .trend-down { color: #ef4444; }
      .hk, .hpb, .hd, .hkd { color: inherit; }


      .panel {
        background: var(--card);
        border-radius: var(--radius);
        padding: 20px 22px 22px;
        box-shadow: 0 14px 28px rgba(56, 65, 80, 0.10);
      }

      .panel-title {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 0;
      }
      .panel-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
      }
      .panel-copy {
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
      .panel-caption {
        color: #8b90a3;
        font-size: 12px;
      }
      .panel-subpill {
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #ffffff;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
        box-shadow: 0 10px 22px rgba(255, 122, 182, 0.25);
      }
      .insight-strip {
        display: grid;
        grid-template-columns: 1.3fr 1fr;
        gap: 16px;
        margin-bottom: 20px;
      }
      .dual-panels {
        display: grid;
        grid-template-columns: 0.95fr 1.05fr;
        gap: 16px;
        align-items: start;
        margin-bottom: 16px;
      }
      .dual-panels .panel {
        margin: 0 !important;
      }
      .status-board,
      .attention-board {
        border-radius: 18px;
        background: linear-gradient(180deg, #fffdfa 0%, #ffffff 100%);
        border: 1px solid #f1f5f9;
        padding: 14px 16px;
      }
      .strip-title {
        font-size: 12px;
        font-weight: 700;
        color: #475569;
        margin-bottom: 12px;
      }
      .status-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
      }
      .status-tile {
        padding: 10px 12px;
        border-radius: 14px;
        background: #f8fafc;
      }
      .status-tile strong {
        display: block;
        font-size: 20px;
        font-weight: 800;
        color: #334155;
        margin-bottom: 4px;
      }
      .status-tile span {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
      }
      .status-tile.aligned {
        background: rgba(22, 143, 74, 0.08);
      }
      .status-tile.mixed {
        background: rgba(245, 162, 93, 0.16);
      }
      .status-tile.partial {
        background: rgba(148, 163, 184, 0.12);
      }
      .status-tile.empty {
        background: #f8fafc;
      }
      .attention-list {
        display: grid;
        gap: 8px;
      }
      .attention-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 14px;
        background: #fff7ed;
      }
      .attention-item strong {
        display: block;
        font-size: 12px;
        color: #334155;
      }
      .attention-item span {
        display: block;
        font-size: 10px;
        color: #9a3412;
        margin-top: 2px;
      }
      .attention-badge {
        padding: 6px 10px;
        border-radius: 999px;
        background: rgba(245, 158, 11, 0.16);
        color: #b45309;
        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
      }
      .attention-empty {
        border-radius: 14px;
        padding: 14px 12px;
        background: #f8fafc;
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
      }
      .divider {
        height: 1px;
        background: #eef0f4;
        margin: 16px 0;
      }
      .heatmap {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
      }
      .heatmap th, .heatmap td {
        padding: 8px 6px;
        text-align: center;
        font-size: 11px;
        border-bottom: 1px solid #eef0f4;
      }
      .heatmap th {
        text-align: left;
        color: #6b7280;
        font-weight: 700;
      }
      .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        padding: 4px 6px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 11px;
      }
      .badge-pos { background: rgba(22,163,74,0.12); color: #168f4a; }
      .badge-neg { background: rgba(229,83,83,0.12); color: #d94b4b; }
      .badge-zero { background: rgba(148,163,184,0.15); color: #6b7280; }
      .mini-bars {
        display: grid;
        gap: 8px;
      }
      .mini-row {
        display: grid;
        grid-template-columns: 152px minmax(92px, 1fr) minmax(92px, 1fr) minmax(92px, 1fr) minmax(92px, 1fr);
        align-items: center;
        column-gap: 10px;
        row-gap: 0;
        font-size: 10px;
        padding: 4px 0;
        border-top: 1px solid #f1f5f9;
      }
      .mini-header {
        display: grid;
        grid-template-columns: 152px minmax(92px, 1fr) minmax(92px, 1fr) minmax(92px, 1fr) minmax(92px, 1fr);
        gap: 10px;
        align-items: center;
        min-height: 20px;
        margin-bottom: 8px;
        padding-bottom: 8px;
        border-bottom: 1px solid #eef2f7;
        color: #6b7280;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        position: sticky;
        top: 0;
        background: #ffffff;
        z-index: 2;
      }
      .mini-header div { text-align: center; line-height: 18px; }
      .mini-label {
        color: #334155;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 10px;
      }
      .mini-cell {
        display: grid;
        grid-template-columns: 1fr 46px;
        align-items: center;
        gap: 3px;
      }
      .mini-out {
        font-size: 10px;
        color: #6b7280;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        text-align: right;
      }
      .mini-out.right {
        text-align: center;
        width: 46px;
        height: 14px;
        line-height: 14px;
        border-radius: 6px;
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #e5e7eb;
        font-size: 9px;
      }
      .mini-out.pos {
        background: rgba(68,84,104,0.12);
        color: #41B38A;
        border-color: rgba(68,84,104,0.25);
      }
      .mini-out.neg {
        background: rgba(255, 122, 182, 0.12);
        color: #ffb36b;
        border-color: rgba(255, 122, 182, 0.35);
      }
      .mini-value {
        position: static;
      }
      .mini-value.left {
        text-align: left;
      }
      .mini-track {
        position: relative;
        height: 12px;
        background: #f1f5f9;
        border-radius: 999px;
        overflow: hidden;
      }
      .mini-track::after {
        content: "";
        position: absolute;
        left: 50%;
        top: 2px;
        bottom: 2px;
        border-left: 1px dashed rgba(55, 65, 81, 0.35);
      }
      .mini-fill {
        position: absolute;
        left: 50%;
        top: 0;
        bottom: 0;
        transform-origin: left center;
      }
      .mini-pos { background: #41B38A; }
      .mini-neg { background: linear-gradient(135deg, #f6b7c8, #f5a25d); }
      .mini-zero { background: #cbd5f5; }
      .compare-panel {
        margin-top: 16px;
      }
      .compare-scroll {
        overflow-x: auto;
        padding-bottom: 6px;
      }
      .notes-panel {
        margin-top: 14px;
      }
      .notes-list {
        display: grid;
        gap: 8px;
      }
      .notes-row {
        display: grid;
        grid-template-columns: 170px 1fr;
        align-items: start;
        gap: 10px;
        padding: 6px 0;
        border-top: 1px solid #f1f5f9;
      }
      .notes-label {
        font-size: 11px;
        font-weight: 700;
        color: #334155;
        padding-top: 6px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .compare-notes {
        width: 100%;
      }
      .compare-note {
        width: 100%;
        min-height: 26px;
        border-radius: 12px;
        border: 1px solid #e6ebf2;
        background: #ffffff;
        padding: 5px 10px;
        resize: vertical;
        font-size: 11px;
        line-height: 1.25;
        color: #334155;
        font-family: inherit;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        overflow: hidden;
      }
      .compare-note:focus {
        outline: none;
        border-color: #f5a25d;
        box-shadow: 0 0 0 3px rgba(245, 162, 93, 0.14);
      }
      .compare-note[disabled] {
        background: #eef2f7;
        color: #94a3b8;
        cursor: not-allowed;
      }
      .note-status {
        margin-top: 4px;
        font-size: 10px;
        color: #94a3b8;
        min-height: 14px;
      }
      .note-status.saved {
        color: #16a34a;
      }
      .note-status.error {
        color: #d94b4b;
      }

      @media (max-width: 1200px) {
        .cards { grid-template-columns: repeat(2, 1fr); }
        .grid { grid-template-columns: 1fr; }
        .app { grid-template-columns: 1fr; }
        .sidebar { flex-direction: row; justify-content: flex-start; overflow-x: auto; }
        .main { padding-right: 0; }
        .insight-strip { grid-template-columns: 1fr; }
        .status-grid { grid-template-columns: repeat(2, 1fr); }
        .dual-panels { grid-template-columns: 1fr; }
        .mini-row, .mini-header { min-width: 700px; }
      }
      @media (max-width: 768px) {
        .app { grid-template-columns: 1fr; padding: 14px; }
        .sidebar { gap: 10px; }
        .logo { width: 38px; height: 38px; }
        .nav-dot { width: 40px; height: 40px; }
        .topbar { grid-template-columns: 1fr; }
        .pill { width: 100%; justify-content: space-between; }
        .pill select { width: 100%; }
        .cards { grid-template-columns: 1fr; }
        .card { padding: 14px 16px; border-radius: 24px; }
        .card h4 { font-size: 16px; }
        .metric { font-size: 28px; }
        .trend { font-size: 22px; }
        .metric-wrap { gap: 8px; }
        .mini-bars { padding-bottom: 6px; }
        .mini-row, .mini-header { min-width: 700px; }
        .status-grid { grid-template-columns: 1fr; }
        .notes-row { grid-template-columns: 1fr; }
        .notes-label { padding-top: 0; }
      }
    
      /* B: Status colors */
      .text-perubahan-pos, .avg-pill .avg-trend.pos, .trend-up, .badge-pos, .mini-out.pos { color: #168f4a !important; }
      .text-perubahan-neg, .avg-pill .avg-trend.neg, .trend-down, .badge-neg, .mini-out.neg { color: #d94b4b !important; }
      .avg-pill .avg-trend.zero, .badge-zero { color: #6b7280 !important; }
    
      /* A: Unified brand actions & table headers */
      :root {
        --rh-gradient: linear-gradient(135deg, #f6b7c8, #f5a25d);
        --rh-accent: #f28b2b;
      }
      .filters button,
      .icon-btn.filter-btn,
      .tab-btn.active,
      .nav-dot.active,
      .nav-dot:hover {
        background: var(--rh-gradient) !important;
        color: #f7f5fb !important;
        border: none !important;
        box-shadow: 0 10px 22px rgba(242, 139, 43, 0.25) !important;
      }
      .avg-pill,
      .badge-important {
        background: var(--rh-gradient) !important;
        color: #f7f5fb !important;
      }
      table th:not(.head-yellow):not(.head-pink):not(.subhead):not(.subhead-dark) {
        background: #2f3441 !important;
        color: #f7f5fb !important;
        border: none !important;
      }
      table td {
        border: none !important;
      }
      .nav-dot img { width: 18px; height: 18px; display: block; }
    </style>
  </head>
  <body>
    <div class="app">
      <aside class="sidebar">
        <div class="logo">RH</div>
        <a class="nav-dot" href="index.php" title="Dashboard"><i class="mdi mdi-view-dashboard"></i></a>
        <a class="nav-dot active" href="perbandingan-harga.php" title="Perbandingan Harga"><i class="mdi mdi-chart-line"></i></a>
        <a class="nav-dot" href="shk.php" title="SHK"><span class="nav-text">HK</span></a>
        <a class="nav-dot" href="hpb.php" title="HPB"><span class="nav-text">HPB</span></a>
        <a class="nav-dot" href="hd.php" title="HD"><span class="nav-text">HD</span></a>
        <a class="nav-dot" href="hkd.php" title="HKD"><span class="nav-text">HKD</span></a>
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="panduan.php" title="Panduan"><i class="mdi mdi-book-open-variant"></i></a>
      </aside>

      <main class="main">
        <form class="topbar" method="get" action="perbandingan-harga.php">
          <div>
            <div class="hello">Perbandingan Perubahan Harga</div>
            <div class="subhello">Ringkasan perbandingan harga komoditas.</div>
          </div>
          <div class="pill" style="min-width:240px;">
            <i class="mdi mdi-filter-variant"></i>
            <select name="komoditas" style="min-width: 180px;" onchange="this.form.submit()">
              <option value="">Semua Komoditas</option>
              <?php foreach ($komoditas_list as $key): ?>
                <?php $label = $komoditas_map[$key] ?? $key; ?>
                <?php $selected = ($komoditas_selected_key === $key) ? 'selected' : ''; ?>
                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="pill">
            <i class="mdi mdi-calendar"></i>
            <select name="bulan" onchange="this.form.submit()">
              <option value="">Semua</option>
              <?php
                $bulan_list = ['januari','februari','maret','april','mei','juni','juli','agustus','september','oktober','november','desember'];
                foreach ($bulan_list as $b) {
                  $selected = ($bulan === $b) ? 'selected' : '';
                  echo '<option value="' . htmlspecialchars($b) . '" ' . $selected . '>' . htmlspecialchars(ucfirst($b)) . '</option>';
                }
              ?>
            </select>
          </div>
          <div class="pill">
            <i class="mdi mdi-calendar-month"></i>
            <select name="tahun" onchange="this.form.submit()">
              <option value="">Semua</option>
              <?php
                $tahun_list = range(2020, 2030);
                foreach ($tahun_list as $t) {
                  $selected = ((string)$tahun === (string)$t) ? 'selected' : '';
                  echo '<option value="' . $t . '" ' . $selected . '>' . $t . '</option>';
                }
              ?>
            </select>
          </div>
          <div class="filters">
            <button type="submit">Filter</button>
          </div>
          <div class="actions">
            <div class="user-pill" style="padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #eef0f4;font-size:12px;color:#6b7280;box-shadow:0 6px 14px rgba(56,65,80,0.08);"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
            <a class="icon-btn" href="logout.php" title="Logout"><i class="mdi mdi-logout"></i></a>
          </div>
        </form>

        <div class="dual-panels">
        <div class="panel">
          <div class="panel-head">
            <div class="panel-copy">
              <div class="panel-title">Ringkasan Level Harga</div>
              <div class="panel-caption">Ringkasan rata-rata perubahan untuk setiap level harga pada filter yang sedang aktif.</div>
            </div>
          </div>
          <div class="cards">
            <?php
              $cards = [
                'HK' => 'hk',
                'HPB' => 'hpb',
                'HD' => 'hd',
                'HKD' => 'hkd',
              ];
              foreach ($cards as $label => $class):
                $avg = $avg_map[$label];
                $has = ($avg !== null);
                if ($has) {
                  $display = number_format((float)$avg, 2, ',', '.') . '%';
                } else {
                  $display = '-';
                }
                $trend = '';
                if ($has) {
                  if ($avg > 0) $trend = 'up';
                  elseif ($avg < 0) $trend = 'down';
                  else $trend = 'zero';
                }
            ?>
              <div class="card label-offset">
                <div>
                  <h4><?php echo $label; ?></h4>
                </div>
                <div class="metric-wrap">
                  <div class="metric <?php echo $class; ?><?php echo $has ? ($avg >= 0 ? ' metric-pos' : ' metric-neg') : ''; ?>"><?php echo $display; ?></div>
                </div>
                <?php if ($has && $trend !== 'zero'): ?>
                  <div class="trend trend-<?php echo $trend; ?>"><?php echo $trend === 'up' ? '▲' : '▼'; ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <div class="panel-copy">
              <div class="panel-title">Perbandingan HK/HPB/HD/HKD per Kabupaten/Kota</div>
              <div class="panel-caption">Fokus utamanya melihat apakah arah perubahan setiap level harga saling sejalan atau justru berlawanan.</div>
            </div>
            <div class="panel-subpill">
              Komoditas: <?php echo htmlspecialchars($display_komoditas); ?> · Bulan: <?php echo htmlspecialchars(ucfirst($bulan)); ?> · Tahun: <?php echo htmlspecialchars($tahun); ?>
            </div>
          </div>
          <div class="insight-strip">
            <div class="status-board">
              <div class="strip-title">Ringkasan arah kabupaten/kota</div>
              <div class="status-grid">
                <div class="status-tile aligned">
                  <strong><?php echo (int)$status_summary['aligned']; ?></strong>
                  <span>Searah</span>
                </div>
                <div class="status-tile mixed">
                  <strong><?php echo (int)$status_summary['mixed']; ?></strong>
                  <span>Tidak searah</span>
                </div>
                <div class="status-tile partial">
                  <strong><?php echo (int)$status_summary['partial']; ?></strong>
                  <span>Data parsial</span>
                </div>
                <div class="status-tile empty">
                  <strong><?php echo (int)$status_summary['empty']; ?></strong>
                  <span>Tidak ada data</span>
                </div>
              </div>
            </div>
            <div class="attention-board">
              <div class="strip-title">Kabupaten yang perlu dicek lebih dulu</div>
              <?php if ($top_attention): ?>
                <div class="attention-list">
                  <?php foreach ($top_attention as $item): ?>
                    <div class="attention-item">
                      <div>
                        <strong><?php echo htmlspecialchars($item['nama']); ?></strong>
                        <span>Ada arah positif dan negatif pada level harga yang tampil</span>
                      </div>
                      <div class="attention-badge"><?php echo (int)$item['active_count']; ?> level aktif</div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="attention-empty">Belum ada kabupaten dengan arah campuran untuk filter yang sedang dipilih.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        </div>

        <div class="panel compare-panel">
          <div class="panel-head">
            <div class="panel-copy">
              <div class="panel-title">Chart Kabupaten/Kota</div>
              <div class="panel-caption">Tampilan ringkas untuk membandingkan nilai antar kabupaten/kota dengan lebih cepat.</div>
            </div>
          </div>
          <div class="mini-header">
            <div></div>
            <div>HK</div>
            <div>HPB</div>
            <div>HD</div>
            <div>HKD</div>
          </div>
          <div class="compare-scroll">
          <div class="mini-bars">
            <?php
              foreach ($kabupaten_status_rows as $row_data):
                $kode = $row_data['kode'];
                $valHK  = $row_data['values']['HK'] ?? null;
                $valHPB = $row_data['values']['HPB'] ?? null;
                $valHD  = $row_data['values']['HD'] ?? null;
                $valHKD = $row_data['values']['HKD'] ?? null;
                $label_text = $row_data['nama'];
                $rows = [
                  'HK' => $valHK,
                  'HPB' => $valHPB,
                  'HD' => $valHD,
                  'HKD' => $valHKD,
                ];
                $maxAbs = (float)$global_max_abs;
            ?>
              <div class="mini-row">
                <div class="mini-label" title="<?php echo htmlspecialchars($label_text); ?>"><?php echo htmlspecialchars($label_text); ?></div>
                <?php foreach ($rows as $label => $v): 
                  $val = ($v === null) ? 0 : (float)$v;
                  $width = min(50, (abs($val) / $maxAbs) * 50);
                  $cls = $val > 0 ? 'mini-pos' : ($val < 0 ? 'mini-neg' : 'mini-zero');
                  $dir = $val >= 0 ? 1 : -1;
                  $val_display = ($v === null) ? '-' : number_format((float)$v, 2, ',', '.');
                ?>
                  <div class="mini-cell">
                    <div class="mini-track" title="<?php echo $label . ': ' . number_format($val, 2, ',', '.'); ?>">
                      <div class="mini-fill <?php echo $cls; ?>" style="width: <?php echo $width; ?>%; transform: translateX(<?php echo $dir >= 0 ? 0 : -100; ?>%);"></div>
                    </div>
                    <?php if ($val != 0): ?>
                      <div class="mini-out right <?php echo ($val > 0 ? 'pos' : 'neg'); ?>">
                        <?php echo ($val > 0 ? '▲' : '▼') . ' ' . htmlspecialchars($val_display); ?>
                      </div>
                    <?php else: ?>
                      <div></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
          </div>

        </div>

        <div class="panel notes-panel">
          <div class="panel-head">
            <div class="panel-copy">
              <div class="panel-title">Penjelasan Kabupaten/Kota</div>
              <div class="panel-caption">Area isi penjelasan dipisahkan agar chart perbandingan tetap rapat dan mudah dibaca dalam satu layar.</div>
            </div>
          </div>
          <div class="notes-list">
            <?php foreach ($kabupaten_status_rows as $row_data): ?>
              <div class="notes-row">
                <div class="notes-label" title="<?php echo htmlspecialchars((string)$row_data['nama']); ?>">
                  <?php echo htmlspecialchars((string)$row_data['nama']); ?>
                </div>
                <div class="compare-notes">
                  <textarea
                    class="compare-note"
                    rows="1"
                    data-kode="<?php echo htmlspecialchars((string)$row_data['kode']); ?>"
                    data-nama="<?php echo htmlspecialchars((string)$row_data['nama']); ?>"
                    data-komoditas="<?php echo htmlspecialchars((string)$display_komoditas); ?>"
                    data-bulan="<?php echo htmlspecialchars((string)$bulan); ?>"
                    data-tahun="<?php echo htmlspecialchars((string)$tahun); ?>"
                    placeholder="Isi penjelasan"
                    <?php echo !empty($row_data['can_edit_penjelasan']) ? '' : 'disabled'; ?>
                  ><?php echo htmlspecialchars((string)$row_data['penjelasan']); ?></textarea>
                  <div class="note-status"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </main>
    </div>

    <script></script>
  
    <script>
      (function () {
        function ping() {
          fetch('presence.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'ping=1'
          }).catch(function () {});
        }
        ping();
        setInterval(ping, 60000);
      })();

      (function () {
        var textareas = Array.prototype.slice.call(document.querySelectorAll('.compare-note'));

        function resize(el) {
          if (!el) return;
          el.style.height = 'auto';
          el.style.height = Math.max(26, el.scrollHeight) + 'px';
        }

        function setStatus(el, text, cls) {
          var box = el.parentElement ? el.parentElement.querySelector('.note-status') : null;
          if (!box) return;
          box.className = 'note-status' + (cls ? ' ' + cls : '');
          box.textContent = text || '';
        }

        function save(el) {
          if (!el || el.disabled) return;
          var current = el.value;
          if ((el.dataset.lastSaved || '') === current) {
            setStatus(el, '');
            return;
          }
          setStatus(el, 'Menyimpan...', '');
          var payload = new URLSearchParams();
          payload.set('action', 'save_penjelasan');
          payload.set('kode_kabupaten', el.dataset.kode || '');
          payload.set('nama_kabupaten', el.dataset.nama || '');
          payload.set('komoditas', el.dataset.komoditas || '');
          payload.set('bulan', el.dataset.bulan || '');
          payload.set('tahun', el.dataset.tahun || '');
          payload.set('penjelasan', current);

          fetch('perbandingan-harga.php?komoditas=<?php echo rawurlencode((string)$komoditas_selected_key); ?>&bulan=<?php echo rawurlencode((string)$bulan); ?>&tahun=<?php echo rawurlencode((string)$tahun); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload.toString()
          })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (!data || !data.ok) {
              throw new Error((data && data.message) || 'Gagal menyimpan');
            }
            el.dataset.lastSaved = current;
            setStatus(el, 'Tersimpan', 'saved');
          })
          .catch(function () {
            setStatus(el, 'Gagal menyimpan', 'error');
          });
        }

        textareas.forEach(function (el) {
          el.dataset.lastSaved = el.value;
          resize(el);
          el.addEventListener('input', function () {
            resize(el);
            setStatus(el, '');
          });
          el.addEventListener('blur', function () {
            save(el);
          });
        });
      })();
    </script>

  </body>
</html>
