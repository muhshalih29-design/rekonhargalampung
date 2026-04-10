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
        grid-template-columns: 1fr auto auto auto;
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
      }
      table { width: 100%; border-collapse: collapse; min-width: 1200px; }
      th, td { border: 1px solid #e5e7eb; }
      th { background: #445468; color: #fff; font-size: 12px; padding: 6px; text-align: center; }
      .head-yellow { background: linear-gradient(135deg, #ff7ab6, #ffb36b); color: #fff; font-weight: 700; }
      .head-pink { background: #e9edf3; color: #445468; font-weight: 700; }
      .subhead { background: #58697d; color: #fff; font-weight: 700; }
      .subhead-dark { background: #3f4f63; color: #fff; font-weight: 700; }
      .col-fixed { background: #ffffff; font-weight: 600; }
      .rh-col { background: #fff3c4; }
      .cell-disabled {
        background: #e0e5ec !important;
        color: #6b7280 !important;
      }
      td { padding: 6px; font-size: 12px; background: #ffffff; }
      .cell-input {
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        font-size: 12px;
        text-align: right;
      }
      .cell-text {
        width: 100%;
        border: 0;
        outline: none;
        background: transparent;
        font-size: 12px;
      }
      .cell-input:disabled,
      .cell-text:disabled {
        background: #e0e5ec;
        color: #6b7280;
      }
      .cell-wrap {
        position: relative;
        width: 100%;
      }
      .cell-wrap .trend {
        position: absolute;
        left: 6px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 11px;
        font-weight: 800;
        pointer-events: none;
      }
      .cell-wrap .cell-input {
        padding-left: 18px;
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
        <a class="nav-dot active" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><i class="mdi mdi-rice"></i></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><i class="mdi mdi-alert-circle-outline"></i></a>
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
          <div class="actions">
            <a class="icon-btn" href="logout.php" title="Logout"><i class="mdi mdi-logout"></i></a>
          </div>
        </form>

        <div class="table-card">
          <table>
            <thead>
              <tr>
                <th colspan="1" class="head-yellow">Komoditas</th>
                <th colspan="6" class="head-yellow">Gabah</th>
                <th colspan="9" class="head-pink">Beras</th>
              </tr>
              <tr>
                <th rowspan="2" class="subhead">Kabupaten/kota</th>
                <th colspan="3" class="subhead">SHPED_HD</th>
                <th colspan="3" class="subhead">SHPED_HKD</th>
                <th colspan="3" class="subhead">SHP</th>
                <th colspan="3" class="subhead">HPB</th>
                <th colspan="3" class="subhead">HK</th>
              </tr>
              <tr>
                <th class="subhead-dark">N-1</th>
                <th class="subhead-dark">N</th>
                <th class="subhead-dark">RH (%)</th>
                <th class="subhead-dark">N-1</th>
                <th class="subhead-dark">N</th>
                <th class="subhead-dark">RH (%)</th>
                <th class="subhead-dark">N-2</th>
                <th class="subhead-dark">N</th>
                <th class="subhead-dark">RH (%)</th>
                <th class="subhead-dark">N-1</th>
                <th class="subhead-dark">N</th>
                <th class="subhead-dark">RH (%)</th>
                <th class="subhead-dark">N-1</th>
                <th class="subhead-dark">N</th>
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
                  $val = function($k) use ($row) {
                    $v = $row[$k] ?? '';
                    if ($v === null || $v === '') return '';
                    if (is_numeric($v)) return number_format((float)$v, 2, ',', '.');
                    return (string)$v;
                  };
                ?>
                <tr data-id="<?php echo (int)$row['id']; ?>">
                  <td class="col-fixed"><input class="cell-text" data-field="kabupaten_kota" value="<?php echo htmlspecialchars($row['kabupaten_kota'] ?? ''); ?>" <?php echo $disabled_all; ?>></td>
                  <td><input class="cell-input" data-field="shped_hd_n1" value="<?php echo htmlspecialchars($val('shped_hd_n1')); ?>" <?php echo $is_locked('shped_hd_n1'); ?>></td>
                  <td><input class="cell-input" data-field="shped_hd_n" value="<?php echo htmlspecialchars($val('shped_hd_n')); ?>" <?php echo $is_locked('shped_hd_n'); ?>></td>
                  <td class="rh-col"><div class="cell-wrap"><span class="trend"></span><input class="cell-input rh-input" data-field="shped_hd_rh" value="<?php echo htmlspecialchars($val('shped_hd_rh')); ?>" <?php echo $is_locked('shped_hd_rh'); ?>></div></td>
                  <td><input class="cell-input" data-field="shped_hkd_n1" value="<?php echo htmlspecialchars($val('shped_hkd_n1')); ?>" <?php echo $is_locked('shped_hkd_n1'); ?>></td>
                  <td><input class="cell-input" data-field="shped_hkd_n" value="<?php echo htmlspecialchars($val('shped_hkd_n')); ?>" <?php echo $is_locked('shped_hkd_n'); ?>></td>
                  <td class="rh-col"><div class="cell-wrap"><span class="trend"></span><input class="cell-input rh-input" data-field="shped_hkd_rh" value="<?php echo htmlspecialchars($val('shped_hkd_rh')); ?>" <?php echo $is_locked('shped_hkd_rh'); ?>></div></td>
                  <td><input class="cell-input" data-field="shp_n2" value="<?php echo htmlspecialchars($val('shp_n2')); ?>" <?php echo $is_locked('shp_n2'); ?>></td>
                  <td><input class="cell-input" data-field="shp_n" value="<?php echo htmlspecialchars($val('shp_n')); ?>" <?php echo $is_locked('shp_n'); ?>></td>
                  <td class="rh-col"><div class="cell-wrap"><span class="trend"></span><input class="cell-input rh-input" data-field="shp_rh" value="<?php echo htmlspecialchars($val('shp_rh')); ?>" <?php echo $is_locked('shp_rh'); ?>></div></td>
                  <td><input class="cell-input" data-field="hpb_n1" value="<?php echo htmlspecialchars($val('hpb_n1')); ?>" <?php echo $is_locked('hpb_n1'); ?>></td>
                  <td><input class="cell-input" data-field="hpb_n" value="<?php echo htmlspecialchars($val('hpb_n')); ?>" <?php echo $is_locked('hpb_n'); ?>></td>
                  <td class="rh-col"><div class="cell-wrap"><span class="trend"></span><input class="cell-input rh-input" data-field="hpb_rh" value="<?php echo htmlspecialchars($val('hpb_rh')); ?>" <?php echo $is_locked('hpb_rh'); ?>></div></td>
                  <td><input class="cell-input" data-field="hk_n1" value="<?php echo htmlspecialchars($val('hk_n1')); ?>" <?php echo $is_locked('hk_n1'); ?>></td>
                  <td><input class="cell-input" data-field="hk_n" value="<?php echo htmlspecialchars($val('hk_n')); ?>" <?php echo $is_locked('hk_n'); ?>></td>
                  <td class="rh-col"><div class="cell-wrap"><span class="trend"></span><input class="cell-input rh-input" data-field="hk_rh" value="<?php echo htmlspecialchars($val('hk_rh')); ?>" <?php echo $is_locked('hk_rh'); ?>></div></td>
                </tr>
              <?php endforeach; ?>
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
          if (isNaN(num) || num === 0) {
            trend.textContent = '=';
            trend.style.color = '#6b7280';
            return;
          }
          if (num > 0) {
            trend.textContent = '▲';
            trend.style.color = '#16a34a';
          } else {
            trend.textContent = '▼';
            trend.style.color = '#dc2626';
          }
        }
        var timers = new WeakMap();
        function scheduleSave(el) {
          if (timers.has(el)) clearTimeout(timers.get(el));
          var t = setTimeout(function () { saveCell(el); timers.delete(el); }, 700);
          timers.set(el, t);
        }
        inputs.forEach(function (el) {
          if (el.disabled) return;
          if (el.classList.contains('rh-input')) updateTrend(el);
          el.addEventListener('input', function () { scheduleSave(el); });
          el.addEventListener('blur', function () { saveCell(el); });
          el.addEventListener('input', function () { updateTrend(el); });
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
              updateTrend(el);
              saveCell(el);
            });
          });
        });
      })();
    </script>
  </body>
</html>
