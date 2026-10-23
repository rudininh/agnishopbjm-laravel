# Ambil katalog tujuan dari tampilan Agni

Permintaan pengguna: saat memilih Shopee Agni di sinkronisasi stok, sediakan Ambil Produk TikTok dan Ambil Produk Gitashop agar tidak perlu berpindah toko.

Gunakan dua tombol di sebelah Ambil Produk pada toolbar Agni, dengan endpoint pengambilan yang sudah digunakan masing-masing toko. Alternatif mengganti pilihan toko otomatis akan mengganggu tampilan; tombol langsung dipilih karena sesuai permintaan. TikTok memakai tiktokItems(true,{account_key:tiktok-agnishopbjm}); Gitashop memakai shopeeItems(true,{account_key:shopee-gitacollectionbjm}). Setelah hasil ok/partial, baca ulang cache Agni dengan sync=false untuk memperbarui indikator tanpa mengganti pilihan toko atau filter.

Tombol hanya muncul pada unified Shopee Agni. Gunakan busy dan inert yang sudah ada untuk menserialisasi pengambilan dengan mirror, batch, dan aksi produk. Tampilkan tujuan yang sedang diambil dan hasil sukses/sebagian/gagal tanpa membocorkan error transport. Data tujuan tidak dimasukkan ke tabel Agni. Kesalahan pembacaan ulang cache tidak boleh diklaim sebagai indikator telah diperbarui.

Tidak mengubah backend, memulai batch, menulis SKU/stok marketplace, atau melakukan commit/push/merge. Pengujian memakai API palsu; publikasi mempertahankan .htaccess dan seluruh aset lama.
