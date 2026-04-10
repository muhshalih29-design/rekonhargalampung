<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();

ob_start();

$cache_ttl = 300;
$page_cache_ttl = 120;
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

$page_cache_key = 'page_perbandingan_' . md5($_SERVER['QUERY_STRING'] ?? '');
$page_cached = cache_get_html($page_cache_key, $page_cache_ttl);
if ($page_cached !== null) {
    echo $page_cached;
    exit;
}

$pdo = db();

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
    <link rel="shortcut icon" href="assets/images/favicon.png" />
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');

      :root {
        --bg: #f7f5f6;
        --bg-2: #f1eaee;
        --card: #ffffff;
        --ink: #374151;
        --muted: #8a93a0;
        --accent: #f28b2b;
        --accent-2: #ff8ea3;
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
        background: linear-gradient(135deg, #ff7ab6, #ffb36b);
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
        margin-bottom: 16px;
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
        border-radius: 9999px;
        padding: 16px 24px;
        box-shadow: 0 12px 28px rgba(56, 65, 80, 0.10);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
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
        padding: 16px;
        box-shadow: 0 14px 28px rgba(56, 65, 80, 0.10);
      }

      .panel-title {
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 10px;
      }
      .panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
      }
      .panel-subpill {
        background: linear-gradient(135deg, #ff7ab6, #ffb36b);
        color: #ffffff;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
        box-shadow: 0 10px 22px rgba(255, 122, 182, 0.25);
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
        gap: 4px;
      }
      .mini-row {
        display: grid;
        grid-template-columns: 160px 1fr 1fr 1fr 1fr;
        align-items: center;
        column-gap: 28px;
        row-gap: 0;
        font-size: 11px;
        margin-bottom: 6px;
      }
      .mini-header {
        display: grid;
        grid-template-columns: 160px 1fr 1fr 1fr 1fr;
        gap: 10px;
        align-items: center;
        height: 18px;
        margin-bottom: 4px;
        color: #6b7280;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.3px;
        text-transform: uppercase;
      }
      .mini-header div { text-align: center; line-height: 18px; }
      .mini-label { color: #6b7280; font-weight: 600; }
      .mini-label { white-space: nowrap; }
      .mini-cell {
        display: grid;
        grid-template-columns: 1fr 50px;
        align-items: center;
        gap: 6px;
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
        width: 50px;
        height: 18px;
        line-height: 18px;
        border-radius: 6px;
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #e5e7eb;
        font-size: 10px;
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
        height: 18px;
        background: #f1f5f9;
        border-radius: 999px;
        overflow: hidden;
      }
      .mini-track::after {
        content: "";
        position: absolute;
        left: 50%;
        top: 3px;
        bottom: 3px;
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
      .mini-neg { background: linear-gradient(135deg, #ff7ab6, #ffb36b); }
      .mini-zero { background: #cbd5f5; }

      @media (max-width: 1200px) {
        .cards { grid-template-columns: repeat(2, 1fr); }
        .grid { grid-template-columns: 1fr; }
        .app { grid-template-columns: 1fr; }
        .sidebar { flex-direction: row; justify-content: flex-start; overflow-x: auto; }
        .main { padding-right: 0; }
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
        .mini-bars { overflow-x: auto; padding-bottom: 6px; }
        .mini-row, .mini-header { min-width: 720px; }
      }
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
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><i class="mdi mdi-grain"></i></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><i class="mdi mdi-alert-circle-outline"></i></a>
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
          <div class="actions">
            <a class="icon-btn" href="logout.php" title="Logout"><i class="mdi mdi-logout"></i></a>
          </div>
        </form>


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
              <h4><?php echo $label; ?></h4>
              <div class="metric-wrap">
                <div class="metric <?php echo $class; ?><?php echo $has ? ($avg >= 0 ? ' metric-pos' : ' metric-neg') : ''; ?>"><?php echo $display; ?></div>
              </div>
              <?php if ($has && $trend !== 'zero'): ?>
                <div class="trend trend-<?php echo $trend; ?>"><?php echo $trend === 'up' ? '▲' : '▼'; ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">Perbandingan HK/HPB/HD/HKD per Kabupaten/Kota</div>
            <div class="panel-subpill">
              Komoditas: <?php echo htmlspecialchars($display_komoditas); ?> · Bulan: <?php echo htmlspecialchars(ucfirst($bulan)); ?> · Tahun: <?php echo htmlspecialchars($tahun); ?>
            </div>
          </div>
          <div class="mini-header">
            <div></div>
            <div>HK</div>
            <div>HPB</div>
            <div>HD</div>
            <div>HKD</div>
          </div>
          <div class="mini-bars">
            <?php
              foreach ($chart_labels as $idx => $kode):
                $valHK  = $chart_data['HK'][$idx] ?? null;
                $valHPB = $chart_data['HPB'][$idx] ?? null;
                $valHD  = $chart_data['HD'][$idx] ?? null;
                $valHKD = $chart_data['HKD'][$idx] ?? null;
                $label_text = $kode;
                if (isset($chart_names[$kode]) && $chart_names[$kode] !== '') {
                  $label_text = $chart_names[$kode];
                }
                $rows = [
                  'HK' => $valHK,
                  'HPB' => $valHPB,
                  'HD' => $valHD,
                  'HKD' => $valHKD,
                ];
                $maxAbs = (float)$global_max_abs;
            ?>
              <div class="mini-row">
                <div class="mini-label"><?php echo htmlspecialchars($label_text); ?></div>
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
      </main>
    </div>

    <script></script>
  </body>
</html>
<?php
if (!headers_sent()) {
    header('Cache-Control: public, max-age=' . $page_cache_ttl);
}
$page_html = ob_get_contents();
if ($page_html !== false) {
    cache_set_html($page_cache_key, $page_html);
}
?>
