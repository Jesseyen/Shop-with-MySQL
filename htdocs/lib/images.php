<?php
/**
 * 圖片上傳（第六階段，第 29 節）
 * - 以實際圖片內容判斷格式（不信任副檔名）：JPG、PNG、WebP；單檔最大 5 MB
 * - 以 GD 重新編碼儲存，去除 EXIF 等元資料（含 GPS）
 * - 大圖最長邊 1200 px、縮圖 400 px；主機支援時存成 WebP
 * - 隨機檔名；uploads 目錄以 .htaccess 只允許圖片、禁止執行 PHP
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

const IMAGE_MAX_BYTES = 5242880;
const IMAGE_LARGE = 1200;
const IMAGE_THUMB = 400;
const IMAGE_PER_PRODUCT = 10;

function image_upload_error(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return '檔案超過主機允許的上傳大小。';
        case UPLOAD_ERR_PARTIAL: return '檔案只上傳了一部分，請重試。';
        case UPLOAD_ERR_NO_FILE: return '沒有選擇檔案。';
        default: return '上傳失敗（錯誤碼 ' . $code . '）。';
    }
}

/** 整理 $_FILES 多檔欄位為清單 */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['name'])) { return []; }
    if (!is_array($f['name'])) { return [$f]; }
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/** 讀取並驗證上傳圖片，回傳 GD 影像資源與是否有透明 */
function image_load_upload(array $file): array
{
    if (!function_exists('imagecreatetruecolor')) { throw new UserError('主機沒有 PHP GD 擴充，無法處理圖片。'); }
    if ($file['error'] !== UPLOAD_ERR_OK) { throw new UserError($file['name'] . '：' . image_upload_error((int)$file['error'])); }
    if ($file['size'] > IMAGE_MAX_BYTES) { throw new UserError($file['name'] . '：檔案超過 5 MB。'); }
    if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') { throw new UserError('上傳檔案不正確。'); }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng'];
    if (defined('IMAGETYPE_WEBP') && function_exists('imagecreatefromwebp')) { $types[IMAGETYPE_WEBP] = 'imagecreatefromwebp'; }
    if (!$info || !isset($types[$info[2]])) { throw new UserError($file['name'] . '：只接受 JPG、PNG、WebP 圖片（依實際內容判斷）。'); }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) { throw new UserError($file['name'] . '：圖片尺寸不正確或過大。'); }
    $im = @$types[$info[2]]($file['tmp_name']);
    if (!$im) { throw new UserError($file['name'] . '：無法讀取圖片內容。'); }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rot = ['3' => 180, '6' => -90, '8' => 90][(string)($exif['Orientation'] ?? '')] ?? 0;
        if ($rot) { $im = imagerotate($im, $rot, 0); }
    }
    return [$im, $info[2] !== IMAGETYPE_JPEG];
}

function image_resized($src, int $max)
{
    $w = imagesx($src);
    $hgt = imagesy($src);
    $scale = min(1, $max / max($w, $hgt));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($hgt * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hgt);
    return $dst;
}

/** 存檔（重新編碼，元資料不會保留），回傳檔名 */
function image_save($im, string $dir, bool $alpha, string $suffix): string
{
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $base = bin2hex(random_bytes(12)) . $suffix;
    if (function_exists('imagewebp')) {
        $name = $base . '.webp';
        $ok = imagewebp($im, $dir . $name, 82);
    } elseif ($alpha) {
        $name = $base . '.png';
        $ok = imagepng($im, $dir . $name, 6);
    } else {
        $bg = imagecreatetruecolor(imagesx($im), imagesy($im));
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
        $name = $base . '.jpg';
        $ok = imagejpeg($bg, $dir . $name, 85);
        imagedestroy($bg);
    }
    if (!$ok) { throw new UserError('圖片寫入失敗，請確認 uploads 目錄可寫入。'); }
    return $name;
}

/** 商品圖片：產生大圖與縮圖 */
function image_store_product(array $file): array
{
    [$im, $alpha] = image_load_upload($file);
    $dir = dirname(__DIR__) . '/' . PRODUCT_IMAGE_DIR;
    $large = image_resized($im, IMAGE_LARGE);
    $thumb = image_resized($im, IMAGE_THUMB);
    $r = ['file_large' => image_save($large, $dir, $alpha, ''), 'file_thumb' => image_save($thumb, $dir, $alpha, '_t'),
          'width' => imagesx($large), 'height' => imagesy($large), 'thumb_width' => imagesx($thumb), 'thumb_height' => imagesy($thumb)];
    imagedestroy($im);
    imagedestroy($large);
    imagedestroy($thumb);
    return $r;
}

/** 站台圖片（Logo、預設 OG 圖、分類 OG 圖）：最長邊 1200 px */
function image_store_site(array $file): string
{
    [$im, $alpha] = image_load_upload($file);
    $large = image_resized($im, IMAGE_LARGE);
    $name = image_save($large, dirname(__DIR__) . '/' . SITE_IMAGE_DIR, $alpha, '');
    imagedestroy($im);
    imagedestroy($large);
    return $name;
}

function image_delete_files(array $names, string $dir = PRODUCT_IMAGE_DIR): void
{
    foreach ($names as $n) {
        if (is_string($n) && preg_match('/^[a-f0-9]{24}(_t)?\.(webp|png|jpg)$/', $n)) {
            @unlink(dirname(__DIR__) . '/' . $dir . $n);
        }
    }
}
