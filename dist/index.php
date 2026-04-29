<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();
$last_updated = date('d M Y H:i');
$pdo = db();
$is_provinsi_user = is_provinsi($user);
$is_kabupaten_user = is_kabupaten($user);
$user_kab_kode = (string)($user['kab_kode'] ?? '');
$role_scope_label = $is_kabupaten_user ? 'kabupaten Anda' : 'seluruh provinsi';
$online_users = [];
try {
    $online_sql = "SELECT email, last_seen FROM users WHERE last_seen IS NOT NULL AND last_seen > (NOW() - INTERVAL '10 minutes')";
    $online_params = [];
    if ($is_kabupaten_user) {
        $online_sql .= " AND role = ? AND kab_kode = ?";
        $online_params[] = 'kabupaten';
        $online_params[] = $user_kab_kode;
    } elseif ($is_provinsi_user) {
        $online_sql .= " AND role = ?";
        $online_params[] = 'provinsi';
    }
    $online_sql .= " ORDER BY last_seen DESC";
    $stmt_online = $pdo->prepare($online_sql);
    $stmt_online->execute($online_params);
    $online_users = $stmt_online->fetchAll();
} catch (Throwable $e) {
    $online_users = [];
}
$pdo = db();

$bulan = isset($_GET['bulan']) ? trim($_GET['bulan']) : '';
$tahun = isset($_GET['tahun']) ? trim($_GET['tahun']) : '';

if ($bulan === '' || $tahun === '') {
    $currentMonth = new DateTime('first day of this month');
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
        $bulan = $map[strtolower($currentMonth->format('F'))] ?? strtolower($currentMonth->format('F'));
    }
    if ($tahun === '') {
        $tahun = $currentMonth->format('Y');
    }
}

$progress_rows = [];
$summary_map = [
    'HK' => ['filled' => 0, 'total' => 0],
    'HPB' => ['filled' => 0, 'total' => 0],
    'HD' => ['filled' => 0, 'total' => 0],
    'HKD' => ['filled' => 0, 'total' => 0],
];
$summary_percent_map = [];
$table_labels = [
    'shk' => 'HK',
    'hpb' => 'HPB',
    'hd'  => 'HD',
    'hkd' => 'HKD',
];

$base = [];
foreach ($table_labels as $tbl => $label) {
    if (!table_exists($pdo, $tbl)) {
        continue;
    }
    $sql = "SELECT kode_kabupaten, nama_kabupaten, " .
           "SUM(CASE WHEN perubahan IS NOT NULL AND perubahan <> 0 THEN 1 ELSE 0 END) AS total_nonzero, " .
           "SUM(CASE WHEN perubahan IS NOT NULL AND perubahan <> 0 AND penjelasan IS NOT NULL AND TRIM(penjelasan) <> '' THEN 1 ELSE 0 END) AS filled " .
           "FROM {$tbl}";
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
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY kode_kabupaten, nama_kabupaten';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt as $row) {
        $kode = (string)$row['kode_kabupaten'];
        $nama = (string)$row['nama_kabupaten'];
        if (!isset($base[$kode])) {
            $base[$kode] = [
                'kode' => $kode,
                'nama' => $nama,
                'progress' => [],
            ];
        }
        $total = (int)$row['total_nonzero'];
        $filled = (int)$row['filled'];
        $percent = $total > 0 ? (int)round(($filled / $total) * 100) : 0;
        $base[$kode]['progress'][$label] = [
            'filled' => $filled,
            'total' => $total,
            'percent' => $percent,
        ];
    }
}

$scoped_summary_map = $summary_map;
foreach ($table_labels as $tbl => $label) {
    if (!table_exists($pdo, $tbl)) {
        continue;
    }
    $sql_sum = "SELECT " .
        "SUM(CASE WHEN perubahan IS NOT NULL AND perubahan <> 0 THEN 1 ELSE 0 END) AS total_nonzero, " .
        "SUM(CASE WHEN perubahan IS NOT NULL AND perubahan <> 0 AND penjelasan IS NOT NULL AND TRIM(penjelasan) <> '' THEN 1 ELSE 0 END) AS filled " .
        "FROM {$tbl}";
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
    if ($is_kabupaten_user && $user_kab_kode !== '') {
        $where[] = 'kode_kabupaten = ?';
        $params[] = $user_kab_kode;
    }
    if ($where) {
        $sql_sum .= ' WHERE ' . implode(' AND ', $where);
    }
    $stmt_sum = $pdo->prepare($sql_sum);
    $stmt_sum->execute($params);
    $row_sum = $stmt_sum->fetch();
    if ($row_sum) {
        $scoped_summary_map[$label] = [
            'filled' => (int)($row_sum['filled'] ?? 0),
            'total' => (int)($row_sum['total_nonzero'] ?? 0),
        ];
    }
}

uksort($base, function ($a, $b) {
    return (int)$a <=> (int)$b;
});
$progress_rows = array_values($base);

$kabupaten_row = null;
if ($is_kabupaten_user && $user_kab_kode !== '') {
    foreach ($progress_rows as $candidate_row) {
        if ((string)($candidate_row['kode'] ?? '') === $user_kab_kode) {
            $kabupaten_row = $candidate_row;
            break;
        }
    }
}

$overall_filled = 0;
$overall_total = 0;
foreach ($scoped_summary_map as $label => $values) {
    $filled = (int)($values['filled'] ?? 0);
    $total = (int)($values['total'] ?? 0);
    $overall_filled += $filled;
    $overall_total += $total;
    $summary_percent_map[$label] = $total > 0 ? (int)round(($filled / $total) * 100) : 0;
}

$level_lowest = null;
$level_highest = null;
foreach ($summary_percent_map as $label => $percent) {
    if ($level_lowest === null || $percent < $summary_percent_map[$level_lowest]) {
        $level_lowest = $label;
    }
    if ($level_highest === null || $percent > $summary_percent_map[$level_highest]) {
        $level_highest = $label;
    }
}

$completed_kabupaten = 0;
$needs_attention = [];
foreach ($progress_rows as &$row) {
    $percents = [];
    $activeLevels = 0;
    $missingLevels = 0;
    foreach (['HK', 'HPB', 'HD', 'HKD'] as $level) {
        $p = $row['progress'][$level] ?? ['filled' => 0, 'total' => 0, 'percent' => 0];
        if (!empty($p['total'])) {
            $activeLevels++;
            $percents[] = (int)$p['percent'];
            if ((int)$p['percent'] < 100) {
                $missingLevels++;
            }
        }
    }
    $avg_percent = $percents ? (int)round(array_sum($percents) / count($percents)) : 0;
    $row['avg_percent'] = $avg_percent;
    $row['active_levels'] = $activeLevels;
    $row['missing_levels'] = $missingLevels;
    if ($activeLevels > 0 && $missingLevels === 0) {
        $completed_kabupaten++;
    }
    if ($activeLevels > 0 && $missingLevels > 0) {
        $needs_attention[] = $row;
    }
}
unset($row);

usort($progress_rows, function ($a, $b) {
    if (($a['avg_percent'] ?? 0) === ($b['avg_percent'] ?? 0)) {
        return strcmp((string)$a['nama'], (string)$b['nama']);
    }
    return ($a['avg_percent'] ?? 0) <=> ($b['avg_percent'] ?? 0);
});

usort($needs_attention, function ($a, $b) {
    if (($a['avg_percent'] ?? 0) === ($b['avg_percent'] ?? 0)) {
        return ($b['missing_levels'] ?? 0) <=> ($a['missing_levels'] ?? 0);
    }
    return ($a['avg_percent'] ?? 0) <=> ($b['avg_percent'] ?? 0);
});
$needs_attention = array_slice($needs_attention, 0, 5);

$summary_completed_count = 0;
$summary_priority_count = 0;
$attention_items = [];
$attention_title = 'Kabupaten Prioritas';
$attention_subtitle = 'Daftar tercepat untuk melihat wilayah dengan progres terendah pada filter yang aktif.';
$summary_completed_label = 'Kabupaten lengkap';
$summary_priority_label = 'Kabupaten prioritas';

if ($is_kabupaten_user) {
    $summary_completed_label = 'Level lengkap';
    $summary_priority_label = 'Level perlu perhatian';
    foreach (['HK', 'HPB', 'HD', 'HKD'] as $level) {
        $item = $kabupaten_row['progress'][$level] ?? ['filled' => 0, 'total' => 0, 'percent' => 0];
        $total_level = (int)($item['total'] ?? 0);
        $percent_level = (int)($item['percent'] ?? 0);
        if ($total_level > 0) {
            if ($percent_level >= 100) {
                $summary_completed_count++;
            } else {
                $summary_priority_count++;
                $attention_items[] = [
                    'label' => $level,
                    'detail' => ((int)($item['filled'] ?? 0)) . ' dari ' . $total_level . ' penjelasan terisi',
                    'score' => $percent_level . '%',
                ];
            }
        }
    }
    $attention_title = 'Level Prioritas';
    $attention_subtitle = 'Level harga pada kabupaten Anda yang masih membutuhkan pengisian penjelasan.';
} else {
    $summary_completed_count = $completed_kabupaten;
    $summary_priority_count = count($needs_attention);
    foreach ($needs_attention as $item) {
        $attention_items[] = [
            'label' => (string)$item['nama'],
            'detail' => (int)$item['missing_levels'] . ' level belum lengkap dari ' . (int)$item['active_levels'] . ' level aktif',
            'score' => (int)$item['avg_percent'] . '%',
        ];
    }
}

$overall_percent = $overall_total > 0 ? (int)round(($overall_filled / $overall_total) * 100) : 0;
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
        background: radial-gradient(1200px 600px at 30% 0%, #f7eff3 0%, var(--bg) 50%, var(--bg-2) 100%);
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
        background: #f4f1f8;
        display: grid;
        place-items: center;
        color: var(--muted);
        text-decoration: none;
        transition: all .2s ease;
      }
      .nav-text { font-size: 12px; font-weight: 700; color: inherit; letter-spacing: 0.5px; }
      .nav-dot.active, .nav-dot:hover {
        color: #ffffff;
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
        margin-bottom: 28px;
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
        border: 1px solid #efe7f1;
        box-shadow: 0 12px 24px rgba(82, 58, 89, 0.08);
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
        border: 1px solid #efe7f1;
        display: grid;
        place-items: center;
        box-shadow: 0 10px 22px rgba(82, 58, 89, 0.08);
      }
      .filters {
        display: flex;
        align-items: center;
        gap: 10px;
      }
      .filters button {
        border-radius: 10px; padding: 8px 14px; font-size: 12px; font-weight: 600; border: none;
        background: var(--rh-gradient);
        color: #fff;
        box-shadow: 0 10px 22px rgba(242, 139, 43, 0.25);
      }

      .cards {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 24px;
      }
      .section-title {
        font-size: 16px;
        font-weight: 700;
        color: #5a6270;
        margin: 0 0 12px;
      }
      .subinfo {
        margin: 12px 0 18px;
        color: var(--ink);
        font-size: 13px;
        font-weight: 700;
        background: #ffffff;
        border: 1px solid #efe7f1;
        border-radius: 14px;
        padding: 8px 14px;
        box-shadow: 0 10px 22px rgba(82, 58, 89, 0.08);
        display: inline-flex;
        gap: 8px;
      }
      .card {
        background: #ffffff;
        border-radius: 26px;
        padding: 18px 20px;
        box-shadow: 0 12px 28px rgba(82, 58, 89, 0.10);
        display: grid;
        grid-template-columns: 1fr auto;
        align-items: center;
        gap: 16px;
      }
      .card h4 {
        margin: 0;
        font-size: 16px;
        color: #9aa3ad;
        font-weight: 700;
        letter-spacing: 0.5px;
      }
      .card-label {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 6px;
      }
      .card-pill {
        display: inline-flex;
        align-items: center;
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 800;
        background: #eef2f7;
        color: #64748b;
      }
      .donut {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: conic-gradient(#f5a25d var(--p), #f1f1f5 0);
        position: relative;
        flex-shrink: 0;
      }
      .donut::after {
        content: "";
        position: absolute;
        inset: 4px;
        background: #ffffff;
        border-radius: 50%;
      }
      .donut-label {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        font-size: 10px;
        font-weight: 800;
        color: #4a5a6a;
        z-index: 1;
      }
      .metric {
        font-size: 22px;
        font-weight: 800;
        letter-spacing: 0.2px;
        color: #4a5a6a;
        line-height: 1.1;
      }
      .metric-sub { font-size: 11px; color: #8b90a3; }
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
        box-shadow: 0 14px 28px rgba(82, 58, 89, 0.10);
      }

      .panel-title {
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 8px;
      }
      .panel-subtitle {
        color: #8a93a0;
        font-size: 11px;
        margin-bottom: 14px;
      }
      .overview-strip {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 16px;
        margin-bottom: 18px;
      }
      .summary-panel,
      .attention-panel {
        background: #ffffff;
        border-radius: 20px;
        padding: 16px;
        box-shadow: 0 12px 24px rgba(82, 58, 89, 0.08);
      }
      .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-top: 12px;
      }
      .summary-tile {
        background: #f8fafc;
        border-radius: 16px;
        padding: 12px;
      }
      .summary-tile strong {
        display: block;
        font-size: 22px;
        color: #1f2430;
        margin-bottom: 4px;
      }
      .summary-tile span {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #6b7280;
      }
      .summary-tile.emphasis {
        background: linear-gradient(135deg, rgba(246, 183, 200, 0.18), rgba(245, 162, 93, 0.22));
      }
      .attention-list {
        display: grid;
        gap: 8px;
        margin-top: 12px;
      }
      .attention-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 12px;
        background: #fff8f1;
        border-radius: 14px;
      }
      .attention-item strong {
        display: block;
        font-size: 12px;
        color: #1f2430;
      }
      .attention-item span {
        display: block;
        font-size: 10px;
        color: #9a3412;
      }
      .attention-score {
        min-width: 48px;
        text-align: center;
        padding: 6px 8px;
        border-radius: 999px;
        background: rgba(245, 162, 93, 0.2);
        color: #b45309;
        font-size: 11px;
        font-weight: 800;
      }
      .attention-empty {
        margin-top: 12px;
        padding: 14px 12px;
        background: #f8fafc;
        border-radius: 14px;
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
      }
      .progress-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 8px;
      }
      .progress-table th {
        text-align: left;
        font-size: 11px;
        color: #ffffff;
        font-weight: 700;
        padding: 10px 12px;
        background: var(--rh-gradient);
        border-radius: 8px;
      }
      .progress-table td {
        padding: 10px 8px;
        background: #fff;
      }
      .progress-row {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 10px 20px rgba(82, 58, 89, 0.06);
      }
      .progress-row:hover td {
        background: #fbfcfe;
      }
      .progress-row td:first-child {
        border-radius: 12px 0 0 12px;
        font-weight: 600;
        font-size: 11px;
        position: sticky;
        left: 0;
        z-index: 1;
      }
      .progress-row td:last-child {
        border-radius: 0 12px 12px 0;
      }
      .progress-cell {
        min-width: 140px;
      }
      .kab-meta {
        display: flex;
        flex-direction: column;
        gap: 2px;
      }
      .kab-name {
        font-size: 12px;
        font-weight: 700;
        color: #1f2430;
      }
      .kab-sub {
        font-size: 10px;
        color: #8a93a0;
      }
      .progress-bar {
        height: 24px;
        background: #f4edf6;
        border-radius: 999px;
        overflow: hidden;
        position: relative;
      }
      .progress-fill {
        height: 100%;
        border-radius: 999px;
        position: relative;
      }
      .progress-fill.fill-hk,
      .progress-fill.fill-hpb,
      .progress-fill.fill-hd,
      .progress-fill.fill-hkd {
        background: var(--rh-gradient);
      }
      .progress-text {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        display: flex;
        align-items: center;
        justify-content: flex-end;
        font-size: 10px;
        font-weight: 700;
        color: #5b6471;
        letter-spacing: 0.2px;
        z-index: 2;
      }
      .fill-hk,
      .fill-hpb,
      .fill-hd,
      .fill-hkd {
        background: var(--rh-gradient);
      }
      .progress-fill::after { display: none; }
      .progress-table { min-width: 760px; }
      .progress-table-wrap { overflow-x: auto; }
      .online-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
      }
      .online-count {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: #f8fafc;
        color: #475569;
        font-size: 12px;
        font-weight: 700;
      }
      .online-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 10px;
      }
      .online-item {
        padding: 12px 14px;
        border-radius: 16px;
        background: #ffffff;
        border: 1px solid #eef0f4;
        box-shadow: 0 10px 22px rgba(56,65,80,0.06);
      }
      .online-item strong {
        display: block;
        font-size: 12px;
        color: #1f2430;
        margin-bottom: 4px;
      }
      .online-item span {
        display: block;
        font-size: 10px;
        color: #8a93a0;
      }

      @media (max-width: 1200px) {
        .cards { grid-template-columns: repeat(2, 1fr); }
        .grid { grid-template-columns: 1fr; }
        .app { grid-template-columns: 1fr; }
        .sidebar { flex-direction: row; justify-content: flex-start; overflow-x: auto; }
        .main { padding-right: 0; }
        .overview-strip { grid-template-columns: 1fr; }
        .summary-grid { grid-template-columns: repeat(2, 1fr); }
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
        .summary-grid { grid-template-columns: 1fr; }
        .online-list { grid-template-columns: 1fr; }
      }
    
      /* A: Unified brand actions & table headers */
      :root {
        --rh-gradient: linear-gradient(135deg, #f6b7c8, #f5a25d);
        --rh-accent: #1f6f8b;
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

      /* Dashboard theme (Health Records style) */
      body {
        background: radial-gradient(1200px 600px at 15% 0%, #f7f7f9 0%, #eef1f5 55%, #e7ebf1 100%);
      }
      .app {
        gap: 16px;
        padding: 20px;
      }
      .sidebar {
        background: #f7f7f9;
        border: none;
        padding: 14px 8px;
      }
      .logo {
        background: #ffffff;
        color: #111827;
        border: 1px solid #eef0f4;
      }
      .nav-dot {
        border-radius: 999px;
        background: #f0f2f6;
        color: #7b8496;
      }
      .nav-dot.active, .nav-dot:hover {
        background: #d4f06f;
        color: #1f2430;
        box-shadow: 0 10px 22px rgba(183, 234, 42, 0.35);
      }
      .main {
        background: #f7f7f9;
        border-radius: 28px;
        padding: 24px;
        box-shadow: 0 22px 48px rgba(26, 30, 40, 0.10);
      }
      .hello {
        font-size: 28px;
        line-height: 1.1;
      }
      .subhello {
        font-size: 12px;
        color: #8b93a5;
      }
      .pill {
        border-radius: 999px;
        background: #ffffff;
        border: 1px solid #eef0f4;
        box-shadow: 0 10px 20px rgba(26, 30, 40, 0.08);
      }
      .filters button {
        border-radius: 999px;
        background: #c8f46d;
        color: #1f2430;
        color: #ffffff;
        box-shadow: 0 10px 22px rgba(26, 30, 40, 0.18);
      }
      .cards {
        gap: 12px;
      }
      .card-link {
        display: block;
        color: inherit;
        text-decoration: none;
      }
      .card {
        border-radius: 22px;
        background: #ffffff;
        box-shadow: 0 14px 26px rgba(26, 30, 40, 0.08);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
      }
      .card-link:hover .card,
      .card-link:focus-visible .card {
        transform: translateY(-2px);
        box-shadow: 0 18px 30px rgba(26, 30, 40, 0.12);
      }
      .card-link:focus-visible {
        outline: none;
      }
      .panel {
        background: #ffffff;
        border-radius: 22px;
        box-shadow: 0 14px 26px rgba(26, 30, 40, 0.08);
      }
      .progress-row {
        box-shadow: none;
        background: #ffffff;
      }
      .progress-bar {
        background: #f0f2f6;
      }
      .progress-fill.fill-hk,
      .progress-fill.fill-hpb,
      .progress-fill.fill-hd,
      .progress-fill.fill-hkd {
        background: #c8f46d;
      }
      .progress-table th {
        background: #1f2430;
        color: #f7f7f9;
      }
    </style>
  </head>
  <body>
    <div class="app">
      <aside class="sidebar">
                <a class="nav-dot active" href="index.php" title="Dashboard"><i class="mdi mdi-view-dashboard"></i></a>
        <a class="nav-dot" href="shk.php" title="SHK"><span class="nav-text">HK</span></a>
        <a class="nav-dot" href="hpb.php" title="HPB"><span class="nav-text">HPB</span></a>
        <a class="nav-dot" href="hd.php" title="HD"><span class="nav-text">HD</span></a>
        <a class="nav-dot" href="hkd.php" title="HKD"><span class="nav-text">HKD</span></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="perbandingan-harga.php" title="Perbandingan Harga"><i class="mdi mdi-chart-line"></i></a>
        <a class="nav-dot" href="arah-berbeda.php" title="Arah Berbeda"><i class="mdi mdi-compare"></i></a>
        <a class="nav-dot" href="panduan.php" title="Panduan"><i class="mdi mdi-book-open-variant"></i></a>
      </aside>

      <main class="main">
        <form class="topbar" method="get" action="index.php">
          <div style="display:flex;align-items:center;gap:12px;">
            <img src="assets/images/rh-icon.png" alt="SIHARGA" style="width:52px;height:52px;border-radius:14px;object-fit:cover;display:block;flex:0 0 auto;">
            <div>
              <div class="hello">Progres Pengisian Konfirmasi Perubahan Harga</div>
              <div class="subhello">Progress pengisian penjelasan per kabupaten/kota.</div>
            </div>
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

        <div class="section-title">Overview</div>
        <div class="cards">
          <?php
            $cards = [
              'HK' => ['class' => 'hk', 'href' => 'shk.php'],
              'HPB' => ['class' => 'hpb', 'href' => 'hpb.php'],
              'HD' => ['class' => 'hd', 'href' => 'hd.php'],
              'HKD' => ['class' => 'hkd', 'href' => 'hkd.php'],
            ];
            foreach ($cards as $label => $card_config):
              $class = $card_config['class'];
              $href = $card_config['href'];
              $filled = $scoped_summary_map[$label]['filled'] ?? 0;
              $total = $scoped_summary_map[$label]['total'] ?? 0;
              $percent = $total > 0 ? (int)round(($filled / $total) * 100) : 0;
              $deg = $percent * 3.6;
              $status_label = 'Perlu perhatian';
              if ($label === $level_highest) {
                  $status_label = 'Paling lengkap';
              } elseif ($label === $level_lowest) {
                  $status_label = 'Paling tertinggal';
              }
          ?>
            <a class="card-link" href="<?php echo htmlspecialchars($href); ?>">
              <div class="card label-offset">
                <div>
                  <div class="card-label">
                    <h4><?php echo $label; ?></h4>
                    <span class="card-pill"><?php echo htmlspecialchars($status_label); ?></span>
                  </div>
                  <div class="metric"><?php echo $percent; ?>%</div>
                  <div class="metric-sub"><?php echo $filled; ?> dari <?php echo $total; ?> penjelasan terisi</div>
                </div>
                <div class="donut" style="--p: <?php echo $deg; ?>deg;">
                  <div class="donut-label"><?php echo $percent; ?>%</div>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="overview-strip">
          <div class="summary-panel">
            <div class="panel-title">Ringkasan Monitoring</div>
            <div class="panel-subtitle">Snapshot cepat untuk melihat progres total pada <?php echo htmlspecialchars($role_scope_label); ?> dan area yang perlu difokuskan lebih dulu.</div>
            <div class="summary-grid">
              <div class="summary-tile emphasis">
                <strong><?php echo $overall_percent; ?>%</strong>
                <span>Progress keseluruhan</span>
              </div>
              <div class="summary-tile">
                <strong><?php echo $summary_completed_count; ?></strong>
                <span><?php echo htmlspecialchars($summary_completed_label); ?></span>
              </div>
              <div class="summary-tile">
                <strong><?php echo htmlspecialchars((string)$level_lowest); ?></strong>
                <span>Level paling tertinggal</span>
              </div>
              <div class="summary-tile">
                <strong><?php echo $summary_priority_count; ?></strong>
                <span><?php echo htmlspecialchars($summary_priority_label); ?></span>
              </div>
            </div>
          </div>
          <div class="attention-panel">
            <div class="panel-title"><?php echo htmlspecialchars($attention_title); ?></div>
            <div class="panel-subtitle"><?php echo htmlspecialchars($attention_subtitle); ?></div>
            <?php if ($attention_items): ?>
              <div class="attention-list">
                <?php foreach ($attention_items as $item): ?>
                  <div class="attention-item">
                    <div>
                      <strong><?php echo htmlspecialchars($item['label']); ?></strong>
                      <span><?php echo htmlspecialchars($item['detail']); ?></span>
                    </div>
                    <div class="attention-score"><?php echo htmlspecialchars($item['score']); ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="attention-empty">
                <?php echo $is_kabupaten_user
                    ? 'Semua level harga pada kabupaten Anda yang memiliki data pada filter ini sudah lengkap.'
                    : 'Semua kabupaten yang memiliki data pada filter ini sudah lengkap.'; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel" style="margin-top:16px;">
          <div class="panel-title">Progress Pengisian Penjelasan Perubahan Harga per Kabupaten/Kota</div>
          <div class="panel-subtitle">Urutan tabel dimulai dari progres rata-rata terendah agar kabupaten yang perlu perhatian muncul lebih dulu. Update terakhir: <?php echo htmlspecialchars($last_updated); ?></div>
          <div class="progress-table-wrap">
            <table class="progress-table">
              <thead>
                <tr>
                  <th>Kabupaten/Kota</th>
                  <th>Rata-rata</th>
                  <th>HK</th>
                  <th>HPB</th>
                  <th>HD</th>
                  <th>HKD</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($progress_rows as $row): ?>
                  <tr class="progress-row">
                    <td>
                      <div class="kab-meta">
                        <span class="kab-name"><?php echo htmlspecialchars($row['nama']); ?></span>
                        <span class="kab-sub"><?php echo (int)($row['missing_levels'] ?? 0); ?> level belum lengkap</span>
                      </div>
                    </td>
                    <td class="progress-cell">
                      <div class="progress-bar">
                        <div class="progress-fill fill-hk" style="width: <?php echo (int)($row['avg_percent'] ?? 0); ?>%;"></div>
                        <div class="progress-text"><?php echo (int)($row['avg_percent'] ?? 0); ?>%</div>
                      </div>
                    </td>
                    <?php
                      $labels = ['HK' => 'fill-hk', 'HPB' => 'fill-hpb', 'HD' => 'fill-hd', 'HKD' => 'fill-hkd'];
                    foreach ($labels as $key => $class):
                      $p = $row['progress'][$key] ?? ['filled' => 0, 'total' => 0, 'percent' => 0];
                      $percent = (int)$p['percent'];
                  ?>
                    <td class="progress-cell">
                      <?php if (!empty($p['total'])): ?>
                        <div class="progress-bar">
                          <div class="progress-fill <?php echo $class; ?>" style="width: <?php echo $percent; ?>%;"></div>
                          <div class="progress-text"><?php echo $percent; ?>%</div>
                        </div>
                      <?php else: ?>
                        <div style="height:21px;"></div>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="panel" style="margin-top:16px;">
          <div class="panel-title">Pengguna Online (10 menit terakhir)</div>
          <div class="online-summary">
            <div class="panel-subtitle" style="margin:0;">
              <?php echo $is_kabupaten_user
                  ? 'Akun admin kabupaten dengan kode yang sama yang masih aktif dalam 10 menit terakhir.'
                  : 'Akun dengan role yang sama yang masih aktif dalam 10 menit terakhir.'; ?>
            </div>
            <div class="online-count"><i class="mdi mdi-account-circle-outline"></i> <?php echo count($online_users); ?> akun online</div>
          </div>
          <?php if (empty($online_users)): ?>
            <div style="color:#8a93a0;font-size:12px;padding:6px 0;">Belum ada pengguna online.</div>
          <?php else: ?>
            <div class="online-list">
              <?php foreach ($online_users as $ou): ?>
                <div class="online-item">
                  <strong><?php echo htmlspecialchars($ou['email']); ?></strong>
                  <span>Aktif terakhir: <?php echo htmlspecialchars(date('d M Y H:i', strtotime((string)$ou['last_seen']))); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
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
    </script>

  </body>
</html>
