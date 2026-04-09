-- Optional performance indexes for filter-heavy pages
CREATE INDEX IF NOT EXISTS idx_shk_filter_fn
ON shk (tahun, lower(trim(bulan)), lower(trim(komoditas)), kode_kabupaten);

CREATE INDEX IF NOT EXISTS idx_hpb_filter_fn
ON hpb (tahun, lower(trim(bulan)), lower(trim(komoditas)), kode_kabupaten);

CREATE INDEX IF NOT EXISTS idx_hd_filter_fn
ON hd (tahun, lower(trim(bulan)), lower(trim(komoditas)), kode_kabupaten);

CREATE INDEX IF NOT EXISTS idx_hkd_filter_fn
ON hkd (tahun, lower(trim(bulan)), lower(trim(komoditas)), kode_kabupaten);
