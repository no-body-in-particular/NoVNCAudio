<?php
// File share for the admin home directory, styled after Dolphin.
//
// Runs as the admin user via hiawatha's cgi-wrapper, so it reaches $HOME with
// the same rights a shell would. Access control is the TLS client certificate
// demanded by the binding this vhost is tied to: by the time a request arrives
// here hiawatha has already verified it against the VNC client CA. There is no
// password on purpose - the certificate is the credential.
//
// Uploads are chunked. A 30GB file cannot be sent as one POST: hiawatha buffers
// request bodies, and its PUT path is capped at 2047MB. So the browser slices
// the file and each chunk is appended to a .part file next to the destination,
// which is renamed into place when the last chunk lands. Total size is bounded
// by free disk, not by any request limit.

declare(strict_types=1);

// The CGI process inherits umask 0117, which masks the execute bit off every
// mkdir - directories came out drw-r----- and nothing could be created inside
// them - and left uploads as rw-rw----. Set the ordinary user umask so folders
// are 0755 and files 0644, the same as anything created from a shell.
umask(0022);

const ROOT  = '/home/admin';
const CHUNK = 32 * 1024 * 1024;   // must stay under post_max_size in .user.ini
const THUMB = 240;                // thumbnail width in px
const CACHE = ROOT . '/.cache/share-thumbs';
const MTLS_PORT = '8443';

// This file lives inside the public website root, so it is reachable on 443 as
// well - but only the 8443 binding carries RequiredCA, i.e. only there has
// hiawatha verified a client certificate. Refuse to do anything on any other
// port and point the browser at the right one. Hiawatha exposes no SSL_CLIENT_*
// variables to CGI, so the port is the signal available here; the actual
// enforcement is RequiredCA on the binding, this is the belt to that braces.
if (($_SERVER['SERVER_PORT'] ?? '') !== MTLS_PORT) {
    $host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'coredump.ws'))[0];
    $to   = 'https://' . $host . ':' . MTLS_PORT . '/';
    header('Location: ' . $to, true, 302);
    header('Content-Type: text/html; charset=utf-8');
    $e = htmlspecialchars($to, ENT_QUOTES);
    exit('<!doctype html><meta charset="utf-8"><title>Share</title>'
       . '<body style="font:15px system-ui;background:#31363b;color:#eff0f1;padding:44px">'
       . '<p>The file share needs your client certificate, which is required on its own port.</p>'
       . '<p><a style="color:#16a085" href="' . $e . '">' . $e . '</a></p>');
}

function resolve(string $rel): ?string {
    $path = realpath(ROOT . '/' . $rel);
    if ($path === false) { return null; }
    if ($path !== ROOT && strpos($path, ROOT . '/') !== 0) { return null; }
    return $path;
}
function rel_of(string $abs): string { return ltrim(substr($abs, strlen(ROOT)), '/'); }
function human(float $n): string {
    $u = ['B','KiB','MiB','GiB','TiB']; $i = 0;
    while ($n >= 1024 && $i < 4) { $n /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$n : number_format($n, 1)) . ' ' . $u[$i];
}
function fail(int $code, string $msg): never { http_response_code($code); header('Content-Type: text/plain'); exit($msg); }

// Rough file-type classification, as Dolphin shows in its Type column.
function kind(string $name): array {
    $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'image' => ['png','jpg','jpeg','gif','webp','svg','bmp','ico','tif','tiff','heic'],
        'audio' => ['mp3','flac','ogg','oga','wav','m4a','aac','opus','mid'],
        'video' => ['mp4','mkv','avi','mov','webm','wmv','m4v','mpg','mpeg'],
        'archive' => ['zip','tar','gz','bz2','xz','7z','rar','zst','tgz','iso','deb','rpm','pkg'],
        'code' => ['c','h','cpp','hpp','py','js','ts','php','sh','bash','rb','go','rs','java','pl','lua','sql','json','xml','yml','yaml','html','css','ini','conf','toml'],
        'document' => ['pdf','doc','docx','odt','xls','xlsx','ods','ppt','pptx','odp','epub'],
        'text' => ['txt','md','log','csv','nfo','rst'],
    ];
    foreach ($map as $k => $exts) { if (in_array($e, $exts, true)) { return [$k, $e === '' ? ucfirst($k) : strtoupper($e) . ' ' . $k]; } }
    return ['file', $e === '' ? 'File' : strtoupper($e) . ' file'];
}

$dirRel = isset($_GET['d']) ? (string)$_GET['d'] : '';
$dir    = resolve($dirRel);
if ($dir === null || !is_dir($dir)) { $dir = ROOT; }
$dirRel = rel_of($dir);

// ---- chunked upload endpoint ---------------------------------------------
if (isset($_GET['chunk'])) {
    header('Content-Type: text/plain');
    $name   = basename((string)($_SERVER['HTTP_X_FILE_NAME'] ?? ''));
    $offset = (int)($_SERVER['HTTP_X_FILE_OFFSET'] ?? -1);
    $total  = (int)($_SERVER['HTTP_X_FILE_TOTAL']  ?? -1);
    if ($name === '' || $name === '.' || $name === '..') { fail(400, 'bad name'); }
    if ($offset < 0 || $total < 0)                       { fail(400, 'bad offset/total'); }
    if (!is_writable($dir))                              { fail(403, 'directory not writable'); }

    $dest = $dir . '/' . $name;
    $part = $dest . '.part';
    $body = fopen('php://input', 'rb');
    if (!$body) { fail(500, 'no body'); }
    $out = fopen($part, $offset === 0 ? 'wb' : 'cb');   // 'cb' preserves earlier chunks
    if (!$out) { fclose($body); fail(500, 'cannot open target'); }
    if (!flock($out, LOCK_EX)) { fclose($body); fclose($out); fail(500, 'cannot lock'); }
    fseek($out, $offset);
    while (!feof($body)) {
        $buf = fread($body, 1024 * 1024);
        if ($buf === false || $buf === '') { break; }
        if (fwrite($out, $buf) === false) { flock($out, LOCK_UN); fclose($out); fclose($body); fail(500, 'write failed'); }
    }
    fflush($out); flock($out, LOCK_UN);
    $size = ftell($out);
    fclose($out); fclose($body);
    if ($size >= $total) {
        if (!rename($part, $dest)) { fail(500, 'cannot finalise'); }
        exit('done ' . $size);
    }
    exit('ok ' . $size);
}

// ---- download -------------------------------------------------------------
if (isset($_GET['f'])) {
    $file = resolve((string)$_GET['f']);
    if ($file === null || !is_file($file) || !is_readable($file)) { fail(404, 'Not found or not readable'); }
    while (ob_get_level()) { ob_end_clean(); }   // never buffer a large file in memory
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', basename($file)) . '"');
    header('X-Content-Type-Options: nosniff');
    $fh = fopen($file, 'rb');
    while (!feof($fh)) { echo fread($fh, 1024 * 1024); flush(); }
    fclose($fh);
    exit;
}


// ---- download a whole folder as a zip ---------------------------------------
// Three steps, because the transfer cannot go through the CGI at all.
//
// Streaming zip's output straight to the client was the obvious approach and it
// does produce a correct archive, but every attempt died between 22MB and 29MB
// of a 2.5GB folder - six times in the access log, HTTP 200 each time, no error
// logged. Building to a temp file first fixed the length but broke it worse:
// the wrapped CGI is killed if it stays silent early on, so hiawatha dropped
// the request after twelve seconds and logged "no output" while zip carried on
// in the background.
//
// So the CGI now only starts the work and reports on it. zip runs detached,
// writing into _zips/ inside the website root, and the finished archive is
// fetched as an ordinary static file - served by hiawatha itself, with a
// Content-Length, a real progress bar, resume support, and no CGI process whose
// lifetime can cut it short. _zips/ is inside the certificate-protected vhost,
// so it is no more reachable than the rest of the share.
const ZIPDIR = __DIR__ . '/_zips';

function zip_sweep(): void {
    // A job nobody collected, or one whose browser went away mid-build.
    foreach (glob(ZIPDIR . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (filemtime($d) < time() - 7200) {
            foreach (glob($d . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($d);
        }
    }
}

if (isset($_GET['zipjob'])) {
    header('Content-Type: application/json');
    $dir = resolve((string)$_GET['zipjob']);
    if ($dir === null || !is_dir($dir) || !is_readable($dir)) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
    if (!is_dir(ZIPDIR)) { @mkdir(ZIPDIR, 0755, true); }
    zip_sweep();

    // The browser supplies the id rather than being told it. exec() does not
    // return here - the wrapped CGI is killed while still inside it, six
    // seconds in, every time - so this request cannot be relied on to reply.
    // It does not need to: the work is already detached and running by then,
    // and ?zipstatus= finds it by the id the browser already holds. The 500
    // that hiawatha reports for this request is expected and harmless.
    $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
    if (strlen($id) !== 16) { echo json_encode(['ok'=>false,'error'=>'bad id']); exit; }
    $name = ($dir === ROOT) ? 'home' : basename($dir);
    // Keep the archive in its own directory so the file itself can carry the
    // folder's name - that is what the browser saves it as, there being no
    // Content-Disposition on a static file.
    $safe = preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $name);
    if ($safe === '' || $safe === null) { $safe = 'folder'; }
    $jobdir = ZIPDIR . '/' . $id;
    if (!@mkdir($jobdir, 0755, true)) { echo json_encode(['ok'=>false,'error'=>'cannot create job']); exit; }

    // Written before zip starts: ?zipstatus= needs it to build the download
    // URL, and this request will not survive to report anything itself.
    @file_put_contents($jobdir . '/name', $safe . '.zip');
    $out  = $jobdir . '/' . $safe . '.zip';
    $part = $out . '.part';

    $stored = '.mp3:.m4a:.aac:.ogg:.oga:.opus:.flac:.wma:.mp4:.m4v:.mkv:.avi:.mov'
            . ':.webm:.wmv:.jpg:.jpeg:.png:.gif:.webp:.heic:.avif:.tif:.tiff'
            . ':.zip:.gz:.bz2:.xz:.zst:.7z:.rar:.jar:.apk:.iso:.pdf:.docx:.xlsx:.pptx';

    // -1 is the fastest compression rather than the default 6, and -n stores
    // what is already compressed instead of deflating it again: 43.3s -> 4.6s
    // on a 1.3GB music folder, for 1.9% more bytes. -y keeps symlinks as
    // symlinks rather than copying whatever they point at into the archive.
    //
    // Written to .part and renamed on success, so the presence of the final
    // name is itself the "finished" signal and a half-built archive can never
    // be downloaded. Detached with setsid so it outlives this request.
    // All three redirections matter, not just stdout. exec() reads the child's
    // pipe until every writer closes it, so a background job still holding
    // stdin keeps the call blocked - which is exactly what happened: the trace
    // reached "about to exec" and stopped, and the CGI was killed six seconds
    // later still waiting for a 15-second zip. With stdin, stdout and stderr
    // all detached the call returns immediately and the request finishes in
    // milliseconds, so no CGI time limit applies to it at all.
    $cmd = sprintf(
        'cd %s && setsid sh -c %s </dev/null >/dev/null 2>&1 &',
        escapeshellarg(dirname($dir)),
        escapeshellarg(sprintf(
            '/usr/bin/zip -r -1 -q -y -n %s %s %s && mv %s %s && chmod 644 %s',
            escapeshellarg($stored), escapeshellarg($part), escapeshellarg(basename($dir)),
            escapeshellarg($part), escapeshellarg($out), escapeshellarg($out)
        ))
    );
    @exec($cmd);
    echo json_encode(['ok'=>true, 'id'=>$id, 'name'=>$safe . '.zip',
                      'url'=>'_zips/' . $id . '/' . rawurlencode($safe . '.zip')]);
    exit;
}

if (isset($_GET['zipstatus'])) {
    header('Content-Type: application/json');
    $id = preg_replace('/[^a-f0-9]/', '', (string)$_GET['zipstatus']);
    $jobdir = ZIPDIR . '/' . $id;
    if ($id === '' || !is_dir($jobdir)) { echo json_encode(['ok'=>false,'error'=>'unknown job']); exit; }
    $name = trim((string)@file_get_contents($jobdir . '/name'));
    $url  = $name === '' ? null : '_zips/' . $id . '/' . rawurlencode($name);
    $done = glob($jobdir . '/*.zip') ?: [];
    $part = glob($jobdir . '/*.zip.part') ?: [];
    if ($done) { echo json_encode(['ok'=>true,'done'=>true,'bytes'=>filesize($done[0]),'url'=>$url,'name'=>$name]); exit; }
    if ($part) { clearstatcache(); echo json_encode(['ok'=>true,'done'=>false,'bytes'=>filesize($part[0])]); exit; }
    // Neither: zip has not created the file yet, or it died before doing so.
    echo json_encode(['ok'=>true,'done'=>false,'bytes'=>0,
                      'stalled'=>(filemtime($jobdir) < time() - 60)]);
    exit;
}


function mime_of(string $name): string {
    static $m = [
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
        'webp'=>'image/webp','svg'=>'image/svg+xml','bmp'=>'image/bmp','ico'=>'image/x-icon',
        'tif'=>'image/tiff','tiff'=>'image/tiff',
        'mp4'=>'video/mp4','m4v'=>'video/mp4','webm'=>'video/webm','mkv'=>'video/x-matroska',
        'mov'=>'video/quicktime','avi'=>'video/x-msvideo','ogv'=>'video/ogg',
        'mp3'=>'audio/mpeg','flac'=>'audio/flac','ogg'=>'audio/ogg','oga'=>'audio/ogg',
        'wav'=>'audio/wav','m4a'=>'audio/mp4','opus'=>'audio/ogg','aac'=>'audio/aac',
        'pdf'=>'application/pdf','txt'=>'text/plain','md'=>'text/plain','log'=>'text/plain',
    ];
    return $m[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

// ---- inline view, used by <img>/<video> in the preview --------------------
// Range support matters for video: without it a browser cannot seek, and some
// refuse to begin playback at all.
if (isset($_GET['view'])) {
    $file = resolve((string)$_GET['view']);
    if ($file === null || !is_file($file) || !is_readable($file)) { fail(404, 'not found'); }
    $size = (int)filesize($file);
    while (ob_get_level()) { ob_end_clean(); }
    $start = 0; $end = $size - 1; $partial = false;
    if (preg_match('/bytes=(\d*)-(\d*)/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $mm)) {
        if ($mm[1] !== '') { $start = (int)$mm[1]; }
        if ($mm[2] !== '') { $end = (int)$mm[2]; }
        if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
        $end = min($end, $size - 1); $partial = true;
    }
    $len = $end - $start + 1;
    if ($partial) { http_response_code(206); header("Content-Range: bytes $start-$end/$size"); }
    header('Content-Type: ' . mime_of($file));
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $len);
    header('Content-Disposition: inline; filename="' . str_replace(['"',"\r","\n"], '', basename($file)) . '"');
    header('X-Content-Type-Options: nosniff');
    $fh = fopen($file, 'rb'); fseek($fh, $start); $left = $len;
    while ($left > 0 && !feof($fh)) {
        $b = fread($fh, (int)min(1048576, $left));
        if ($b === '' || $b === false) { break; }
        echo $b; $left -= strlen($b); flush();
    }
    fclose($fh); exit;
}

// ---- thumbnail ------------------------------------------------------------
// Cached under ~/.cache/share-thumbs, keyed on path+mtime+size, so a changed
// file regenerates and an unchanged one is never re-decoded.
if (isset($_GET['thumb'])) {
    $file = resolve((string)$_GET['thumb']);
    if ($file === null || !is_file($file) || !is_readable($file)) { fail(404, 'not found'); }
    [$k, ] = kind(basename($file));
    if ($k !== 'image' && $k !== 'video') { fail(404, 'no preview'); }
    // chmod explicitly: the CGI process runs with a umask that strips the
    // execute bit from mkdir's mode, leaving drw------- - which is writable by
    // the permission bits but cannot have entries created in it without +x.
    if (!is_dir(CACHE)) { @mkdir(CACHE, 0700, true); @chmod(CACHE, 0700); }
    $cached = CACHE . '/' . sha1($file . '|' . filemtime($file) . '|' . filesize($file) . '|' . THUMB) . '.jpg';

    if (!is_file($cached) || filesize($cached) === 0) {
        $ok = false;
        if ($k === 'image') {
            $info = @getimagesize($file);
            // guard: decoding a huge image would exhaust memory_limit
            if ($info && (int)$info[0] * (int)$info[1] <= 60000000) {
                $src = match ($info[2]) {
                    IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
                    IMAGETYPE_PNG  => @imagecreatefrompng($file),
                    IMAGETYPE_GIF  => @imagecreatefromgif($file),
                    IMAGETYPE_WEBP => @imagecreatefromwebp($file),
                    IMAGETYPE_BMP  => @imagecreatefrombmp($file),
                    default        => false,
                };
                if ($src) {
                    $t = imagescale($src, THUMB, -1, IMG_BILINEAR_FIXED);
                    if ($t) {
                        // flatten onto the view background so transparency does not go black
                        $flat = imagecreatetruecolor(imagesx($t), imagesy($t));
                        imagefill($flat, 0, 0, imagecolorallocate($flat, 35, 38, 41));
                        imagecopy($flat, $t, 0, 0, 0, 0, imagesx($t), imagesy($t));
                        $ok = imagejpeg($flat, $cached, 82);
                        imagedestroy($flat); imagedestroy($t);
                    }
                    imagedestroy($src);
                }
            }
        } else {
            // a frame a few seconds in, so we avoid a black opening frame
            $mk = fn(string $seek) => sprintf(
                'ffmpeg -nostdin -loglevel error %s -i %s -frames:v 1 -vf scale=%d:-1 -q:v 4 -y %s 2>/dev/null',
                $seek, escapeshellarg($file), THUMB, escapeshellarg($cached));
            @exec($mk('-ss 3'), $out1, $rc1);
            if (!is_file($cached) || filesize($cached) === 0) { @exec($mk(''), $out2, $rc2); }  // very short clip
            $ok = is_file($cached) && filesize($cached) > 0;
        }
        if (!$ok) { @unlink($cached); fail(404, 'no preview'); }
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($cached));
    header('Cache-Control: private, max-age=86400');
    readfile($cached);
    exit;
}


// ---- file operations ------------------------------------------------------
// Everything is resolved through resolve() first, so a path that escapes the
// home directory is rejected before any of this runs.
function rrmdir(string $path): bool {
    if (is_link($path) || is_file($path)) { return @unlink($path); }
    foreach (array_diff(scandir($path) ?: [], ['.','..']) as $e) { rrmdir($path . '/' . $e); }
    return @rmdir($path);
}
function rcopy(string $src, string $dst): bool {
    if (is_dir($src)) {
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) { return false; }
        foreach (array_diff(scandir($src) ?: [], ['.','..']) as $e) {
            if (!rcopy($src . '/' . $e, $dst . '/' . $e)) { return false; }
        }
        return true;
    }
    return @copy($src, $dst);
}
// A free name in $dir based on $name: "file.txt" -> "file (1).txt".
function unique_in(string $dir, string $name): string {
    if (!file_exists($dir . '/' . $name)) { return $name; }
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext === '' ? $name : substr($name, 0, -(strlen($ext) + 1));
    for ($i = 1; $i < 10000; $i++) {
        $try = $base . ' (' . $i . ')' . ($ext === '' ? '' : '.' . $ext);
        if (!file_exists($dir . '/' . $try)) { return $try; }
    }
    return $base . '-' . bin2hex(random_bytes(4)) . ($ext === '' ? '' : '.' . $ext);
}

if (isset($_GET['op'])) {
    header('Content-Type: application/json');
    $in     = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $paths  = (array)($in['paths'] ?? []);
    $done = 0; $errors = [];

    // resolve the sources once; refuse the home directory itself
    $srcs = [];
    foreach ($paths as $rel) {
        $abs = resolve((string)$rel);
        if ($abs === null || $abs === ROOT) { $errors[] = (string)$rel . ': invalid path'; continue; }
        $srcs[] = $abs;
    }

    $destDir = null;
    if (in_array($action, ['move','copy'], true)) {
        $destDir = resolve((string)($in['dest'] ?? ''));
        if ($destDir === null || !is_dir($destDir)) { echo json_encode(['ok'=>false,'error'=>'bad destination']); exit; }
    }

    switch ($action) {
        case 'trash':
            // freedesktop.org trash, so these show up in the real Dolphin's Trash
            $tf = ROOT . '/.local/share/Trash/files';
            $ti = ROOT . '/.local/share/Trash/info';
            if ((!is_dir($tf) && !@mkdir($tf, 0700, true)) || (!is_dir($ti) && !@mkdir($ti, 0700, true))) {
                echo json_encode(['ok'=>false,'error'=>'cannot create trash directory']); exit;
            }
            foreach ($srcs as $abs) {
                $name = unique_in($tf, basename($abs));
                if (@rename($abs, $tf . '/' . $name)) {
                    @file_put_contents($ti . '/' . $name . '.trashinfo',
                        "[Trash Info]\nPath=" . str_replace('%2F', '/', rawurlencode($abs)) .
                        "\nDeletionDate=" . date('Y-m-d\TH:i:s') . "\n");
                    $done++;
                } else { $errors[] = basename($abs) . ': could not move to trash'; }
            }
            break;

        case 'delete':
            foreach ($srcs as $abs) {
                if (rrmdir($abs)) { $done++; } else { $errors[] = basename($abs) . ': could not delete'; }
            }
            break;

        case 'move':
            foreach ($srcs as $abs) {
                if (is_dir($abs) && strpos($destDir . '/', $abs . '/') === 0) { $errors[] = basename($abs) . ': cannot move into itself'; continue; }
                $target = $destDir . '/' . unique_in($destDir, basename($abs));
                if (@rename($abs, $target)) { $done++; }
                elseif (rcopy($abs, $target) && rrmdir($abs)) { $done++; }   // cross-device fallback
                else { $errors[] = basename($abs) . ': could not move'; }
            }
            break;

        case 'copy':
            foreach ($srcs as $abs) {
                if (is_dir($abs) && strpos($destDir . '/', $abs . '/') === 0) { $errors[] = basename($abs) . ': cannot copy into itself'; continue; }
                $target = $destDir . '/' . unique_in($destDir, basename($abs));
                if (rcopy($abs, $target)) { $done++; } else { $errors[] = basename($abs) . ': could not copy'; }
            }
            break;

        case 'rename':
            $new = basename((string)($in['name'] ?? ''));
            if ($new === '' || $new === '.' || $new === '..') { echo json_encode(['ok'=>false,'error'=>'bad name']); exit; }
            if (count($srcs) !== 1) { echo json_encode(['ok'=>false,'error'=>'rename takes one item']); exit; }
            $abs = $srcs[0];
            if (file_exists(dirname($abs) . '/' . $new)) { echo json_encode(['ok'=>false,'error'=>'a file with that name already exists']); exit; }
            if (@rename($abs, dirname($abs) . '/' . $new)) { $done++; } else { $errors[] = 'could not rename'; }
            break;

        case 'mkdir':
            $new = basename((string)($in['name'] ?? ''));
            $where = resolve((string)($in['dest'] ?? ''));
            if ($new === '' || $new === '.' || $new === '..') { echo json_encode(['ok'=>false,'error'=>'bad name']); exit; }
            if ($where === null || !is_dir($where)) { echo json_encode(['ok'=>false,'error'=>'bad destination']); exit; }
            if (@mkdir($where . '/' . $new, 0755)) { $done++; } else { $errors[] = 'could not create folder'; }
            break;

        default:
            echo json_encode(['ok'=>false,'error'=>'unknown action']); exit;
    }
    echo json_encode(['ok' => $errors === [], 'done' => $done, 'error' => implode('; ', $errors)]);
    exit;
}

// ---- listing --------------------------------------------------------------
$sort = $_GET['s'] ?? 'name';
$ord  = ($_GET['o'] ?? 'a') === 'd' ? 'd' : 'a';
$dirs = []; $files = [];
if ($dh = @opendir($dir)) {
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $dir . '/' . $e;
        $row = ['name' => $e, 'size' => is_readable($p) ? (float)@filesize($p) : 0.0, 'date' => (int)@filemtime($p)];
        if (is_dir($p)) { $dirs[] = $row; } else { $files[] = $row; }
    }
    closedir($dh);
}
$cmp = function(array $a, array $b) use ($sort, $ord): int {
    $r = match ($sort) {
        'size' => $a['size'] <=> $b['size'],
        'date' => $a['date'] <=> $b['date'],
        default => strnatcasecmp($a['name'], $b['name']),
    };
    return $ord === 'd' ? -$r : $r;
};
usort($dirs, $cmp); usort($files, $cmp);

$parent = ($dir === ROOT) ? null : rel_of(dirname($dir));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$link = fn(string $rel) => '?d=' . urlencode($rel) . '&s=' . urlencode($sort) . '&o=' . $ord;
$free = (float)(disk_free_space($dir) ?: 0);

// Places, as Dolphin's sidebar - only those that actually exist
$places = [];
foreach ([['', 'Home', 'home'], ['Desktop','Desktop','desktop'], ['Documents','Documents','doc'],
          ['Downloads','Downloads','down'], ['Music','Music','audio'], ['Pictures','Pictures','image'],
          ['Videos','Videos','video'], ['Public','Public','folder'], ['GitHub','GitHub','code']] as [$rel,$label,$ic]) {
    if (is_dir(ROOT . '/' . $rel)) { $places[] = [$rel, $label, $ic]; }
}
// breadcrumb segments
$crumbs = []; $acc = '';
foreach (array_filter(explode('/', $dirRel)) as $seg) { $acc = ($acc === '' ? $seg : $acc . '/' . $seg); $crumbs[] = [$seg, $acc]; }
// Allow only the VNC page to frame this, so it can be shown as an overlay there
// without letting any other site frame it (clickjacking).
$selfHost = explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'coredump.ws'))[0];
header("Content-Security-Policy: frame-ancestors 'self' https://" . $selfHost);

$sortLink = fn(string $col) => '?d=' . urlencode($dirRel) . '&s=' . $col . '&o=' . ($sort === $col && $ord === 'a' ? 'd' : 'a');
$arrow = fn(string $col) => $sort === $col ? ($ord === 'a' ? ' ▲' : ' ▼') : '';
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($dirRel === '' ? 'admin' : basename($dirRel)) ?> — Dolphin</title>
<style>
/* Breeze Dark / Manjaro Maia palette */
:root{
  --win:#31363b; --view:#232629; --side:#2b3034; --head:#31363b;
  --fg:#eff0f1; --dim:#9aa2a8; --line:#4d5257; --hover:#2e3439;
  --sel:#16a085; --sel-dim:#127d68; --blue:#3daee9; --danger:#da4453;
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;background:var(--win);color:var(--fg);
     font:13px/1.45 "Noto Sans","DejaVu Sans",Cantarell,-apple-system,BlinkMacSystemFont,sans-serif;
     display:flex;flex-direction:column;overflow:hidden;-webkit-user-select:none;user-select:none}

.toolbar{display:flex;align-items:center;gap:4px;padding:5px 8px;background:var(--head);
         border-bottom:1px solid var(--line);flex:none}
.tb{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:28px;padding:0 8px;
    min-width:28px;border-radius:4px;color:var(--fg);text-decoration:none;flex:none;background:none;
    border:0;font:inherit;cursor:pointer;white-space:nowrap}
.tb:hover:not(:disabled):not([aria-disabled=true]){background:var(--hover)}
.tb:disabled,.tb[aria-disabled=true]{opacity:.35;pointer-events:none}
.tb.danger:hover{background:var(--danger);color:#fff}
.sepr{width:1px;height:20px;background:var(--line);margin:0 3px;flex:none}
.crumbs{flex:1;display:flex;align-items:center;gap:2px;background:var(--view);border:1px solid var(--line);
        border-radius:4px;padding:4px 8px;margin:0 4px;overflow:hidden;white-space:nowrap;min-width:0}
.crumbs a{color:var(--fg);text-decoration:none;padding:1px 5px;border-radius:3px}
.crumbs a:hover{background:var(--hover);color:var(--sel)}
.crumbs .sep{color:var(--dim)}.crumbs .cur{color:var(--dim);padding:1px 5px}

.main{flex:1;display:flex;min-height:0}
.side{width:190px;flex:none;background:var(--side);border-right:1px solid var(--line);overflow-y:auto;padding:8px 0}
.side h2{font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);margin:4px 0 6px;padding:0 12px;font-weight:700}
.place{display:flex;align-items:center;gap:8px;padding:5px 12px;color:var(--fg);text-decoration:none}
.place:hover{background:var(--hover)}
.place.on{background:var(--sel);color:#fff}.place.on svg{fill:#fff}

.view{flex:1;background:var(--view);overflow:auto;min-width:0;position:relative}
table{width:100%;border-collapse:collapse}
thead th{position:sticky;top:0;background:var(--head);text-align:left;font-weight:600;font-size:12px;
         color:var(--dim);padding:6px 10px;border-bottom:1px solid var(--line);white-space:nowrap;z-index:2}
thead th a{color:inherit;text-decoration:none}thead th a:hover{color:var(--fg)}
tbody td{padding:3px 10px;border-bottom:1px solid rgba(255,255,255,.03);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
tbody tr{cursor:default}
tbody tr:hover{background:var(--hover)}
tbody tr.sel{background:var(--sel);color:#fff}
tbody tr.sel td{color:#fff}
tbody tr.cut{opacity:.45}
td.name{max-width:1px;width:55%}
td.name .row{display:flex;align-items:center;gap:9px;overflow:hidden}
td.name span.n{overflow:hidden;text-overflow:ellipsis}
td.size{text-align:right;color:var(--dim);font-variant-numeric:tabular-nums;width:110px}
td.date,td.type{color:var(--dim);width:165px}
tr:hover td.size,tr:hover td.date,tr:hover td.type,tr.sel td.size,tr.sel td.date,tr.sel td.type{color:inherit}
svg.ic{width:16px;height:16px;flex:none}
img.thumb{width:32px;height:24px;object-fit:cover;border-radius:2px;flex:none;background:#1b1e20}
.folder{fill:#3daee9}.f-image{fill:#c9a227}.f-audio{fill:#b76ecf}.f-video{fill:#e07a5f}
.f-archive{fill:#c0894a}.f-code{fill:#16a085}.f-document{fill:#e05c5c}.f-text{fill:#8fa1ad}.f-file{fill:#7f8c8d}
.empty{padding:26px 12px;color:var(--dim)}

/* drag-and-drop straight onto the folder view - no upload dock */
.dropover{position:absolute;inset:0;z-index:5;display:none;align-items:center;justify-content:center;
          background:rgba(22,160,133,.14);border:2px dashed var(--sel);pointer-events:none}
.dropover.on{display:flex}
.dropover div{background:var(--win);border:1px solid var(--sel);border-radius:6px;padding:12px 20px;
              color:var(--fg);box-shadow:0 8px 26px rgba(0,0,0,.5)}
tbody tr.droptarget{outline:2px solid var(--sel);outline-offset:-2px;background:rgba(22,160,133,.18)}
/* Zipping a folder happens before any download starts, and the browser shows
   nothing at all during it - so without this the UI looks idle for however long
   the archive takes, which on a 2.5GB folder is long enough to assume it has
   failed. The status line alone was too easy to miss. */
#zipbox{position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:60;display:none;
        align-items:center;gap:10px;padding:10px 16px;max-width:92vw;
        background:var(--head);color:var(--fg);border:1px solid var(--line);border-radius:8px;
        box-shadow:0 10px 30px rgba(0,0,0,.45);font-size:13px}
#zipbox.on{display:flex}
#zipbox .spin{flex:none;width:14px;height:14px;border:2px solid var(--line);
              border-top-color:var(--sel);border-radius:50%;animation:zspin .8s linear infinite}
#zipbox.done .spin{animation:none;border-color:var(--sel)}
@keyframes zspin{to{transform:rotate(360deg)}}
#zipbox .nm{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:40vw}
#prog{display:none;align-items:center;gap:8px;flex:1;justify-content:center}
#prog.on{display:flex}
#prog progress{width:150px;height:6px}
#log{color:var(--fg);font-family:"DejaVu Sans Mono",ui-monospace,monospace}
.status{flex:none;display:flex;justify-content:space-between;gap:12px;padding:4px 10px;background:var(--head);
        border-top:1px solid var(--line);color:var(--dim);font-size:12px}

/* context menu */
.menu{position:fixed;z-index:60;background:var(--win);border:1px solid var(--line);border-radius:5px;
      padding:4px;min-width:190px;box-shadow:0 8px 26px rgba(0,0,0,.55);display:none}
.menu.on{display:block}
.menu button{display:flex;width:100%;align-items:center;justify-content:space-between;gap:18px;background:none;
             border:0;color:var(--fg);font:inherit;padding:6px 10px;border-radius:3px;cursor:pointer;text-align:left}
.menu button:hover:not(:disabled){background:var(--sel);color:#fff}
.menu button:disabled{opacity:.35;cursor:default}
.menu button kbd{color:var(--dim);font:11px "DejaVu Sans Mono",monospace}
.menu button:hover:not(:disabled) kbd{color:#dff}
.menu hr{border:0;border-top:1px solid var(--line);margin:4px 2px}

/* preview lightbox */
.lb{position:fixed;inset:0;z-index:80;background:rgba(20,22,24,.94);display:none;flex-direction:column}
.lb.on{display:flex}
.lb header{display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--line);color:var(--fg)}
.lb header .t{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lb .body{flex:1;display:flex;align-items:center;justify-content:center;min-height:0;padding:14px;gap:10px}
.lb img,.lb video{max-width:100%;max-height:100%;object-fit:contain;background:#111}
.lb .audio{display:flex;flex-direction:column;align-items:center;gap:18px;padding:34px 40px;
           background:var(--win);border:1px solid var(--line);border-radius:10px;min-width:min(460px,86vw)}
.lb .audio .disc{width:104px;height:104px;border-radius:50%;display:flex;align-items:center;justify-content:center;
                 background:radial-gradient(circle at 50% 50%,#2c3f3a 0 34%,#1b1e20 35%);color:var(--sel);font-size:40px}
.lb .audio .nm{color:var(--fg);font-size:14px;text-align:center;max-width:520px;word-break:break-word}
.lb .audio audio{width:min(460px,84vw)}
.lb .nav{background:rgba(255,255,255,.07);border:0;color:#fff;font-size:22px;width:44px;height:64px;
         border-radius:5px;cursor:pointer;flex:none}
.lb .nav:hover{background:var(--sel)}
@media(max-width:700px){.side{display:none}}
</style></head><body>

<svg style="display:none">
 <symbol id="i-folder" viewBox="0 0 16 16"><path d="M1.5 3h4l1.2 1.5H14.5c.3 0 .5.2.5.5v8c0 .3-.2.5-.5.5h-13c-.3 0-.5-.2-.5-.5v-9.5c0-.3.2-.5.5-.5z"/></symbol>
 <symbol id="i-file" viewBox="0 0 16 16"><path d="M3 1h6l4 4v10H3z" opacity=".85"/><path d="M9 1l4 4H9z" opacity=".5"/></symbol>
 <symbol id="i-up" viewBox="0 0 16 16"><path d="M8 3l5 5h-3v5H6V8H3z"/></symbol>
 <symbol id="i-back" viewBox="0 0 16 16"><path d="M10 3L5 8l5 5z"/></symbol>
 <symbol id="i-home" viewBox="0 0 16 16"><path d="M8 2l6 5.5h-2V14H4V7.5H2z"/></symbol>
 <symbol id="i-newdir" viewBox="0 0 16 16"><path d="M1.5 3h4l1.2 1.5H14.5c.3 0 .5.2.5.5v8c0 .3-.2.5-.5.5h-13c-.3 0-.5-.2-.5-.5v-9.5c0-.3.2-.5.5-.5z" opacity=".7"/><path d="M11 6h1.4v2H14v1.4h-1.6V11H11V9.4H9.4V8H11z" fill="#fff"/></symbol>
</svg>

<div class="toolbar">
 <a class="tb" href="<?= $parent === null ? '#' : $h($link($parent)) ?>" <?= $parent === null ? 'aria-disabled="true"' : '' ?> title="Back"><svg class="ic"><use href="#i-back"/></svg></a>
 <a class="tb" href="<?= $parent === null ? '#' : $h($link($parent)) ?>" <?= $parent === null ? 'aria-disabled="true"' : '' ?> title="Up"><svg class="ic"><use href="#i-up"/></svg></a>
 <a class="tb" href="<?= $h($link('')) ?>" title="Home"><svg class="ic"><use href="#i-home"/></svg></a>
 <div class="crumbs">
   <a href="<?= $h($link('')) ?>">admin</a>
   <?php foreach ($crumbs as $i => [$seg, $path]): ?>
     <span class="sep">›</span>
     <?php if ($i === count($crumbs) - 1): ?><span class="cur"><?= $h($seg) ?></span>
     <?php else: ?><a href="<?= $h($link($path)) ?>"><?= $h($seg) ?></a><?php endif; ?>
   <?php endforeach; ?>
 </div>
 <a class="tb" href="/music/" title="Music player">Music</a>
 <button class="tb" id="b-new" title="New folder"><svg class="ic folder"><use href="#i-newdir"/></svg>New</button>
 <div class="sepr"></div>
 <button class="tb" id="b-cut"    disabled title="Cut (Ctrl+X)">Cut</button>
 <button class="tb" id="b-copy"   disabled title="Copy (Ctrl+C)">Copy</button>
 <button class="tb" id="b-paste"  disabled title="Paste (Ctrl+V)">Paste</button>
 <button class="tb" id="b-rename" disabled title="Rename (F2)">Rename</button>
 <button class="tb danger" id="b-del" disabled title="Move to Trash (Del)">Delete</button>
</div>

<div class="main">
 <nav class="side">
  <h2>Places</h2>
  <?php foreach ($places as [$rel, $label, $ic]): ?>
   <a class="place<?= $rel === $dirRel ? ' on' : '' ?>" href="<?= $h($link($rel)) ?>" data-drop="<?= $h($rel) ?>">
     <svg class="ic folder"><use href="#<?= $rel === '' ? 'i-home' : 'i-folder' ?>"/></svg>
     <span><?= $h($label) ?></span></a>
  <?php endforeach; ?>
 </nav>

 <div class="view" id="view">
  <div class="dropover" id="dropover"><div>Drop to upload into <b id="dropinto"></b></div></div>
  <table>
   <thead><tr>
     <th><a href="<?= $h($sortLink('name')) ?>">Name<?= $arrow('name') ?></a></th>
     <th style="text-align:right"><a href="<?= $h($sortLink('size')) ?>">Size<?= $arrow('size') ?></a></th>
     <th><a href="<?= $h($sortLink('date')) ?>">Date Modified<?= $arrow('date') ?></a></th>
     <th>Type</th>
   </tr></thead>
   <tbody id="rows">
   <?php foreach ($dirs as $r): $p = ($dirRel === '' ? '' : $dirRel . '/') . $r['name']; ?>
    <tr data-path="<?= $h($p) ?>" data-name="<?= $h($r['name']) ?>" data-kind="dir">
        <td class="name"><div class="row"><svg class="ic folder"><use href="#i-folder"/></svg><span class="n"><?= $h($r['name']) ?></span></div></td>
        <td class="size">—</td><td class="date"><?= $r['date'] ? $h(date('Y-m-d H:i', $r['date'])) : '' ?></td><td class="type">Folder</td></tr>
   <?php endforeach; ?>
   <?php foreach ($files as $r): $p = ($dirRel === '' ? '' : $dirRel . '/') . $r['name']; [$k,$label] = kind($r['name']); $thumbable = ($k === 'image' || $k === 'video'); $prev = $thumbable || $k === 'audio'; ?>
    <tr data-path="<?= $h($p) ?>" data-name="<?= $h($r['name']) ?>" data-kind="<?= $h($k) ?>"<?= $prev ? ' data-preview="1"' : '' ?>>
        <td class="name"><div class="row">
          <?php if ($thumbable): ?><img class="thumb" loading="lazy" src="?thumb=<?= urlencode($p) ?>" alt="" onerror="this.replaceWith(Object.assign(document.createElementNS('http://www.w3.org/2000/svg','svg'),{}))">
          <?php else: ?><svg class="ic f-<?= $k ?>"><use href="#i-file"/></svg><?php endif; ?>
          <span class="n"><?= $h($r['name']) ?></span></div></td>
        <td class="size"><?= $h(human($r['size'])) ?></td><td class="date"><?= $r['date'] ? $h(date('Y-m-d H:i', $r['date'])) : '' ?></td><td class="type"><?= $h($label) ?></td></tr>
   <?php endforeach; ?>
   <?php if (!$dirs && !$files): ?><tr class="norow"><td colspan="4" class="empty">This folder is empty.</td></tr><?php endif; ?>
   </tbody>
  </table>
 </div>
</div>

<div id="zipbox"><span class="spin"></span><span class="nm" id="zipname"></span><span id="zipmsg"></span></div>
<div class="status">
 <span id="stat"><?= count($dirs) ?> folder<?= count($dirs) === 1 ? '' : 's' ?>, <?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span>
 <span id="prog"><progress id="bar" value="0" max="100"></progress><span id="log"></span></span>
 <span><?= $h(human($free)) ?> free &middot; <?= $h(human(CHUNK)) ?> chunks</span>
</div>

<div class="menu" id="menu">
 <button data-a="open">Open<kbd>Enter</kbd></button>
 <button data-a="download">Download</button>
 <hr>
 <button data-a="cut">Cut<kbd>Ctrl+X</kbd></button>
 <button data-a="copy">Copy<kbd>Ctrl+C</kbd></button>
 <button data-a="paste">Paste<kbd>Ctrl+V</kbd></button>
 <hr>
 <button data-a="rename">Rename<kbd>F2</kbd></button>
 <button data-a="trash">Move to Trash<kbd>Del</kbd></button>
 <button data-a="delete">Delete permanently<kbd>Shift+Del</kbd></button>
 <hr>
 <button data-a="newdir">New folder</button>
 <button data-a="upload">Upload files…</button>
 <input type="file" id="pick" multiple hidden>
</div>

<div class="lb" id="lb">
 <header><span class="t" id="lb-name"></span>
   <a class="tb" id="lb-dl" href="#" title="Download">Download</a>
   <button class="tb" id="lb-close" title="Close (Esc)">✕</button></header>
 <div class="body"><button class="nav" id="lb-prev">‹</button><span id="lb-holder"></span><button class="nav" id="lb-next">›</button></div>
</div>

<script>
const DIR = <?= json_encode($dirRel) ?>, CHUNK = <?= CHUNK ?>;
const rows = [...document.querySelectorAll('#rows tr[data-path]')];
const stat = document.getElementById('stat');
const log  = document.getElementById('log');
const say  = m => log.textContent = m;
let anchor = null;

/* ---------- selection, as Dolphin: click selects, ctrl toggles, shift ranges */
const selected = () => rows.filter(r => r.classList.contains('sel'));
function selOnly(r){ rows.forEach(x => x.classList.toggle('sel', x === r)); anchor = r; sync(); }
function selToggle(r){ r.classList.toggle('sel'); anchor = r; sync(); }
function selRange(r){
  if (!anchor) return selOnly(r);
  const a = rows.indexOf(anchor), b = rows.indexOf(r), [lo,hi] = a < b ? [a,b] : [b,a];
  rows.forEach((x,i) => x.classList.toggle('sel', i >= lo && i <= hi));
  sync();
}
function clearSel(){ rows.forEach(r => r.classList.remove('sel')); sync(); }
function sync(){
  const n = selected().length;
  for (const [id,on] of [['b-cut',n],['b-copy',n],['b-del',n],['b-rename',n===1]])
    document.getElementById(id).disabled = !on;
  document.getElementById('b-paste').disabled = !clip().paths.length;
  stat.textContent = n ? (n + ' selected') : stat.dataset.base;
}
stat.dataset.base = stat.textContent;

rows.forEach(r => {
  r.addEventListener('mousedown', e => {
    if (e.button === 2 && r.classList.contains('sel')) return;   // keep multi-selection on right-click
    if (e.ctrlKey || e.metaKey) selToggle(r); else if (e.shiftKey) selRange(r); else selOnly(r);
  });
  r.addEventListener('dblclick', () => open_(r));
});
document.getElementById('view').addEventListener('mousedown', e => { if (e.target.closest('tr')) return; clearSel(); });

/* ---------- clipboard (cut/copy) kept per tab */
const clip = () => { try { return JSON.parse(sessionStorage.getItem('clip')) || {mode:'',paths:[]}; } catch { return {mode:'',paths:[]}; } };
function setClip(mode){
  const paths = selected().map(r => r.dataset.path);
  sessionStorage.setItem('clip', JSON.stringify({mode, paths}));
  rows.forEach(r => r.classList.toggle('cut', mode === 'cut' && paths.includes(r.dataset.path)));
  say(paths.length + ' item(s) ready to ' + (mode === 'cut' ? 'move' : 'copy'));
  sync();
}

/* ---------- server operations */
async function op(action, extra = {}){
  const body = Object.assign({action, paths: selected().map(r => r.dataset.path)}, extra);
  const res  = await fetch('?op=1', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
  let j; try { j = await res.json(); } catch { j = {ok:false, error:'bad response'}; }
  if (!j.ok) { say('Error: ' + (j.error || res.status)); return false; }
  return true;
}
const reload = () => location.href = '?d=' + encodeURIComponent(DIR) + '&s=<?= $h($sort) ?>&o=<?= $h($ord) ?>';

async function doTrash(perm){
  const n = selected().length; if (!n) return;
  const names = selected().map(r => r.dataset.name).join(', ');
  if (perm && !confirm('Permanently delete ' + n + ' item(s)?\n\n' + names + '\n\nThis cannot be undone.')) return;
  say(perm ? 'Deleting…' : 'Moving to trash…');
  if (await op(perm ? 'delete' : 'trash')) reload();
}
async function doPaste(){
  const c = clip(); if (!c.paths.length) return;
  say(c.mode === 'cut' ? 'Moving…' : 'Copying…');
  const res = await fetch('?op=1', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action: c.mode === 'cut' ? 'move' : 'copy', paths: c.paths, dest: DIR})});
  const j = await res.json().catch(() => ({ok:false,error:'bad response'}));
  if (!j.ok) return say('Error: ' + (j.error || ''));
  sessionStorage.removeItem('clip'); reload();
}
async function doRename(){
  const r = selected()[0]; if (!r) return;
  const name = prompt('Rename to:', r.dataset.name); if (!name || name === r.dataset.name) return;
  if (await op('rename', {name})) reload();
}
async function doNewDir(){
  const name = prompt('New folder name:', 'New Folder'); if (!name) return;
  const res = await fetch('?op=1', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'mkdir', name, dest: DIR})});
  const j = await res.json().catch(() => ({ok:false}));
  j.ok ? reload() : say('Error: ' + (j.error || ''));
}

/* ---------- open / preview */
const previewable = rows.filter(r => r.dataset.preview);
let lbIndex = -1;
function open_(r){
  if (r.dataset.kind === 'dir') { location.href = '?d=' + encodeURIComponent(r.dataset.path); return; }
  if (r.dataset.preview) { lbIndex = previewable.indexOf(r); showLb(); return; }
  location.href = '?f=' + encodeURIComponent(r.dataset.path);
}
const lb = document.getElementById('lb'), holder = document.getElementById('lb-holder');
function showLb(){
  const r = previewable[lbIndex]; if (!r) return;
  const src = '?view=' + encodeURIComponent(r.dataset.path);
  holder.innerHTML = '';
  if (r.dataset.kind === 'audio') {
    // Nothing to display for audio, so show a card with the filename and controls.
    const card = document.createElement('div'); card.className = 'audio';
    const disc = document.createElement('div'); disc.className = 'disc'; disc.textContent = '\u266A';
    const nm   = document.createElement('div'); nm.className = 'nm'; nm.textContent = r.dataset.name;
    const au   = document.createElement('audio');
    au.src = src; au.controls = true; au.autoplay = true;
    // Roll on to the next audio file, the way a player would. Images and video
    // in between are skipped rather than interrupting playback.
    au.addEventListener('ended', () => {
      for (let n = 1; n <= previewable.length; n++) {
        const j = (lbIndex + n) % previewable.length;
        if (previewable[j].dataset.kind === 'audio') { lbIndex = j; showLb(); return; }
      }
    });
    card.append(disc, nm, au);
    holder.appendChild(card);
  } else {
    const el = document.createElement(r.dataset.kind === 'video' ? 'video' : 'img');
    el.src = src;
    if (r.dataset.kind === 'video') { el.controls = true; el.autoplay = true; }
    holder.appendChild(el);
  }
  document.getElementById('lb-name').textContent = r.dataset.name + '  (' + (lbIndex+1) + '/' + previewable.length + ')';
  document.getElementById('lb-dl').href = '?f=' + encodeURIComponent(r.dataset.path);
  lb.classList.add('on');
}
function closeLb(){ lb.classList.remove('on'); holder.innerHTML = ''; }
document.getElementById('lb-close').onclick = closeLb;
lb.addEventListener('click', e => { if (e.target === lb || e.target.classList.contains('body')) closeLb(); });
document.getElementById('lb-prev').onclick = () => { lbIndex = (lbIndex - 1 + previewable.length) % previewable.length; showLb(); };
document.getElementById('lb-next').onclick = () => { lbIndex = (lbIndex + 1) % previewable.length; showLb(); };

/* Download the selection. A file goes straight through ?f=. A folder is zipped
   by a detached job and then fetched as a static file from _zips/, which is
   what makes it survive: the archive does not travel through a CGI process, so
   nothing can cut it off partway, and the browser gets a Content-Length and a
   real progress bar. Progress while it builds is reported here instead. */
const mb = n => (n / 1048576).toFixed(0) + ' MB';

const zipbox  = document.getElementById('zipbox');
const zipname = document.getElementById('zipname');
const zipmsg  = document.getElementById('zipmsg');
function zipShow(name, msg, done){
  zipname.textContent = name;
  zipmsg.textContent  = msg;
  zipbox.classList.toggle('done', !!done);
  zipbox.classList.add('on');
}
const zipHide = () => zipbox.classList.remove('on');

async function doDownload(){
  const r = selected()[0];
  if (!r) return;
  if (r.dataset.kind !== 'dir') { location.href = '?f=' + encodeURIComponent(r.dataset.path); return; }

  // The id is minted here, not by the server. The request that starts the zip
  // is killed by the CGI time limit while zip is still running, so it never
  // answers - but the work is detached and carries on, and polling by an id we
  // already hold finds it. Its failure is therefore ignored on purpose.
  const id = [...crypto.getRandomValues(new Uint8Array(8))]
               .map(b => b.toString(16).padStart(2, '0')).join('');
  const name = r.dataset.name;

  zipShow(name, ' - preparing the archive…');
  say('Zipping ' + name + '…');
  fetch('?zipjob=' + encodeURIComponent(r.dataset.path) + '&id=' + id).catch(() => {});

  let waited = 0;
  const tick = setInterval(async () => {
    waited++;
    const st = await fetch('?zipstatus=' + id).then(x => x.json()).catch(() => null);
    if (!st || !st.ok) {
      if (waited > 20) {
        clearInterval(tick);
        zipShow(name, ' - could not be zipped', true);
        setTimeout(zipHide, 6000);
        say('The archive never started - check the server log.');
      }
      return;
    }
    if (st.done && st.url) {
      clearInterval(tick);
      zipShow(name, ' - ready, ' + mb(st.bytes) + ', starting download', true);
      say('Downloading ' + st.name);
      setTimeout(zipHide, 4000);
      location.href = st.url;
      return;
    }
    // Nothing to show a percentage against - the finished size is not known
    // until it is finished - so report what exists so far and keep it moving.
    zipShow(name, ' - zipping, ' + mb(st.bytes) + ' so far…');
    say('Zipping ' + name + '… ' + mb(st.bytes));
  }, 1000);
}

/* ---------- context menu */
const menu = document.getElementById('menu');
function showMenu(x, y){
  const n = selected().length, one = n === 1, r = selected()[0];
  const en = {open: one, download: one, cut: n, copy: n,
              paste: clip().paths.length, rename: one, trash: n, delete: n, newdir: true, upload: true};
  menu.querySelectorAll('button').forEach(b => b.disabled = !en[b.dataset.a]);
  // A folder downloads as a zip, so say so rather than letting the plain
  // "Download" label imply a single file is on its way.
  const dl = menu.querySelector('button[data-a="download"]');
  if (dl) { dl.textContent = (one && r && r.dataset.kind === 'dir') ? 'Download as zip' : 'Download'; }
  menu.classList.add('on');
  const w = menu.offsetWidth, h = menu.offsetHeight;
  menu.style.left = Math.min(x, innerWidth  - w - 6) + 'px';
  menu.style.top  = Math.min(y, innerHeight - h - 6) + 'px';
}
const hideMenu = () => menu.classList.remove('on');
document.addEventListener('contextmenu', e => {
  if (e.target.closest('.dock') || e.target.closest('.lb')) return;
  const tr = e.target.closest('tr[data-path]');
  e.preventDefault();
  if (tr && !tr.classList.contains('sel')) selOnly(tr);
  if (!tr) clearSel();
  showMenu(e.clientX, e.clientY);
});
document.addEventListener('click', hideMenu);
menu.addEventListener('click', e => {
  const b = e.target.closest('button'); if (!b || b.disabled) return;
  ({open:()=>open_(selected()[0]), download:doDownload,
    cut:()=>setClip('cut'), copy:()=>setClip('copy'), paste:doPaste, rename:doRename,
    trash:()=>doTrash(false), delete:()=>doTrash(true), newdir:doNewDir, upload:()=>document.getElementById('pick').click()})[b.dataset.a]();
});

/* ---------- toolbar + keyboard */
document.getElementById('b-cut').onclick    = () => setClip('cut');
document.getElementById('b-copy').onclick   = () => setClip('copy');
document.getElementById('b-paste').onclick  = doPaste;
document.getElementById('b-rename').onclick = doRename;
document.getElementById('b-del').onclick    = () => doTrash(false);
document.getElementById('b-new').onclick    = doNewDir;
addEventListener('keydown', e => {
  if (/^(INPUT|TEXTAREA)$/.test(e.target.tagName)) return;
  if (lb.classList.contains('on')) {
    if (e.key === 'Escape') closeLb();
    if (e.key === 'ArrowLeft')  document.getElementById('lb-prev').click();
    if (e.key === 'ArrowRight') document.getElementById('lb-next').click();
    return;
  }
  const c = e.ctrlKey || e.metaKey;
  if (c && e.key === 'a') { e.preventDefault(); rows.forEach(r => r.classList.add('sel')); sync(); }
  else if (c && e.key === 'x') setClip('cut');
  else if (c && e.key === 'c') setClip('copy');
  else if (c && e.key === 'v') doPaste();
  else if (e.key === 'Delete') doTrash(e.shiftKey);
  else if (e.key === 'F2') doRename();
  else if (e.key === 'Enter' && selected().length === 1) open_(selected()[0]);
  else if (e.key === 'Escape') { clearSel(); hideMenu(); }
});
sync();

/* ---------- chunked upload, driven by dropping onto the folder view */
const view = document.getElementById('view'), dropover = document.getElementById('dropover'),
      dropinto = document.getElementById('dropinto'), prog = document.getElementById('prog'),
      bar = document.getElementById('bar'), pick = document.getElementById('pick');
let dropDir = DIR, dropRow = null, depth = 0;

// A file dragged anywhere else in the window must not navigate the page away.
['dragenter','dragover','drop'].forEach(t => addEventListener(t, e => e.preventDefault()));

function markRow(tr){
  if (dropRow === tr) return;
  if (dropRow) dropRow.classList.remove('droptarget');
  dropRow = tr;
  if (dropRow) dropRow.classList.add('droptarget');
}
function label(){ dropinto.textContent = dropDir === '' ? 'admin' : dropDir.split('/').pop(); }

view.addEventListener('dragenter', e => { if (!e.dataTransfer.types.includes('Files')) return; depth++; dropover.classList.add('on'); });
view.addEventListener('dragover',  e => {
  if (!e.dataTransfer.types.includes('Files')) return;
  e.dataTransfer.dropEffect = 'copy';
  const tr = e.target.closest('tr[data-kind="dir"]');       // dropping onto a folder puts it there
  markRow(tr);
  dropDir = tr ? tr.dataset.path : DIR;
  label();
});
view.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; dropover.classList.remove('on'); markRow(null); } });
view.addEventListener('drop', e => {
  depth = 0; dropover.classList.remove('on');
  const tr = e.target.closest('tr[data-kind="dir"]');
  dropDir = tr ? tr.dataset.path : DIR;
  markRow(null);
  if (e.dataTransfer.files.length) upload([...e.dataTransfer.files], dropDir);
});
pick.onchange = () => { if (pick.files.length) upload([...pick.files], DIR); };

async function sendFile(file, dir){
  let offset = 0, t0 = Date.now();
  while (offset < file.size) {
    const slice = file.slice(offset, offset + CHUNK);
    const res = await fetch('?chunk=1&d=' + encodeURIComponent(dir), {method:'POST', body: slice,
      headers:{ 'X-File-Name': encodeURIComponent(file.name).replace(/%20/g,' '),
                'X-File-Offset': String(offset), 'X-File-Total': String(file.size) }});
    const txt = (await res.text()).trim();
    if (!res.ok) throw new Error(txt || res.status);
    offset += slice.size;
    const pct = offset / file.size * 100, mbs = (offset/1048576)/((Date.now()-t0)/1000);
    bar.value = pct;
    say(file.name + ' — ' + pct.toFixed(0) + '%  (' + mbs.toFixed(1) + ' MiB/s)');
  }
}
async function upload(files, dir){
  prog.classList.add('on');
  try {
    for (let i = 0; i < files.length; i++) { bar.value = 0; say(files[i].name + ' …'); await sendFile(files[i], dir); }
    say('Done'); reload();
  } catch (e) { say('Failed: ' + e.message); }
}
</script>
</body></html>
