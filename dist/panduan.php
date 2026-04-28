<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$user = require_auth();
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Panduan</title>
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
      .main { background: transparent; padding-right: 8px; }
      .topbar {
        display: grid;
        grid-template-columns: 1fr auto;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
      }
      .hello { font-size: 22px; font-weight: 700; }
      .subhello { color: var(--muted); font-size: 12px; }
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
      .card {
        background: var(--card);
        border-radius: var(--radius);
        padding: 18px;
        box-shadow: 0 14px 28px rgba(56, 65, 80, 0.10);
      }
      .card h3 { margin: 0 0 10px; font-size: 16px; }
      .card p { margin: 6px 0; color: #5b6471; font-size: 13px; }
      .card .step { margin: 10px 0; padding: 10px 12px; border-radius: 12px; background: #f8fafc; border: 1px solid #eef2f7; }
      .card .step strong { display: block; margin-bottom: 4px; }
      .guide-grid {
        display: grid;
        grid-template-columns: 1.15fr 1fr;
        gap: 14px;
        margin-top: 14px;
      }
      .quick-grid,
      .legend-grid,
      .role-grid,
      .page-grid,
      .faq-grid {
        display: grid;
        gap: 10px;
      }
      .quick-grid {
        grid-template-columns: repeat(4, 1fr);
      }
      .quick-card,
      .legend-item,
      .role-card,
      .page-item,
      .faq-item {
        padding: 12px 14px;
        border-radius: 14px;
        background: #f8fafc;
        border: 1px solid #eef2f7;
      }
      .quick-card strong,
      .legend-item strong,
      .role-card strong,
      .page-item strong,
      .faq-item strong {
        display: block;
        margin-bottom: 4px;
      }
      .quick-card span,
      .legend-item span,
      .role-card span,
      .page-item span,
      .faq-item span {
        display: block;
        color: #5b6471;
        font-size: 12px;
        line-height: 1.45;
      }
      .quick-card {
        background: linear-gradient(180deg, #fffaf7 0%, #ffffff 100%);
      }
      .quick-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 999px;
        background: linear-gradient(135deg, #f6b7c8, #f5a25d);
        color: #fff;
        font-size: 12px;
        font-weight: 800;
        margin-bottom: 8px;
      }
      .role-grid,
      .page-grid,
      .faq-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      .role-card.emphasis {
        background: linear-gradient(180deg, #fff8f4 0%, #ffffff 100%);
      }
      .legend-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
      }
      .legend-badge {
        min-width: 34px;
        height: 34px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 800;
      }
      .legend-badge.pos {
        background: rgba(22, 143, 74, 0.10);
        color: #168f4a;
      }
      .legend-badge.neg {
        background: rgba(217, 75, 75, 0.10);
        color: #d94b4b;
      }
      .legend-badge.locked {
        background: #cbd5e1;
        color: #475569;
      }
      .legend-badge.warn {
        background: rgba(249, 115, 22, 0.12);
        color: #f97316;
      }
      .page-grid {
        margin-top: 10px;
      }
      .page-item {
        display: flex;
        gap: 12px;
        align-items: flex-start;
      }
      .page-icon {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        border: 1px solid #eef2f7;
        box-shadow: 0 6px 14px rgba(56, 65, 80, 0.06);
        flex: 0 0 auto;
      }
      .page-icon img {
        width: 18px;
        height: 18px;
        display: block;
      }
      .hint-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 12px;
      }
      .hint-pill {
        padding: 8px 12px;
        border-radius: 999px;
        background: #ffffff;
        border: 1px solid #eef2f7;
        color: #5b6471;
        font-size: 12px;
        font-weight: 600;
        box-shadow: 0 6px 14px rgba(56, 65, 80, 0.06);
      }
      @media (max-width: 1200px) {
        .app { grid-template-columns: 1fr; }
        .sidebar { flex-direction: row; justify-content: flex-start; overflow-x: auto; }
        .main { padding-right: 0; }
        .guide-grid,
        .role-grid,
        .page-grid,
        .faq-grid {
          grid-template-columns: 1fr;
        }
        .quick-grid {
          grid-template-columns: repeat(2, 1fr);
        }
      }
      @media (max-width: 768px) {
        .app { padding: 14px; }
        .sidebar { gap: 10px; }
        .logo { width: 38px; height: 38px; }
        .nav-dot { width: 40px; height: 40px; }
        .topbar { grid-template-columns: 1fr; }
        .quick-grid {
          grid-template-columns: 1fr;
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
        <div class="logo"><img src="assets/images/rh-icon.png" alt="RH" style="width:100%;height:100%;object-fit:cover;display:block;border-radius:inherit;"></div>
        <a class="nav-dot" href="index.php" title="Dashboard"><i class="mdi mdi-view-dashboard"></i></a>
        <a class="nav-dot" href="shk.php" title="SHK"><span class="nav-text">HK</span></a>
        <a class="nav-dot" href="hpb.php" title="HPB"><span class="nav-text">HPB</span></a>
        <a class="nav-dot" href="hd.php" title="HD"><span class="nav-text">HD</span></a>
        <a class="nav-dot" href="hkd.php" title="HKD"><span class="nav-text">HKD</span></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="perbandingan-harga.php" title="Perbandingan Harga"><i class="mdi mdi-chart-line"></i></a>
        <a class="nav-dot" href="arah-berbeda.php" title="Arah Berbeda"><i class="mdi mdi-compare"></i></a>
        <a class="nav-dot active" href="panduan.php" title="Panduan"><i class="mdi mdi-book-open-variant"></i></a>
      </aside>

      <main class="main">
        <div class="topbar">
          <div>
            <div class="hello">Panduan Penggunaan Rekon Harga Lampung</div>
            <div class="subhello">Panduan penggunaan sistem untuk admin kabupaten/kota dan admin provinsi sesuai alur kerja terbaru.</div>
          </div>
          <a class="icon-btn" href="logout.php" title="Logout"><i class="mdi mdi-logout"></i></a>
        </div>

        <div class="card">
          <h3>Mulai dari Mana</h3>
          <p>Halaman ini dirancang supaya pengguna baru bisa langsung memahami alur kerja utama sebelum mulai mengisi data.</p>
          <div class="quick-grid">
            <div class="quick-card">
              <div class="quick-number">1</div>
              <strong>Pilih halaman</strong>
              <span>Masuk ke HK, HPB, HD, HKD, Harga Ekstrem, Hulu Hilir Beras, atau Perbandingan Harga sesuai kebutuhan pekerjaan.</span>
            </div>
            <div class="quick-card">
              <div class="quick-number">2</div>
              <strong>Pilih periode</strong>
              <span>Gunakan filter bulan dan tahun agar data yang tampil sesuai periode kerja yang sedang diproses.</span>
            </div>
            <div class="quick-card">
              <div class="quick-number">3</div>
              <strong>Isi data</strong>
              <span>Ketik pada sel yang aktif. Gunakan <strong>Enter</strong> untuk ke bawah dan <strong>Tab</strong> untuk ke kanan.</span>
            </div>
            <div class="quick-card">
              <div class="quick-number">4</div>
              <strong>Pastikan tersimpan</strong>
              <span>Sistem akan menyimpan otomatis saat pindah sel, blur dari field, atau saat pengguna berhenti mengetik beberapa saat.</span>
            </div>
          </div>
          <div class="hint-strip">
            <div class="hint-pill">Autosave aktif</div>
            <div class="hint-pill">Enter = pindah ke bawah</div>
            <div class="hint-pill">Tab = pindah ke kanan</div>
            <div class="hint-pill">Kolom abu-abu = tidak bisa diedit</div>
          </div>
        </div>

        <div class="card" style="margin-top:14px;">
          <h3>Role & Hak Akses</h3>
          <div class="role-grid">
            <div class="role-card emphasis">
              <strong>Admin Kabupaten/Kota</strong>
              <span>Dapat melihat semua halaman, tetapi hanya dapat mengedit bagian yang diizinkan untuk kabupaten/kotanya sendiri. Pada halaman konfirmasi perubahan harga dan perbandingan harga, fokus utamanya ada pada kolom <strong>Penjelasan</strong> untuk wilayahnya.</span>
            </div>
            <div class="role-card">
              <strong>Admin Provinsi</strong>
              <span>Dapat mengakses seluruh data dan fitur administratif, termasuk <strong>tambah komoditas</strong>, <strong>hapus komoditas</strong>, serta mengisi penjelasan lintas kabupaten/kota pada halaman yang mendukung.</span>
            </div>
            <div class="role-card">
              <strong>Bisa Diedit</strong>
              <span>Sel aktif dengan latar putih normal, kolom penjelasan, data ekstrem yang relevan, dan area input yang memang dibuka untuk role Anda.</span>
            </div>
            <div class="role-card">
              <strong>Tidak Bisa Diedit</strong>
              <span>Sel abu-abu, kolom yang dikunci sistem, serta baris atau kabupaten yang tidak sesuai dengan hak akses akun yang sedang login.</span>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:14px;">
          <h3>Status Warna & Simbol</h3>
          <div class="legend-grid">
            <div class="legend-item">
              <div class="legend-badge pos">▲</div>
              <div>
                <strong>Hijau / Positif</strong>
                <span>Menunjukkan perubahan bernilai positif atau arah naik.</span>
              </div>
            </div>
            <div class="legend-item">
              <div class="legend-badge neg">▼</div>
              <div>
                <strong>Merah / Negatif</strong>
                <span>Menunjukkan perubahan bernilai negatif atau arah turun.</span>
              </div>
            </div>
            <div class="legend-item">
              <div class="legend-badge locked">-</div>
              <div>
                <strong>Sel Abu-abu</strong>
                <span>Kolom terkunci, tidak relevan untuk wilayah tertentu, atau tidak bisa diedit oleh role yang sedang login.</span>
              </div>
            </div>
            <div class="legend-item">
              <div class="legend-badge warn">⚠</div>
              <div>
                <strong>Tanda Warning</strong>
                <span>Menunjukkan arah perubahan yang tidak sejalan antar level harga atau ada kondisi yang perlu dicek lebih lanjut, terutama pada analisis perbandingan dan hulu hilir beras.</span>
              </div>
            </div>
            <div class="legend-item">
              <div class="legend-badge" style="background:#dcfce7;color:#166534;">✓</div>
              <div>
                <strong>Pill Komoditas Hijau</strong>
                <span>Menandakan komoditas tersebut tidak lagi membutuhkan penjelasan, baik karena semua penjelasan sudah terisi maupun memang tidak ada baris yang membutuhkan penjelasan.</span>
              </div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:14px;">
          <h3>Ikon Sidebar & Fungsi Halaman</h3>
          <div class="page-grid">
            <div class="page-item">
              <div class="page-icon"><i class="mdi mdi-view-dashboard"></i></div>
              <div>
                <strong>Dashboard</strong>
                <span>Ringkasan progres pengisian penjelasan per kabupaten/kota. Kartu-kartu dashboard menyesuaikan role yang sedang login, sedangkan tabel progres kabupaten/kota tetap tampil global.</span>
              </div>
            </div>
            <div class="page-item">
              <div class="page-icon"><span class="nav-text">HK</span></div>
              <div>
                <strong>HK</strong>
                <span>Konfirmasi perubahan harga konsumen, input SP2KP, catatan, penurunan konsumsi, dan penjelasan. Tersedia chip komoditas serta tambah/hapus komoditas untuk admin provinsi.</span>
              </div>
            </div>
            <div class="page-item">
              <div class="page-icon"><span class="nav-text">HPB</span></div>
              <div>
                <strong>HPB</strong>
                <span>Konfirmasi perubahan harga perdagangan besar dengan struktur tabel per komoditas, chip status komoditas, dan dukungan tambah/hapus komoditas untuk admin provinsi.</span>
              </div>
            </div>
            <div class="page-item">
              <div class="page-icon"><span class="nav-text">HD</span></div>
              <div>
                <strong>HD</strong>
                <span>Konfirmasi perubahan harga produsen pedesaan dan pengisian penjelasan per komoditas, termasuk navigasi chip komoditas yang lebih ringkas.</span>
              </div>
            </div>
            <div class="page-item">
              <div class="page-icon"><span class="nav-text">HKD</span></div>
              <div>
                <strong>HKD</strong>
                <span>Konfirmasi perubahan harga konsumen pedesaan untuk komoditas pada tabel HKD, dengan alur chip komoditas yang sama seperti level harga lainnya.</span>
              </div>
            </div>
            <div class="page-item">
              <div class="page-icon"><img src="assets/images/warning.png" alt="Ekstrem"></div>
              <div>
                <strong>Harga Ekstrem</strong>
                <span>Input data harga ekstrem per subsektor, komoditas, kualitas, satuan, dan konfirmasi kabupaten, lengkap dengan dukungan paste dari Excel dan filter header kolom.</span>
              </div>
            </div>
            <div class="page-item">
              <div class="page-icon"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras"></div>
              <div>
                <strong>Hulu Hilir Beras</strong>
                <span>Pengisian tabel gabah dan beras yang menghubungkan SHPED_HD, SHPED_HKD, SHP, HPB, dan HK. Terdapat validasi arah RH dan warning dinamis pada kabupaten/kota terkait.</span>
              </div>
            </div>
            <div class="page-item">
              <div class="page-icon"><i class="mdi mdi-chart-line"></i></div>
              <div>
                <strong>Perbandingan Harga</strong>
                <span>Melihat perbandingan rata-rata perubahan HK, HPB, HD, dan HKD serta chart ringkas per kabupaten/kota. Kolom penjelasan per kabupaten/kota tersedia pada panel terpisah di bawah chart.</span>
              </div>
            </div>
            <div class="page-item">
              <div class="page-icon"><i class="mdi mdi-book-open-variant"></i></div>
              <div>
                <strong>Panduan</strong>
                <span>Ringkasan alur kerja, hak akses, legenda warna, ikon sidebar, dan pertanyaan yang sering muncul.</span>
              </div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:14px;">
          <h3>Langkah Pengisian Data</h3>
          <div class="guide-grid">
            <div>
              <div class="step">
                <strong>1) Pilih bulan dan tahun</strong>
                <p>Gunakan filter di bagian atas halaman agar data yang tampil sesuai periode kerja.</p>
              </div>
              <div class="step">
                <strong>2) Cari komoditas dan baris wilayah</strong>
                <p>Gunakan chip/tab komoditas atau filter yang tersedia, lalu pastikan baris kabupaten/kota sesuai dengan wilayah yang sedang dikerjakan.</p>
              </div>
              <div class="step">
                <strong>3) Isi sel yang aktif</strong>
                <p>Ketik langsung pada field yang terbuka. Jika kolom berwarna abu-abu, berarti field tersebut tidak dapat diedit oleh akun Anda.</p>
              </div>
              <div class="step">
                <strong>4) Gunakan keyboard navigation</strong>
                <p><strong>Enter</strong> untuk ke bawah dan <strong>Tab</strong> untuk ke kanan. Ini mempercepat pengisian saat data cukup banyak.</p>
              </div>
            </div>
            <div>
              <div class="step">
                <strong>5) Pastikan autosave berjalan</strong>
                <p>Sistem akan menyimpan otomatis saat pengguna pindah sel, blur dari field, atau berhenti mengetik beberapa saat.</p>
              </div>
              <div class="step">
                <strong>6) Periksa simbol dan warning</strong>
                <p>Jika ada tanda warning, cek kembali apakah arah perubahan antar level harga sudah konsisten atau perlu konfirmasi tambahan.</p>
              </div>
              <div class="step">
                <strong>7) Gunakan chip komoditas sebagai penanda progres</strong>
                <p>Pill komoditas akan berubah hijau jika pada periode aktif komoditas tersebut sudah tidak membutuhkan penjelasan lagi.</p>
              </div>
              <div class="step">
                <strong>8) Pantau progres di Dashboard</strong>
                <p>Dashboard dapat dipakai untuk melihat progres pengisian, level harga yang tertinggal, kabupaten yang masih perlu perhatian, serta akun yang sedang online.</p>
              </div>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:14px;">
          <h3>Pertanyaan yang Sering Muncul</h3>
          <div class="faq-grid">
            <div class="faq-item">
              <strong>Kenapa kolom saya abu-abu?</strong>
              <span>Kolom tersebut dikunci oleh sistem, tidak relevan untuk wilayah tertentu, atau tidak bisa diedit oleh role akun yang sedang login.</span>
            </div>
            <div class="faq-item">
              <strong>Bagaimana tahu data sudah tersimpan?</strong>
              <span>Data akan tersimpan otomatis saat Anda pindah sel atau berhenti mengisi. Setelah halaman di-refresh, nilai yang tersimpan akan tetap tampil.</span>
            </div>
            <div class="faq-item">
              <strong>Kenapa pill komoditas berubah hijau?</strong>
              <span>Itu berarti komoditas tersebut sudah tidak membutuhkan penjelasan lagi pada filter yang sedang aktif, baik karena semuanya sudah lengkap maupun memang tidak ada baris yang perlu diisi.</span>
            </div>
            <div class="faq-item">
              <strong>Kenapa data tidak muncul saat filter?</strong>
              <span>Biasanya karena bulan, tahun, atau komoditas yang dipilih belum memiliki data. Periksa kembali filter dan kembalikan ke periode yang benar.</span>
            </div>
            <div class="faq-item">
              <strong>Apa arti tanda warning?</strong>
              <span>Warning menunjukkan ada kondisi yang perlu perhatian, misalnya arah perubahan tidak sejalan atau isian tertentu perlu dicek ulang.</span>
            </div>
            <div class="faq-item">
              <strong>Kenapa chart perbandingan dan penjelasan dipisah?</strong>
              <span>Supaya chart perbandingan per kabupaten/kota bisa tampil lebih rapat dan mudah dibandingkan dalam satu layar, sementara area penjelasan tetap tersedia pada panel terpisah di bawahnya.</span>
            </div>
          </div>
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
