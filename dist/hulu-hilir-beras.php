<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();
$pdo = db();

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

$bulan_list = ['januari','februari','maret','april','mei','juni','juli','agustus','september','oktober','november','desember'];
$bulan_label = ($bulan !== '') ? ucfirst($bulan) : 'N';
$bulan_prev_label = 'N-1';
$bulan_index = array_search(strtolower($bulan), $bulan_list, true);
if ($bulan_index !== false) {
    $prev_index = ($bulan_index - 1 + 12) % 12;
    $bulan_prev_label = ucfirst($bulan_list[$prev_index]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    if (is_kabupaten($user)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $field = isset($_POST['field']) ? trim($_POST['field']) : '';
    $value = isset($_POST['value']) ? $_POST['value'] : null;

    $allowed = [
        'kd_kako' => 'int',
        'kabupaten_kota' => 'text',
        'penjelasan' => 'text',
        'shped_hd_n1' => 'decimal',
        'shped_hd_n' => 'decimal',
        'shped_hd_rh' => 'decimal',
        'shped_hkd_n1' => 'decimal',
        'shped_hkd_n' => 'decimal',
        'shped_hkd_rh' => 'decimal',
        'shp_n2' => 'decimal',
        'shp_n' => 'decimal',
        'shp_rh' => 'decimal',
        'hpb_n1' => 'decimal',
        'hpb_n' => 'decimal',
        'hpb_rh' => 'decimal',
        'hk_n1' => 'decimal',
        'hk_n' => 'decimal',
        'hk_rh' => 'decimal',
    ];

    if ($id <= 0 || !isset($allowed[$field])) {
        http_response_code(400);
        echo 'Invalid request';
        exit;
    }

    $stmt_kab = $pdo->prepare('SELECT kabupaten_kota FROM hulu_hilir_beras WHERE id = ?');
    $stmt_kab->execute([$id]);
    $row_kab = $stmt_kab->fetch();
    $kab = $row_kab ? strtolower(trim((string)$row_kab['kabupaten_kota'])) : '';
    $lock_shped = in_array($kab, ['bandar lampung','metro'], true);
    $lock_shp_hpb = in_array($kab, ['lampung barat','lampung utara','way kanan','tulang bawang','pesawaran','mesuji','tulang bawang barat','pesisir barat'], true);
    $allow_hk = in_array($kab, ['lampung timur','mesuji','metro','bandar lampung'], true);
    if (in_array($field, ['shped_hd_n1','shped_hd_n','shped_hd_rh','shped_hkd_n1','shped_hkd_n','shped_hkd_rh'], true) && $lock_shped) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    if (in_array($field, ['shp_n2','shp_n','shp_rh','hpb_n1','hpb_n','hpb_rh'], true) && $lock_shp_hpb) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    if (in_array($field, ['hk_n1','hk_n','hk_rh'], true) && !$allow_hk) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $type = $allowed[$field];
    if ($type === 'decimal') {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            $stmt = $pdo->prepare("UPDATE hulu_hilir_beras SET {$field} = NULL WHERE id = ?");
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
            $stmt = $pdo->prepare("UPDATE hulu_hilir_beras SET {$field} = ? WHERE id = ?");
            $stmt->execute([$num, $id]);
        }
    } elseif ($type === 'int') {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            $stmt = $pdo->prepare("UPDATE hulu_hilir_beras SET {$field} = NULL WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            if (!ctype_digit($raw)) {
                http_response_code(400);
                echo 'Invalid number';
                exit;
            }
            $stmt = $pdo->prepare("UPDATE hulu_hilir_beras SET {$field} = ? WHERE id = ?");
            $stmt->execute([(int)$raw, $id]);
        }
    } else {
        $raw = is_string($value) ? $value : '';
        $stmt = $pdo->prepare("UPDATE hulu_hilir_beras SET {$field} = ? WHERE id = ?");
        $stmt->execute([$raw, $id]);
    }

    echo 'OK';
    exit;
}

$where = [];
$params = [];
if ($bulan !== '') {
    $where[] = 'TRIM(LOWER(bulan)) = ?';
    $params[] = strtolower(trim($bulan));
}
if ($tahun !== '' && ctype_digit($tahun)) {
    $where[] = 'tahun = ?';
    $params[] = (int)$tahun;
}

$sql = 'SELECT * FROM hulu_hilir_beras';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY kd_kako ASC, kabupaten_kota ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$avg_fields = [
    'shped_hd_n1','shped_hd_n','shped_hd_rh',
    'shped_hkd_n1','shped_hkd_n','shped_hkd_rh',
    'shp_n2','shp_n','shp_rh',
    'hpb_n1','hpb_n','hpb_rh',
    'hk_n1','hk_n','hk_rh'
];
$avg_sum = [];
$avg_count = [];
foreach ($avg_fields as $f) { $avg_sum[$f] = 0; $avg_count[$f] = 0; }
foreach ($rows as $r) {
    foreach ($avg_fields as $f) {
        if (!isset($r[$f])) continue;
        $v = $r[$f];
        if ($v === null || $v === '' || !is_numeric($v)) continue;
        $num = (float)$v;
        if ($num == 0) continue;
        $avg_sum[$f] += $num;
        $avg_count[$f] += 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Hulu Hilir Beras</title>
    <link rel="stylesheet" href="assets/vendors/mdi/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="assets/vendors/ti-icons/css/themify-icons.css">
    <link rel="stylesheet" href="assets/vendors/css/vendor.bundle.base.css">
    <link rel="stylesheet" href="assets/vendors/font-awesome/css/font-awesome.min.css">
    <link rel="shortcut icon" href="assets/images/rh-icon.png" />
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
      :root {
        --bg: #f7f5f6;
        --bg-2: #f1eaee;
        --card: #ffffff;
        --ink: #374151;
        --muted: #8a93a0;
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
      .main { background: transparent; padding-right: 8px; }
      .topbar {
        display: grid;
        grid-template-columns: 1fr auto auto auto auto;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
      }
      .hello { font-size: 22px; font-weight: 700; }
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
      .actions { display: flex; align-items: center; gap: 8px; }
      .filters {
        display: flex;
        align-items: center;
        gap: 10px;
      }
      .filters button {
        border-radius: 10px; padding: 8px 14px; font-size: 12px; font-weight: 600; border: none;
        background: linear-gradient(135deg, #ff7ab6, #ffb36b);
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
        text-decoration: none;
        color: inherit;
      }
      .table-card {
        background: var(--card);
        border-radius: var(--radius);
        padding: 16px;
        box-shadow: 0 14px 28px rgba(56, 65, 80, 0.10);
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      table { width: 100%; border-collapse: collapse; min-width: 1320px; }
      th, td { border: 1px solid #e5e7eb; }
      th { background: #445468; color: #fff; font-size: 10px; padding: 3px 6px; text-align: center; }
      thead th { position: sticky; top: 0; z-index: 2; }
      .head-yellow { background: linear-gradient(135deg, #ff7ab6, #ffb36b); color: #fff; font-weight: 700; }
      .head-pink { background: #e9edf3; color: #445468; font-weight: 700; }
      .subhead { background: #58697d; color: #fff; font-weight: 700; }
      .subhead-dark { background: #3f4f63; color: #fff; font-weight: 700; }
      .col-fixed { background: #ffffff; font-weight: 600; }
      .rh-col { background: #fff3c4; }
      .beras-col { background: #ffffff; }
      .cell-disabled {
        background: #cbd5e1 !important;
        color: #475569 !important;
      }
      td { padding: 3px 6px; font-size: 10px; background: #ffffff; }
      .cell-input {
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        font-size: 10px;
        text-align: right;
      }
      .num-int,
      .num-dec {
        width: 6ch;
        max-width: 6ch;
      }
      .cell-textarea {
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        font-size: 10px;
        resize: vertical;
        min-height: 18px;
      }
      .cell-text {
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        font-size: 10px;
      }
      .col-fixed {
        width: 6ch;
        max-width: 6ch;
      }
      .col-fixed .cell-text {
        width: 6ch;
        max-width: 6ch;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .cell-input:disabled,
      .cell-text:disabled {
        background: transparent;
        color: #475569;
      }
      .cell-wrap {
        display: flex;
        align-items: center;
        gap: 2px;
        width: 100%;
      }
      .cell-wrap .trend {
        margin-right: -4px;
      }
      .cell-wrap .trend {
        font-size: 10px;
        font-weight: 800;
        line-height: 1;
      }
      .kab-warning {
        background: #fde2e2 !important;
        color: #7f1d1d;
      }
      .warn-icon {
        font-size: 12px;
        font-weight: 800;
        color: #f97316;
        line-height: 1;
        margin-left: 6px;
      }
      .text-perubahan-pos { color: #168f4a; font-weight: 700; }
      .text-perubahan-neg { color: #d94b4b; font-weight: 700; }
      .num-dec { text-align: right; }
      .num-int { text-align: right; }
      .avg-row td {
        background: #e9edf3;
        font-weight: 700;
      }
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
      }
    
      /* A: Unified brand actions & table headers */
      :root {
        --rh-gradient: linear-gradient(135deg, #ff7ab6, #ffb36b);
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
        <a class="nav-dot active" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
      </aside>

      <main class="main">
        <form class="topbar" method="get" action="hulu-hilir-beras.php">
          <div>
            <div class="hello">Hulu Hilir Beras</div>
            <div class="subhello">Rekon Harga</div>
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
            <div class="user-pill" style="padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #eef0f4;font-size:12px;color:#6b7280;box-shadow:0 6px 14px rgba(56,65,80,0.08);"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
            <a class="icon-btn" href="logout.php" title="Logout"><i class="mdi mdi-logout"></i></a>
          </div>
        </form>

        <div class="table-card">
          <table>
            <thead>
              <tr>
                <th colspan="1" class="head-yellow">Komoditas</th>
                <th colspan="3" class="head-yellow">Gabah</th>
                <th colspan="12" class="head-yellow">Beras</th>
                <th colspan="1" class="head-yellow">Penjelasan</th>
              </tr>
              <tr>
                <th rowspan="2" class="subhead">Kab/Kot</th>
                <th colspan="3" class="subhead">SHPED_HD</th>
                <th colspan="3" class="subhead">SHPED_HKD</th>
                <th colspan="3" class="subhead">SHP</th>
                <th colspan="3" class="subhead">HPB</th>
                <th colspan="3" class="subhead">HK</th>
                <th rowspan="2" class="subhead">Penjelasan</th>
              </tr>
              <tr>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_prev_label); ?></th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_label); ?></th>
                <th class="subhead-dark">RH (%)</th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_prev_label); ?></th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_label); ?></th>
                <th class="subhead-dark">RH (%)</th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_prev_label); ?></th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_label); ?></th>
                <th class="subhead-dark">RH (%)</th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_prev_label); ?></th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_label); ?></th>
                <th class="subhead-dark">RH (%)</th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_prev_label); ?></th>
                <th class="subhead-dark"><?php echo htmlspecialchars($bulan_label); ?></th>
                <th class="subhead-dark">RH (%)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                  $disabled_all = is_kabupaten($user) ? 'disabled' : '';
                  $kab = strtolower(trim((string)($row['kabupaten_kota'] ?? '')));
                  $lock_shped = in_array($kab, ['bandar lampung','metro'], true);
                  $lock_shp_hpb = in_array($kab, ['lampung barat','lampung utara','way kanan','tulang bawang','pesawaran','mesuji','tulang bawang barat','pesisir barat'], true);
                  $allow_hk = in_array($kab, ['lampung timur','mesuji','metro','bandar lampung'], true);
                  $is_locked = function(string $field) use ($disabled_all, $lock_shped, $lock_shp_hpb, $allow_hk) {
                    if ($disabled_all !== '') return $disabled_all;
                    if (in_array($field, ['shped_hd_n1','shped_hd_n','shped_hd_rh','shped_hkd_n1','shped_hkd_n','shped_hkd_rh'], true) && $lock_shped) return 'disabled';
                    if (in_array($field, ['shp_n2','shp_n','shp_rh','hpb_n1','hpb_n','hpb_rh'], true) && $lock_shp_hpb) return 'disabled';
                    if (in_array($field, ['hk_n1','hk_n','hk_rh'], true) && !$allow_hk) return 'disabled';
                    return '';
                  };
                  $val_int = function($k) use ($row) {
                    $v = $row[$k] ?? '';
                    if ($v === null || $v === '') return '';
                    if (is_numeric($v)) return number_format((float)$v, 0, ',', '.');
                    return (string)$v;
                  };
                  $val_dec = function($k) use ($row) {
                    $v = $row[$k] ?? '';
                    if ($v === null || $v === '') return '';
                    if (is_numeric($v)) return number_format((float)$v, 2, ',', '.');
                    return (string)$v;
                  };
                  $rh_fields = ['shped_hd_rh','shped_hkd_rh','shp_rh','hpb_rh','hk_rh'];
                  $has_pos = false;
                  $has_neg = false;
                  $baseline_sign = 0;
                  foreach ($rh_fields as $f) {
                    $v = $row[$f] ?? null;
                    if (!is_numeric($v) || (float)$v == 0.0) continue;
                    $sign = ((float)$v > 0) ? 1 : -1;
                    if ($baseline_sign === 0) $baseline_sign = $sign;
                    if ($sign > 0) $has_pos = true;
                    if ($sign < 0) $has_neg = true;
                  }
                  $rh_mismatch = $has_pos && $has_neg;
                  $rh_warn = function(string $field) use ($row, $rh_mismatch, $baseline_sign) {
                    if (!$rh_mismatch) return false;
                    $v = $row[$field] ?? null;
                    if (!is_numeric($v) || (float)$v == 0.0) return false;
                    $sign = ((float)$v > 0) ? 1 : -1;
                    return $baseline_sign !== 0 && $sign !== $baseline_sign;
                  };
                ?>
                <tr data-id="<?php echo (int)$row['id']; ?>">
                  <td class="col-fixed<?php echo $rh_mismatch ? ' kab-warning' : ''; ?>">
                    <div style="display:flex;align-items:center;gap:6px;">
                      <input class="cell-text" data-field="kd_kako" value="<?php echo htmlspecialchars($row['kd_kako'] ?? ''); ?>" <?php echo $disabled_all; ?>>
                      <span class="warn-icon" style="<?php echo $rh_mismatch ? '' : 'display:none;'; ?>">⚠</span>
                    </div>
                  </td>
                  <td><input class="cell-input num-int" data-field="shped_hd_n1" value="<?php echo htmlspecialchars($val_int('shped_hd_n1')); ?>" <?php echo $is_locked('shped_hd_n1'); ?>></td>
                  <td><input class="cell-input num-int" data-field="shped_hd_n" value="<?php echo htmlspecialchars($val_int('shped_hd_n')); ?>" <?php echo $is_locked('shped_hd_n'); ?>></td>
                  <td class="rh-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" data-field="shped_hd_rh" value="<?php echo htmlspecialchars($val_dec('shped_hd_rh')); ?>" <?php echo $is_locked('shped_hd_rh'); ?>><span class="trend"></span></div></td>
                  <td><input class="cell-input num-int" data-field="shped_hkd_n1" value="<?php echo htmlspecialchars($val_int('shped_hkd_n1')); ?>" <?php echo $is_locked('shped_hkd_n1'); ?>></td>
                  <td><input class="cell-input num-int" data-field="shped_hkd_n" value="<?php echo htmlspecialchars($val_int('shped_hkd_n')); ?>" <?php echo $is_locked('shped_hkd_n'); ?>></td>
                  <td class="rh-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" data-field="shped_hkd_rh" value="<?php echo htmlspecialchars($val_dec('shped_hkd_rh')); ?>" <?php echo $is_locked('shped_hkd_rh'); ?>><span class="trend"></span></div></td>
                  <td class="beras-col"><input class="cell-input num-int" data-field="shp_n2" value="<?php echo htmlspecialchars($val_int('shp_n2')); ?>" <?php echo $is_locked('shp_n2'); ?>></td>
                  <td class="beras-col"><input class="cell-input num-int" data-field="shp_n" value="<?php echo htmlspecialchars($val_int('shp_n')); ?>" <?php echo $is_locked('shp_n'); ?>></td>
                  <td class="rh-col beras-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" data-field="shp_rh" value="<?php echo htmlspecialchars($val_dec('shp_rh')); ?>" <?php echo $is_locked('shp_rh'); ?>><span class="trend"></span></div></td>
                  <td class="beras-col"><input class="cell-input num-int" data-field="hpb_n1" value="<?php echo htmlspecialchars($val_int('hpb_n1')); ?>" <?php echo $is_locked('hpb_n1'); ?>></td>
                  <td class="beras-col"><input class="cell-input num-int" data-field="hpb_n" value="<?php echo htmlspecialchars($val_int('hpb_n')); ?>" <?php echo $is_locked('hpb_n'); ?>></td>
                  <td class="rh-col beras-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" data-field="hpb_rh" value="<?php echo htmlspecialchars($val_dec('hpb_rh')); ?>" <?php echo $is_locked('hpb_rh'); ?>><span class="trend"></span></div></td>
                  <td class="beras-col"><input class="cell-input num-int" data-field="hk_n1" value="<?php echo htmlspecialchars($val_int('hk_n1')); ?>" <?php echo $is_locked('hk_n1'); ?>></td>
                  <td class="beras-col"><input class="cell-input num-int" data-field="hk_n" value="<?php echo htmlspecialchars($val_int('hk_n')); ?>" <?php echo $is_locked('hk_n'); ?>></td>
                  <td class="rh-col beras-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" data-field="hk_rh" value="<?php echo htmlspecialchars($val_dec('hk_rh')); ?>" <?php echo $is_locked('hk_rh'); ?>><span class="trend"></span></div></td>
                  <td><textarea class="cell-text cell-textarea" data-field="penjelasan" <?php echo $disabled_all; ?>><?php echo htmlspecialchars($row['penjelasan'] ?? ''); ?></textarea></td>
                </tr>
              <?php endforeach; ?>
              <tr class="avg-row">
                <td class="col-fixed"><input class="cell-text" value="Rata-rata" disabled></td>
                <?php
                  $fmt_int = function($v) {
                    return $v === null ? '' : number_format($v, 0, ',', '.');
                  };
                  $fmt_dec = function($v) {
                    return $v === null ? '' : number_format($v, 2, ',', '.');
                  };
                  $avg_val = function($f) use ($avg_sum, $avg_count) {
                    return ($avg_count[$f] ?? 0) > 0 ? ($avg_sum[$f] / $avg_count[$f]) : null;
                  };
                ?>
                <td><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('shped_hd_n1'))); ?>" disabled></td>
                <td><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('shped_hd_n'))); ?>" disabled></td>
                <td class="rh-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" value="<?php echo htmlspecialchars($fmt_dec($avg_val('shped_hd_rh'))); ?>" disabled><span class="trend"></span></div></td>
                <td><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('shped_hkd_n1'))); ?>" disabled></td>
                <td><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('shped_hkd_n'))); ?>" disabled></td>
                <td class="rh-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" value="<?php echo htmlspecialchars($fmt_dec($avg_val('shped_hkd_rh'))); ?>" disabled><span class="trend"></span></div></td>
                <td class="beras-col"><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('shp_n2'))); ?>" disabled></td>
                <td class="beras-col"><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('shp_n'))); ?>" disabled></td>
                <td class="rh-col beras-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" value="<?php echo htmlspecialchars($fmt_dec($avg_val('shp_rh'))); ?>" disabled><span class="trend"></span></div></td>
                <td class="beras-col"><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('hpb_n1'))); ?>" disabled></td>
                <td class="beras-col"><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('hpb_n'))); ?>" disabled></td>
                <td class="rh-col beras-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" value="<?php echo htmlspecialchars($fmt_dec($avg_val('hpb_rh'))); ?>" disabled><span class="trend"></span></div></td>
                <td class="beras-col"><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('hk_n1'))); ?>" disabled></td>
                <td class="beras-col"><input class="cell-input num-int" value="<?php echo htmlspecialchars($fmt_int($avg_val('hk_n'))); ?>" disabled></td>
                <td class="rh-col beras-col"><div class="cell-wrap"><input class="cell-input num-dec rh-input" value="<?php echo htmlspecialchars($fmt_dec($avg_val('hk_rh'))); ?>" disabled><span class="trend"></span></div></td>
                <td><textarea class="cell-text cell-textarea" disabled></textarea></td>
              </tr>
            </tbody>
          </table>
        </div>
      </main>
    </div>

    <script>
      (function () {
        var inputs = document.querySelectorAll('.cell-input, .cell-text');
        function saveCell(el) {
          var row = el.closest('tr');
          if (!row) return;
          var id = row.getAttribute('data-id');
          var field = el.getAttribute('data-field');
          if (!id || !field) return;
          var value = el.value || '';
          if (el.classList.contains('num-int')) {
            value = value.replace(/\./g, '').replace(/,/g, '');
          }
          // num-dec values are normalized in PHP to avoid double conversion
          var formData = new FormData();
          formData.append('action', 'update');
          formData.append('id', id);
          formData.append('field', field);
          formData.append('value', value);
          fetch('hulu-hilir-beras.php', { method: 'POST', body: formData }).catch(function () {});
        }
        function updateTrend(el) {
          if (!el.classList.contains('rh-input')) return;
          var wrap = el.closest('.cell-wrap');
          if (!wrap) return;
          var trend = wrap.querySelector('.trend');
          var raw = (el.value || '').replace(/\./g, '').replace(/,/g, '.');
          var num = parseFloat(raw);
          el.classList.remove('text-perubahan-pos', 'text-perubahan-neg');
          if (isNaN(num) || num === 0) {
            trend.textContent = '=';
            trend.style.color = '#6b7280';
            return;
          }
          if (num > 0) {
            trend.textContent = '▲';
            trend.style.color = '#168f4a';
            el.classList.add('text-perubahan-pos');
          } else {
            trend.textContent = '▼';
            trend.style.color = '#d94b4b';
            el.classList.add('text-perubahan-neg');
          }
        }
        function updateRowWarning(row) {
          if (!row || row.classList.contains('avg-row')) return;
          var rhInputs = row.querySelectorAll('.rh-input');
          var hasPos = false;
          var hasNeg = false;
          rhInputs.forEach(function (inp) {
            var raw = (inp.value || '').replace(/\./g, '').replace(/,/g, '.');
            var num = parseFloat(raw);
            if (isNaN(num) || num === 0) return;
            if (num > 0) hasPos = true;
            if (num < 0) hasNeg = true;
          });
          var mismatch = hasPos && hasNeg;
          var kabCell = row.querySelector('td.col-fixed');
          if (kabCell) {
            if (mismatch) kabCell.classList.add('kab-warning');
            else kabCell.classList.remove('kab-warning');
            var warn = kabCell.querySelector('.warn-icon');
            if (warn) warn.style.display = mismatch ? '' : 'none';
          }
        }
        var timers = new WeakMap();
        function scheduleSave(el) {
          if (timers.has(el)) clearTimeout(timers.get(el));
          var t = setTimeout(function () { saveCell(el); timers.delete(el); }, 700);
          timers.set(el, t);
        }
        function formatInt(el) {
          var raw = (el.value || '').replace(/\./g, '').replace(/,/g, '');
          if (raw === '') { el.value = ''; return; }
          var num = parseInt(raw, 10);
          if (isNaN(num)) return;
          el.value = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(num);
        }
        function formatDec(el) {
          var raw = (el.value || '').replace(/\./g, '').replace(/,/g, '.');
          if (raw === '') { el.value = ''; return; }
          var num = parseFloat(raw);
          if (isNaN(num)) return;
          el.value = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num);
        }
        inputs.forEach(function (el) {
          if (el.disabled) {
            var td = el.closest('td');
            if (td) td.classList.add('cell-disabled');
            return;
          }
          if (el.classList.contains('rh-input')) updateTrend(el);
          updateRowWarning(el.closest('tr'));
          if (el.classList.contains('num-int')) formatInt(el);
          if (el.classList.contains('num-dec')) updateTrend(el);
          el.addEventListener('input', function () {
            if (el.classList.contains('num-int')) formatInt(el);
            if (el.classList.contains('num-dec')) updateTrend(el);
            if (el.classList.contains('rh-input')) updateRowWarning(el.closest('tr'));
            scheduleSave(el);
          });
          el.addEventListener('blur', function () {
            if (el.classList.contains('num-dec')) formatDec(el);
            if (el.classList.contains('num-int')) formatInt(el);
            saveCell(el);
            updateAvgRow();
          });
          el.addEventListener('keydown', function (e) {
            var row = el.closest('tr');
            if (!row) return;
            var rowIndex = Array.prototype.indexOf.call(row.parentElement.querySelectorAll('tr'), row);
            var colIndex = Array.prototype.indexOf.call(row.querySelectorAll('.cell-input, .cell-text'), el);
            if (e.key === 'Enter') {
              e.preventDefault();
              saveCell(el);
              var nextRow = row.parentElement.querySelectorAll('tr')[rowIndex + 1];
              if (nextRow) {
                var nextEl = nextRow.querySelectorAll('.cell-input, .cell-text')[colIndex];
                if (nextEl) nextEl.focus();
              }
            } else if (e.key === 'Tab') {
              e.preventDefault();
              saveCell(el);
              var nextCol = e.shiftKey ? colIndex - 1 : colIndex + 1;
              var curRow = row;
              var maxCol = row.querySelectorAll('.cell-input, .cell-text').length - 1;
              if (nextCol < 0) { nextCol = maxCol; rowIndex -= 1; }
              if (nextCol > maxCol) { nextCol = 0; rowIndex += 1; }
              var targetRow = row.parentElement.querySelectorAll('tr')[rowIndex];
              if (targetRow) {
                var targetEl = targetRow.querySelectorAll('.cell-input, .cell-text')[nextCol];
                if (targetEl) targetEl.focus();
              }
            }
          });
        });

        document.addEventListener('paste', function (e) {
          var target = e.target;
          if (!target || !target.classList || !target.classList.contains('cell-input')) return;
          var text = (e.clipboardData || window.clipboardData).getData('text');
          if (!text || (text.indexOf('\t') === -1 && text.indexOf('\n') === -1 && text.indexOf('\r') === -1)) return;
          e.preventDefault();
          var rows = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
          if (rows.length && rows[rows.length - 1].trim() === '') rows.pop();

          var startRow = target.closest('tr');
          if (!startRow) return;
          var rowList = Array.prototype.slice.call(startRow.closest('tbody').querySelectorAll('tr'));
          var startRowIdx = rowList.indexOf(startRow);
          if (startRowIdx < 0) return;
          var startColIdx = Array.prototype.indexOf.call(startRow.querySelectorAll('.cell-input, .cell-text'), target);
          if (startColIdx < 0) return;

          rows.forEach(function (rowText, rIdx) {
            var cols = rowText.split('\t');
            var rowEl = rowList[startRowIdx + rIdx];
            if (!rowEl) return;
            var editables = rowEl.querySelectorAll('.cell-input, .cell-text');
            cols.forEach(function (cellText, cIdx) {
              var el = editables[startColIdx + cIdx];
              if (!el || el.disabled) return;
              el.value = cellText.trim();
              if (el.classList.contains('num-int')) formatInt(el);
              if (el.classList.contains('num-dec')) formatDec(el);
              updateTrend(el);
              saveCell(el);
            });
          });
          updateAvgRow();
        });

        function updateAvgRow() {
          var avgRow = document.querySelector('.avg-row');
          if (!avgRow) return;
          var rows = Array.prototype.slice.call(document.querySelectorAll('tbody tr'));
          var dataRows = rows.filter(function (r) { return !r.classList.contains('avg-row'); });
          if (!dataRows.length) return;
          var fields = [
            'shped_hd_n1','shped_hd_n','shped_hd_rh',
            'shped_hkd_n1','shped_hkd_n','shped_hkd_rh',
            'shp_n2','shp_n','shp_rh',
            'hpb_n1','hpb_n','hpb_rh',
            'hk_n1','hk_n','hk_rh'
          ];
          fields.forEach(function (field, idx) {
            var sum = 0;
            var count = 0;
            dataRows.forEach(function (r) {
              var input = r.querySelector('[data-field=\"' + field + '\"]');
              if (!input) return;
              var raw = (input.value || '').replace(/\./g, '').replace(/,/g, '.');
              var num = parseFloat(raw);
              if (isNaN(num) || num === 0) return;
              sum += num;
              count += 1;
            });
            var avg = count > 0 ? (sum / count) : null;
            var avgCell = avgRow.querySelectorAll('td')[idx + 1];
            if (!avgCell) return;
            var input = avgCell.querySelector('input');
            if (!input) return;
            if (avg === null) {
              input.value = '';
              updateTrend(input);
              return;
            }
            if (field.indexOf('_rh') !== -1) {
              input.value = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(avg);
            } else {
              input.value = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(avg);
            }
            updateTrend(input);
          });
        }
        updateAvgRow();
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
