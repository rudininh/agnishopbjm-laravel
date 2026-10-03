# Hapus Varian Target yang Tidak Ada di Shopee Agni

## Tujuan dan cakupan

Pada /sinkronisasi-stok, akun TikTok AgniShopBJM dan Shopee
GitaCollectionBJM (Gitashop) menyediakan penghapusan satuan atau massal
untuk varian yang terbukti tidak lagi tersedia pada produk sumber Shopee
AgniShopBJM. Akun sumber tidak menyediakan aksi cleanup ini.

Permintaan berulang pengguna menegaskan kedua target dan kedua mode hapus.
Spesifikasi ini menunggu tinjauan pengguna sebelum implementasi.

## Pilihan pendekatan

1. Direkomendasikan: preview berdasarkan katalog terbaru dan identitas sumber,
   dilanjutkan konfirmasi, validasi ulang, penghapusan, dan verifikasi.
2. Perbandingan seller SKU saja lebih sederhana tetapi ditolak karena SKU
   salah atau nama berubah bukan bukti bahwa varian sumber telah dihapus.

## Antarmuka

- Tambahkan tombol Hapus Varian Tidak Ada di Agni di baris aksi atas,
  dekat aksi perbaikan SKU, hanya untuk akun target yang didukung.
- Tombol membuka preview seluruh katalog akun terpilih, bukan hanya halaman
  tabel yang sedang terlihat. Tampilkan cakupan dan progres pemeriksaan.
- Kelompokkan kandidat per produk; tampilkan nama, seller SKU, ID varian,
  produk sumber, alasan kandidat atau alasan diblokir.
- Sediakan Hapus pada setiap kandidat dan Hapus Semua Kandidat Aman.
- Konfirmasi menyebut akun tujuan, jumlah produk/varian, dan sifat permanen
  penghapusan marketplace. Aksi massal tidak mencakup baris diblokir.
- Pergantian akun membatalkan preview lama. Cegah submit ganda dan konflik
  dengan perbaikan SKU atau penghapusan lain yang sedang berjalan.
- Hasil membedakan terhapus terverifikasi, belum terverifikasi, gagal,
  diblokir, dan preview kedaluwarsa.

## Aturan kelayakan

- Sumber selalu akun shopee-agnishopbjm; target hanya tiktok-agnishopbjm
  atau shopee-gitacollectionbjm. Validasi identitas toko dan token di server.
- Hubungan produk sumber harus tunggal dan dapat dibuktikan dari mapping
  atau prefix internal yang konsisten; nama produk saja tidak cukup.
- Ambil daftar model sumber dan varian target lengkap dari API terbaru.
  Timeout, paginasi belum lengkap, otorisasi gagal, respons kosong ambigu,
  atau sumber tidak ditemukan bukan bukti penghapusan.
- Pertahankan target bila identitas model sumber masih ada, atau kecocokan
  SKU/nama varian terbaru menunjukkan varian masih ada. Konflik antar
  identitas, nama ganda, prefix campuran, dan dugaan rename diblokir.
- Kandidat hanya layak bila hubungan produk sumber terbukti, katalog sumber
  lengkap, dan tidak ada kecocokan maupun konflik identitas varian.
- Stok nol, nonaktif, dan SKU tidak sesuai template bukan alasan hapus.
- Jangan hapus varian terakhir, seluruh produk, atau produk yang sumbernya
  hilang/tidak dapat diperiksa; laporkan untuk pemeriksaan manual.

## Arsitektur dan alur

Service cleanup terpisah mengorkestrasi pembacaan katalog, classifier murni,
preview persisten, submit, dan hasil. Gateway per akun menangani API dan
verifikasi. Komponen dialog bersama dipakai ShopeeStock dan TiktokStock.
Reuse helper penghapusan hanya setelah memastikan isolasi akun dan bahwa
helper tidak mengubah sumber atau Stock Master.

Preview menyimpan run ID, akun target, revision katalog, kandidat, dan alasan
blokir. Submit menerima run ID, revision, serta pilihan ID kandidat; server
menolak ID di luar preview atau akun berbeda. Otorisasi mengikuti endpoint
mutasi yang berlaku dan tidak boleh lebih longgar.

Sebelum mutasi, klaim run secara atomik dan kunci operasi per produk target.
Baca ulang sumber dan target; perubahan snapshot menolak kelompok produk
terkait dan meminta preview baru. Submit ulang tidak mengulang mutasi.

TikTok diproses per produk dengan daftar varian tersisa dibangun dari detail
remote terbaru. Semua atribut varian bukan target dipertahankan. Shopee Gita
menggunakan delete_model dengan kredensial Gita; setiap hasil diperiksa
sebelum melanjutkan penghapusan berikutnya dalam produk yang sama.

Verifikasi ulang memastikan ID target hilang dan seluruh ID bukan target
tetap ada. Respons API sukses saja tidak cukup. Jika hasil tidak pasti,
hentikan mutasi lanjutan pada produk tersebut; jangan retry otomatis.
Kegagalan satu produk tidak membatalkan hasil produk lain.

Cache dan listing hanya direkonsiliasi untuk akun/varian target yang sudah
terverifikasi terhapus. Kuantitas, ledger, dan identitas Shopee Agni pada
Stock Master tidak diubah. Jangan menghapus baris Stock Master.

Audit mencatat akun, run, identitas produk/varian, alasan, revision, dan hasil
tersensor. Jangan menyimpan token, signature, secret, atau header autentikasi.

## Pengujian dan publikasi

Gunakan API palsu untuk seluruh tes mutasi. Uji kandidat valid, hapus satuan
dan massal, isolasi akun, ID asing, snapshot berubah, submit ganda, sumber
gagal/tidak lengkap, SKU salah tetapi varian masih ada, rename ambigu,
varian terakhir, survivor preservation, dan kegagalan verifikasi.

Uji UI untuk cakupan seluruh katalog, konfirmasi, pergantian akun, status
hasil, serta pencegahan submit ganda. Jalankan PHPUnit dan tes frontend,
build Vite, lalu publish index dan aset ke backend/public. Verifikasi host
tanpa menjalankan penghapusan marketplace sungguhan.
