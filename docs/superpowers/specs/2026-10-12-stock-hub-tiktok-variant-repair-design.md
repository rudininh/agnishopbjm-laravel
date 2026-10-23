# Tombol Perbaiki Mengikuti Agni untuk Varian TikTok

Status: disetujui pengguna pada 12 Oktober 2026 untuk diimplementasikan.

## Tujuan

Operator dapat memperbaiki konflik SKU/nama varian produk TikTok langsung dari modal Varian TikTok Agni di `/sinkronisasi-stok`, tanpa menjalankan skrip internal atau mengisi ulang identitas varian. Shopee Agni menjadi acuan SKU dan nama. Instruksi pengguna sebelumnya mengizinkan penghapusan varian TikTok yang terbukti sudah tidak ada di Agni.

## Pilihan pendekatan

1. **Direkomendasikan: tombol per produk di modal yang sudah ada.** Satu klik memulai pemeriksaan dan perbaikan produk tersebut, dengan progres dan hasil tersimpan. Mudah menampilkan konflik serta status peninjauan pada produk yang bersangkutan.
2. **Alternatif: perbaikan seluruh katalog.** Menambahkan koordinasi batch, ringkasan penghapusan lintas produk, dan pemulihan banyak pekerjaan. Ditunda agar fitur pertama fokus pada perbaikan produk yang sedang diperiksa.

## Pengalaman operator

- Tombol **Perbaiki Mengikuti Agni** tersedia pada modal produk TikTok yang terkait dengan sumber Agni ketika perbaikan identitas diperlukan. Ketersediaan memakai jenis masalah yang diberikan backend, bukan pencocokan kalimat error di frontend.
- Untuk percobaan lama yang belum memiliki jenis masalah tersimpan, backend boleh menawarkan pemeriksaan/perbaikan hanya bila percobaan blocked belum pernah dikirim dan memiliki tujuan terkait yang diketahui. Ini adalah kandidat pemeriksaan terbaru, bukan bukti konflik identitas; seluruh validasi tetap dijalankan sebelum perubahan. Pesan error lama tidak dipakai untuk menyimpulkan penyebab.
- Teks di dekat tombol menjelaskan bahwa SKU dan nama mengikuti Agni; varian TikTok yang terbukti sudah tidak ada di Agni dapat dihapus. Harga dan stok varian yang dipertahankan tetap memakai snapshot TikTok; stok varian baru tetap mengikuti alur penambahan Agni yang sudah ada.
- Satu klik memeriksa sumber terbaru, menyusun rencana, memeriksa kepemilikan SKU, menyiapkan gambar untuk varian yang berubah, dan mengirim satu perubahan produk. Operator tidak memasukkan ulang SKU/nama.
- Progres menampilkan pemeriksaan, persiapan gambar, pengiriman, serta verifikasi. Hasil memuat jumlah dan nama varian yang diperbaiki, dihapus, dan masih kurang.
- **Menunggu peninjauan TikTok** merupakan status tersendiri. Operator dapat memakai **Periksa Status**. TikTok menentukan waktu persetujuan; aplikasi tidak menganggap diterima sama dengan sudah aktif.
- Setelah perbaikan terverifikasi, tampilkan **Lanjutkan Tambah Varian** untuk menjalankan alur penambahan varian yang sudah ada. Pengiriman perbaikan dan penambahan merupakan dua operasi tercatat; fitur ini tidak menjanjikan persetujuan otomatis dari TikTok.
- Saat modal dibuka ulang atau halaman dimuat ulang, status dipulihkan dari server. Pemulihan GET tidak mengirim perubahan. Klik ganda atau respons terputus memakai identitas permintaan yang sama.
- Batch katalog dapat tetap membuka modal ini melalui Periksa Produk. Tombol perbaikan tidak menjalankan penghapusan otomatis di seluruh katalog.

## Data dan batas perbaikan

- Sumber tetap Shopee Agni dan tujuan tetap TikTok Agni. Backend menentukan produk tujuan dari hubungan yang terverifikasi; identitas akun/tujuan tidak dapat dipilih bebas oleh klien.
- Gunakan `StockHubTiktokSourceReconciliationPlan` sebagai dasar rencana. Cocokkan SKU Agni terlebih dahulu, lalu kombinasi atribut persis yang unik atau hubungan model sumber yang konsisten.
- Perubahan nama memakai nama Agni. Jika nama berubah, gambar atribut terkait mengikuti gambar sumber Agni, mengikuti dukungan satu/dua atribut pada payload yang sudah ada.
- Penghapusan hanya untuk varian yang memenuhi `OrphanVariantClassifier` sebagai eligible pada snapshot sumber/tujuan terbaru. Varian terakhir atau seluruh varian tidak boleh dihapus.
- SKU duplikat, kombinasi bertabrakan, hubungan produk/model bertentangan, kemungkinan rename yang belum pasti, sumber tidak lengkap, perubahan tertunda yang bukan operasi ini, atau perbedaan writable selain seller_sku antara versi TikTok tetap diblokir dengan alasan yang jelas.
- Jika SKU tujuan perlu diubah, pemeriksaan katalog harus membuktikan SKU tersebut tidak dimiliki produk TikTok lain. Kepemilikan Agni juga harus konsisten, termasuk produk tidak aktif.
- Paket kosong/nol menggunakan aturan Agni lalu template bersama yang sudah ada. Data paket valid dipertahankan. Normalisasi paket tidak boleh menyembunyikan perbedaan nyata antara versi aktif dan pemeriksaan.
- Jangan mengubah harga, inventori, gudang, ID varian yang dipertahankan, atau jumlah Stock Master sebagai efek samping perbaikan identitas.

## Alur backend

Workflow repair terpisah dari penambahan varian: persiapan → pemeriksaan katalog → unggah gambar perubahan → validasi ulang → pengiriman → verifikasi → terverifikasi / menunggu peninjauan / hasil belum pasti / diblokir / ditolak.

- Simpan run, request key, sumber/tujuan, rencana, snapshot pembanding, progres, serta attempted_at sebelum HTTP edit. Gunakan guard sumber/tujuan dan lease koordinasi marketplace yang sama dengan alur penambahan.
- Bekukan konteks akun sebelum attempted_at. Perubahan produk dikirim sekali tanpa retry transport.
- Baca ulang sumber, tujuan, kepemilikan, dan revisi sebelum pengiriman. Perubahan nyata membatalkan rencana; perbedaan urutan daftar sumber saja tidak dianggap perubahan.
- Setelah attempted_at tersimpan, pemulihan hanya membaca hasil. Percobaan accepted atau uncertain tidak boleh dikirim ulang melalui request baru maupun alur legacy.
- Verifikasi mencakup seluruh ID SKU, seller_sku, nama/identitas atribut, URI gambar yang dikirim, harga, inventori, gudang, dan kontrak paket. Kedua versi harus disetujui dan cocok sebelum status terverifikasi serta pembaruan cache/mapping.
- Audit internal yang sudah accepted/under_review dari perbaikan operator sebelumnya dikenali agar tombol memulihkan pekerjaan tersebut. Jangan membuat perbaikan kedua untuk produk yang sama. Pertahankan audit dan interlock lama.
- Penolakan eksplisit boleh diperiksa ulang lewat permintaan baru. Riwayat pengiriman tidak dihapus.
- Respons publik hanya memuat status, identitas yang diperlukan, progres, ringkasan, dan alasan aman; token, raw payload, transport exceptions, serta URL bertanda tangan tidak diekspos.

## Komponen

- Planner perbaikan yang sudah ada: pemetaan deterministik serta validasi orphan.
- Service dan gateway repair: workflow durable, koordinasi, pengiriman, verifikasi, cache, dan pemulihan audit operator yang relevan.
- Controller/API repair: start, status berdasarkan sumber/run, dan step eksplisit dengan validasi input.
- Modal `StockHubTiktokVariantAddition.vue`: tombol, progres, ringkasan, status peninjauan, serta transisi eksplisit ke penambahan.
- Controller state frontend terpisah untuk repair, memakai pola request identity dan pemulihan yang sudah dipakai penambahan.

## Pengujian dan penerimaan

- Tes backend mereproduksi approved live/review dengan SKU berbeda, lalu membuktikan rencana mengikuti Agni dan penghapusan hanya orphan yang valid.
- Tes menolak sumber/mapping ambigu, SKU lintas produk, sumber berubah, audit lain tertunda, serta perbedaan harga/stok/atribut antar versi.
- Tes membuktikan klik ganda, timeout, restart, dan request baru tidak mengulang edit accepted/uncertain; attempted_at harus tersimpan sebelum HTTP.
- Tes dual-readback memastikan peninjauan tetap pending dan perubahan cache hanya sesudah verifikasi lengkap.
- Tes pemulihan audit operator sebelumnya memastikan status pending dikenali tanpa edit kedua.
- Tes frontend mencakup tombol berdasarkan jenis masalah, progres, disabled saat sibuk, pemulihan, Periksa Status, dan Lanjutkan Tambah Varian hanya setelah repair verified.
- Jalankan tes terkait, tes frontend, build, review independen, serta verifikasi halaman/aset terbit. Verifikasi fitur memakai HTTP fake atau inspeksi baca saja; jangan mengirim edit marketplace hanya sebagai pengujian.

## Di luar tahap pertama

Perbaikan seluruh katalog dalam satu klik, fitur repair Gitashop, menebak pasangan berdasarkan nama mirip, dan memaksa persetujuan TikTok.
