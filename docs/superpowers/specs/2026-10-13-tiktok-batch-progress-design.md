# Informasi proses batch varian TikTok

Permintaan pengguna: tunjukkan secara detail dan berkala apakah batch sedang berjalan. Pembacaan awal GET menunjukkan status running pada tahap repair/scanning, tetapi respons batch menghilangkan progress anak yang sudah tersimpan.

Pilihan: cukup animasi (tidak memberi bukti progres), progress dan riwayat dari respons batch (dipilih), atau streaming server (tidak diperlukan). Gunakan respons serial yang sudah ada, tanpa membuat worker atau mutasi tambahan.

Tampilkan jumlah produk TikTok yang diperiksa beserta total bila diketahui, gambar yang diproses, progres SKU sumber, tahap/produk saat ini, waktu respons terakhir, durasi permintaan aktif, dan riwayat maksimal 30 perubahan bermakna selama halaman terbuka. Jam tampilan diperbarui tiap detik; riwayat hanya bertambah setelah bukti respons baru, bukan dari timer. Bedakan status tersimpan dengan eksekusi lokal aktif. Permintaan lama diberi keterangan menunggu, bukan klaim gagal atau sukses.

Backend hanya mengekspos angka progress yang diizinkan dan timestamp tersimpan untuk identitas anak/sumber yang cocok. GET tetap lokal dan tidak mengubah data. Jangan expose cursor, payload, transport errors atau credential. Tidak ada perubahan aturan SKU, penghapusan, penambahan, replay, pause, resume, request key atau verifikasi. Tidak menjalankan batch nyata untuk pengujian. Pertahankan perubahan sebelumnya, aset publik dan .htaccess. Tidak commit/push/merge.
