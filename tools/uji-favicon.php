<?php

/**
 * Uji `tools/bangun-favicon.php` tanpa menyisakan berkas di proyek.
 *
 * Menguji skrip pembuat gambar hanya dengan "tidak error" tidak cukup. ICO
 * yang salah offsetnya tetap lolos tanpa peringatan dan peramban menolaknya
 * diam-diam, jadi header dan setiap entri dibaca ulang byte demi byte.
 *
 * Aset uji sengaja TRANSPARAN di sudut, supaya dua hal bisa dibedakan:
 *   - favicon.ico harus mempertahankan transparansi,
 *   - apple-touch-icon.png harus memaksa latar putih, karena iOS tidak bisa
 *     menangani gambar transparan dan hasilnya kotak hitam di tema gelap.
 *
 * Sumber juga sengaja 512 x 480, bukan persegi, supaya jalur pemotongan ikut
 * teruji. Yang dipotong selalu sisi terpanjang yang di tengah.
 */
$root = dirname(__DIR__);
$brand = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'brand';

if (! is_dir($brand)) {
    fwrite(STDERR, "public/brand/ belum ada\n");

    exit(1);
}

$logo = $brand.DIRECTORY_SEPARATOR.'logo.png';
$apple = $brand.DIRECTORY_SEPARATOR.'apple-touch-icon.png';
$icoPath = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'favicon.ico';

$sebelumIco = is_file($icoPath) ? (string) file_get_contents($icoPath) : null;

// Aset uji: 512 x 480, latar tembus pandang, lingkaran di tengah.
$g = imagecreatetruecolor(512, 480);
imagealphablending($g, false);
imagesavealpha($g, true);
$tembus = imagecolorallocatealpha($g, 0, 0, 0, 127);
imagefilledrectangle($g, 0, 0, 511, 479, $tembus);
imagealphablending($g, true);
imagefilledellipse($g, 256, 240, 300, 300, imagecolorallocate($g, 99, 102, 241));
imagepng($g, $logo);
imagedestroy($g);

echo "== jalankan skrip ==\n";
passthru('php '.escapeshellarg($root.'/tools/bangun-favicon.php'), $kode);

$gagal = $kode !== 0;

if (! $gagal) {
    echo "\n== struktur public/favicon.ico ==\n";

    $ico = (string) file_get_contents($icoPath);

    $reserved = unpack('v', substr($ico, 0, 2))[1];
    $tipe = unpack('v', substr($ico, 2, 2))[1];
    $jumlah = unpack('v', substr($ico, 4, 2))[1];

    printf("  reserved=%d (harus 0)   type=%d (harus 1)   jumlah=%d (harus 3)\n", $reserved, $tipe, $jumlah);
    $gagal = $gagal || $reserved !== 0 || $tipe !== 1 || $jumlah !== 3;

    $akhirEntri = 6 + ($jumlah * 16);

    for ($i = 0; $i < $jumlah; $i++) {
        $e = 6 + ($i * 16);

        // Susunan entri ICO: lebar(1) tinggi(1) warna(1) reserved(1)
        //                   planes(2) bitcount(2) panjang(4) offset(4)
        $w = ord($ico[$e]);
        $h = ord($ico[$e + 1]);
        $planes = unpack('v', substr($ico, $e + 4, 2))[1];
        $bitcount = unpack('v', substr($ico, $e + 6, 2))[1];
        $bytes = unpack('V', substr($ico, $e + 8, 4))[1];
        $offset = unpack('V', substr($ico, $e + 12, 4))[1];

        $tandaPng = substr($ico, $offset, 8) === "\x89PNG\r\n\x1a\n";
        $dalamBatas = $offset + $bytes <= strlen($ico);

        printf("  gambar %d: %2dx%-2d planes=%-2d bitcount=%-2d %5d byte  offset %5d  png=%s  dalamBatas=%s\n",
            $i + 1, $w, $h, $planes, $bitcount, $bytes, $offset,
            $tandaPng ? 'ya' : 'TIDAK',
            $dalamBatas ? 'ya' : 'TIDAK');

        $gagal = $gagal || ! $tandaPng || ! $dalamBatas || $planes !== 1 || $bitcount !== 32;

        $panjang[$i + 1] = $bytes;
        $mulai[$i + 1] = $offset;
    }

    // Entri pertama wajib menunjuk tepat sesudah seluruh blok entri.
    $mulai[1] === $akhirEntri || $gagal = true;
    printf("  entri pertama mulai di %d, blok entri berakhir di %d: %s\n",
        $mulai[1], $akhirEntri, $mulai[1] === $akhirEntri ? 'rapat' : 'TIDAK RAPAT');

    // Rantai offset harus rapat: setiap data dimulai tepat setelah data sebelumnya.
    $rapat = true;

    for ($i = 2; $i <= $jumlah; $i++) {
        $rapat = $rapat && $mulai[$i] === $mulai[$i - 1] + $panjang[$i - 1];
    }

    printf("  rantai offset rapat: %s\n", $rapat ? 'ya' : 'TIDAK');
    $gagal = $gagal || ! $rapat;

    echo "\n== transparansi ==\n";

    // Sisipkan kembali gambar 48 px untuk diperiksa lewat GD.
    $offset48 = $mulai[3];
    $png48 = imagecreatefromstring(substr($ico, $offset48, $panjang[3]));

    if ($png48 === false) {
        echo "  gambar 48 px tidak bisa dibaca\n";
        $gagal = true;
    } else {
        $rgb = imagecolorat($png48, 1, 1);
        $alpha = ($rgb >> 24) & 0x7F;
        printf("  favicon.ico sudut (1,1): alpha=%d (harus 127, tembus pandang)\n", $alpha);
        $gagal = $gagal || $alpha !== 127;

        $tengah = imagecolorat($png48, 24, 24);
        printf("  favicon.ico tengah      : %d,%d,%d (harus 99,102,241)\n",
            ($tengah >> 16) & 0xFF, ($tengah >> 8) & 0xFF, $tengah & 0xFF);
        $gagal = $gagal || (($tengah >> 16) & 0xFF) !== 99;

        imagedestroy($png48);
    }

    echo "\n== apple-touch-icon.png ==\n";

    $a = imagecreatefromstring((string) file_get_contents($apple));

    if ($a === false) {
        echo "  tidak bisa dibaca\n";
        $gagal = true;
    } else {
        printf("  ukuran %dx%d (harus 180x180)\n", imagesx($a), imagesy($a));
        $gagal = $gagal || imagesx($a) !== 180 || imagesy($a) !== 180;

        $sudut = imagecolorat($a, 1, 1);
        printf("  sudut (1,1) RGB = %d,%d,%d (harus 255,255,255)\n",
            ($sudut >> 16) & 0xFF, ($sudut >> 8) & 0xFF, $sudut & 0xFF);
        $gagal = $gagal || $sudut !== 0xFFFFFF;

        imagedestroy($a);
    }
}

// Kembalikan proyek ke keadaan semula.
@unlink($logo);
@unlink($apple);

if ($sebelumIco === null) {
    @unlink($icoPath);
} else {
    file_put_contents($icoPath, $sebelumIco);
}

echo "\n== hasil ==\n";
echo $gagal ? "ADA YANG GAGAL\n" : "SEMUA CEK LULUS\n";
echo "berkas uji sudah dibersihkan\n";

exit($gagal ? 1 : 0);
