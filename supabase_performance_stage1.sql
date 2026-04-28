-- Stage 1 performance indexes for Rekon Harga Lampung
-- Run once in Supabase SQL Editor.

CREATE INDEX IF NOT EXISTS shk_bulan_tahun_idx ON shk (bulan, tahun);
CREATE INDEX IF NOT EXISTS shk_bulan_tahun_kab_kom_idx ON shk (bulan, tahun, kode_kabupaten, komoditas);
CREATE INDEX IF NOT EXISTS shk_kom_norm_idx ON shk ((LOWER(TRIM(komoditas))));
CREATE INDEX IF NOT EXISTS shk_bulan_norm_idx ON shk ((LOWER(TRIM(bulan))));

CREATE INDEX IF NOT EXISTS hpb_bulan_tahun_idx ON hpb (bulan, tahun);
CREATE INDEX IF NOT EXISTS hpb_bulan_tahun_kab_kom_idx ON hpb (bulan, tahun, kode_kabupaten, komoditas);
CREATE INDEX IF NOT EXISTS hpb_kom_norm_idx ON hpb ((LOWER(TRIM(komoditas))));
CREATE INDEX IF NOT EXISTS hpb_bulan_norm_idx ON hpb ((LOWER(TRIM(bulan))));

CREATE INDEX IF NOT EXISTS hd_bulan_tahun_idx ON hd (bulan, tahun);
CREATE INDEX IF NOT EXISTS hd_bulan_tahun_kab_kom_idx ON hd (bulan, tahun, kode_kabupaten, komoditas);
CREATE INDEX IF NOT EXISTS hd_kom_norm_idx ON hd ((LOWER(TRIM(komoditas))));
CREATE INDEX IF NOT EXISTS hd_bulan_norm_idx ON hd ((LOWER(TRIM(bulan))));

CREATE INDEX IF NOT EXISTS hkd_bulan_tahun_idx ON hkd (bulan, tahun);
CREATE INDEX IF NOT EXISTS hkd_bulan_tahun_kab_kom_idx ON hkd (bulan, tahun, kode_kabupaten, komoditas);
CREATE INDEX IF NOT EXISTS hkd_kom_norm_idx ON hkd ((LOWER(TRIM(komoditas))));
CREATE INDEX IF NOT EXISTS hkd_bulan_norm_idx ON hkd ((LOWER(TRIM(bulan))));

CREATE INDEX IF NOT EXISTS ekstrem_bulan_tahun_idx ON ekstrem (bulan, tahun);
CREATE INDEX IF NOT EXISTS ekstrem_bulan_norm_idx ON ekstrem ((LOWER(TRIM(bulan))));
CREATE INDEX IF NOT EXISTS ekstrem_kab_bulan_tahun_idx ON ekstrem (kab, bulan, tahun);

CREATE INDEX IF NOT EXISTS hulu_hilir_beras_bulan_tahun_idx ON hulu_hilir_beras (bulan, tahun);
CREATE INDEX IF NOT EXISTS hulu_hilir_beras_kab_bulan_tahun_idx ON hulu_hilir_beras (kabupaten_kota, bulan, tahun);

CREATE INDEX IF NOT EXISTS perbandingan_penjelasan_key_idx
  ON perbandingan_penjelasan (kode_kabupaten, komoditas, bulan, tahun);

CREATE INDEX IF NOT EXISTS users_last_seen_idx ON users (last_seen);
CREATE INDEX IF NOT EXISTS app_sessions_expires_idx ON app_sessions (expires);
