<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();
$pdo = db();

// Keep visible HD commodities controlled: default seeded from April, extras appear only when added by admin.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS hd_visible_commodities (
        komoditas TEXT PRIMARY KEY,
        source TEXT NOT NULL DEFAULT 'april_default',
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
");

$bulan_map = [
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
$bulan_list = ['januari','februari','maret','april','mei','juni','juli','agustus','september','oktober','november','desember'];

$all = isset($_GET['all']) ? trim($_GET['all']) : '';
$bulan = isset($_GET['bulan']) ? trim($_GET['bulan']) : '';
$tahun = isset($_GET['tahun']) ? trim($_GET['tahun']) : '';
$notice = isset($_GET['notice']) ? trim($_GET['notice']) : '';
$notice_type = isset($_GET['notice_type']) ? trim($_GET['notice_type']) : 'success';

if ($all === '' && $bulan === '' && $tahun === '') {
    $currentMonth = new DateTime('first day of this month');
    $bulan = strtolower($currentMonth->format('F'));
    $bulan = $bulan_map[$bulan] ?? $bulan;
    $tahun = $currentMonth->format('Y');
}

ensure_hd_visible_seed($pdo, ctype_digit((string)$tahun) ? (int)$tahun : null);

function parse_hd_decimal($value): ?float
{
    $raw = is_string($value) ? trim($value) : '';
    if ($raw === '' || $raw === '-') {
        return null;
    }
    $raw = str_replace(["\xC2\xA0", ' '], '', $raw);
    $raw = str_replace(['−', '–', '—'], '-', $raw);
    $raw = str_replace('%', '', $raw);
    $raw = preg_replace('/[^0-9,\.\-]/u', '', $raw) ?? $raw;
    $hasComma = strpos($raw, ',') !== false;
    $hasDot = strpos($raw, '.') !== false;
    if ($hasComma && $hasDot) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } elseif ($hasComma) {
        $parts = explode(',', $raw);
        $last = end($parts);
        if ($last !== false && strlen((string)$last) <= 2) {
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
    } elseif ($hasDot) {
        $parts = explode('.', $raw);
        $last = end($parts);
        if (!($last !== false && strlen((string)$last) <= 2)) {
            $raw = str_replace('.', '', $raw);
        }
    }
    if ($raw === '' || $raw === '-' || !is_numeric($raw)) {
        return null;
    }
    return (float)$raw;
}

function normalize_hd_header(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = str_replace(['(', ')', '%', '-', '_'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function ensure_hd_visible_seed(PDO $pdo, ?int $preferredYear = null): void
{
    $yearsToTry = [];
    if ($preferredYear !== null) {
        $yearsToTry[] = $preferredYear;
    }
    if (!in_array(2026, $yearsToTry, true)) {
        $yearsToTry[] = 2026;
    }

    $insertedAny = false;
    foreach ($yearsToTry as $year) {
        $seedStmt = $pdo->prepare("
            INSERT INTO hd_visible_commodities (komoditas, source)
            SELECT DISTINCT TRIM(komoditas), 'april_default'
            FROM hd
            WHERE TRIM(COALESCE(komoditas, '')) <> ''
              AND TRIM(LOWER(bulan)) = 'april'
              AND tahun = ?
            ON CONFLICT (komoditas) DO NOTHING
        ");
        $seedStmt->execute([$year]);
        if ($seedStmt->rowCount() > 0) { $insertedAny = true; }
    }

    if (!$insertedAny) {
        $fallbackStmt = $pdo->prepare("
            INSERT INTO hd_visible_commodities (komoditas, source)
            SELECT DISTINCT TRIM(komoditas), 'april_default'
            FROM hd
            WHERE TRIM(COALESCE(komoditas, '')) <> ''
              AND TRIM(LOWER(bulan)) = 'april'
            ON CONFLICT (komoditas) DO NOTHING
        ");
        $fallbackStmt->execute();
    }
}

function parse_hd_upload_rows(string $tmpPath, string $filename): array
{
    $content = @file_get_contents($tmpPath);
    if ($content === false || trim($content) === '') {
        throw new RuntimeException('File tidak dapat dibaca.');
    }

    $isHtml = stripos($content, '<table') !== false || stripos($filename, '.xls') !== false;
    $rows = [];
    if ($isHtml) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($content);
        libxml_clear_errors();
        if (!$loaded) {
            throw new RuntimeException('Format HTML XLS tidak valid.');
        }
        $xpath = new DOMXPath($dom);
        $trNodes = $xpath->query('//table[1]//tr');
        if (!$trNodes || $trNodes->length === 0) {
            throw new RuntimeException('Tabel pada file tidak ditemukan.');
        }
        foreach ($trNodes as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (!($cell instanceof DOMElement)) {
                    continue;
                }
                $tag = strtolower($cell->tagName);
                if ($tag !== 'th' && $tag !== 'td') {
                    continue;
                }
                $text = trim(preg_replace('/\s+/', ' ', (string)$cell->textContent) ?? '');
                $cells[] = $text;
            }
            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }
    } else {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }
    }

    if (empty($rows)) {
        throw new RuntimeException('Data pada file kosong.');
    }

    $headerIndex = -1;
    $header = [];
    foreach ($rows as $idx => $row) {
        $joined = normalize_hd_header(implode(' ', $row));
        if (strpos($joined, 'nama komoditas') !== false && strpos($joined, 'kabupaten') !== false) {
            $headerIndex = $idx;
            $header = $row;
            break;
        }
    }
    if ($headerIndex === -1) {
        throw new RuntimeException('Header tidak cocok. Pastikan file dari export tabel yang benar.');
    }

    $map = [
        'kabupaten' => null,
        'nama_komoditas' => null,
        'perubahan_rata_rata' => null,
    ];
    foreach ($header as $i => $cell) {
        $h = normalize_hd_header((string)$cell);
        if ($h === 'kabupaten') {
            $map['kabupaten'] = $i;
        } elseif ($h === 'nama komoditas') {
            $map['nama_komoditas'] = $i;
        } elseif ($h === 'perubahan rata rata') {
            $map['perubahan_rata_rata'] = $i;
        }
    }
    if ($map['kabupaten'] === null || $map['nama_komoditas'] === null || $map['perubahan_rata_rata'] === null) {
        throw new RuntimeException('Kolom wajib tidak ditemukan di file (Kabupaten, Nama Komoditas, Perubahan Rata-rata).');
    }

    $grouped = [];
    for ($r = $headerIndex + 1; $r < count($rows); $r++) {
        $row = $rows[$r];
        $kabRaw = trim((string)($row[$map['kabupaten']] ?? ''));
        $komoditas = trim((string)($row[$map['nama_komoditas']] ?? ''));
        $perubahanRaw = (string)($row[$map['perubahan_rata_rata']] ?? '');
        if ($kabRaw === '' || $komoditas === '') {
            continue;
        }
        $perubahan = parse_hd_decimal($perubahanRaw);
        if ($perubahan === null) {
            continue;
        }
        $kabDigits = preg_replace('/\D+/', '', $kabRaw) ?? '';
        if ($kabDigits === '') {
            continue;
        }
        $kabShort = str_pad(substr($kabDigits, -2), 2, '0', STR_PAD_LEFT);
        $kodeKabupaten = '18' . $kabShort;
        $key = strtolower($kodeKabupaten . '|' . $komoditas);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'kode_kabupaten' => $kodeKabupaten,
                'komoditas' => $komoditas,
                'sum' => 0.0,
                'count' => 0,
            ];
        }
        $grouped[$key]['sum'] += (float)$perubahan;
        $grouped[$key]['count'] += 1;
    }

    if (empty($grouped)) {
        throw new RuntimeException('Tidak ada baris valid yang bisa diimpor.');
    }
    return array_values($grouped);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_hd_data') {
    if (!is_provinsi($user)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $isAjaxUpload = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $respondUpload = static function (string $type, string $message, string $redirect = '') use ($isAjaxUpload): void {
        if ($isAjaxUpload) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => $type === 'success',
                'notice_type' => $type,
                'notice' => $message,
                'redirect' => $redirect,
            ]);
            exit;
        }
        if ($redirect !== '') {
            header('Location: ' . $redirect);
        } else {
            header('Location: hd.php?notice_type=' . urlencode($type) . '&notice=' . urlencode($message));
        }
        exit;
    };

    $filterBulan = strtolower(trim((string)($_POST['filter_bulan'] ?? $bulan)));
    $filterTahun = trim((string)($_POST['filter_tahun'] ?? $tahun));
    if ($filterBulan === '' || $filterTahun === '' || !ctype_digit($filterTahun)) {
        $respondUpload('error', 'Pilih bulan dan tahun yang valid sebelum upload.', 'hd.php?notice_type=error&notice=' . urlencode('Pilih bulan dan tahun yang valid sebelum upload.'));
    }
    if (!isset($_FILES['hd_upload_file']) || !is_array($_FILES['hd_upload_file']) || (int)($_FILES['hd_upload_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $respondUpload('error', 'File upload tidak valid.', 'hd.php?notice_type=error&notice=' . urlencode('File upload tidak valid.'));
    }

    $tmpPath = (string)$_FILES['hd_upload_file']['tmp_name'];
    $fileName = (string)($_FILES['hd_upload_file']['name'] ?? 'upload.xls');
    try {
        $groupedRows = parse_hd_upload_rows($tmpPath, $fileName);

        $stmtKabMap = $pdo->query("SELECT DISTINCT kode_kabupaten, nama_kabupaten FROM hd WHERE TRIM(COALESCE(kode_kabupaten, '')) <> '' AND TRIM(COALESCE(nama_kabupaten, '')) <> ''");
        $kabupatenNameMap = [];
        foreach ($stmtKabMap as $kabRow) {
            $kabupatenNameMap[(string)$kabRow['kode_kabupaten']] = (string)$kabRow['nama_kabupaten'];
        }

        $selectStmt = $pdo->prepare("
            SELECT id
            FROM hd
            WHERE kode_kabupaten = ?
              AND TRIM(LOWER(komoditas)) = TRIM(LOWER(?))
              AND TRIM(LOWER(bulan)) = ?
              AND tahun = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $updateStmt = $pdo->prepare("UPDATE hd SET perubahan = ?, catatan = '' WHERE id = ?");
        $insertStmt = $pdo->prepare("
            INSERT INTO hd (kode_kabupaten, nama_kabupaten, bulan, tahun, komoditas, perubahan, catatan, penjelasan, time_stamp)
            VALUES (?, ?, ?, ?, ?, ?, '', '', NOW())
        ");

        $updated = 0;
        $inserted = 0;
        $pdo->beginTransaction();
        foreach ($groupedRows as $item) {
            $kodeKab = (string)$item['kode_kabupaten'];
            $komoditasVal = (string)$item['komoditas'];
            $avgPerubahan = ((float)$item['sum']) / max(1, (int)$item['count']);
            $selectStmt->execute([$kodeKab, $komoditasVal, $filterBulan, (int)$filterTahun]);
            $existing = $selectStmt->fetch();
            if ($existing) {
                $updateStmt->execute([$avgPerubahan, (int)$existing['id']]);
                $updated++;
            } else {
                $namaKabupaten = $kabupatenNameMap[$kodeKab] ?? $kodeKab;
                $insertStmt->execute([$kodeKab, $namaKabupaten, $filterBulan, (int)$filterTahun, $komoditasVal, $avgPerubahan]);
                $inserted++;
            }
        }
        $pdo->commit();

        $noticeMsg = 'Upload berhasil: ' . $updated . ' baris diperbarui, ' . $inserted . ' baris ditambahkan.';
        $redirect = 'hd.php?bulan=' . urlencode($filterBulan) . '&tahun=' . urlencode($filterTahun) . '&notice_type=success&notice=' . urlencode($noticeMsg);
        $respondUpload('success', $noticeMsg, $redirect);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = trim((string)$e->getMessage());
        if ($msg === '') {
            $msg = 'Upload data gagal.';
        }
        $redirect = 'hd.php?bulan=' . urlencode($filterBulan) . '&tahun=' . urlencode($filterTahun) . '&notice_type=error&notice=' . urlencode($msg);
        $respondUpload('error', $msg, $redirect);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_commodity') {
    if (!is_provinsi($user)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $commodity_raw = isset($_POST['commodity_name']) ? (string)$_POST['commodity_name'] : '';
    $commodity_name = preg_replace('/\s+/', ' ', trim($commodity_raw));
    $delete_scope = isset($_POST['delete_scope']) ? trim((string)$_POST['delete_scope']) : 'current';
    $filter_bulan = isset($_POST['filter_bulan']) ? trim((string)$_POST['filter_bulan']) : '';
    $filter_tahun = isset($_POST['filter_tahun']) ? trim((string)$_POST['filter_tahun']) : '';

    if ($commodity_name === '') {
        header('Location: hd.php?notice_type=error&notice=' . urlencode('Komoditas yang akan dihapus belum dipilih.'));
        exit;
    }
    if (!in_array($delete_scope, ['current', 'all'], true)) {
        header('Location: hd.php?notice_type=error&notice=' . urlencode('Pilihan hapus komoditas tidak valid.'));
        exit;
    }

    $redirect_query = [];
    if ($filter_bulan !== '') {
        $redirect_query['bulan'] = $filter_bulan;
    }
    if ($filter_tahun !== '' && ctype_digit($filter_tahun)) {
        $redirect_query['tahun'] = $filter_tahun;
    }

    if ($delete_scope === 'current') {
        if ($filter_bulan === '' || $filter_tahun === '' || !ctype_digit($filter_tahun)) {
            $redirect_query['notice_type'] = 'error';
            $redirect_query['notice'] = 'Filter bulan dan tahun harus dipilih untuk hapus komoditas pada bulan ini saja.';
            header('Location: hd.php?' . http_build_query($redirect_query));
            exit;
        }
        $delete_stmt = $pdo->prepare('DELETE FROM hd WHERE TRIM(LOWER(komoditas)) = TRIM(LOWER(?)) AND TRIM(LOWER(bulan)) = ? AND tahun = ?');
        $delete_stmt->execute([$commodity_name, strtolower($filter_bulan), (int)$filter_tahun]);
        $deleted = $delete_stmt->rowCount();
        $notice_text = $deleted > 0
            ? 'Komoditas ' . $commodity_name . ' berhasil dihapus untuk ' . ucfirst($filter_bulan) . ' ' . $filter_tahun . '.'
            : 'Tidak ada data komoditas ' . $commodity_name . ' pada ' . ucfirst($filter_bulan) . ' ' . $filter_tahun . '.';
        $redirect_query['notice_type'] = $deleted > 0 ? 'success' : 'info';
        $redirect_query['notice'] = $notice_text;
    } else {
        $delete_stmt = $pdo->prepare('DELETE FROM hd WHERE TRIM(LOWER(komoditas)) = TRIM(LOWER(?))');
        $delete_stmt->execute([$commodity_name]);
        $deleted = $delete_stmt->rowCount();
        $notice_text = $deleted > 0
            ? 'Komoditas ' . $commodity_name . ' berhasil dihapus untuk semua bulan.'
            : 'Tidak ada data komoditas ' . $commodity_name . ' yang bisa dihapus.';
        $redirect_query['notice_type'] = $deleted > 0 ? 'success' : 'info';
        $redirect_query['notice'] = $notice_text;
    }

    header('Location: hd.php?' . http_build_query($redirect_query));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_filtered_month_data') {
    if (!is_provinsi($user)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $filter_bulan = strtolower(trim((string)($_POST['filter_bulan'] ?? '')));
    $filter_tahun = trim((string)($_POST['filter_tahun'] ?? ''));

    $redirect_query = [];
    if ($filter_bulan !== '') {
        $redirect_query['bulan'] = $filter_bulan;
    }
    if ($filter_tahun !== '' && ctype_digit($filter_tahun)) {
        $redirect_query['tahun'] = $filter_tahun;
    }

    if ($filter_bulan === '' || $filter_tahun === '' || !ctype_digit($filter_tahun)) {
        $redirect_query['notice_type'] = 'error';
        $redirect_query['notice'] = 'Filter bulan dan tahun wajib dipilih sebelum hapus data.';
        header('Location: hd.php?' . http_build_query($redirect_query));
        exit;
    }

    $delete_stmt = $pdo->prepare('DELETE FROM hd WHERE TRIM(LOWER(bulan)) = ? AND tahun = ?');
    $delete_stmt->execute([$filter_bulan, (int)$filter_tahun]);
    $deleted = (int)$delete_stmt->rowCount();

    $redirect_query['notice_type'] = $deleted > 0 ? 'success' : 'info';
    $redirect_query['notice'] = $deleted > 0
        ? ('Data HD bulan ' . ucfirst($filter_bulan) . ' ' . $filter_tahun . ' berhasil dihapus (' . $deleted . ' baris).')
        : ('Tidak ada data HD pada bulan ' . ucfirst($filter_bulan) . ' ' . $filter_tahun . ' untuk dihapus.');

    header('Location: hd.php?' . http_build_query($redirect_query));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_commodity') {
    if (!is_provinsi($user)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $commodity_raw = isset($_POST['commodity_name']) ? (string)$_POST['commodity_name'] : '';
    $commodity_name = preg_replace('/\s+/', ' ', trim($commodity_raw));
    if ($commodity_name === '') {
        header('Location: hd.php?notice_type=error&notice=' . urlencode('Nama komoditas tidak boleh kosong.'));
        exit;
    }

    $today = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $current_month_en = strtolower($today->format('F'));
    $current_month = $bulan_map[$current_month_en] ?? $current_month_en;
    $current_year = (int)$today->format('Y');
    $start_index = array_search($current_month, $bulan_list, true);
    if ($start_index === false) {
        $start_index = 0;
    }
    $months_to_create = array_slice($bulan_list, $start_index);

    $stmt_kab = $pdo->query("SELECT DISTINCT kode_kabupaten, nama_kabupaten FROM hd WHERE TRIM(COALESCE(kode_kabupaten, '')) <> '' AND TRIM(COALESCE(nama_kabupaten, '')) <> '' ORDER BY CAST(kode_kabupaten AS INTEGER) ASC, nama_kabupaten ASC");
    $kabupaten_rows = $stmt_kab->fetchAll();
    if (empty($kabupaten_rows) || empty($months_to_create)) {
        header('Location: hd.php?notice_type=error&notice=' . urlencode('Data kabupaten/kota dasar belum tersedia.'));
        exit;
    }

    $insert_stmt = $pdo->prepare("
        INSERT INTO hd (kode_kabupaten, nama_kabupaten, bulan, tahun, komoditas, time_stamp)
        SELECT ?, ?, ?, ?, ?, NOW()
        WHERE NOT EXISTS (
            SELECT 1
            FROM hd
            WHERE kode_kabupaten = ?
              AND nama_kabupaten = ?
              AND TRIM(LOWER(bulan)) = ?
              AND tahun = ?
              AND TRIM(LOWER(komoditas)) = TRIM(LOWER(?))
        )
    ");
    $inserted = 0;

    try {
        $pdo->beginTransaction();
        foreach ($months_to_create as $target_month) {
            foreach ($kabupaten_rows as $kabupaten) {
                $kode = trim((string)$kabupaten['kode_kabupaten']);
                $nama = trim((string)$kabupaten['nama_kabupaten']);
                $insert_stmt->execute([
                    $kode,
                    $nama,
                    $target_month,
                    $current_year,
                    $commodity_name,
                    $kode,
                    $nama,
                    strtolower($target_month),
                    $current_year,
                    $commodity_name,
                ]);
                $inserted += $insert_stmt->rowCount();
            }
        }
        $visible_stmt = $pdo->prepare("INSERT INTO hd_visible_commodities (komoditas, source) VALUES (?, 'manual_add') ON CONFLICT (komoditas) DO NOTHING");
        $visible_stmt->execute([$commodity_name]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: hd.php?notice_type=error&notice=' . urlencode('Gagal menambahkan komoditas baru.'));
        exit;
    }

    $notice_text = $inserted > 0
        ? 'Komoditas ' . $commodity_name . ' berhasil ditambahkan untuk ' . count($months_to_create) . ' bulan sampai Desember 2026.'
        : 'Komoditas ' . $commodity_name . ' sudah tersedia untuk semua bulan berjalan sampai Desember 2026.';
    $notice_kind = $inserted > 0 ? 'success' : 'info';

    header('Location: hd.php?bulan=' . urlencode($current_month) . '&tahun=' . $current_year . '&komoditas=' . urlencode($commodity_name) . '&notice_type=' . urlencode($notice_kind) . '&notice=' . urlencode($notice_text));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $field = isset($_POST['field']) ? trim($_POST['field']) : '';
    $value = isset($_POST['value']) ? $_POST['value'] : null;

    $allowed = [
        'perubahan' => 'decimal',
        'catatan' => 'text',
        'penjelasan' => 'text',
    ];

    if ($id <= 0 || !isset($allowed[$field])) {
        http_response_code(400);
        echo 'Invalid request';
        exit;
    }
    if (is_kabupaten($user) && $field !== 'penjelasan') {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    if (is_kabupaten($user)) {
        $stmt_kab = $pdo->prepare('SELECT kode_kabupaten FROM hd WHERE id = ?');
        $stmt_kab->execute([$id]);
        $row_kab = $stmt_kab->fetch();
        $kode = $row_kab ? (string)$row_kab['kode_kabupaten'] : '';
        if (!can_edit_penjelasan($user, $kode)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    $type = $allowed[$field];

    if ($type === 'decimal') {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            $sql = "UPDATE hd SET {$field} = NULL WHERE id = ?";
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
            $sql = "UPDATE hd SET {$field} = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$num, $id]);
        }
    } elseif ($type === 'enum') {
    } else {
        $raw = is_string($value) ? $value : '';
        $sql = "UPDATE hd SET {$field} = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$raw, $id]);
    }

    echo 'OK';
    exit;
}

$where = [];
$types = '';
$params = [];
$visible_join_condition = "EXISTS (SELECT 1 FROM hd_visible_commodities v WHERE TRIM(LOWER(v.komoditas)) = TRIM(LOWER(h.komoditas)))";

if ($bulan !== '') {
    $where[] = 'TRIM(LOWER(h.bulan)) = ?';
    $types .= 's';
    $params[] = strtolower(trim($bulan));
}
if ($tahun !== '' && ctype_digit($tahun)) {
    $where[] = 'h.tahun = ?';
    $types .= 'i';
    $params[] = (int)$tahun;
}

$sql = 'SELECT h.* FROM hd h';
$where_with_visible = $where;
$where_with_visible[] = $visible_join_condition;
if ($where_with_visible) {
    $sql .= ' WHERE ' . implode(' AND ', $where_with_visible);
}
$sql .= ' ORDER BY h.komoditas ASC, CAST(h.kode_kabupaten AS INTEGER) ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$avg_map = [];
$avg_sql = 'SELECT h.komoditas, AVG(NULLIF(h.perubahan,0)) AS avg_perubahan FROM hd h';
if ($where_with_visible) {
    $avg_sql .= ' WHERE ' . implode(' AND ', $where_with_visible);
}
$avg_sql .= ' GROUP BY h.komoditas';
$stmt_avg = $pdo->prepare($avg_sql);
$stmt_avg->execute($params);
foreach ($stmt_avg as $avg_row) {
    $k = isset($avg_row['komoditas']) ? trim((string)$avg_row['komoditas']) : '';
    $avg_map[$k] = $avg_row['avg_perubahan'];
}

$komoditas_tabs = [];
$tab_sql = 'SELECT DISTINCT h.komoditas FROM hd h';
if ($where_with_visible) {
    $tab_sql .= ' WHERE ' . implode(' AND ', $where_with_visible);
}
$tab_sql .= ' ORDER BY h.komoditas ASC';
$stmt_tabs = $pdo->prepare($tab_sql);
$stmt_tabs->execute($params);
foreach ($stmt_tabs as $row) {
    $k = isset($row['komoditas']) ? trim((string)$row['komoditas']) : '';
    if ($k !== '') {
        $komoditas_tabs[] = $k;
    }
}
$komoditas_pending_map = [];
$pending_sql = "SELECT h.komoditas,
    SUM(CASE WHEN perubahan IS NOT NULL AND perubahan <> 0 AND (penjelasan IS NULL OR TRIM(penjelasan) = '') THEN 1 ELSE 0 END) AS pending_count
    FROM hd h";
$pending_where = $where;
$pending_params = $params;
$pending_where[] = $visible_join_condition;
if (is_kabupaten($user) && !empty($user['kab_kode'])) {
    $pending_where[] = 'h.kode_kabupaten = ?';
    $pending_params[] = (string)$user['kab_kode'];
}
if ($pending_where) {
    $pending_sql .= ' WHERE ' . implode(' AND ', $pending_where);
}
$pending_sql .= ' GROUP BY h.komoditas';
$stmt_pending = $pdo->prepare($pending_sql);
$stmt_pending->execute($pending_params);
foreach ($stmt_pending as $pending_row) {
    $k = isset($pending_row['komoditas']) ? trim((string)$pending_row['komoditas']) : '';
    if ($k === '') continue;
    $komoditas_pending_map[$k] = (int)($pending_row['pending_count'] ?? 0);
}
$pending_items = [];
foreach ($komoditas_tabs as $k) {
    $count = (int)($komoditas_pending_map[$k] ?? 0);
    if ($count > 0) {
        $pending_items[] = ['label' => $k, 'count' => $count];
    }
}
$komoditas_selected = isset($_GET['komoditas']) ? trim($_GET['komoditas']) : '';
if ($komoditas_selected === '' && !empty($komoditas_tabs)) {
    $komoditas_selected = $komoditas_tabs[0];
}

$columns = [
    'nama_kabupaten' => 'Kabupaten/Kota',
    'perubahan' => 'Perubahan',
    'catatan' => 'Catatan',
    'penjelasan' => 'Penjelasan',
];
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>HD</title>
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
      .tab-btn.is-done {
        background: linear-gradient(135deg, rgba(22, 163, 74, 0.14), rgba(124, 227, 143, 0.22));
        border-color: rgba(22, 163, 74, 0.28);
        color: #136f3e;
        box-shadow: 0 10px 20px rgba(22, 163, 74, 0.14);
      }
      .tab-btn.active {
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #fff;
        border-color: transparent;
        box-shadow: 0 12px 24px rgba(255, 122, 182, 0.25);
      }
      .tab-btn.active.is-done { color: #ffffff; }
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
      .notice {
        margin-bottom: 14px;
        padding: 12px 14px;
        border-radius: 14px;
        font-size: 12px;
        font-weight: 600;
        box-shadow: 0 10px 24px rgba(56, 65, 80, 0.08);
      }
      .notice-success { background: rgba(22, 143, 74, 0.10); color: #136f3e; border: 1px solid rgba(22, 143, 74, 0.16); }
      .notice-error { background: rgba(217, 75, 75, 0.10); color: #b43636; border: 1px solid rgba(217, 75, 75, 0.16); }
      .notice-info { background: rgba(242, 139, 43, 0.10); color: #b96a1b; border: 1px solid rgba(242, 139, 43, 0.18); }
      .commodity-add-card {
        background: linear-gradient(135deg, rgba(246, 183, 200, 0.18), rgba(245, 162, 93, 0.12));
        border: 1px solid rgba(245, 162, 93, 0.18);
        border-radius: 22px;
        padding: 16px 18px;
        box-shadow: 0 14px 28px rgba(56, 65, 80, 0.08);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
      }
      .commodity-add-copy { display: flex; align-items: center; gap: 12px; }
      .commodity-add-icon {
        width: 46px;
        height: 46px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        background: var(--rh-gradient);
        color: #fff;
        box-shadow: 0 12px 24px rgba(242, 139, 43, 0.24);
        flex: 0 0 auto;
      }
      .commodity-add-title { font-size: 14px; font-weight: 700; color: var(--ink); }
      .commodity-add-subtitle { font-size: 12px; color: var(--muted); margin-top: 3px; }
      .commodity-add-form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
      .commodity-add-input {
        min-width: 280px;
        height: 40px;
        border-radius: 14px;
        border: 1px solid #e8d8d0;
        background: rgba(255, 255, 255, 0.96);
        padding: 0 14px;
        font-size: 13px;
        color: var(--ink);
        font-family: inherit;
        box-shadow: inset 0 1px 1px rgba(15, 23, 42, 0.04);
      }
      .commodity-add-input:focus { outline: none; border-color: #ff9f91; box-shadow: 0 0 0 3px rgba(255, 122, 182, 0.16); }
      .commodity-add-btn {
        height: 40px;
        padding: 0 16px;
        border-radius: 14px;
        border: none;
        background: var(--rh-gradient);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 12px 24px rgba(242, 139, 43, 0.22);
      }
      .commodity-delete-btn {
        height: 40px;
        padding: 0 16px;
        border-radius: 14px;
        border: 1px solid rgba(217, 75, 75, 0.22);
        background: rgba(255, 255, 255, 0.92);
        color: #d94b4b;
        font-size: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 10px 22px rgba(217, 75, 75, 0.10);
      }
      .commodity-delete-btn:disabled { opacity: 0.5; cursor: not-allowed; }
      .delete-help { width: 100%; font-size: 11px; color: var(--muted); text-align: right; }
      .upload-kitchen-card {
        background: #ffffff;
        border: 1px solid #eceff5;
        border-radius: 22px;
        padding: 14px 16px;
        box-shadow: 0 10px 22px rgba(56, 65, 80, 0.06);
        margin: -2px 0 16px;
      }
      .upload-kitchen-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
      }
      .upload-kitchen-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--ink);
        display: inline-flex;
        align-items: center;
        gap: 8px;
      }
      .upload-kitchen-title i { color: #f08a3a; }
      .upload-kitchen-note { font-size: 12px; color: var(--muted); }
      .upload-kitchen-form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
      .upload-kitchen-input {
        min-width: 260px;
        max-width: 420px;
        width: 100%;
        height: 40px;
        border: 1px solid #e4e8f1;
        border-radius: 12px;
        padding: 8px 10px;
        background: #f8fafc;
        color: var(--ink);
        font-size: 12px;
      }
      .upload-kitchen-btn {
        height: 40px;
        padding: 0 14px;
        border-radius: 12px;
        border: none;
        background: var(--rh-gradient);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
      }
      .upload-kitchen-tip { margin-top: 8px; font-size: 11px; color: var(--muted); }
      .upload-progress-wrap { margin-top: 10px; display: none; }
      .upload-progress-wrap.active { display: block; }
      .upload-progress-text {
        font-size: 12px;
        color: var(--ink);
        margin-bottom: 6px;
        font-weight: 600;
      }
      .upload-progress-track {
        width: 100%;
        height: 10px;
        border-radius: 999px;
        background: #edf1f7;
        overflow: hidden;
      }
      .upload-progress-fill {
        width: 0%;
        height: 100%;
        border-radius: 999px;
        background: var(--rh-gradient);
        transition: width .2s ease;
      }
      .upload-progress-fill.processing {
        animation: uploadPulse 1.1s ease-in-out infinite alternate;
      }
      @keyframes uploadPulse {
        from { opacity: 0.75; }
        to { opacity: 1; }
      }
      .delete-modal {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.42);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        z-index: 2000;
      }
      .delete-modal.open { display: flex; }
      .delete-panel {
        width: min(460px, 100%);
        background: #ffffff;
        border-radius: 24px;
        padding: 22px;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
      }
      .delete-panel h3 { margin: 0 0 8px; font-size: 18px; font-weight: 700; color: var(--ink); }
      .delete-panel p { margin: 0; font-size: 13px; line-height: 1.5; color: var(--muted); }
      .delete-target {
        margin-top: 14px;
        padding: 12px 14px;
        border-radius: 16px;
        background: rgba(246, 183, 200, 0.12);
        color: var(--ink);
        font-size: 13px;
        font-weight: 700;
      }
      .delete-scope { margin-top: 16px; display: grid; gap: 10px; }
      .delete-option {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 14px;
        border: 1px solid #ece6ef;
        border-radius: 16px;
        background: #fbfbfd;
      }
      .delete-option input { margin-top: 3px; }
      .delete-option strong { display: block; font-size: 13px; color: var(--ink); }
      .delete-option span { display: block; margin-top: 2px; font-size: 12px; color: var(--muted); line-height: 1.45; }
      .delete-option.is-disabled { opacity: 0.55; }
      .delete-actions { margin-top: 18px; display: flex; justify-content: flex-end; gap: 10px; }
      .delete-cancel-btn {
        height: 40px;
        padding: 0 16px;
        border-radius: 14px;
        border: 1px solid #e6e8ef;
        background: #ffffff;
        color: var(--ink);
        font-size: 12px;
        font-weight: 700;
      }
      .delete-confirm-btn {
        height: 40px;
        padding: 0 16px;
        border-radius: 14px;
        border: none;
        background: linear-gradient(135deg, #ef4444, #f28b2b);
        color: #ffffff;
        font-size: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 12px 24px rgba(217, 75, 75, 0.20);
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
      .table-responsive { overflow-x: auto; }
      .table-responsive table { min-width: 980px; }
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
        .commodity-add-card { flex-direction: column; align-items: stretch; }
        .commodity-add-form { justify-content: stretch; }
        .commodity-add-input,
        .commodity-add-btn,
        .commodity-delete-btn { width: 100%; }
        .upload-kitchen-head { flex-direction: column; align-items: flex-start; }
        .upload-kitchen-input,
        .upload-kitchen-btn { width: 100%; max-width: none; }
        .delete-help { text-align: left; }
        .delete-actions { flex-direction: column-reverse; }
        .delete-cancel-btn,
        .delete-confirm-btn { width: 100%; justify-content: center; }
        .tabs { margin: 32px 0 16px; }
        .table-card { padding: 12px; }
      }
    
      /* D: Consistent row/input sizing */
      table td { vertical-align: middle; }
      table input, table select { height: 28px; }
      table textarea { min-height: 28px; }
    
      /* B: Status colors */
      .text-perubahan-pos, .avg-pill .avg-trend.pos, .trend-up, .badge-pos, .mini-out.pos { color: #168f4a !important; }
      .text-perubahan-neg, .avg-pill .avg-trend.neg, .trend-down, .badge-neg, .mini-out.neg { color: #d94b4b !important; }
      .avg-pill .avg-trend.zero, .badge-zero { color: #6b7280 !important; }
    
      /* C: Tabs scroll and clarity */
      .tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 8px 8px 2px;
        align-items: flex-start;
        scrollbar-width: thin;
        max-height: 92px;
        margin: 26px 0 18px;
        background: rgba(255, 255, 255, 0.68);
        border: 1px solid #eef0f4;
        border-radius: 20px;
        box-shadow: 0 10px 24px rgba(56, 65, 80, 0.06);
        align-content: flex-start;
      }
      .tabs::-webkit-scrollbar { height: 6px; }
      .tabs::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 999px; }
      .tab-btn {
        white-space: nowrap;
        flex: 0 0 auto;
        min-height: 32px;
        max-width: 180px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        line-height: 1.1;
        padding: 7px 12px;
        font-size: 11px;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      @media (max-width: 768px) {
        .tabs {
          max-height: none;
          overflow: visible;
        }
        .tab-btn {
          max-width: 100%;
          flex-basis: auto;
        }
      }
    
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
        <a class="nav-dot" href="shk.php" title="SHK"><span class="nav-text">HK</span></a>
        <a class="nav-dot" href="hpb.php" title="HPB"><span class="nav-text">HPB</span></a>
        <a class="nav-dot active" href="hd.php" title="HD"><span class="nav-text">HD</span></a>
        <a class="nav-dot" href="hkd.php" title="HKD"><span class="nav-text">HKD</span></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="perbandingan-harga.php" title="Perbandingan Harga"><i class="mdi mdi-chart-line"></i></a>
        <a class="nav-dot" href="arah-berbeda.php" title="Arah Berbeda"><i class="mdi mdi-compare"></i></a>
        <a class="nav-dot" href="panduan.php" title="Panduan"><i class="mdi mdi-book-open-variant"></i></a>
      </aside>

      <main class="main">
        <form class="topbar" method="get" action="hd.php">
          <div>
            <div class="hello">Konfirmasi Perubahan Harga Produsen Pedesaan</div>
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
            <div class="user-pill" style="padding:6px 10px;border-radius:999px;background:#fff;border:1px solid #eef0f4;font-size:12px;color:#6b7280;box-shadow:0 6px 14px rgba(56,65,80,0.08);"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
            <a class="icon-btn" href="logout.php" title="Logout"><i class="mdi mdi-logout"></i></a>
          </div>
        </form>
        <?php if ($notice !== ''): ?>
          <div class="notice notice-<?php echo htmlspecialchars(in_array($notice_type, ['success', 'error', 'info'], true) ? $notice_type : 'success'); ?>">
            <?php echo htmlspecialchars($notice); ?>
          </div>
        <?php endif; ?>
        <?php if (is_provinsi($user)): ?>
          <div class="commodity-add-card">
            <div class="commodity-add-copy">
              <div class="commodity-add-icon"><i class="mdi mdi-plus-thick"></i></div>
              <div>
                <div class="commodity-add-title">Tambah Komoditas HD</div>
                <div class="commodity-add-subtitle">Otomatis membuat tabel untuk semua kabupaten/kota dari bulan berjalan sampai Desember 2026.</div>
              </div>
            </div>
            <form class="commodity-add-form" method="post" action="hd.php">
              <input type="hidden" name="action" value="add_commodity">
              <input type="text" name="commodity_name" class="commodity-add-input" placeholder="Contoh: Gula Kristal" maxlength="100" required>
              <button type="submit" class="commodity-add-btn">
                <i class="mdi mdi-playlist-plus"></i>
                Tambah Komoditas
              </button>
              <button type="button" class="commodity-delete-btn" id="open-delete-commodity" disabled>
                <i class="mdi mdi-delete-outline"></i>
                Hapus Komoditas
              </button>
              <div class="delete-help" id="delete-help">Pilih tab komoditas yang ingin dihapus terlebih dahulu.</div>
            </form>
          </div>
          <div class="upload-kitchen-card">
            <div class="upload-kitchen-head">
              <div class="upload-kitchen-title"><i class="mdi mdi-database-import-outline"></i>Dapur Olah Data HD</div>
              <div class="upload-kitchen-note">Upload file export (`.xls` / `.csv`) untuk update massal nilai HD pada bulan & tahun terfilter.</div>
            </div>
            <form class="upload-kitchen-form" id="upload-kitchen-form" method="post" action="hd.php" enctype="multipart/form-data">
              <input type="hidden" name="action" value="upload_hd_data">
              <input type="hidden" name="filter_bulan" value="<?php echo htmlspecialchars($bulan); ?>">
              <input type="hidden" name="filter_tahun" value="<?php echo htmlspecialchars($tahun); ?>">
              <input type="file" name="hd_upload_file" class="upload-kitchen-input" accept=".xls,.csv,.html,.htm" required>
              <button type="submit" class="upload-kitchen-btn">
                <i class="mdi mdi-upload-outline"></i>
                Upload & Isi Data HD
              </button>
              <button type="button" class="commodity-delete-btn" id="open-delete-month-data" <?php echo ($bulan === '' || $tahun === '' || !ctype_digit((string)$tahun)) ? 'disabled' : ''; ?>>
                <i class="mdi mdi-delete-alert-outline"></i>
                Hapus Data Bulan Terfilter
              </button>
            </form>
            <div class="upload-kitchen-tip">
              Kolom yang dibaca: <strong>Kabupaten</strong>, <strong>Nama Komoditas</strong>, <strong>Perubahan Rata-rata (%)</strong>. Kolom catatan akan dikosongkan.
              <?php if ($bulan === '' || $tahun === '' || !ctype_digit((string)$tahun)): ?>
                Pilih filter bulan+tahun agar tombol hapus data aktif.
              <?php endif; ?>
            </div>
            <div class="upload-progress-wrap" id="upload-progress-wrap">
              <div class="upload-progress-text" id="upload-progress-text">Menyiapkan upload...</div>
              <div class="upload-progress-track">
                <div class="upload-progress-fill" id="upload-progress-fill"></div>
              </div>
            </div>
          </div>
          <div class="delete-modal" id="delete-month-data-modal" aria-hidden="true">
            <div class="delete-panel" role="dialog" aria-modal="true" aria-labelledby="delete-month-data-title">
              <h3 id="delete-month-data-title">Hapus Data HD Bulan Terfilter</h3>
              <p>Tindakan ini akan menghapus <strong>semua data HD</strong> pada bulan dan tahun yang sedang difilter.</p>
              <div class="delete-target">
                Target: <?php echo htmlspecialchars(($bulan !== '' ? ucfirst($bulan) : 'Bulan') . ($tahun !== '' ? ' ' . $tahun : '')); ?>
              </div>
              <form method="post" action="hd.php" id="delete-month-data-form">
                <input type="hidden" name="action" value="delete_filtered_month_data">
                <input type="hidden" name="filter_bulan" value="<?php echo htmlspecialchars($bulan); ?>">
                <input type="hidden" name="filter_tahun" value="<?php echo htmlspecialchars($tahun); ?>">
                <div class="delete-actions" style="margin-top:16px;">
                  <button type="button" class="delete-cancel-btn" id="close-delete-month-data">Batal</button>
                  <button type="submit" class="delete-confirm-btn">
                    <i class="mdi mdi-alert-outline"></i>
                    Ya, Hapus Semua Data
                  </button>
                </div>
              </form>
            </div>
          </div>
          <div class="delete-modal" id="delete-commodity-modal" aria-hidden="true">
            <div class="delete-panel" role="dialog" aria-modal="true" aria-labelledby="delete-commodity-title">
              <h3 id="delete-commodity-title">Hapus Komoditas HD</h3>
              <p>Data yang dihapus tidak bisa dikembalikan otomatis. Pastikan scope penghapusannya sudah sesuai.</p>
              <div class="delete-target">
                Komoditas terpilih: <span id="delete-commodity-name">-</span>
              </div>
              <form method="post" action="hd.php" id="delete-commodity-form">
                <input type="hidden" name="action" value="delete_commodity">
                <input type="hidden" name="commodity_name" id="delete-commodity-input" value="">
                <input type="hidden" name="filter_bulan" value="<?php echo htmlspecialchars($bulan); ?>">
                <input type="hidden" name="filter_tahun" value="<?php echo htmlspecialchars($tahun); ?>">
                <div class="delete-scope">
                  <label class="delete-option" id="delete-option-current">
                    <input type="radio" name="delete_scope" value="current" checked>
                    <div>
                      <strong>Hapus pada bulan terfilter saja</strong>
                      <span>Komoditas akan dihapus hanya untuk <span id="delete-current-label"><?php echo htmlspecialchars(($bulan !== '' ? ucfirst($bulan) : 'Bulan') . ($tahun !== '' ? ' ' . $tahun : '')); ?></span>.</span>
                    </div>
                  </label>
                  <label class="delete-option" id="delete-option-all">
                    <input type="radio" name="delete_scope" value="all">
                    <div>
                      <strong>Hapus untuk semua bulan</strong>
                      <span>Menghapus seluruh data komoditas dari Januari sampai Desember 2026 yang tersedia di tabel HD.</span>
                    </div>
                  </label>
                </div>
                <div class="delete-actions">
                  <button type="button" class="delete-cancel-btn" id="close-delete-commodity">Batal</button>
                  <button type="submit" class="delete-confirm-btn">
                    <i class="mdi mdi-alert-outline"></i>
                    Ya, Hapus Komoditas
                  </button>
                </div>
              </form>
            </div>
          </div>
        <?php endif; ?>
        <?php if (!empty($komoditas_tabs)): ?>
          <div class="tabs" data-selected="<?php echo htmlspecialchars($komoditas_selected); ?>">
            <?php foreach ($komoditas_tabs as $k): ?>
              <?php $is_active = ($k === $komoditas_selected) ? 'active' : ''; ?>
              <?php $is_done = ((int)($komoditas_pending_map[$k] ?? 0) === 0) ? 'is-done' : ''; ?>
              <button type="button" class="tab-btn <?php echo trim($is_active . ' ' . $is_done); ?>" data-komoditas="<?php echo htmlspecialchars($k); ?>">
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
              $row_kode = isset($row['kode_kabupaten']) ? (string)$row['kode_kabupaten'] : '';
              $can_edit_row = is_provinsi($user) || (is_kabupaten($user) && (string)$user['kab_kode'] === $row_kode);
              $can_edit_other = is_provinsi($user);
              $can_edit_penjelasan = $can_edit_row;
              $disabled_other = $can_edit_other ? '' : 'disabled';
              $disabled_penjelasan = $can_edit_penjelasan ? '' : 'disabled';

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
                <?php if ($key === 'perubahan'): ?>
                  <?php
                    $num = is_numeric($value) ? (float)$value : null;
                    $class = '';
                    if ($num !== null) {
                      $class = $num >= 0 ? 'text-perubahan-pos' : 'text-perubahan-neg';
                    }
                  ?>
                  <td class="perubahan-cell">
                    <div class="d-flex align-items-center justify-content-center">
                      <input type="text" inputmode="decimal" class="form-control form-control-sm perubahan-input editable-cell <?php echo $class; ?>" data-field="perubahan" value="<?php echo htmlspecialchars($value_display); ?>" placeholder="0,00" <?php echo $disabled_other; ?>>
                      <?php if ($num !== null): ?>
                        <?php if ($num >= 0): ?>
                          <span class="trend trend-up">▲</span>
                        <?php else: ?>
                          <span class="trend trend-down">▼</span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                <?php elseif ($key === 'catatan'): ?>
                  <td>
                    <textarea class="form-control form-control-sm editable-cell wrap-textarea" data-field="catatan" rows="1" placeholder="Isi catatan" <?php echo $disabled_other; ?>><?php echo htmlspecialchars($value_display); ?></textarea>
                  </td>
                <?php elseif ($key === 'penjelasan'): ?>
                <td>
                  <textarea class="form-control form-control-sm editable-cell wrap-textarea" data-field="penjelasan" rows="1" placeholder="Isi penjelasan" <?php echo $disabled_penjelasan; ?>><?php echo htmlspecialchars($value_display); ?></textarea>
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
        var selectedKomoditas = '';
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
        attachFormat('.sp2kp-input');

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
        function updatePendingChip(card) {
          if (!card) return;
          var komoditas = card.getAttribute('data-komoditas');
          if (!komoditas) return;
          var rows = Array.prototype.slice.call(card.querySelectorAll('tbody tr'));
          var needsPending = rows.some(function (row) {
            var penjelasanInput = row.querySelector('textarea[data-field="penjelasan"]');
            if (!penjelasanInput || penjelasanInput.disabled) return false;
            var perubahanInput = row.querySelector('.perubahan-input');
            var value = perubahanInput ? parseIdNumber(perubahanInput.value) : null;
            return value !== null && value !== 0 && penjelasanInput.value.trim() === '';
          });
          var tab = tabsWrap ? tabsWrap.querySelector('.tab-btn[data-komoditas="' + CSS.escape(komoditas) + '"]') : null;
          if (tab) tab.classList.toggle('is-done', !needsPending);
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
        var deleteButton = document.getElementById('open-delete-commodity');
        var deleteHelp = document.getElementById('delete-help');
        var deleteModal = document.getElementById('delete-commodity-modal');
        var closeDeleteButton = document.getElementById('close-delete-commodity');
        var deleteCommodityName = document.getElementById('delete-commodity-name');
        var deleteCommodityInput = document.getElementById('delete-commodity-input');
        var deleteCurrentOption = document.getElementById('delete-option-current');
        var deleteCurrentRadio = deleteCurrentOption ? deleteCurrentOption.querySelector('input[name="delete_scope"]') : null;
        var deleteAllRadio = document.querySelector('#delete-option-all input[name="delete_scope"]');
        var canDeleteCurrent = <?php echo ($bulan !== '' && $tahun !== '' && ctype_digit((string)$tahun)) ? 'true' : 'false'; ?>;
        var deleteMonthButton = document.getElementById('open-delete-month-data');
        var deleteMonthModal = document.getElementById('delete-month-data-modal');
        var closeDeleteMonthButton = document.getElementById('close-delete-month-data');
        var uploadKitchenForm = document.getElementById('upload-kitchen-form');
        var uploadProgressWrap = document.getElementById('upload-progress-wrap');
        var uploadProgressFill = document.getElementById('upload-progress-fill');
        var uploadProgressText = document.getElementById('upload-progress-text');

        function setUploadProgress(percent, message) {
          if (!uploadProgressWrap || !uploadProgressFill || !uploadProgressText) return;
          uploadProgressWrap.classList.add('active');
          uploadProgressFill.style.width = Math.max(0, Math.min(100, percent)) + '%';
          uploadProgressText.textContent = message || ('Upload ' + Math.round(percent) + '%');
        }

        function syncDeleteUi(name) {
          selectedKomoditas = name || '';
          if (deleteCommodityName) deleteCommodityName.textContent = selectedKomoditas || '-';
          if (deleteCommodityInput) deleteCommodityInput.value = selectedKomoditas;
          if (deleteButton) deleteButton.disabled = !selectedKomoditas;
          if (deleteHelp) {
            deleteHelp.textContent = selectedKomoditas
              ? 'Komoditas aktif siap dihapus. Pilih scope hapus saat konfirmasi muncul.'
              : 'Pilih tab komoditas yang ingin dihapus terlebih dahulu.';
          }
        }

        function openDeleteModal() {
          if (!deleteModal || !selectedKomoditas) return;
          deleteModal.classList.add('open');
          deleteModal.setAttribute('aria-hidden', 'false');
          if (canDeleteCurrent && deleteCurrentRadio) {
            deleteCurrentRadio.disabled = false;
            deleteCurrentRadio.checked = true;
            deleteCurrentOption.classList.remove('is-disabled');
          } else if (deleteCurrentRadio) {
            deleteCurrentRadio.disabled = true;
            deleteCurrentRadio.checked = false;
            deleteCurrentOption.classList.add('is-disabled');
            if (deleteAllRadio) deleteAllRadio.checked = true;
          }
        }

        function closeDeleteModal() {
          if (!deleteModal) return;
          deleteModal.classList.remove('open');
          deleteModal.setAttribute('aria-hidden', 'true');
        }
        function openDeleteMonthModal() {
          if (!deleteMonthModal) return;
          deleteMonthModal.classList.add('open');
          deleteMonthModal.setAttribute('aria-hidden', 'false');
        }
        function closeDeleteMonthModal() {
          if (!deleteMonthModal) return;
          deleteMonthModal.classList.remove('open');
          deleteMonthModal.setAttribute('aria-hidden', 'true');
        }

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
            syncDeleteUi(name);
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
        } else {
          syncDeleteUi('');
        }

        if (deleteButton) deleteButton.addEventListener('click', openDeleteModal);
        if (closeDeleteButton) closeDeleteButton.addEventListener('click', closeDeleteModal);
        if (deleteMonthButton) deleteMonthButton.addEventListener('click', openDeleteMonthModal);
        if (closeDeleteMonthButton) closeDeleteMonthButton.addEventListener('click', closeDeleteMonthModal);
        if (deleteModal) {
          deleteModal.addEventListener('click', function (e) {
            if (e.target === deleteModal) closeDeleteModal();
          });
        }
        if (deleteMonthModal) {
          deleteMonthModal.addEventListener('click', function (e) {
            if (e.target === deleteMonthModal) closeDeleteMonthModal();
          });
        }
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            closeDeleteModal();
            closeDeleteMonthModal();
          }
        });
        var deleteForm = document.getElementById('delete-commodity-form');
        if (deleteForm) {
          deleteForm.addEventListener('submit', function (e) {
            if (!selectedKomoditas) {
              e.preventDefault();
              closeDeleteModal();
            }
          });
        }

        if (uploadKitchenForm) {
          uploadKitchenForm.addEventListener('submit', function (e) {
            var fileInput = uploadKitchenForm.querySelector('input[name="hd_upload_file"]');
            if (!fileInput || !fileInput.files || !fileInput.files.length) return;
            e.preventDefault();

            var submitBtn = uploadKitchenForm.querySelector('.upload-kitchen-btn');
            if (submitBtn) {
              submitBtn.disabled = true;
              submitBtn.textContent = 'Mengupload...';
            }
            setUploadProgress(0, 'Mulai upload file...');
            if (uploadProgressFill) uploadProgressFill.classList.remove('processing');
            var processingTimer = null;
            function startProcessingPhase() {
              if (uploadProgressFill) uploadProgressFill.classList.add('processing');
              if (processingTimer) return;
              processingTimer = setInterval(function () {
                if (!uploadProgressFill) return;
                var current = parseFloat((uploadProgressFill.style.width || '90').replace('%', '')) || 90;
                var next = Math.min(98, current + 1.2);
                uploadProgressFill.style.width = next + '%';
              }, 500);
            }
            function stopProcessingPhase() {
              if (processingTimer) {
                clearInterval(processingTimer);
                processingTimer = null;
              }
              if (uploadProgressFill) uploadProgressFill.classList.remove('processing');
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', uploadKitchenForm.getAttribute('action') || 'hd.php', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = function (evt) {
              if (!evt.lengthComputable) return;
              var p = (evt.loaded / evt.total) * 100;
              var visual = Math.min(90, p * 0.9);
              setUploadProgress(visual, 'Upload file: ' + Math.round(p) + '%');
            };
            xhr.upload.onload = function () {
              setUploadProgress(92, 'File selesai diupload. Sedang memproses data di server...');
              startProcessingPhase();
            };
            xhr.onload = function () {
              stopProcessingPhase();
              var resp = null;
              try { resp = JSON.parse(xhr.responseText || '{}'); } catch (err) { resp = null; }
              if (xhr.status >= 200 && xhr.status < 300 && resp && typeof resp === 'object') {
                setUploadProgress(100, resp.ok ? 'Upload selesai, memuat ulang...' : (resp.notice || 'Upload selesai.'));
                if (resp.redirect) {
                  window.location.href = resp.redirect;
                  return;
                }
                window.location.reload();
                return;
              }
              setUploadProgress(100, 'Upload selesai, memuat halaman...');
              window.location.reload();
            };
            xhr.onerror = function () {
              stopProcessingPhase();
              setUploadProgress(100, 'Gagal upload. Coba lagi.');
              if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="mdi mdi-upload-outline"></i>Upload & Isi Data HD';
              }
            };
            xhr.send(new FormData(uploadKitchenForm));
          });
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

          fetch('hd.php', { method: 'POST', body: formData })
            .then(function (res) { return res.text(); })
            .catch(function () { /* silent */ });
        }

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
            if (!found) el.value = value;
          } else {
            el.value = value;
          }
          if (el.classList.contains('perubahan-input') || el.classList.contains('sp2kp-input')) {
            el.value = formatIdNumber(el.value);
          }
          if (el.tagName.toLowerCase() === 'textarea') {
            autoResize(el);
          }
          if (el.classList.contains('perubahan-input')) {
            updateTrend(el);
            updateRowHighlight(el);
            updateAvgForCard(el.closest('.table-card'));
            updatePendingChip(el.closest('.table-card'));
          }
          if (el.getAttribute('data-field') === 'penjelasan') {
            updatePendingChip(el.closest('.table-card'));
          }
          saveCell(el);
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

          rows.forEach(function (rowText, rIdx) {
            var cols = rowText.split('\t');
            var rowEl = rowList[startRowIdx + rIdx];
            if (!rowEl) return;
            var editables = rowEl.querySelectorAll('.editable-cell');
            cols.forEach(function (cellText, cIdx) {
              var el = editables[startColIdx + cIdx];
              if (!el) return;
              setEditableValue(el, cellText.trim());
            });
          });
        });

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
          el.addEventListener('blur', function () {
            if (el.getAttribute('data-field') === 'penjelasan' || el.classList.contains('perubahan-input')) {
              updatePendingChip(el.closest('.table-card'));
            }
            saveCell(el);
          });
          el.addEventListener('change', function () { saveCell(el); });
          if (el.tagName.toLowerCase() === 'textarea') {
            el.addEventListener('input', function () {
              autoResize(el);
              updatePendingChip(el.closest('.table-card'));
            });
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
        document.querySelectorAll('.table-card[data-komoditas]').forEach(function (card) { updatePendingChip(card); });
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
