<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();
$last_updated = date('d M Y H:i');
$pdo = db();
$online_users = [];
try {
    $stmt_online = $pdo->query("SELECT email, last_seen FROM users WHERE last_seen IS NOT NULL AND last_seen > (NOW() - INTERVAL '10 minutes') ORDER BY last_seen DESC");
    $online_users = $stmt_online->fetchAll();
} catch (Throwable $e) {
    $online_users = [];
}
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

$progress_rows = [];
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

uksort($base, function ($a, $b) {
    return (int)$a <=> (int)$b;
});
$progress_rows = array_values($base);
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
        margin-bottom: 16px;
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
        border-radius: 9999px;
        padding: 16px 24px;
        box-shadow: 0 12px 28px rgba(82, 58, 89, 0.10);
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
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 10px;
      }
      .progress-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 6px;
      }
      .progress-table th {
        text-align: left;
        font-size: 11px;
        color: var(--muted);
        font-weight: 700;
        padding: 4px 6px;
      }
      .progress-table td {
        padding: 6px;
        background: #fff;
      }
      .progress-row {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 10px 20px rgba(82, 58, 89, 0.06);
      }
      .progress-row td:first-child {
        border-radius: 12px 0 0 12px;
        font-weight: 600;
        font-size: 11px;
      }
      .progress-row td:last-child {
        border-radius: 0 12px 12px 0;
      }
      .progress-cell {
        min-width: 140px;
      }
      .progress-bar {
        height: 20px;
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
    </style>
  </head>
  <body>
    <div class="app">
      <aside class="sidebar">
        <div class="logo">RH</div>
        <a class="nav-dot active" href="index.php" title="Dashboard"><i class="mdi mdi-view-dashboard"></i></a>
        <a class="nav-dot" href="perbandingan-harga.php" title="Perbandingan Harga"><i class="mdi mdi-chart-line"></i></a>
        <a class="nav-dot" href="shk.php" title="SHK"><span class="nav-text">HK</span></a>
        <a class="nav-dot" href="hpb.php" title="HPB"><span class="nav-text">HPB</span></a>
        <a class="nav-dot" href="hd.php" title="HD"><span class="nav-text">HD</span></a>
        <a class="nav-dot" href="hkd.php" title="HKD"><span class="nav-text">HKD</span></a>
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="panduan.php" title="Panduan"><i class="mdi mdi-book-open-variant"></i></a>
      </aside>

      <main class="main">
        <form class="topbar" method="get" action="index.php">
          <div>
            <div class="hello">Progres Pengisian Konfirmasi Perubahan Harga</div>
            <div class="subhello">Progress pengisian penjelasan per kabupaten/kota.</div>
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


        <div class="panel" style="margin-top:16px;">
          <div class="panel-title">Progress Pengisian Penjelasan Perubahan Harga per Kabupaten/Kota</div>
          <div style="color:#8a93a0;font-size:11px;margin-top:-6px;margin-bottom:8px;">Update terakhir: <?php echo htmlspecialchars($last_updated); ?></div>
          <div class="progress-table-wrap">
            <table class="progress-table">
              <thead>
                <tr>
                  <th>Kabupaten/Kota</th>
                  <th>HK</th>
                  <th>HPB</th>
                  <th>HD</th>
                  <th>HKD</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($progress_rows as $row): ?>
                  <tr class="progress-row">
                    <td><?php echo htmlspecialchars($row['nama']); ?></td>
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
          <?php if (empty($online_users)): ?>
            <div style="color:#8a93a0;font-size:12px;padding:6px 0;">Belum ada pengguna online.</div>
          <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
              <?php foreach ($online_users as $ou): ?>
                <div style="padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #eef0f4;font-size:12px;color:#6b7280;box-shadow:0 6px 14px rgba(56,65,80,0.08);">
                  <?php echo htmlspecialchars($ou['email']); ?>
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
