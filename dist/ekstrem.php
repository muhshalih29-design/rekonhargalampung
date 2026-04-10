<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
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
        'harga_bulan_ini' => 'decimal',
        'harga_bulan_lalu' => 'decimal',
        'perubahan_rata_rata' => 'decimal',
        'konfirmasi_kab' => 'text',
    ];

    if ($id <= 0 || !isset($allowed[$field])) {
        http_response_code(400);
        echo 'Invalid request';
        exit;
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

$sql = 'SELECT * FROM ekstrem';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY komoditas ASC, kab ASC, kecamatan ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$avg_map = [];
$avg_sql = 'SELECT komoditas, AVG(NULLIF(perubahan_rata_rata,0)) AS avg_perubahan FROM ekstrem';
if ($where) {
    $avg_sql .= ' WHERE ' . implode(' AND ', $where);
}
$avg_sql .= ' GROUP BY komoditas';
$stmt_avg = $pdo->prepare($avg_sql);
$stmt_avg->execute($params);
foreach ($stmt_avg as $avg_row) {
    $k = isset($avg_row['komoditas']) ? trim((string)$avg_row['komoditas']) : '';
    $avg_map[$k] = $avg_row['avg_perubahan'];
}

$komoditas_tabs = [];
$tab_sql = 'SELECT DISTINCT komoditas FROM ekstrem';
if ($where) {
    $tab_sql .= ' WHERE ' . implode(' AND ', $where);
}
$tab_sql .= ' ORDER BY komoditas ASC';
$stmt_tabs = $pdo->prepare($tab_sql);
$stmt_tabs->execute($params);
foreach ($stmt_tabs as $row) {
    $k = isset($row['komoditas']) ? trim((string)$row['komoditas']) : '';
    if ($k !== '') {
        $komoditas_tabs[] = $k;
    }
}
$komoditas_selected = isset($_GET['komoditas']) ? trim($_GET['komoditas']) : '';
if ($komoditas_selected === '' && !empty($komoditas_tabs)) {
    $komoditas_selected = $komoditas_tabs[0];
}

$columns = [
    'subsektor' => 'Subsektor',
    'kab' => 'Kabupaten/Kota',
    'kecamatan' => 'Kecamatan',
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

      .main {
        background: transparent;
        padding-right: 8px;
        max-width: 1400px;
        width: 100%;
      }

      .topbar {
        display: grid;
        grid-template-columns: 1fr auto auto auto auto;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
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
        background: linear-gradient(135deg, #ff7ab6, #ffb36b);
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
        background: var(--accent); color: #fff;
      }

      .table-card {
        background: var(--card);
        border-radius: var(--radius);
        padding: 16px;
        box-shadow: 0 14px 28px rgba(56, 65, 80, 0.10);
      }
      .komoditas-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
      }
      .avg-pill {
        background: linear-gradient(135deg, #ff7ab6, #ffb36b);
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
      .table-responsive { overflow-x: auto; }
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
        <a class="nav-dot active" href="ekstrem.php" title="Ekstrem"><i class="mdi mdi-alert-circle-outline"></i></a>
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
            <div class="icon-btn"><i class="mdi mdi-account-circle"></i></div>
          </div>
        </form>
        <?php if (!empty($komoditas_tabs)): ?>
          <div class="tabs" data-selected="<?php echo htmlspecialchars($komoditas_selected); ?>">
            <?php foreach ($komoditas_tabs as $k): ?>
              <?php $is_active = ($k === $komoditas_selected) ? 'active' : ''; ?>
              <button type="button" class="tab-btn <?php echo $is_active; ?>" data-komoditas="<?php echo htmlspecialchars($k); ?>">
                <?php echo htmlspecialchars($k); ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (empty($rows)): ?>
          <div class="table-card">
            <div class="text-center">Belum ada data.</div>
          </div>
        <?php else: ?>
          <?php
            $current_komoditas = null;
            $row_index = 0;
          ?>
          <?php foreach ($rows as $row): ?>
            <?php
              $komoditas = isset($row['komoditas']) ? trim((string)$row['komoditas']) : '';
              if ($komoditas !== $current_komoditas):
                if ($current_komoditas !== null) {
                  echo '</tbody></table></div></div>';
                }
                $current_komoditas = $komoditas;
                $avg_val = array_key_exists($current_komoditas, $avg_map) ? $avg_map[$current_komoditas] : null;
                $avg_display = ($avg_val === null) ? '-' : number_format((float)$avg_val, 2, ',', '.');
                echo '<div class="table-card" data-komoditas="' . htmlspecialchars($current_komoditas) . '" style="margin-bottom:16px;">';
                echo '<div class="komoditas-head">';
                echo '<div style="font-weight:700;">' . htmlspecialchars($current_komoditas) . '</div>';
                echo '<div class="avg-pill"><span class="avg-trend zero">=</span>Rata-rata perubahan: <span class="avg-value">' . htmlspecialchars($avg_display) . '</span></div>';
                echo '</div>';
                echo '<div class="table-responsive"><table><thead><tr>';
                foreach ($columns as $key => $label) {
                  echo '<th>' . $label . '</th>';
                }
                echo '</tr></thead><tbody>';
              endif;

              $values = [];
              foreach ($columns as $key => $label) {
                $value = array_key_exists($key, $row) ? $row[$key] : null;
                if ($value === null || $value === '') {
                  $values[] = '-';
                } else {
                  if (is_numeric($value)) {
                    $values[] = number_format((float)$value, 2, ',', '.');
                  } else {
                    $values[] = htmlspecialchars((string)$value);
                  }
                }
              }
            ?>
            <tr data-id="<?php echo (int)$row['id']; ?>" data-row="<?php echo $row_index; ?>">
              <?php foreach ($columns as $key => $label): ?>
                <?php
                  $value = array_key_exists($key, $row) ? $row[$key] : null;
                  if ($value === null || $value === '') {
                    $value_display = '';
                  } else {
                    if (is_numeric($value)) {
                      $value_display = number_format((float)$value, 2, ',', '.');
                    } else {
                      $value_display = (string)$value;
                    }
                  }
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
                    <div class="d-flex align-items-center justify-content-center">
                      <input type="text" inputmode="decimal" class="form-control form-control-sm perubahan-input editable-cell <?php echo $class; ?>" data-field="perubahan_rata_rata" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="0,00">
                      <?php if ($num !== null): ?>
                        <?php if ($num >= 0): ?>
                          <span class="trend trend-up">▲</span>
                        <?php else: ?>
                          <span class="trend trend-down">▼</span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                <?php elseif ($key === 'harga_bulan_ini'): ?>
                  <td>
                    <input type="text" inputmode="decimal" class="form-control form-control-sm harga-input editable-cell" data-field="harga_bulan_ini" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="0,00">
                  </td>
                <?php elseif ($key === 'harga_bulan_lalu'): ?>
                  <td>
                    <input type="text" inputmode="decimal" class="form-control form-control-sm harga-input editable-cell" data-field="harga_bulan_lalu" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="0,00">
                  </td>
                <?php elseif ($key === 'konfirmasi_kab'): ?>
                  <td>
                    <textarea class="form-control form-control-sm editable-cell wrap-textarea" data-field="konfirmasi_kab" rows="1" placeholder="Isi konfirmasi"><?php echo htmlspecialchars($value_display); ?></textarea>
                  </td>
                <?php else: ?>
                  <td><?php echo htmlspecialchars($value_display === '' ? '-' : $value_display); ?></td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
            <?php $row_index++; ?>
          <?php endforeach; ?>
          <?php if ($current_komoditas !== null): ?>
            </tbody></table></div></div>
          <?php endif; ?>
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

        var tabsWrap = document.querySelector('.tabs');
        if (tabsWrap) {
          function setActiveTab(name) {
            var buttons = tabsWrap.querySelectorAll('.tab-btn');
            buttons.forEach(function (btn) {
              btn.classList.toggle('active', btn.getAttribute('data-komoditas') === name);
            });
            var cards = document.querySelectorAll('.table-card[data-komoditas]');
            cards.forEach(function (card) {
              card.classList.toggle('hidden', card.getAttribute('data-komoditas') !== name);
            });
          }
          tabsWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.tab-btn');
            if (!btn) return;
            var name = btn.getAttribute('data-komoditas');
            if (!name) return;
            setActiveTab(name);
          });
          var initial = tabsWrap.getAttribute('data-selected') || '';
          if (!initial) {
            var first = tabsWrap.querySelector('.tab-btn');
            if (first) initial = first.getAttribute('data-komoditas') || '';
          }
          if (initial) {
            setActiveTab(initial);
          }
        }

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

        var editable = document.querySelectorAll('.editable-cell');
        editable.forEach(function (el) {
          el.addEventListener('blur', function () { saveCell(el); });
          el.addEventListener('change', function () { saveCell(el); });
          if (el.tagName.toLowerCase() === 'textarea') {
            el.addEventListener('input', function () { autoResize(el); });
          }
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
      })();
    </script>
  </body>
</html>
