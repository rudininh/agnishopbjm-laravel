# Sinkron stok Shopee Agni ke TikTok dan Gitashop

Status: disetujui pengguna pada 2026-10-04. Implementasi diuji dengan API palsu; tidak ada sinkron stok live sebagai bagian pengujian.

## Tujuan

Tambahkan sinkronisasi stok di `/sinkronisasi-stok` dari Shopee AgniShopBJM ke TikTok AgniShopBJM dan Shopee GitaCollectionBJM. Pengguna dapat menjalankan seluruh katalog, satu produk, atau satu varian dengan alur yang sama. Kuantitas sumber berasal dari API Shopee Agni terbaru.

## Pilihan pendekatan

1. Direkomendasikan: tambahkan kontrol ke hub dan layar produk yang ada, dengan service sinkronisasi khusus, pembacaan per akun, serta proses bertahap yang menyimpan hasil. Ini mendukung dua target dan katalog besar tanpa menggantung pada satu request panjang.
2. Menyalin tombol halaman Anomali Stok lebih cepat, tetapi implementasi lama hanya mendukung TikTok dan sinkron massalnya menggunakan stok cache; pendekatan ini tidak memenuhi seluruh kebutuhan.
3. Membuat halaman sinkronisasi baru memberi ruang lebih luas, tetapi memisahkan aksi dari produk dan varian yang sedang dilihat. Pengguna meminta aksi di hub yang ada, sehingga pendekatan ini tidak dipilih.

## Antarmuka dan cakupan

- Tampilkan sumber tetap `Shopee AgniShopBJM` dan pilihan tujuan `TikTok AgniShopBJM` serta `Gitashop`; keduanya terpilih secara default dan sedikitnya satu tujuan wajib dipilih.
- Tombol utama `Samakan Semua Stok dari Shopee Agni` mencakup seluruh katalog sumber dan semua halaman, bukan hanya produk yang tampak akibat pagination atau filter layar. Label penjelas menyebut cakupan tersebut.
- Tambahkan tombol `Samakan Stok Produk` pada baris produk dan `Samakan Stok Varian` pada baris varian. Aksi memakai tujuan yang dipilih di hub.
- Untuk baris dari toko target, server membuktikan hubungan ke produk/varian sumber. Cakupan produk dibatasi pada varian yang terkait dengan produk yang dipilih; tidak meluas ke produk sumber atau target lain yang tidak terkait.
- Bedakan aksi baru dari tombol `Sync` yang sudah ada: tombol lama hanya mengambil ulang data produk.
- Klik tombol baru memulai satu proses pemeriksaan dan pengiriman dengan progres, jumlah berhasil, sudah sama, dilewati, gagal, dan perlu diperiksa. Tidak diperlukan input stok manual.
- Hasil per varian menampilkan SKU, produk/varian sumber, jumlah sumber, tujuan, jumlah sebelumnya bila tersedia, jumlah setelah verifikasi, dan alasan hasil.
- Cegah klik ganda, pergantian cakupan saat proses berjalan, serta aksi katalog yang bertabrakan. Setelah proses berakhir, perbarui daftar toko yang sedang dilihat.
- Proses yang tersimpan dapat dibuka lagi setelah reload; item yang sudah selesai tidak dikirim ulang.

## Sumber stok dan Stock Master

Stok Shopee Agni menjadi acuan untuk aksi manual ini. Stok nol valid apabila dibaca secara eksplisit dari API; kegagalan baca atau nilai tidak tersedia tidak pernah dianggap nol dan tidak memakai fallback stok cache.

Kuantitas Stock Master dan ledger penyesuaian tetap dipertahankan sesuai persetujuan pengguna. Aksi hanya mengubah inventory target dan cache marketplace terkait yang hasilnya terverifikasi.

## Identitas akun dan varian

- Akun sumber hanya `shopee-agnishopbjm`; target hanya `tiktok-agnishopbjm` dan `shopee-gitacollectionbjm`.
- Gunakan listing/mapping per akun yang identitasnya tervalidasi. Fallback hanya boleh dari satu kecocokan seller SKU dan identitas varian yang dapat dibuktikan pada akun yang benar.
- Konflik SKU, mapping ambigu, varian nonaktif/terhapus, target tidak tersedia, dan hubungan sumber tidak terbukti menghasilkan status dilewati dengan alasan, bukan tebakan.
- ID item/model Gitashop wajib berasal dari akun Gitashop; dilarang memakai ID Agni sebagai fallback.
- Untuk cakupan produk/varian, server mengikat ID layar ke akun yang dipilih dan menyelesaikan hubungan sumber. Client tidak menentukan jumlah stok atau identitas remote tujuan yang dipercaya begitu saja.
- Pembacaan katalog sumber dilakukan sampai pagination lengkap. Pembacaan yang gagal dilaporkan sebagai tidak lengkap; hasil parsial tidak diklaim sebagai seluruh katalog selesai.

## Proses dan integrasi

Service baru mengelola run, cakupan, item, status target, dan ringkasan persisten. Gateway memakai registry kredensial per akun serta helper API stock yang sudah ada. Jangan memperbesar `OmnichannelController` untuk orkestrasi baru.

Tahapan: mulai run, identifikasi cakupan, baca sumber/target terbaru, jalankan kelompok kecil, verifikasi, lalu tampilkan hasil. Endpoint step membatasi pekerjaan setiap request dan melanjutkan cursor yang disimpan sehingga tidak memerlukan daemon queue baru di PC.

Gunakan API inventory TikTok dan update_stock Shopee untuk mengubah stok saja. Nama, seller SKU, harga, gambar, jumlah varian, serta katalog sumber tetap dipertahankan.

Stok sumber dibaca ulang sebelum pengiriman. Selama satu varian diproses, kedua target menerima kuantitas sumber yang sama; perubahan sumber yang terdeteksi sebelum pengiriman target berikutnya membuat item perlu diperiksa ulang, bukan melanjutkan snapshot lama tanpa pemberitahuan.

Jangan memakai `MarketplaceSyncService::updateLocalStock` untuk aksi default karena helper tersebut juga menulis kuantitas Stock Master. Pakai pembaruan cache yang spesifik akun setelah verifikasi.

Status berhasil hanya diberikan setelah API menerima perubahan dan pembacaan target membuktikan stoknya sesuai. Jumlah target yang sudah sama hanya dianggap demikian berdasarkan pembacaan terbaru. Target yang gagal tidak membatalkan keberhasilan target lain.

Timeout mutasi ditandai belum terverifikasi dan diperiksa dengan pembacaan ulang; tidak dikirim ulang otomatis. Jika pengguna memilih mencoba ulang, baca ulang sumber dan target terlebih dahulu dan kirim hanya item yang masih memerlukan perubahan.

Gunakan penguncian katalog yang sudah ada untuk mencegah konflik dengan edit/hapus SKU. Koordinasikan operasi dengan lease marketplace lokal/remote yang digunakan scheduler STB; token lease tetap di server dan tidak masuk respons browser. Lease perlu diperbarui dan dilepas saat selesai atau dibatalkan. Pengambilan lease yang gagal tidak boleh diklaim melindungi operasi.

Audit menyimpan run, akun, ID produk/varian, nilai stok, hasil, dan alasan ringkas. Token, secret, signature, respons marketplace lengkap, dan gambar bitmap tidak disimpan dalam audit. Akses endpoint mengikuti kebijakan aksi marketplace lokal yang sudah ada.

## Verifikasi dan publikasi

Tes backend memakai API palsu: cakupan semua/produk/varian, dua target, stok nol, sumber gagal, stok cache berbeda, mapping ambigu, isolasi Gitashop, SKU terhapus, target tidak lengkap, perubahan konkuren, timeout, status parsial, submit ganda, kelanjutan run, serta Stock Master/ledger/harga/gambar tetap untuk aksi default.

Tes frontend memastikan cakupan dan akun tepat, label berbeda dari refresh cache, pilihan tujuan, progres, resume setelah reload, dan hasil per varian. Build frontend dan publikasikan index/aset ke `backend/public`. Verifikasi halaman dan endpoint dengan operasi baca; jangan mengirim stok marketplace live sebagai bagian pengujian fitur tanpa instruksi pengguna.
