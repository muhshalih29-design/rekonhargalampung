<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();
$pdo = db();

$bulan = isset($_GET['bulan']) ? trim((string)$_GET['bulan']) : '';
$tahun = isset($_GET['tahun']) ? trim((string)$_GET['tahun']) : '';

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

$levels = [
    'HK' => 'shk',
    'HPB' => 'hpb',
    'HD' => 'hd',
    'HKD' => 'hkd',
];
$existingTables = [];
foreach ($levels as $label => $tableName) {
    if (table_exists($pdo, $tableName)) {
        $existingTables[$label] = $tableName;
    }
}

$commodityMap = [];
foreach ($existingTables as $label => $tableName) {
    $sql = "SELECT TRIM(komoditas) AS komoditas, AVG(NULLIF(perubahan,0)) AS avg_perubahan
            FROM {$tableName}
            WHERE TRIM(LOWER(bulan)) = ? AND tahun = ?
            GROUP BY TRIM(komoditas)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([strtolower(trim($bulan)), (int)$tahun]);
    while ($row = $stmt->fetch()) {
        $komoditas = trim((string)($row['komoditas'] ?? ''));
        if ($komoditas === '') {
            continue;
        }
        $key = strtolower($komoditas);
        if (!isset($commodityMap[$key])) {
            $commodityMap[$key] = [
                'name' => $komoditas,
                'HK' => null,
                'HPB' => null,
                'HD' => null,
                'HKD' => null,
            ];
        } else {
            $existingName = (string)$commodityMap[$key]['name'];
            if ($existingName === strtolower($existingName) && $komoditas !== strtolower($komoditas)) {
                $commodityMap[$key]['name'] = $komoditas;
            }
        }
        $commodityMap[$key][$label] = $row['avg_perubahan'] !== null ? (float)$row['avg_perubahan'] : null;
    }
}

$allRows = [];
$mixedCount = 0;
foreach ($commodityMap as $item) {
    $nonZero = [];
    foreach (['HK', 'HPB', 'HD', 'HKD'] as $label) {
        $value = $item[$label];
        if ($value === null || (float)$value == 0.0) {
            continue;
        }
        $nonZero[] = (float)$value;
    }

    $hasPos = false;
    $hasNeg = false;
    $maxAbs = 0.0;
    foreach ($nonZero as $value) {
        if ($value > 0) {
            $hasPos = true;
        }
        if ($value < 0) {
            $hasNeg = true;
        }
        $maxAbs = max($maxAbs, abs($value));
    }

    $statusKey = 'empty';
    $statusLabel = 'Tidak ada data';
    if ($hasPos && $hasNeg) {
        $statusKey = 'mixed';
        $statusLabel = 'Tidak searah';
        $mixedCount++;
    } elseif (count($nonZero) >= 2) {
        $statusKey = 'aligned';
        $statusLabel = 'Searah';
    } elseif (count($nonZero) === 1) {
        $statusKey = 'partial';
        $statusLabel = 'Data parsial';
    }

    $item['active_count'] = count($nonZero);
    $item['intensity'] = $maxAbs;
    $item['status_key'] = $statusKey;
    $item['status_label'] = $statusLabel;
    $allRows[] = $item;
}

usort($allRows, static function (array $a, array $b): int {
    $statusRank = ['mixed' => 1, 'aligned' => 2, 'partial' => 3, 'empty' => 4];
    $ra = $statusRank[$a['status_key']] ?? 9;
    $rb = $statusRank[$b['status_key']] ?? 9;
    if ($ra !== $rb) {
        return $ra <=> $rb;
    }
    if ((int)$a['active_count'] === (int)$b['active_count']) {
        if ((float)$a['intensity'] === (float)$b['intensity']) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        }
        return ((float)$b['intensity'] <=> (float)$a['intensity']);
    }
    return ((int)$b['active_count'] <=> (int)$a['active_count']);
});

function fmt_change($val): string
{
    if ($val === null) {
        return '-';
    }
    return number_format((float)$val, 2, ',', '.') . '%';
}

function trend_class($val): string
{
    if ($val === null) {
        return 'neutral';
    }
    return ((float)$val >= 0) ? 'pos' : 'neg';
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Arah Berbeda</title>
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
        background: #fff;
        border-radius: 22px;
        border: 1px solid #eef0f4;
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
      }
      .nav-dot.active, .nav-dot:hover {
        background: #fff;
        color: var(--accent);
        box-shadow: 0 12px 24px rgba(242, 139, 43, 0.2);
      }
      .nav-text { font-size: 12px; font-weight: 700; color: inherit; letter-spacing: .5px; }
      .main { padding-right: 8px; }
      .topbar {
        display: grid;
        grid-template-columns: 1fr auto auto auto;
        align-items: center;
        gap: 12px;
        margin-bottom: 14px;
      }
      .hello { font-size: 24px; font-weight: 700; }
      .subhello { color: var(--muted); font-size: 12px; }
      .pill {
        background: #fff;
        border-radius: 16px;
        padding: 8px 12px;
        border: 1px solid #eef0f4;
        box-shadow: 0 12px 24px rgba(56, 65, 80, 0.08);
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--muted);
        font-size: 12px;
      }
      .pill select {
        border: 0;
        background: transparent;
        color: var(--ink);
        outline: none;
      }
      .filters button {
        border-radius: 10px;
        padding: 8px 14px;
        border: none;
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #fff;
        font-weight: 600;
      }
      .card {
        background: #fff;
        border-radius: 18px;
        padding: 16px;
        box-shadow: 0 14px 28px rgba(56, 65, 80, 0.1);
      }
      .meta {
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
      }
      .meta-chip {
        border-radius: 999px;
        padding: 6px 10px;
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
      }
      .count-chip {
        border-radius: 999px;
        padding: 6px 10px;
        border: 1px solid #f0d4be;
        background: #fff7ef;
        color: #a04d18;
        font-size: 11px;
        font-weight: 700;
      }
      table { width: 100%; border-collapse: collapse; }
      th, td {
        text-align: left;
        padding: 10px 10px;
        font-size: 12px;
        border-bottom: 1px solid #eef3f2;
      }
      th {
        color: #4b5563;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
      }
      .value.pos { color: #168f4a; font-weight: 700; }
      .value.neg { color: #d94b4b; font-weight: 700; }
      .value.neutral { color: #94a3b8; font-weight: 600; }
      .trend-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        border: 1px solid #f4d2d2;
        background: #fff5f5;
        color: #b54545;
      }
      .trend-badge.aligned {
        border-color: #cce8d7;
        background: #f4fcf7;
        color: #237a45;
      }
      .trend-badge.partial {
        border-color: #d9e1ef;
        background: #f8fafc;
        color: #61738a;
      }
      .trend-badge.empty {
        border-color: #e5e7eb;
        background: #f9fafb;
        color: #6b7280;
      }
      .empty {
        padding: 18px;
        border-radius: 14px;
        background: #f8fafc;
        color: #64748b;
        font-size: 13px;
        border: 1px dashed #dce3ec;
      }
      @media (max-width: 1100px) {
        .app { grid-template-columns: 1fr; padding: 14px; }
        .sidebar { flex-direction: row; justify-content: flex-start; overflow-x: auto; }
        .topbar { grid-template-columns: 1fr; }
      }
    </style>
  </head>
  <body>
    <div class="app">
      <aside class="sidebar">
        <div class="logo"><img src="assets/images/rh-icon.png" alt="RH" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:inherit;"></div>
        <a class="nav-dot" href="index.php" title="Dashboard"><i class="mdi mdi-view-dashboard"></i></a>
        <a class="nav-dot" href="shk.php" title="HK"><span class="nav-text">HK</span></a>
        <a class="nav-dot" href="hpb.php" title="HPB"><span class="nav-text">HPB</span></a>
        <a class="nav-dot" href="hd.php" title="HD"><span class="nav-text">HD</span></a>
        <a class="nav-dot" href="hkd.php" title="HKD"><span class="nav-text">HKD</span></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="perbandingan-harga.php" title="Perbandingan Harga"><i class="mdi mdi-chart-line"></i></a>
        <a class="nav-dot active" href="arah-berbeda.php" title="Arah Berbeda"><i class="mdi mdi-compare"></i></a>
        <a class="nav-dot" href="panduan.php" title="Panduan"><i class="mdi mdi-book-open-variant"></i></a>
      </aside>
      <main class="main">
        <form class="topbar" method="get" action="arah-berbeda.php">
          <div>
            <div class="title-banner-wrap" style="margin-bottom:12px;display:flex;align-items:center;line-height:0;">
              <img src="assets/images/title-banner.png" alt="Rekon Harga Lampung" style="width:min(320px,100%);height:auto;display:block;border-radius:18px;box-shadow:0 12px 26px rgba(56,65,80,0.10);">
            </div>
            <div class="hello">Komoditas Arah Berbeda</div>
            <div class="subhello">Daftar komoditas yang arah perubahan antar HK/HPB/HD/HKD tidak sejalan.</div>
          </div>
          <div class="pill">
            <i class="mdi mdi-calendar"></i>
            <select name="bulan">
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
              <?php
              foreach (range(2020, 2030) as $y) {
                  $selected = ((string)$tahun === (string)$y) ? 'selected' : '';
                  echo '<option value="' . $y . '" ' . $selected . '>' . $y . '</option>';
              }
              ?>
            </select>
          </div>
          <div class="filters">
            <button type="submit">Filter</button>
          </div>
        </form>

        <div class="card">
          <div class="meta">
            <div class="meta-chip">Bulan: <?php echo htmlspecialchars(ucfirst($bulan)); ?> · Tahun: <?php echo htmlspecialchars((string)$tahun); ?></div>
            <div class="count-chip"><?php echo $mixedCount; ?> komoditas arah berbeda · <?php echo count($allRows); ?> total komoditas</div>
          </div>
          <?php if (empty($allRows)): ?>
            <div class="empty">Belum ada data komoditas pada filter ini.</div>
          <?php else: ?>
            <div style="overflow:auto;">
              <table>
                <thead>
                  <tr>
                    <th style="width:60px;">No</th>
                    <th>Komoditas</th>
                    <th>HK</th>
                    <th>HPB</th>
                    <th>HD</th>
                    <th>HKD</th>
                    <th style="width:170px;">Keterangan</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($allRows as $i => $row): ?>
                    <tr>
                      <td><?php echo $i + 1; ?></td>
                      <td style="font-weight:700;"><?php echo htmlspecialchars((string)$row['name']); ?></td>
                      <?php foreach (['HK','HPB','HD','HKD'] as $label): ?>
                        <?php $v = $row[$label]; ?>
                        <td><span class="value <?php echo trend_class($v); ?>"><?php echo htmlspecialchars(fmt_change($v)); ?></span></td>
                      <?php endforeach; ?>
                      <td>
                        <span class="trend-badge <?php echo htmlspecialchars((string)$row['status_key']); ?>">
                          <i class="mdi <?php echo ($row['status_key'] === 'mixed') ? 'mdi-alert-circle-outline' : (($row['status_key'] === 'aligned') ? 'mdi-check-circle-outline' : 'mdi-information-outline'); ?>"></i>
                          <?php echo htmlspecialchars((string)$row['status_label']); ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </main>
    </div>
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
