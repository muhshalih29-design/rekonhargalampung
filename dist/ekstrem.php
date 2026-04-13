<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();
$pdo = db();

$all = isset($_GET['all']) ? trim($_GET['all']) : '';
$bulan = isset($_GET['bulan']) ? trim($_GET['bulan']) : '';
$tahun = isset($_GET['tahun']) ? trim($_GET['tahun']) : '';

if ($all === '' && $bulan === '' && $tahun === '') {
    $lastMonth = new DateTime('first day of last month');
    $bulan = strtolower($lastMonth->format('F'));
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
    $bulan = $map[$bulan] ?? $bulan;
    $tahun = $lastMonth->format('Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $field = isset($_POST['field']) ? trim($_POST['field']) : '';
    $value = isset($_POST['value']) ? $_POST['value'] : null;

    $allowed = [
        'subsektor' => 'text',
        'kab' => 'int',
        'kecamatan' => 'int',
        'komoditas' => 'text',
        'kualitas' => 'text',
        'satuan' => 'text',
        'harga_bulan_ini' => 'decimal',
        'harga_bulan_lalu' => 'decimal',
        'perubahan_rata_rata' => 'decimal',
        'konfirmasi_kab' => 'text',
        'bulan' => 'text',
        'tahun' => 'int',
    ];

    if ($id <= 0 || !isset($allowed[$field])) {
        http_response_code(400);
        echo 'Invalid request';
        exit;
    }
    if (is_kabupaten($user) && $field !== 'konfirmasi_kab') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    if (is_kabupaten($user)) {
        $stmt_kab = $pdo->prepare('SELECT kab FROM ekstrem WHERE id = ?');
        $stmt_kab->execute([$id]);
        $row_kab = $stmt_kab->fetch();
        $kab = $row_kab ? (string)$row_kab['kab'] : '';
        $kab_code = (int)($user['kab_kode'] ?? 0);
        $kab_short = $kab_code > 0 ? (string)($kab_code % 100) : '';
        if ($kab_short === '' || $kab !== $kab_short) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    $type = $allowed[$field];

    if ($type === 'decimal') {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            $sql = "UPDATE ekstrem SET {$field} = NULL WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
        } else {
            $normalized = str_replace('.', '', $raw);
            $normalized = str_replace(',', '.', $normalized);
            $num = is_numeric($normalized) ? (float)$normalized : null;
            if ($num === null) {
                http_response_code(400);
                echo 'Invalid number';
                exit;
            }
            $sql = "UPDATE ekstrem SET {$field} = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$num, $id]);
        }
    } elseif ($type === 'int') {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            $sql = "UPDATE ekstrem SET {$field} = NULL WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
        } else {
            if (!ctype_digit($raw)) {
                http_response_code(400);
                echo 'Invalid number';
                exit;
            }
            $sql = "UPDATE ekstrem SET {$field} = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([(int)$raw, $id]);
        }
    } elseif ($type === 'enum') {
    } else {
        $raw = is_string($value) ? $value : '';
        $sql = "UPDATE ekstrem SET {$field} = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$raw, $id]);
    }

    echo 'OK';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_update') {
    if (is_kabupaten($user)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $payload = isset($_POST['payload']) ? $_POST['payload'] : '';
    $items = json_decode($payload, true);
    if (!is_array($items)) {
        http_response_code(400);
        echo 'Invalid payload';
        exit;
    }
    $allowed = [
        'subsektor' => 'text',
        'kab' => 'int',
        'kecamatan' => 'int',
        'komoditas' => 'text',
        'kualitas' => 'text',
        'satuan' => 'text',
        'harga_bulan_ini' => 'decimal',
        'harga_bulan_lalu' => 'decimal',
        'perubahan_rata_rata' => 'decimal',
        'konfirmasi_kab' => 'text',
        'bulan' => 'text',
        'tahun' => 'int',
    ];
    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int)$item['id'] : 0;
            $field = isset($item['field']) ? trim($item['field']) : '';
            $value = isset($item['value']) ? $item['value'] : null;
            if ($id <= 0 || !isset($allowed[$field])) {
                continue;
            }
            $type = $allowed[$field];
            if ($type === 'decimal') {
                $raw = is_string($value) ? trim($value) : '';
                if ($raw === '') {
                    $sql = "UPDATE ekstrem SET {$field} = NULL WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$id]);
                } else {
                    $normalized = str_replace('.', '', $raw);
                    $normalized = str_replace(',', '.', $normalized);
                    $num = is_numeric($normalized) ? (float)$normalized : null;
                    if ($num === null) {
                        continue;
                    }
                    $sql = "UPDATE ekstrem SET {$field} = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$num, $id]);
                }
            } elseif ($type === 'int') {
                $raw = is_string($value) ? trim($value) : '';
                if ($raw === '') {
                    $sql = "UPDATE ekstrem SET {$field} = NULL WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$id]);
                } else {
                    if (!ctype_digit($raw)) {
                        continue;
                    }
                    $sql = "UPDATE ekstrem SET {$field} = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([(int)$raw, $id]);
                }
            } else {
                $raw = is_string($value) ? $value : '';
                $sql = "UPDATE ekstrem SET {$field} = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$raw, $id]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo 'Batch failed';
        exit;
    }
    echo 'OK';
    exit;
}

$where = [];
$types = '';
$params = [];

if ($bulan !== '') {
    $where[] = 'TRIM(LOWER(bulan)) = ?';
    $types .= 's';
    $params[] = strtolower(trim($bulan));
}
if ($tahun !== '' && ctype_digit($tahun)) {
    $where[] = 'tahun = ?';
    $types .= 'i';
    $params[] = (int)$tahun;
}
if (is_kabupaten($user)) {
    $kab_code = (int)($user['kab_kode'] ?? 0);
    if ($kab_code > 0) {
        $kab_short = $kab_code % 100;
        $where[] = 'kab = ?';
        $params[] = (string)$kab_short;
    }
}

$sql = 'SELECT * FROM ekstrem';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY komoditas ASC, kab ASC, kecamatan ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$avg_all = null;
$avg_sql = 'SELECT AVG(NULLIF(perubahan_rata_rata,0)) AS avg_perubahan FROM ekstrem';
if ($where) {
    $avg_sql .= ' WHERE ' . implode(' AND ', $where);
}
$stmt_avg = $pdo->prepare($avg_sql);
$stmt_avg->execute($params);
$avg_row = $stmt_avg->fetch();
if ($avg_row) {
    $avg_all = $avg_row['avg_perubahan'];
}

$columns = [
    'subsektor' => 'Subsektor',
    'kab' => 'Kab/Kot',
    'kecamatan' => 'Kec',
    'komoditas' => 'Komoditas',
    'kualitas' => 'Kualitas',
    'satuan' => 'Satuan',
    'harga_bulan_ini' => 'Harga Bulan Ini',
    'harga_bulan_lalu' => 'Harga Bulan Lalu',
    'perubahan_rata_rata' => 'Perubahan Rata-rata (%)',
    'konfirmasi_kab' => 'Konfirmasi Kab',
];
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Ekstrem</title>
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
        overflow-x: hidden;
      }

      .app {
        min-height: 100vh;
        display: grid;
        grid-template-columns: 84px 1fr;
        gap: 18px;
        padding: 22px;
        width: 100%;
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
        max-width: 1400px;
        width: 100%;
        min-width: 0;
      }

      .topbar {
        display: grid;
        grid-template-columns: 1fr auto auto auto auto;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
      }
      .th-label { display: block; font-size: 12px; font-weight: 700; }
      .th-filter {
        display: block;
        margin-top: 6px;
      }
      .th-filter select {
        width: 100%;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 4px 6px;
        font-size: 11px;
        background: #ffffff;
        color: #334155;
      }
      .tabs {
        margin: 54px 0 18px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        overflow-x: hidden;
      }
      .tab-btn {
        border: 1px solid #e5e7eb;
        background: #ffffff;
        color: #475569;
        padding: 8px 14px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all .2s ease;
        box-shadow: 0 8px 20px rgba(56,65,80,0.08);
      }
      .tab-btn.active {
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #fff;
        border-color: transparent;
        box-shadow: 0 12px 24px rgba(255, 122, 182, 0.25);
      }
      .table-card.hidden { display: none; }

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

      .pill select {
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

      .table-card {
        background: var(--card);
        border-radius: var(--radius);
        padding: 16px;
        box-shadow: 0 14px 28px rgba(56, 65, 80, 0.10);
        overflow-x: auto;
        max-width: 100%;
        -webkit-overflow-scrolling: touch;
      }
      .paste-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        font-weight: 700;
        color: #6b7280;
        padding: 6px 10px;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
      }
      .paste-status.active {
        color: #1f6f4f;
        background: rgba(65,179,138,0.12);
        border-color: rgba(65,179,138,0.35);
      }
      .komoditas-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
      }
      .avg-pill {
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #ffffff;
        padding: 8px 14px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 700;
        white-space: nowrap;
        box-shadow: 0 10px 24px rgba(255, 122, 182, 0.35);
      }
      .avg-pill .avg-trend {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 800;
        margin-right: 6px;
      }
      .avg-pill .avg-trend.pos { color: #16a34a; }
      .avg-pill .avg-trend.neg { color: #ef4444; }
      .avg-pill .avg-trend.zero { color: #6b7280; }

      table { width: 100%; border-collapse: separate; border-spacing: 0; }
      .ekstrem-table th:nth-child(1),
      .ekstrem-table td:nth-child(1) { width: 90px; }
      .ekstrem-table th:nth-child(2),
      .ekstrem-table td:nth-child(2) { width: 80px; }
      .ekstrem-table th:nth-child(3),
      .ekstrem-table td:nth-child(3) { width: 80px; }
      thead th {
        background: #445468; color: #ffffff; padding: 10px 12px; font-size: 12px; font-weight: 700; white-space: normal; line-height: 1.1;
      }
      tbody td {
        padding: 10px 12px; vertical-align: top; border-bottom: 1px solid #eef3f2; background: #ffffff; font-size: 13px; line-height: 1.4;
      }
      tbody tr.row-pos td:first-child {
        background: linear-gradient(90deg, rgba(124,227,143,0.45) 0%, #ffffff 85%) !important;
      }
      tbody tr.row-neg td:first-child {
        background: linear-gradient(90deg, rgba(255,138,138,0.45) 0%, #ffffff 85%) !important;
      }
      tbody tr:last-child td { border-bottom: 0; }

      .text-perubahan-pos { color: #168f4a; font-weight: 700; }
      .text-perubahan-neg { color: #d94b4b; font-weight: 700; }
      .trend { font-size: 12px; font-weight: 800; display: inline-flex; align-items: center; margin-left: 6px; line-height: 1; }
      .trend-up { color: #16a34a; }
      .trend-down { color: #e55353; }
      .trend-zero { color: #6b7280; }

      .sp2kp-input { max-width: 12ch; text-align: right; }
      .perubahan-input { text-align: right; max-width: 12ch; }
      .text-input { width: 100%; min-width: 140px; }
      .text-input.compact-input { min-width: 70px; max-width: 90px; }
      .int-input { text-align: center; max-width: 80px; }
      .input-with-icon {
        position: relative;
        display: inline-flex;
        align-items: center;
        width: 100%;
      }
      .input-with-icon .trend {
        position: absolute;
        left: 8px;
        font-size: 12px;
        font-weight: 800;
        pointer-events: none;
      }
      .input-with-icon .perubahan-input {
        padding-left: 22px;
      }
      .year-input { max-width: 8ch; text-align: right; }
      .harga-input { text-align: right; max-width: 12ch; }
      .perubahan-cell { text-align: center; }
      .penurunan-select { max-width: 8ch; }
      .wrap-textarea { white-space: normal; word-break: break-word; min-height: 36px; resize: none; overflow: hidden; line-height: 1.4; padding: 8px 10px; font-size: 13px; width: 100%; min-width: 240px; }
      .form-control, .form-select {
        font-size: 13px;
        border-radius: 10px;
        border: 1px solid #d1d5db;
        background: #ffffff;
        padding: 8px 10px;
        height: 34px;
        line-height: 1.2;
        box-shadow: inset 0 1px 1px rgba(15, 23, 42, 0.04);
        font-family: inherit;
      }
      .form-control:focus, .form-select:focus, .wrap-textarea:focus {
        outline: none;
        border-color: #ff9f91;
        box-shadow: 0 0 0 3px rgba(255, 122, 182, 0.18);
      }
      .wrap-textarea.form-control { height: auto; min-height: 36px; }
      .form-control:disabled,
      .form-select:disabled {
        background: #f3f4f6 !important;
        color: #9aa3ad !important;
        border-color: #e5e7eb !important;
      }
      .form-select {
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        padding-right: 28px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");
        background-repeat: no-repeat;
        background-position: right 10px center;
      }

      .komoditas-alt-a > td { background: #ffffff !important; }
      .komoditas-alt-b > td { background: #e3f2fd !important; }
      .table-responsive { width: 100%; overflow-x: auto; }
      .table-responsive table { min-width: 1200px; }
      .tabs { overflow-x: hidden; flex-wrap: wrap; padding-bottom: 4px; }
      .tab-btn { white-space: nowrap; }

      @media (max-width: 1200px) {
        .app { grid-template-columns: 1fr; }
        .sidebar { flex-direction: row; justify-content: flex-start; overflow-x: auto; }
        .main { padding-right: 0; }
      }
      @media (max-width: 768px) {
        .app { padding: 14px; }
        .sidebar { gap: 10px; }
        .logo { width: 38px; height: 38px; }
        .nav-dot { width: 40px; height: 40px; }
        .topbar { grid-template-columns: 1fr; }
        .pill { width: 100%; justify-content: space-between; }
        .pill select { width: 100%; }
        .tabs { margin: 32px 0 16px; }
        .table-card { padding: 12px; }
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
        color: #ffffff !important;
        border: none !important;
        box-shadow: 0 10px 22px rgba(242, 139, 43, 0.25) !important;
      }
      .avg-pill,
      .badge-important {
        background: var(--rh-gradient) !important;
        color: #ffffff !important;
      }
      table th:not(.head-yellow):not(.head-pink):not(.subhead):not(.subhead-dark) {
        background: #445468 !important;
        color: #ffffff !important;
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
        <a class="nav-dot" href="perbandingan-harga.php" title="Perbandingan Harga"><i class="mdi mdi-chart-line"></i></a>
        <a class="nav-dot" href="shk.php" title="SHK"><span class="nav-text">HK</span></a>
        <a class="nav-dot" href="hpb.php" title="HPB"><span class="nav-text">HPB</span></a>
        <a class="nav-dot" href="hd.php" title="HD"><span class="nav-text">HD</span></a>
        <a class="nav-dot" href="hkd.php" title="HKD"><span class="nav-text">HKD</span></a>
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot active" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="panduan.php" title="Panduan"><i class="mdi mdi-book-open-variant"></i></a>
      </aside>

      <main class="main">
        <form class="topbar" method="get" action="ekstrem.php">
          <div>
            <div class="hello">Ekstrem Harga</div>
            <div class="subhello">Rekon Harga Perdagangan Besar</div>
          </div>
          <div class="pill">
            <i class="mdi mdi-calendar"></i>
            <select name="bulan">
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
            <select name="tahun">
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
            <a class="icon-btn" href="logout.php" title="Logout"><i class="mdi mdi-logout"></i></a>
          </div>
        </form>
        <?php if (empty($rows)): ?>
          <div class="table-card">
            <div class="text-center">Belum ada data.</div>
          </div>
        <?php else: ?>
          <?php
            $row_index = 0;
            $avg_display = ($avg_all === null) ? '-' : number_format((float)$avg_all, 2, ',', '.');
          ?>
          <div class="table-card" style="margin-bottom:16px;">
            <div class="komoditas-head">
              <div style="font-weight:700;">Tabel Ekstrem</div>
              <div class="avg-pill"><span class="avg-trend zero">=</span>Rata-rata perubahan: <span class="avg-value"><?php echo htmlspecialchars($avg_display); ?></span></div>
            </div>
            <div class="table-responsive"><table class="ekstrem-table"><thead><tr>
              <?php
                $col_index = 0;
                foreach ($columns as $key => $label) {
                  $filterable = in_array($key, ['subsektor','kab','kecamatan','komoditas','kualitas'], true);
                  echo '<th>';
                  echo '<span class="th-label">' . $label . '</span>';
                  if ($filterable) {
                    echo '<span class="th-filter"><select class="ekstrem-filter" data-col="' . $col_index . '"><option value="">Semua</option></select></span>';
                  }
                  echo '</th>';
                  $col_index++;
                }
              ?>
            </tr></thead><tbody>
          <?php foreach ($rows as $row): ?>
            <tr data-id="<?php echo (int)$row['id']; ?>" data-row="<?php echo $row_index; ?>">
              <?php foreach ($columns as $key => $label): ?>
                <?php
                  $value = array_key_exists($key, $row) ? $row[$key] : null;
                  if ($value === null || $value === '') {
                    $value_display = '';
                  } else {
                    if (is_numeric($value)) {
                      if ($key === 'kab' || $key === 'kecamatan' || $key === 'tahun') {
                          $value_display = number_format((float)$value, 0, ',', '.');
                      } else {
                          $value_display = number_format((float)$value, 2, ',', '.');
                      }
                    } else {
                      $value_display = (string)$value;
                    }
                  }
                  $disabled_all = is_kabupaten($user) ? 'disabled' : '';
                  $disabled_konfirmasi = is_kabupaten($user) ? '' : '';
                ?>
                <?php if ($key === 'perubahan_rata_rata'): ?>
                  <?php
                    $num = is_numeric($value) ? (float)$value : null;
                    $class = '';
                    if ($num !== null) {
                      $class = $num >= 0 ? 'text-perubahan-pos' : 'text-perubahan-neg';
                    }
                  ?>
                  <td class="perubahan-cell">
                    <div class="input-with-icon">
                      <?php if ($num !== null): ?>
                        <?php if ($num >= 0): ?>
                          <span class="trend trend-up">▲</span>
                        <?php else: ?>
                          <span class="trend trend-down">▼</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="trend trend-zero">=</span>
                      <?php endif; ?>
                      <input type="text" inputmode="decimal" class="form-control form-control-sm perubahan-input editable-cell <?php echo $class; ?>" data-field="perubahan_rata_rata" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="0,00" <?php echo $disabled_all; ?>>
                    </div>
                  </td>
                <?php elseif ($key === 'harga_bulan_ini'): ?>
                  <td>
                    <input type="text" inputmode="decimal" class="form-control form-control-sm harga-input editable-cell" data-field="harga_bulan_ini" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="0,00" <?php echo $disabled_all; ?>>
                  </td>
                <?php elseif ($key === 'harga_bulan_lalu'): ?>
                  <td>
                    <input type="text" inputmode="decimal" class="form-control form-control-sm harga-input editable-cell" data-field="harga_bulan_lalu" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="0,00" <?php echo $disabled_all; ?>>
                  </td>
                <?php elseif ($key === 'konfirmasi_kab'): ?>
                  <td>
                    <textarea class="form-control form-control-sm editable-cell wrap-textarea" data-field="konfirmasi_kab" rows="1" placeholder="Isi konfirmasi" <?php echo (is_kabupaten($user) ? '' : $disabled_all); ?>><?php echo htmlspecialchars($value_display); ?></textarea>
                  </td>
                <?php elseif ($key === 'tahun'): ?>
                  <td>
                    <input type="text" inputmode="numeric" class="form-control form-control-sm text-input year-input editable-cell" data-field="tahun" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="2026" <?php echo $disabled_all; ?>>
                  </td>
                <?php elseif ($key === 'bulan'): ?>
                  <td>
                    <input type="text" class="form-control form-control-sm text-input editable-cell" data-field="bulan" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="Maret" <?php echo $disabled_all; ?>>
                  </td>
                  <?php elseif ($key === 'subsektor' || $key === 'kab' || $key === 'kecamatan' || $key === 'komoditas' || $key === 'kualitas' || $key === 'satuan'): ?>
                  <td>
                    <?php $compact = ($key === 'subsektor' || $key === 'kab' || $key === 'kecamatan' || $key === 'satuan') ? ' compact-input' : ''; ?>
                    <?php if ($key === 'kab' || $key === 'kecamatan'): ?>
                      <input type="text" inputmode="numeric" maxlength="3" pattern="\\d*" class="form-control form-control-sm text-input int-input<?php echo $compact; ?> editable-cell" data-field="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="0" <?php echo $disabled_all; ?>>
                    <?php else: ?>
                      <input type="text" class="form-control form-control-sm text-input<?php echo $compact; ?> editable-cell" data-field="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="-" <?php echo $disabled_all; ?>>
                    <?php endif; ?>
                  </td>
                <?php else: ?>
                  <td><?php echo htmlspecialchars($value_display === '' ? '-' : $value_display); ?></td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
            <?php $row_index++; ?>
          <?php endforeach; ?>
            </tbody></table></div></div>
        <?php endif; ?>
      </main>
    </div>

    <script>
      (function () {
        function autoResize(el) {
          el.style.height = 'auto';
          el.style.height = el.scrollHeight + 'px';
        }
        var textareas = document.querySelectorAll('.wrap-textarea');
        textareas.forEach(function (ta) {
          autoResize(ta);
          ta.addEventListener('input', function () { autoResize(ta); });
        });

        function formatIdNumber(raw) {
          if (!raw) return '';
          var s = String(raw).replace(/\./g, '').replace(/,/g, '.').replace(/\s+/g, '');
          var num = parseFloat(s);
          if (isNaN(num)) return raw;
          return new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num);
        }

        function attachFormat(selector) {
          var inputs = document.querySelectorAll(selector);
          inputs.forEach(function (inp) {
            if (inp.value) inp.value = formatIdNumber(inp.value);
            inp.addEventListener('blur', function () {
              inp.value = formatIdNumber(inp.value);
            });
          });
        }

        attachFormat('.perubahan-input');
        attachFormat('.harga-input');

        function parseIdNumber(raw) {
          if (!raw) return null;
          var s = String(raw).replace(/\./g, '').replace(/,/g, '.').replace(/\s+/g, '');
          var num = parseFloat(s);
          return isNaN(num) ? null : num;
        }

        function updateTrend(input) {
          var wrapper = input.closest('td');
          if (!wrapper) return;
          var trend = wrapper.querySelector('.trend');
          var value = parseIdNumber(input.value);
          if (value === null || value === 0) {
            if (!trend) {
              trend = document.createElement('span');
              trend.className = 'trend';
              input.parentElement.appendChild(trend);
            }
            trend.textContent = '=';
            trend.className = 'trend trend-zero';
            return;
          }
          if (!trend) {
            trend = document.createElement('span');
            trend.className = 'trend';
            input.parentElement.appendChild(trend);
          }
          if (value >= 0) {
            trend.textContent = '▲';
            trend.className = 'trend trend-up';
          } else {
            trend.textContent = '▼';
            trend.className = 'trend trend-down';
          }
        }
        function updateRowHighlight(input) {
          var row = input.closest('tr');
          if (!row) return;
          row.classList.remove('row-pos', 'row-neg');
          var value = parseIdNumber(input.value);
          if (value > 0) {
            row.classList.add('row-pos');
          } else if (value < 0) {
            row.classList.add('row-neg');
          }
        }
        function updateAvgForCard(card) {
          if (!card) return;
          var avgEl = card.querySelector('.avg-value');
          if (!avgEl) return;
          var trendEl = card.querySelector('.avg-trend');
          var inputs = card.querySelectorAll('.perubahan-input');
          var sum = 0;
          var count = 0;
          inputs.forEach(function (inp) {
            var val = parseIdNumber(inp.value);
            if (val !== null && val !== 0) {
              sum += val;
              count += 1;
            }
          });
          if (count === 0) {
            avgEl.textContent = '-';
            if (trendEl) {
              trendEl.textContent = '=';
              trendEl.className = 'avg-trend zero';
            }
            return;
          }
          var avg = sum / count;
          avgEl.textContent = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(avg);
          if (trendEl) {
            if (avg > 0) {
              trendEl.textContent = '▲';
              trendEl.className = 'avg-trend pos';
            } else if (avg < 0) {
              trendEl.textContent = '▼';
              trendEl.className = 'avg-trend neg';
            } else {
              trendEl.textContent = '=';
              trendEl.className = 'avg-trend zero';
            }
          }
        }
        function updateAllAverages() {
          var cards = document.querySelectorAll('.table-card');
          cards.forEach(function (card) { updateAvgForCard(card); });
        }

        var perubahanInputs = document.querySelectorAll('.perubahan-input');
        perubahanInputs.forEach(function (inp) {
          inp.addEventListener('input', function () {
            updateTrend(inp);
            updateRowHighlight(inp);
            updateAvgForCard(inp.closest('.table-card'));
          });
          inp.addEventListener('blur', function () {
            updateTrend(inp);
            updateRowHighlight(inp);
            updateAvgForCard(inp.closest('.table-card'));
          });
          updateTrend(inp);
          updateRowHighlight(inp);
          updateAvgForCard(inp.closest('.table-card'));
        });
        updateAllAverages();

        function getCellValue(cell) {
          if (!cell) return '';
          var input = cell.querySelector('input, textarea, select');
          if (input) return (input.value || '').toString().trim();
          return (cell.textContent || '').toString().trim();
        }

        function buildHeaderFilters(card) {
          if (!card) return;
          var selects = card.querySelectorAll('.ekstrem-filter');
          selects.forEach(function (sel) {
            var col = parseInt(sel.getAttribute('data-col'), 10);
            var rows = card.querySelectorAll('tbody tr');
            var values = [];
            rows.forEach(function (row) {
              var cell = row.cells[col];
              var val = getCellValue(cell);
              if (val !== '') values.push(val);
            });
            var uniq = Array.from(new Set(values.map(function (v) { return v.toLowerCase(); })))
              .map(function (v) {
                var original = values.find(function (x) { return x.toLowerCase() === v; }) || v;
                return original;
              })
              .sort(function (a, b) { return a.localeCompare(b, 'id'); });
            var current = sel.value;
            sel.innerHTML = '<option value=\"\">Semua</option>';
            uniq.forEach(function (v) {
              var opt = document.createElement('option');
              opt.value = v;
              opt.textContent = v;
              sel.appendChild(opt);
            });
            sel.value = current;
          });
        }

        function applyHeaderFilters() {
          var cards = document.querySelectorAll('.table-card');
          cards.forEach(function (card) {
            var filters = card.querySelectorAll('.ekstrem-filter');
            var active = [];
            filters.forEach(function (f) {
              var val = (f.value || '').trim().toLowerCase();
              if (val !== '') {
                active.push({ col: parseInt(f.getAttribute('data-col'), 10), val: val });
              }
            });
            var rows = card.querySelectorAll('tbody tr');
            rows.forEach(function (row) {
              var show = true;
              active.forEach(function (f) {
                var cell = row.cells[f.col];
                var text = getCellValue(cell).toLowerCase();
                if (text !== f.val) show = false;
              });
              row.style.display = show ? '' : 'none';
            });
          });
        }

        document.querySelectorAll('.table-card').forEach(function (card) {
          buildHeaderFilters(card);
        });
        var headerFilters = document.querySelectorAll('.ekstrem-filter');
        headerFilters.forEach(function (sel) {
          sel.addEventListener('change', applyHeaderFilters);
        });

        function saveCell(el) {
          var row = el.closest('tr');
          if (!row) return;
          var id = row.getAttribute('data-id');
          var field = el.getAttribute('data-field');
          if (!id || !field) return;

          var value = el.value;
          if (el.tagName.toLowerCase() === 'select') {
            value = el.options[el.selectedIndex].value;
          }

          var formData = new FormData();
          formData.append('action', 'update');
          formData.append('id', id);
          formData.append('field', field);
          formData.append('value', value);

          fetch('ekstrem.php', { method: 'POST', body: formData })
            .then(function (res) { return res.text(); })
            .catch(function () { /* silent */ });
        }

        function focusCell(rowIndex, colIndex) {
          var row = document.querySelector('tr[data-row="' + rowIndex + '"]');
          if (!row) return;
          var cells = row.querySelectorAll('.editable-cell');
          var el = cells[colIndex];
          if (el) {
            el.focus();
            if (el.tagName.toLowerCase() === 'input' || el.tagName.toLowerCase() === 'textarea') {
              el.select();
            }
          }
        }

        var saveTimers = new WeakMap();
        function scheduleSave(el) {
          if (saveTimers.has(el)) clearTimeout(saveTimers.get(el));
          var t = setTimeout(function () {
            saveCell(el);
            saveTimers.delete(el);
          }, 700);
          saveTimers.set(el, t);
        }

        var editable = document.querySelectorAll('.editable-cell');
        editable.forEach(function (el) {
          el.addEventListener('blur', function () { saveCell(el); });
          el.addEventListener('change', function () { saveCell(el); });
          el.addEventListener('input', function () { scheduleSave(el); });
          if (el.tagName.toLowerCase() === 'textarea') {
            el.addEventListener('input', function () { autoResize(el); });
          }
          el.addEventListener('blur', function () {
            var card = el.closest('.table-card');
            if (card) buildHeaderFilters(card);
          });
          el.addEventListener('keydown', function (e) {
            var row = el.closest('tr');
            if (!row) return;
            var rowIndex = parseInt(row.getAttribute('data-row'), 10);
            var colIndex = Array.prototype.indexOf.call(row.querySelectorAll('.editable-cell'), el);
            if (colIndex < 0) return;

            if (e.key === 'Enter') {
              e.preventDefault();
              saveCell(el);
              focusCell(rowIndex + 1, colIndex);
            } else if (e.key === 'Tab') {
              e.preventDefault();
              saveCell(el);
              var nextCol = e.shiftKey ? colIndex - 1 : colIndex + 1;
              var nextRow = rowIndex;
              var maxCol = row.querySelectorAll('.editable-cell').length - 1;
              if (nextCol < 0) { nextCol = maxCol; nextRow = rowIndex - 1; }
              if (nextCol > maxCol) { nextCol = 0; nextRow = rowIndex + 1; }
              focusCell(nextRow, nextCol);
            }
          });
        });

        function setEditableValue(el, value) {
          if (!el) return;
          if (el.tagName.toLowerCase() === 'select') {
            var found = false;
            for (var i = 0; i < el.options.length; i++) {
              if (el.options[i].value === value) {
                el.selectedIndex = i;
                found = true;
                break;
              }
            }
            if (!found) {
              el.value = value;
            }
          } else {
            el.value = value;
          }
          if (el.classList.contains('perubahan-input') || el.classList.contains('harga-input')) {
            el.value = formatIdNumber(el.value);
          }
          if (el.tagName.toLowerCase() === 'textarea') {
            autoResize(el);
          }
          if (el.classList.contains('perubahan-input')) {
            updateTrend(el);
            updateRowHighlight(el);
            updateAvgForCard(el.closest('.table-card'));
          }
          saveCell(el);
        }

        var pasteStatusEl = document.getElementById('paste-status');
        function setPasteStatus(text, active) {
          if (!pasteStatusEl) return;
          pasteStatusEl.textContent = text;
          pasteStatusEl.classList.toggle('active', !!active);
        }

        document.addEventListener('paste', function (e) {
          var target = e.target;
          if (!target || !target.classList || !target.classList.contains('editable-cell')) return;
          var text = (e.clipboardData || window.clipboardData).getData('text');
          if (!text || (text.indexOf('\t') === -1 && text.indexOf('\n') === -1 && text.indexOf('\r') === -1)) return;
          e.preventDefault();

          var rows = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
          if (rows.length && rows[rows.length - 1].trim() === '') rows.pop();

          var startRow = target.closest('tr');
          if (!startRow) return;
          var card = target.closest('.table-card');
          if (!card) return;
          var rowList = Array.prototype.slice.call(card.querySelectorAll('tbody tr'));
          var startRowIdx = rowList.indexOf(startRow);
          if (startRowIdx < 0) return;
          var startColIdx = Array.prototype.indexOf.call(startRow.querySelectorAll('.editable-cell'), target);
          if (startColIdx < 0) return;

          var total = 0;
          rows.forEach(function (rowText) {
            total += rowText.split('\t').length;
          });
          var done = 0;
          setPasteStatus('Menempel 0/' + total, true);

          var batch = [];
          rows.forEach(function (rowText, rIdx) {
            var cols = rowText.split('\t');
            var rowEl = rowList[startRowIdx + rIdx];
            if (!rowEl) return;
            var editables = rowEl.querySelectorAll('.editable-cell');
            cols.forEach(function (cellText, cIdx) {
              var el = editables[startColIdx + cIdx];
              if (!el) return;
              var val = cellText.trim();
              setEditableValue(el, val);
              var rowId = rowEl.getAttribute('data-id');
              var field = el.getAttribute('data-field');
              if (rowId && field) {
                batch.push({ id: rowId, field: field, value: val });
              }
              done += 1;
              setPasteStatus('Menempel ' + done + '/' + total, true);
            });
          });
          if (batch.length) {
            var fd = new FormData();
            fd.append('action', 'batch_update');
            fd.append('payload', JSON.stringify(batch));
            fetch('ekstrem.php', { method: 'POST', body: fd })
              .then(function () { setPasteStatus('Selesai', false); })
              .catch(function () { setPasteStatus('Gagal', false); });
          }
          if (card) buildHeaderFilters(card);
          applyHeaderFilters();
          if (!batch.length) {
            setTimeout(function () {
              setPasteStatus('Selesai', false);
            }, 400);
          }
        });
      })();
    </script>
  
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
    </script>

  </body>
</html>
