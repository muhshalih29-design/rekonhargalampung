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
        <a class="nav-dot" href="hulu-hilir-beras.php" title="Hulu Hilir Beras"><img src="assets/images/rice-2.png" alt="Hulu Hilir Beras" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot" href="ekstrem.php" title="Ekstrem"><img src="assets/images/warning.png" alt="Ekstrem" style="width:18px;height:18px;display:block;" /></a>
        <a class="nav-dot active" href="panduan.php" title="Panduan"><i class="mdi mdi-book-open-variant"></i></a>
      </aside>

      <main class="main">
        <div class="topbar">
          <div>
            <div class="hello">Panduan Admin Kabupaten/Kota</div>
            <div class="subhello">Cara pengisian data oleh admin kabupaten/kota.</div>
          </div>
          <a class="icon-btn" href="logout.php" title="Logout"><i class="mdi mdi-logout"></i></a>
        </div>

        <div class="card">
          <h3>Ringkas</h3>
          <p>Admin Kabupaten/Kota hanya dapat mengedit kolom <strong>Penjelasan</strong> pada kabupaten/kota miliknya. Kolom lain tampil abu‑abu dan tidak bisa diubah.</p>
        </div>

        <div class="card" style="margin-top:14px;">
          <h3>Dashboard</h3>
          <p>Halaman Dashboard menampilkan <strong>progres pengisian penjelasan</strong> per kabupaten/kota. Bar menunjukkan persentase baris yang sudah terisi penjelasan untuk nilai perubahan yang bukan 0.</p>
          <p>Jika suatu level harga (HK/HPB/HD/HKD) tidak tersedia pada kabupaten tertentu, bar level tersebut tidak ditampilkan.</p>
        </div>

        <div class="card" style="margin-top:14px;">
          <h3>Ikon & Fungsi Halaman</h3>
          <div class="step">
            <strong>Dashboard</strong>
            <p>Ringkasan progres pengisian penjelasan per kabupaten/kota.</p>
          </div>
          <div class="step">
            <strong>Perbandingan Harga</strong>
            <p>Perbandingan rata-rata perubahan HK/HPB/HD/HKD per kabupaten/kota.</p>
          </div>
          <div class="step">
            <strong>HK</strong>
            <p>Konfirmasi perubahan harga konsumen.</p>
          </div>
          <div class="step">
            <strong>HPB</strong>
            <p>Konfirmasi perubahan harga perdagangan besar.</p>
          </div>
          <div class="step">
            <strong>HD</strong>
            <p>Konfirmasi perubahan harga produsen pedesaan.</p>
          </div>
          <div class="step">
            <strong>HKD</strong>
            <p>Konfirmasi perubahan harga konsumen pedesaan.</p>
          </div>
          <div class="step">
            <strong>Hulu Hilir Beras</strong>
            <p>Tabel gabah & beras (SHPED_HD, SHPED_HKD, SHP, HPB, HK) per kabupaten/kota.</p>
          </div>
          <div class="step">
            <strong>Ekstrem</strong>
            <p>Input data harga ekstrem per subsektor/komoditas dan konfirmasi kabupaten.</p>
          </div>
          <div class="step">
            <strong>Panduan</strong>
            <p>Petunjuk penggunaan untuk admin kabupaten/kota.</p>
          </div>
        </div>

        <div class="card" style="margin-top:14px;">
          <h3>Langkah Pengisian</h3>
          <div class="step">
            <strong>1) Pilih bulan dan tahun</strong>
            <p>Gunakan filter di bagian atas halaman untuk memilih periode data.</p>
          </div>
          <div class="step">
            <strong>2) Cari baris kabupaten/kota</strong>
            <p>Pastikan baris kabupaten/kota yang tampil sesuai kode kabupaten Anda.</p>
          </div>
          <div class="step">
            <strong>3) Isi kolom Penjelasan</strong>
            <p>Ketik penjelasan pada kolom <strong>Penjelasan</strong>. Sistem akan menyimpan otomatis.</p>
          </div>
          <div class="step">
            <strong>4) Pindah baris dengan Enter/Tab</strong>
            <p>Enter untuk pindah ke baris bawah, Tab untuk pindah ke sel kanan (jika tersedia).</p>
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
