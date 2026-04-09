CREATE TABLE IF NOT EXISTS shk (
  id BIGSERIAL PRIMARY KEY,
  kode_kabupaten VARCHAR(20) NOT NULL,
  nama_kabupaten VARCHAR(100) NOT NULL,
  bulan VARCHAR(20) NOT NULL,
  tahun INT NOT NULL,
  komoditas VARCHAR(100) NOT NULL,
  perubahan NUMERIC(12,2),
  sp2kp NUMERIC(12,2),
  catatan TEXT,
  penurunan_konsumsi VARCHAR(10),
  penjelasan TEXT,
  time_stamp TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS hpb (
  id BIGSERIAL PRIMARY KEY,
  kode_kabupaten VARCHAR(20) NOT NULL,
  nama_kabupaten VARCHAR(100) NOT NULL,
  bulan VARCHAR(20) NOT NULL,
  tahun INT NOT NULL,
  komoditas VARCHAR(100) NOT NULL,
  perubahan NUMERIC(12,2),
  sp2kp NUMERIC(12,2),
  catatan TEXT,
  penurunan_konsumsi VARCHAR(10),
  penjelasan TEXT,
  time_stamp TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS hd (
  id BIGSERIAL PRIMARY KEY,
  kode_kabupaten VARCHAR(20) NOT NULL,
  nama_kabupaten VARCHAR(100) NOT NULL,
  bulan VARCHAR(20) NOT NULL,
  tahun INT NOT NULL,
  komoditas VARCHAR(100) NOT NULL,
  perubahan NUMERIC(12,2),
  sp2kp NUMERIC(12,2),
  catatan TEXT,
  penurunan_konsumsi VARCHAR(10),
  penjelasan TEXT,
  time_stamp TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS hkd (
  id BIGSERIAL PRIMARY KEY,
  kode_kabupaten VARCHAR(20) NOT NULL,
  nama_kabupaten VARCHAR(100) NOT NULL,
  bulan VARCHAR(20) NOT NULL,
  tahun INT NOT NULL,
  komoditas VARCHAR(100) NOT NULL,
  perubahan NUMERIC(12,2),
  sp2kp NUMERIC(12,2),
  catatan TEXT,
  penurunan_konsumsi VARCHAR(10),
  penjelasan TEXT,
  time_stamp TIMESTAMPTZ DEFAULT NOW()
);
