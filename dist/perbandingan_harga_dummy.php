<?php
require_once __DIR__ . '/auth.php';
$user = require_auth();
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Perbandingan Harga Dummy</title>
    <link rel="stylesheet" href="assets/vendors/mdi/css/materialdesignicons.min.css">
    <link rel="shortcut icon" href="assets/images/rh-icon.png" />
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap');
      :root {
        --bg: #edf0f4;
        --card: #ffffff;
        --ink: #1f2430;
        --muted: #8b93a5;
        --accent: #d4f06f;
        --accent-strong: #b7ea2a;
        --shadow: 0 22px 48px rgba(26, 30, 40, 0.10);
        --radius: 22px;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        font-family: "Manrope", sans-serif;
        background: radial-gradient(1200px 600px at 15% 0%, #f7f7f9 0%, #eef1f5 55%, #e7ebf1 100%);
        color: var(--ink);
      }
      .page {
        display: grid;
        grid-template-columns: 80px 1fr;
        gap: 18px;
        padding: 20px;
        min-height: 100vh;
      }
      .sidebar {
        background: #f7f7f9;
        border-radius: 24px;
        padding: 14px 8px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        box-shadow: var(--shadow);
      }
      .logo {
        width: 44px;
        height: 44px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        background: #fff;
        font-weight: 800;
        color: #111827;
        border: 1px solid #eef0f4;
      }
      .side-btn {
        width: 44px;
        height: 44px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        background: #f0f2f6;
        color: #7b8496;
        text-decoration: none;
      }
      .side-btn.active {
        background: var(--accent);
        color: #1f2430;
        font-weight: 800;
        box-shadow: 0 10px 22px rgba(183, 234, 42, 0.35);
      }
      .main {
        background: #f7f7f9;
        border-radius: 28px;
        padding: 24px;
        box-shadow: var(--shadow);
      }
      .topbar {
        display: grid;
        grid-template-columns: 1fr auto;
        align-items: center;
        gap: 16px;
        margin-bottom: 20px;
      }
      .title {
        font-size: 28px;
        font-weight: 800;
        line-height: 1.1;
      }
      .title span {
        display: block;
        font-size: 14px;
        color: var(--muted);
        font-weight: 600;
      }
      .top-actions {
        display: flex;
        gap: 10px;
        align-items: center;
      }
      .pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        border: 1px solid #eef0f4;
        border-radius: 999px;
        padding: 8px 12px;
        font-size: 12px;
        color: #6b7280;
      }
      .pill strong { color: #111827; }
      .grid {
        display: grid;
        grid-template-columns: 2.3fr 1.2fr;
        gap: 16px;
      }
      .card {
        background: var(--card);
        border-radius: var(--radius);
        padding: 16px;
        box-shadow: 0 12px 28px rgba(26, 30, 40, 0.08);
      }
      .card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
      }
      .card-title {
        font-size: 14px;
        font-weight: 700;
      }
      .tag {
        background: #f0f2f6;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 11px;
        color: #6b7280;
      }
      .kpi-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-bottom: 10px;
      }
      .kpi {
        border-radius: 16px;
        padding: 12px;
        background: #f7f7fa;
      }
      .kpi .big { font-size: 18px; font-weight: 800; }
      .kpi .sub { font-size: 11px; color: #7b8496; }
      .chart {
        height: 140px;
        display: flex;
        align-items: flex-end;
        gap: 6px;
      }
      .bar {
        width: 18px;
        border-radius: 999px;
        background: #111827;
        opacity: 0.15;
      }
      .bar.accent { background: var(--accent); opacity: 1; }
      .right-card {
        display: grid;
        gap: 12px;
      }
      .progress-card {
        background: #ffffff;
        border-radius: 18px;
        padding: 12px;
      }
      .progress {
        height: 10px;
        background: #f0f2f6;
        border-radius: 999px;
        overflow: hidden;
      }
      .progress > span {
        display: block;
        height: 100%;
        background: var(--accent);
        width: 62%;
      }
      .mini-list {
        display: grid;
        gap: 8px;
        margin-top: 10px;
      }
      .mini-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 12px;
        color: #6b7280;
      }
      .accent-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--accent);
        display: inline-block;
        margin-right: 6px;
      }
      .lower {
        display: grid;
        grid-template-columns: 1.1fr 1fr 1fr;
        gap: 16px;
        margin-top: 16px;
      }
      .big-stat {
        font-size: 28px;
        font-weight: 800;
      }
      @media (max-width: 1200px) {
        .grid { grid-template-columns: 1fr; }
        .lower { grid-template-columns: 1fr; }
      }
      @media (max-width: 900px) {
        .page { grid-template-columns: 1fr; }
        .sidebar { flex-direction: row; justify-content: flex-start; overflow-x: auto; }
      }
    </style>
  </head>
  <body>
    <div class="page">
      <aside class="sidebar">
        <div class="logo">RH</div>
        <a class="side-btn active" href="#">P</a>
        <a class="side-btn" href="#">%</a>
        <a class="side-btn" href="#">#</a>
        <a class="side-btn" href="#">$</a>
      </aside>
      <main class="main">
        <div class="topbar">
          <div class="title">Perbandingan Harga<br><span>Dummy layout preview</span></div>
          <div class="top-actions">
            <div class="pill"><strong>Sep 02</strong>–Sep 09</div>
            <div class="pill">24h</div>
            <div class="pill"><strong>Weekly</strong></div>
          </div>
        </div>

        <div class="grid">
          <div class="card">
            <div class="card-head">
              <div class="card-title">Analitik Perbandingan</div>
              <span class="tag">Tracker</span>
            </div>
            <div class="kpi-row">
              <div class="kpi">
                <div class="big">19.365</div>
                <div class="sub">Total Perubahan</div>
              </div>
              <div class="kpi">
                <div class="big">09.45</div>
                <div class="sub">Rata-rata</div>
              </div>
              <div class="kpi">
                <div class="big">98.57%</div>
                <div class="sub">Konfirmasi</div>
              </div>
            </div>
            <div class="chart">
              <div class="bar" style="height:30%"></div>
              <div class="bar" style="height:42%"></div>
              <div class="bar accent" style="height:80%"></div>
              <div class="bar" style="height:55%"></div>
              <div class="bar" style="height:40%"></div>
              <div class="bar" style="height:35%"></div>
              <div class="bar accent" style="height:70%"></div>
              <div class="bar" style="height:50%"></div>
            </div>
          </div>

          <div class="right-card">
            <div class="progress-card">
              <div class="card-head">
                <div class="card-title">Progress</div>
                <span class="tag">Weekly</span>
              </div>
              <div class="progress"><span></span></div>
              <div class="mini-list">
                <div class="mini-item"><span><span class="accent-dot"></span>HK</span><strong>139</strong></div>
                <div class="mini-item"><span>HPB</span><strong>84</strong></div>
                <div class="mini-item"><span>HD</span><strong>52</strong></div>
                <div class="mini-item"><span>HKD</span><strong>31</strong></div>
              </div>
            </div>
          </div>
        </div>

        <div class="lower">
          <div class="card">
            <div class="card-title">Get more exposure</div>
            <div class="big-stat" style="margin-top:10px;">89%</div>
            <div class="sub">Avg. accuracy</div>
          </div>
          <div class="card">
            <div class="card-title">Take a breath</div>
            <div class="big-stat" style="margin-top:10px;">10.57</div>
            <div class="sub">Avg. change</div>
          </div>
          <div class="card">
            <div class="card-title">Workout</div>
            <div class="big-stat" style="margin-top:10px;">97.5%</div>
            <div class="sub">Completion</div>
          </div>
        </div>
      </main>
    </div>
  </body>
</html>
