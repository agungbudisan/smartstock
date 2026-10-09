<?php

/**
 * Bangkitkan favicon dari logo 1:1 di `public/brand/logo.png`.
 *
 * Dua berkas dihasilkan:
 *
 *   public/favicon.ico                    16 + 32 + 48 px dalam satu berkas
 *   public/brand/apple-touch-icon.png     180 x 180 px
 *
 * Kenapa `favicon.ico` harus dibangkitkan, bukan cukup diberi tag HTML:
 * berkas aslinya 0 byte. Peramban tetap memintanya di `/favicon.ico`,
 * menerima berkas kosong, lalu menampilkan ikon generik. Tag HTML hanya
 * menentukan dari mana ikon diambil, tidak memperbaiki isinya.
 *
 * Jalankan setelah menyalin logo 1:1 ke `public/brand/logo.png`:
 *
 *   php tools/bangun-favicon.php
 */
$root = dirname(__DIR__);
$brand = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'brand';
$sumber = $brand.DIRECTORY_SEPARATOR.'logo.png';

if (! is_file($sumber)) {
    fwrite(STDERR, "logo.png belum ada di public/brand/.\n");
    fwrite(STDERR, "Salin berkas logo 1:1 ke sana dengan nama persis 'logo.png', lalu jalankan ulang.\n");

    exit(1);
}

$asal = @imagecreatefromstring((string) file_get_contents($sumber));

if ($asal === false) {
    fwrite(STDERR, "logo.png tidak bisa dibaca sebagai gambar. Format PNG, JPG, atau WebP?\n");

    exit(1);
}

printf("Sumber: %d x %d px\n", imagesx($asal), imagesy($asal));

/**
 * Potong jadi persegi, lalu resize.
 *
 * Potong dulu, bukan resize langsung: aset yang tercatat 512 x 480 akan terlihat
 * seperti tepi kosong kalau hanya diperkecil, karena kelipihannya tidak wajib
 * simetris dan sisi terpanjang akan menggantung.
 */
function persegi(GdImage $asal, int $sisi): GdImage
{
    $lebar = imagesx($asal);
    $tinggi = imagesy($asal);
    $potongan = min($lebar, $tinggi);

    $x = (int) round(($lebar - $potongan) / 2);
    $y = (int) round(($tinggi - $potongan) / 2);

    $sumber = imagecreatetruecolor($potongan, $potongan);
    imagealphablending($sumber, false);
    imagesavealpha($sumber, true);
    imagecopy($sumber, $asal, 0, 0, $x, $y, $potongan, $potongan);

    $keluaran = imagecreatetruecolor($sisi, $sisi);
    imagealphablending($keluaran, false);
    imagesavealpha($keluaran, true);

    $tembus = imagecolorallocatealpha($keluaran, 0, 0, 0, 127);
    imagefilledrectangle($keluaran, 0, 0, $sisi, $sisi, $tembus);
    imagealphablending($keluaran, true);

    imagecopyresampled($keluaran, $sumber, 0, 0, 0, 0, $sisi, $sisi, $potongan, $potongan);
    imagedestroy($sumber);

    return $keluaran;
}

$ukuran = [16, 32, 48];
$ikons = [];

foreach ($ukuran as $sisi) {
    $gambar = persegi($asal, $sisi);

    ob_start();
    imagepng($gambar);
    $ikons[$sisi] = (string) ob_get_clean();

    imagedestroy($gambar);
}

/*
 * Tulis ICO sendiri.
 *
 * Susunan berkas: header 6 byte, satu entri 16 byte per gambar, lalu data PNG
 * apa adanya. Sejak Windows Vista, data di dalam ICO boleh berupa PNG, jadi
 * tidak perlu encoder BMP yang harus menangani masking dan palet sendiri.
 */
$jumlah = count($ikons);
$offset = 6 + ($jumlah * 16);

$ico = pack('vvv', 0, 1, $jumlah);

foreach ($ikons as $sisi => $data) {
    $ico .= pack(
        'CCCCvvVV',
        $sisi >= 256 ? 0 : $sisi,
        $sisi >= 256 ? 0 : $sisi,
        0,
        0,
        1,
        32,
        strlen($data),
        $offset
    );

    $offset += strlen($data);
}

foreach ($ikons as $data) {
    $ico .= $data;
}

$jalurIco = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'favicon.ico';
file_put_contents($jalurIco, $ico);

printf("  public/favicon.ico                %6d byte  (16/32/48)\n", strlen($ico));

/*
 * Ikon homescreen Apple tidak bisa menangani gambar transparan, dan hasilnya
 * kotak hitam di tema gelap. Jadi ikon diberi latar putih paksa.
 */
$sisiApple = 180;
$apple = imagecreatetruecolor($sisiApple, $sisiApple);
$putih = imagecolorallocate($apple, 255, 255, 255);
imagefilledrectangle($apple, 0, 0, $sisiApple, $sisiApple, $putih);

$potongan = min(imagesx($asal), imagesy($asal));
imagecopyresampled(
    $apple,
    $asal,
    0,
    0,
    (int) round((imagesx($asal) - $potongan) / 2),
    (int) round((imagesy($asal) - $potongan) / 2),
    $sisiApple,
    $sisiApple,
    $potongan,
    $potongan
);

$jalurApple = $brand.DIRECTORY_SEPARATOR.'apple-touch-icon.png';
imagepng($apple, $jalurApple, 9);
imagedestroy($apple);

printf("  public/brand/apple-touch-icon.png %6d byte  (180)\n", (int) filesize($jalurApple));
