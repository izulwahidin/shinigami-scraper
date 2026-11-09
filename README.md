# Shinigami Scraper

Scraper gambar manga/manhwa/manhua dari **shinigami.asia** (`api.shngm.io`) dengan:

- **PHP 8.2+ murni** (tanpa framework, tanpa dependency runtime).
- **Cepat & paralel** — `curl_multi` untuk download gambar dan request API.
- **Resumable** — file sementara `.part`, gambar yang sudah ada dilewati otomatis.
- **SQLite** — menyimpan status per project/chapter, aman untuk cron berulang.
- **Folder rapi** — dikelompokkan per tipe, judul, dan chapter.
- **Auto update** — cron tiap 10 menit, dilindungi file lock agar tidak tumpang tindih.
- **Validasi gambar** — file HTML/Cloudflare tidak akan tersimpan sebagai `.jpg`.

> Gunakan hanya untuk konten yang Anda berhak akses/simpan. Hormati hak cipta dan
> ketentuan layanan sumber.

---

## 1. Kebutuhan

| Komponen | Versi / catatan |
|---|---|
| PHP | >= 8.2 (diuji di PHP 8.5) |
| Ekstensi | `curl`, `json`, `mbstring`, `pdo`, `pdo_sqlite` |
| OS | Linux/macOS/WSL (Windows native juga bisa, tanpa fast-move) |
| Composer | **Opsional**. Autoloader fallback sudah disediakan. |

Cek cepat:

```bash
php -v
php -m | grep -E 'curl|pdo_sqlite|mbstring'
```

---

## 2. Mulai cepat

```bash
cd /path/ke/shinigami

# Lihat bantuan
php bin/scrape.php help

# Uji koneksi + lihat rencana download (tidak menulis gambar)
php bin/scrape.php sync --limit=3 --latest=1 --dry-run -v

# Download 1 chapter terbaru dari 1 project sebagai uji coba
php bin/scrape.php sync --limit=1 --latest=1 -v

# Jalankan sinkronisasi penuh (SEMUA project, SEMUA chapter yang belum ada)
php bin/scrape.php sync
```

> **Peringatan ukuran disk:** satu chapter bisa 10–30 MB. Arsip penuh 175 project
> bisa mencapai ratusan GB. Untuk pemanasan, gunakan `--latest=N` atau `--project=...`
> terlebih dahulu.

Hasil uji:

```text
downloads/
└── manhwa/
    └── the-player-hides-his-past/
        ├── project.json
        ├── cover.jpg
        ├── cover_portrait.jpg
        └── chapter-0146/
            ├── metadata.json
            ├── 001.jpg
            ├── 002.jpg
            └── ...
```

- `project.json` — metadata project + payload API.
- `metadata.json` — metadata chapter + mapping nama file asli → file lokal.
- Chapter desimal tetap aman: `12.5` → `chapter-0012.5`.
- Nomor chapter yang bentrok otomatis diberi akhiran id pendek.

---

## 3. Perintah

Semua perintah dijalankan dari root project.

### `sync` / `update` / `scrape`

Sinkronisasi project dan download chapter yang belum selesai.

```bash
php bin/scrape.php sync [opsi]
php bin/scrape.php update [opsi]     # alias, cocok untuk cron
```

| Opsi | Fungsi |
|---|---|
| `--project=ID\|SLUG` | Batasi ke satu project (ID API atau slug folder yang sudah ada). |
| `--type=manhwa` | Filter `manga`, `manhwa`, atau `manhua`. |
| `--latest=N` | Hanya pertimbangkan N chapter terbaru **per project**. |
| `--limit=N` | Maksimal N project (berguna untuk uji coba). |
| `--force` | Download ulang chapter yang sudah selesai (folder chapter dihapus dulu). |
| `--refresh` | Cek ulang chapter selesai; download ulang jika `updated_at`/jumlah gambar berubah. |
| `--dry-run` | Tampilkan rencana tanpa download gambar. |
| `--concurrency=N` | Jumlah download gambar paralel (default `16`). |
| `--api-concurrency=N` | Jumlah request detail chapter paralel (default `8`). |
| `-q`, `--quiet` | Tidak menulis progress ke stdout (tetap masuk log). |
| `-v`, `-vv` | Verbose / debug. |

Contoh:

```bash
# Hanya manhwa, 3 chapter terbaru per project
php bin/scrape.php sync --type=manhwa --latest=3

# Satu project, download ulang chapter terbaru
php bin/scrape.php sync --project=f9b2bafd-64d6-4eb7-86df-0cc9684ffc56 --latest=1 --force

# Cek apakah ada chapter yang di-update di sumber
php bin/scrape.php sync --refresh --latest=10

# Uji performa tanpa menyentuh file gambar
php bin/scrape.php sync --limit=5 --latest=2 --dry-run -v
```

### `stats`

```bash
php bin/scrape.php stats
php bin/scrape.php stats --disk   # hitung juga ukuran folder downloads (lebih lambat)
```

### `projects`

```bash
php bin/scrape.php projects
php bin/scrape.php projects --type=manhwa
php bin/scrape.php projects --failed
php bin/scrape.php projects --json
```

### `retry-failed`

Reset semua chapter berstatus `failed` lalu coba download lagi.

```bash
php bin/scrape.php retry-failed
php bin/scrape.php retry-failed --project=f9b2bafd-64d6-4eb7-86df-0cc9684ffc56
php bin/scrape.php retry-failed --reset-only   # hanya reset status, tanpa download
```

---

## 4. Cron otomatis tiap 10 menit

### Cara otomatis

```bash
./bin/install-cron.sh
crontab -l
```

Entry yang dipasang:

```cron
*/10 * * * * cd '/path/ke/shinigami' && '/usr/bin/php' bin/scrape.php update --quiet >> '/path/ke/shinigami/storage/logs/cron.log' 2>&1 # shinigami-scraper
```

Ganti jadwal:

```bash
SHINIGAMI_CRON_SCHEDULE="*/5 * * * *" ./bin/install-cron.sh
```

Hapus:

```bash
./bin/install-cron.sh --remove
```

### Manual

```cron
*/10 * * * * cd /path/ke/shinigami && /usr/bin/php bin/scrape.php update --quiet >> storage/logs/cron.log 2>&1
```

Catatan:

- File lock `storage/scraper.lock` mencegah dua proses berjalan bersamaan. Jika run
  sebelumnya belum selesai, cron berikutnya langsung keluar dengan aman.
- Log utama: `storage/logs/scraper.log` (rotasi otomatis pada 10 MB, menyimpan satu
  file `.1`).
- `cron.log` hanya untuk error startup/cron itu sendiri.
- Setelah run pertama selesai, run berikutnya hanya mengunduh chapter baru.

---

## 5. Konfigurasi

Semua pengaturan ada di `config/config.php` dan bisa dioverride lewat environment
variable — cocok untuk cron/systemd tanpa mengedit kode.

| Environment | Default | Keterangan |
|---|---|---|
| `SHINIGAMI_API_BASE` | `https://api.shngm.io/v1` | Base URL API. |
| `SHINIGAMI_REFERER` | `https://11.shinigami.asia/` | Referer untuk API & assets. |
| `SHINIGAMI_USER_AGENT` | Chrome UA | User-Agent. |
| `SHINIGAMI_DOWNLOAD_DIR` | `./downloads` | Folder hasil download. |
| `SHINIGAMI_STORAGE_DIR` | `./storage` | Folder SQLite, lock, dan log. |
| `SHINIGAMI_STAGING_DIR` | auto | Folder sementara download. `off` = tulis langsung ke tujuan. |
| `SHINIGAMI_FAST_MOVE` | `true` | Pakai `mv` native saat memindahkan dari staging lintas filesystem. |
| `SHINIGAMI_CONCURRENCY` | `16` | Download gambar paralel. |
| `SHINIGAMI_API_CONCURRENCY` | `8` | Request detail chapter paralel. |
| `SHINIGAMI_API_RETRIES` | `3` | Retry API. |
| `SHINIGAMI_DOWNLOAD_RETRIES` | `3` | Retry download gambar. |
| `SHINIGAMI_API_TIMEOUT` | `30` | Timeout request API (detik). |
| `SHINIGAMI_DOWNLOAD_TIMEOUT` | `60` | Timeout download gambar (detik). |
| `SHINIGAMI_MIN_IMAGE_BYTES` | `256` | Ukuran minimum agar file dianggap valid. |
| `SHINIGAMI_CHAPTER_CHUNK` | `40` | Jumlah chapter diproses per batch (memori vs progres). |
| `SHINIGAMI_SKIP_COVERS` | `false` | Lewati download cover. |
| `SHINIGAMI_LOG_LEVEL` | `info` | `debug`, `info`, `warn`, `error`. |
| `SHINIGAMI_LOG_MAX_BYTES` | `10485760` | Ambang rotasi log. |

Contoh untuk cron:

```cron
*/10 * * * * cd /srv/shinigami && SHINIGAMI_CONCURRENCY=24 SHINIGAMI_DOWNLOAD_DIR=/mnt/archive/shinigami /usr/bin/php bin/scrape.php update --quiet >> storage/logs/cron.log 2>&1
```

---

## 6. Cara kerja singkat

1. Ambil daftar seluruh project dari `/manga/list?type=project...`.
2. Simpan/refresh metadata project ke SQLite. Slug folder **tidak berubah** meski judul di API berubah.
3. Untuk project yang chapter terbarunya berubah (atau punya pekerjaan tertunda):
   - Ambil daftar chapter dari `/chapter/{manga_id}/list`.
   - Upsert chapter baru ke SQLite.
4. Pilih chapter yang perlu diproses (`pending`, `failed`, `downloading`, atau `dirty`).
5. Ambil detail chapter secara paralel dari `/chapter/detail/{chapter_id}`.
6. Bangun URL gambar: `base_url + chapter.path + filename`.
7. Download semua gambar dengan `curl_multi` (pool tetap penuh, retry + backoff).
8. Setelah **semua** gambar chapter ada dan valid, tulis `metadata.json` dan tandai chapter `done`.
9. Jika sebagian gagal, chapter ditandai `failed`; file yang berhasil tetap disimpan dan run berikutnya melanjutkan.

### Status chapter

| Status | Arti |
|---|---|
| `pending` | Belum diproses. |
| `downloading` | Sedang diproses (atau terhenti; akan dilanjutkan). |
| `done` | Semua gambar lengkap dan valid. |
| `failed` | Ada gambar yang gagal; bisa di-retry. |
| `empty` | API tidak mengembalikan gambar untuk chapter tersebut. |

### Incremental update

- Project yang `latest_chapter_id`-nya sama dan tidak punya pekerjaan tertunda akan
  dilewati tanpa request chapter list.
- Chapter `done` tidak di-request detailnya lagi.
- `--refresh` memaksa pengecekan chapter selesai (berguna jika chapter di-reupload).
- `--force` menghapus folder chapter terpilih lalu mengunduh ulang.

---

## 7. Performa

- Default 16 download paralel dan 8 detail chapter paralel. Naikkan jika koneksi
  dan CDN mengizinkan (`--concurrency=24 --api-concurrency=12`).
- Jika dijalankan dari **WSL** dengan folder di `/mnt/d` (DrvFs), menulis langsung
  ke tujuan sangat lambat. Scraper otomatis memakai staging di `/tmp` lalu
  memindahkannya dengan `mv` native. Bisa dimatikan dengan
  `SHINIGAMI_STAGING_DIR=off` atau `SHINIGAMI_FAST_MOVE=0`.
- Folder chapter dibuat per batch (default 40 chapter) untuk membatasi memori.
- Retry menggunakan exponential backoff dan menghormati kegagalan `429`/`5xx`.
- File gambar divalidasi lewat magic bytes (JPEG/PNG/WebP/GIF/AVIF/BMP) sebelum
  dipindahkan ke folder final, sehingga halaman error Cloudflare tidak ikut tersimpan.

---

## 8. Troubleshooting

**`Another scraper process is already running`**
: Run sebelumnya masih berjalan. Tunggu, atau cek `storage/scraper.lock` dan proses
  PHP yang aktif. Jangan hapus lock saat proses masih hidup.

**Banyak chapter `failed`**
: Jalankan `php bin/scrape.php retry-failed -v`. Cek juga log
  `storage/logs/scraper.log`. Error umum: timeout, `429`, atau CDN menolak sementara.
  Kurangi concurrency jika terlalu agresif.

**Gambar tersimpan tapi isinya HTML**
: Scraper memvalidasi magic bytes, jadi kasus ini seharusnya tidak terjadi. Jika
  ada, cek `SHINIGAMI_MIN_IMAGE_BYTES` dan hapus chapter terkait lalu jalankan
  `sync --project=... --force`.

**Ingin mengulang satu chapter**
: Hapus folder chapter, lalu jalankan `sync --project=... --latest=N --force`.
  Status di SQLite akan otomatis disesuaikan saat `--force`.

**File gambar terhapus tanpa sengaja**
: Jalankan `php bin/scrape.php sync --project=SLUG --refresh -v`. Chapter yang
  folder, metadata, atau sebagian file gambarnya hilang akan diunduh ulang;
  chapter yang masih utuh tetap dilewati. Gunakan `--force` jika ingin
  mengunduh ulang semua chapter tanpa pemeriksaan.

**Mau pindah lokasi arsip**
: Set `SHINIGAMI_DOWNLOAD_DIR`. Database menyimpan path relatif (folder), bukan
  path absolut, sehingga arsip bisa dipindah tanpa mengubah DB.

**Database rusak / ingin mulai dari nol**
: Hentikan scraper, hapus `storage/shinigami.sqlite*`, lalu jalankan `sync` lagi.
  File gambar yang sudah ada akan dilewati berdasarkan keberadaan file.

**API berubah**
: Ubah `SHINIGAMI_API_BASE` / endpoint di `src/Api/ShinigamiApi.php`. Semua parsing
  terpusat di `src/Domain`.

---

## 9. Struktur source

```text
bin/
  scrape.php              # entry point CLI
  install-cron.sh         # pasang/hapus cron 10 menit
config/
  config.php              # default + env override
resources/
  schema.sql              # skema SQLite
src/
  Api/                    # endpoint Shinigami
  Console/                # CLI, arg parser, commands
  Domain/                 # DTO Project, ChapterSummary, ChapterDetail
  Download/               # curl_multi downloader + staging/fast move
  Http/                   # curl client dengan retry/backoff
  Repository/             # akses SQLite
  Scraper/                # orkestrasi sync incremental
  Support/                # config, logger, lock, slug, path, filesystem
tests/
  run.php                 # smoke test tanpa dependency
```

Menjalankan test:

```bash
php tests/run.php
# atau
composer test
```

---

## 10. Lisensi & tanggung jawab

Kode ini disediakan untuk keperluan pembelajaran/arsip pribadi. Anda bertanggung
jawab penuh atas penggunaan, termasuk kepatuhan terhadap hak cipta dan ketentuan
layanan sumber. Jangan gunakan untuk mendistribusikan ulang konten tanpa izin.
