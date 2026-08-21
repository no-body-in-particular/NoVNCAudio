<?php
// Music player for ~/Music, served from the client-certificate vhost.
//
// Runs as the admin user via hiawatha's cgi-wrapper, next to the file share.
// Access control is the TLS client certificate required by the 8443 binding;
// hiawatha has verified it before this script runs. The port guard below is the
// belt to that braces, because this file also sits inside the public site root.

declare(strict_types=1);

// The CGI process inherits umask 0117, which strips the execute bit from mkdir
// and leaves cache directories that cannot have entries created in them.
umask(0022);

const ROOT      = '/home/admin/Music';
const CACHE     = '/home/admin/.cache/share-music';
const MTLS_PORT = '8443';

if (($_SERVER['SERVER_PORT'] ?? '') !== MTLS_PORT) {
    $host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'coredump.ws'))[0];
    $to   = 'https://' . $host . ':' . MTLS_PORT . '/music/';
    header('Location: ' . $to, true, 302);
    $e = htmlspecialchars($to, ENT_QUOTES);
    exit('<!doctype html><meta charset="utf-8"><title>Music</title>'
       . '<body style="font:15px system-ui;background:#31363b;color:#eff0f1;padding:44px">'
       . '<p>The music player needs your client certificate, which is required on its own port.</p>'
       . '<p><a style="color:#16a085" href="' . $e . '">' . $e . '</a></p>');
}

function resolve(string $rel): ?string {
    $p = realpath(ROOT . '/' . $rel);
    if ($p === false) { return null; }
    if ($p !== ROOT && strpos($p, ROOT . '/') !== 0) { return null; }
    return $p;
}
function rel_of(string $abs): string { return ltrim(substr($abs, strlen(ROOT)), '/'); }
function fail(int $c, string $m): never { http_response_code($c); header('Content-Type: text/plain'); exit($m); }
function is_audio(string $n): bool {
    return in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)),
        ['mp3','flac','ogg','oga','opus','m4a','aac','wav','webm','wma','aiff','alac'], true);
}
function audio_mime(string $n): string {
    return [
        'mp3'=>'audio/mpeg','flac'=>'audio/flac','ogg'=>'audio/ogg','oga'=>'audio/ogg',
        'opus'=>'audio/ogg','m4a'=>'audio/mp4','aac'=>'audio/aac','wav'=>'audio/wav',
        'webm'=>'audio/webm','wma'=>'audio/x-ms-wma','aiff'=>'audio/aiff','alac'=>'audio/mp4',
    ][strtolower(pathinfo($n, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}
function cache_path(string $key): string {
    if (!is_dir(CACHE)) { @mkdir(CACHE, 0700, true); @chmod(CACHE, 0700); }
    return CACHE . '/' . $key;
}

// Tags via ffprobe, cached on path+mtime+size so a library is only probed once.
function tags(string $abs): array {
    $key = sha1($abs . '|' . filemtime($abs) . '|' . filesize($abs)) . '.json';
    $cf  = cache_path($key);
    if (is_file($cf)) {
        $j = json_decode((string)file_get_contents($cf), true);
        if (is_array($j)) { return $j; }
    }
    $out = @shell_exec(sprintf('ffprobe -v quiet -print_format json -show_format %s 2>/dev/null', escapeshellarg($abs)));
    $j   = json_decode((string)$out, true);
    $t   = $j['format']['tags'] ?? [];
    $low = [];
    foreach ((array)$t as $k => $v) { $low[strtolower((string)$k)] = (string)$v; }
    $meta = [
        'title'  => $low['title']  ?? pathinfo($abs, PATHINFO_FILENAME),
        'artist' => $low['artist'] ?? ($low['album_artist'] ?? ''),
        'album'  => $low['album']  ?? basename(dirname($abs)),
        'track'  => (int)($low['track'] ?? 0),
        'dur'    => (float)($j['format']['duration'] ?? 0),
    ];
    @file_put_contents($cf, json_encode($meta));
    return $meta;
}

// ---- stream, with Range so seeking works ---------------------------------
if (isset($_GET['stream'])) {
    $f = resolve((string)$_GET['stream']);
    if ($f === null || !is_file($f) || !is_readable($f) || !is_audio($f)) { fail(404, 'not found'); }
    $size = (int)filesize($f);
    while (ob_get_level()) { ob_end_clean(); }
    $start = 0; $end = $size - 1; $partial = false;
    if (preg_match('/bytes=(\d*)-(\d*)/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
        if ($m[1] !== '') { $start = (int)$m[1]; }
        if ($m[2] !== '') { $end = (int)$m[2]; }
        if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
        $end = min($end, $size - 1); $partial = true;
    }
    $len = $end - $start + 1;
    if ($partial) { http_response_code(206); header("Content-Range: bytes $start-$end/$size"); }
    header('Content-Type: ' . audio_mime($f));
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $len);
    header('Cache-Control: private, max-age=3600');
    $h = fopen($f, 'rb'); fseek($h, $start); $left = $len;
    while ($left > 0 && !feof($h)) {
        $b = fread($h, (int)min(524288, $left));
        if ($b === '' || $b === false) { break; }
        echo $b; $left -= strlen($b); flush();
    }
    fclose($h); exit;
}

// ---- album art: a cover file in the folder, else embedded art ------------
if (isset($_GET['cover'])) {
    $d = resolve((string)$_GET['cover']);
    if ($d === null || !is_dir($d)) { fail(404, 'not found'); }
    foreach (['cover.jpg','folder.jpg','front.jpg','cover.png','folder.png','album.jpg'] as $n) {
        foreach ([$n, strtoupper($n), ucfirst($n)] as $cand) {
            if (is_file($d . '/' . $cand)) {
                header('Content-Type: ' . (str_ends_with(strtolower($cand), '.png') ? 'image/png' : 'image/jpeg'));
                header('Cache-Control: private, max-age=86400');
                readfile($d . '/' . $cand); exit;
            }
        }
    }
    // embedded art from the first track
    $first = null;
    foreach (scandir($d) ?: [] as $e) { if ($e[0] !== '.' && is_file($d . '/' . $e) && is_audio($e)) { $first = $d . '/' . $e; break; } }
    if ($first === null) { fail(404, 'no cover'); }
    $cf = cache_path(sha1('cover|' . $first . '|' . filemtime($first)) . '.jpg');
    if (!is_file($cf) || filesize($cf) === 0) {
        @exec(sprintf('ffmpeg -nostdin -loglevel error -i %s -an -map 0:v? -frames:v 1 -vf scale=400:-1 -y %s 2>/dev/null',
              escapeshellarg($first), escapeshellarg($cf)));
        if (!is_file($cf) || filesize($cf) === 0) { @unlink($cf); fail(404, 'no cover'); }
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    readfile($cf); exit;
}


// ---- YouTube download -----------------------------------------------------
// Calls /tmp/youtube.py, which wraps yt-dlp and writes MP3s into a subfolder of
// the destination named after the playlist (or the video title for a single
// video). A playlist can take minutes, so the job is detached and its output
// tailed through ?ytstatus rather than held open on the request.
// Kept beside this file rather than in /tmp, which does not survive a reboot.
// It sits inside the public website root, so both UrlToolkits deny .py to stop
// it being served as source - it is only ever executed, never fetched.
define('YT_SCRIPT', __DIR__ . '/youtube.py');
const JOBS      = CACHE . '/jobs';

if (isset($_GET['ytstatus'])) {
    header('Content-Type: application/json');
    $id = preg_replace('/[^a-f0-9]/', '', (string)$_GET['ytstatus']);
    $log = JOBS . '/' . $id . '.log';
    if ($id === '' || !is_file($log)) { echo json_encode(['ok'=>false,'error'=>'unknown job']); exit; }
    $running = is_file(JOBS . '/' . $id . '.pid')
               && is_numeric($pid = trim((string)@file_get_contents(JOBS . '/' . $id . '.pid')))
               && @posix_kill((int)$pid, 0);
    $txt = (string)@file_get_contents($log);
    if (strlen($txt) > 4000) { $txt = '…' . substr($txt, -4000); }
    echo json_encode(['ok'=>true, 'running'=>$running, 'log'=>$txt]);
    exit;
}

if (isset($_GET['yt'])) {
    header('Content-Type: application/json');
    $in   = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $url  = trim((string)($in['url'] ?? ''));
    $dest = resolve((string)($in['dest'] ?? ''));

    // Only accept a YouTube URL. The value reaches a shell command, so this is
    // both a sanity check and the thing that stops arbitrary arguments being
    // handed to yt-dlp - escapeshellarg alone would still allow, say, a file://
    // URL or another site.
    if (!preg_match('~^https://(www\.|m\.|music\.)?(youtube\.com/|youtu\.be/)[\w\-/?=&.%]+$~', $url)) {
        echo json_encode(['ok'=>false,'error'=>'Not a YouTube URL']); exit;
    }
    if ($dest === null || !is_dir($dest) || !is_writable($dest)) {
        echo json_encode(['ok'=>false,'error'=>'destination not writable']); exit;
    }
    if (!is_file(YT_SCRIPT)) {
        echo json_encode(['ok'=>false,'error'=>'youtube.py is not at ' . YT_SCRIPT . ' any more']); exit;
    }
    if (!is_dir(JOBS)) { @mkdir(JOBS, 0700, true); @chmod(JOBS, 0700); }

    $id  = bin2hex(random_bytes(8));
    $log = JOBS . '/' . $id . '.log';
    $pf  = JOBS . '/' . $id . '.pid';
    $cmd = sprintf('setsid python3 -u %s %s %s > %s 2>&1 & echo $! > %s',
        escapeshellarg(YT_SCRIPT), escapeshellarg($url), escapeshellarg($dest),
        escapeshellarg($log), escapeshellarg($pf));
    @exec('/bin/sh -c ' . escapeshellarg($cmd) . ' >/dev/null 2>&1');
    echo json_encode(['ok'=>true, 'id'=>$id]);
    exit;
}


// ---- every track under a folder, for "play this folder and below" ---------
if (isset($_GET['tracks'])) {
    header('Content-Type: application/json');
    $base = resolve((string)$_GET['tracks']);
    if ($base === null || !is_dir($base)) { echo json_encode(['ok'=>false,'error'=>'bad folder']); exit; }
    $found = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        if (count($found) >= 2000) { break; }        // guard against a pathological tree
        if (!$f->isFile() || !is_audio($f->getFilename()) || !$f->isReadable()) { continue; }
        $found[] = $f->getPathname();
    }
    // natural order by path, so folders and track numbers come out in order
    usort($found, 'strnatcasecmp');
    $out = [];
    foreach ($found as $abs) {
        $m = tags($abs);
        $out[] = ['file' => rel_of($abs), 'title' => $m['title'], 'artist' => $m['artist']];
    }
    echo json_encode(['ok'=>true, 'tracks'=>$out]);
    exit;
}


// ---- web app manifest ------------------------------------------------------
// Android keeps a page's audio alive in the background when it presents a media
// session; installing it to the home screen makes that more reliable still and
// gives it its own task rather than a browser tab that can be discarded.
if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json');
    echo json_encode([
        'name'             => 'Music',
        'short_name'       => 'Music',
        'start_url'        => './',
        'scope'            => './',
        'display'          => 'standalone',
        'background_color' => '#232629',
        'theme_color'      => '#31363b',
        'orientation'      => 'any',
        'icons'            => [],
    ]);
    exit;
}

// ---- listing --------------------------------------------------------------
$dirRel = isset($_GET['d']) ? (string)$_GET['d'] : '';
$dir    = resolve($dirRel);
if ($dir === null || !is_dir($dir)) { $dir = ROOT; }
$dirRel = rel_of($dir);

$folders = []; $tracks = [];
if ($dh = @opendir($dir)) {
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..' || $e[0] === '.') { continue; }
        $p = $dir . '/' . $e;
        if (is_dir($p)) { $folders[] = $e; }
        elseif (is_audio($e) && is_readable($p)) {
            $m = tags($p);
            $m['file'] = ($dirRel === '' ? '' : $dirRel . '/') . $e;
            $m['name'] = $e;
            $tracks[] = $m;
        }
    }
    closedir($dh);
}
natcasesort($folders); $folders = array_values($folders);
usort($tracks, fn($a, $b) => ($a['track'] <=> $b['track']) ?: strnatcasecmp($a['name'], $b['name']));

// folders for the sidebar: every directory under the root
$albums = [];
foreach (scandir(ROOT) ?: [] as $e) { if ($e[0] !== '.' && is_dir(ROOT . '/' . $e)) { $albums[] = $e; } }
natcasesort($albums); $albums = array_values($albums);

$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$hm = function (float $s): string {
    if ($s <= 0) { return ''; }
    $s = (int)round($s);
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
$parent = ($dir === ROOT) ? null : rel_of(dirname($dir));
$total  = array_sum(array_column($tracks, 'dur'));
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#31363b">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="?manifest=1">
<title><?= $h($dirRel === '' ? 'Music' : basename($dirRel)) ?> — Music</title>
<style>
:root{--win:#31363b;--view:#232629;--side:#2b3034;--head:#31363b;--fg:#eff0f1;--dim:#9aa2a8;
      --line:#4d5257;--hover:#2e3439;--sel:#16a085}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;background:var(--win);color:var(--fg);display:flex;flex-direction:column;overflow:hidden;
     font:13px/1.45 "Noto Sans","DejaVu Sans",Cantarell,-apple-system,BlinkMacSystemFont,sans-serif}
.toolbar{display:flex;align-items:center;gap:10px;padding:7px 12px;background:var(--head);
         border-bottom:1px solid var(--line);flex:none}
.toolbar b{font-size:14px}
.toolbar a{color:var(--fg);text-decoration:none;padding:4px 9px;border-radius:4px}
.toolbar a:hover{background:var(--hover);color:var(--sel)}
.grow{flex:1}
#ytForm{display:flex;gap:6px;align-items:center}
#ytUrl{background:var(--view);border:1px solid var(--line);border-radius:4px;color:var(--fg);
       padding:5px 9px;font:inherit;width:290px}
#ytUrl:focus{outline:0;border-color:var(--sel)}
.ytbtn{background:var(--sel);color:#fff;border:0;border-radius:4px;padding:6px 13px;font:inherit;
       font-weight:600;cursor:pointer}
.ytbtn:hover{background:#1abc9c}.ytbtn:disabled{opacity:.5;cursor:default}
#ytPanel{display:none;position:fixed;right:14px;bottom:76px;width:min(560px,92vw);max-height:44vh;
         background:var(--win);border:1px solid var(--line);border-radius:8px;z-index:40;
         box-shadow:0 10px 34px rgba(0,0,0,.5);flex-direction:column}
#ytPanel.on{display:flex}
#ytPanel header{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--line)}
#ytPanel header b{flex:1}
#ytPanel pre{margin:0;padding:10px 12px;overflow:auto;font:11.5px/1.5 "DejaVu Sans Mono",ui-monospace,monospace;
             color:var(--dim);white-space:pre-wrap;word-break:break-word}
@media(max-width:900px){#ytUrl{width:150px}}
.main{flex:1;display:flex;min-height:0}
.side{width:210px;flex:none;background:var(--side);border-right:1px solid var(--line);overflow-y:auto;padding:8px 0}
.side h2{font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);margin:4px 0 6px;padding:0 12px}
.side a{display:block;padding:6px 12px;color:var(--fg);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.side a:hover{background:var(--hover)}.side a.on{background:var(--sel);color:#fff}
.view{flex:1;overflow:auto;background:var(--view);padding:14px 16px}
.albumhead{display:flex;gap:16px;align-items:flex-end;margin-bottom:16px}
.art{border-radius:6px;background:linear-gradient(135deg,#2c3f3a,#1b1e20);display:flex;align-items:center;
     justify-content:center;color:#16a085;flex:none;position:relative;overflow:hidden}
.art::before{content:'♪';font-size:2em;opacity:.6}
.art img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.albumhead .art{width:132px;height:132px;font-size:34px}
.albumhead h1{margin:0 0 4px;font-size:20px}
.albumhead .meta{color:var(--dim);font-size:12.5px}
.btn{background:var(--sel);color:#fff;border:0;border-radius:20px;padding:7px 18px;font:inherit;
     font-weight:600;cursor:pointer;margin-top:10px}
.btn:hover{background:#1abc9c}
table{width:100%;border-collapse:collapse}
td{padding:6px 10px;border-bottom:1px solid rgba(255,255,255,.04);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
tr{cursor:pointer}tr:hover{background:var(--hover)}
tr.playing{background:rgba(22,160,133,.18)}
tr.playing td.t{color:var(--sel);font-weight:600}
tr.folderrow.picked{background:rgba(22,160,133,.22);outline:1px solid var(--sel);outline-offset:-1px}
td.num{width:34px;color:var(--dim);text-align:right;font-variant-numeric:tabular-nums}
td.ar{color:var(--dim);width:34%}
td.d{width:60px;text-align:right;color:var(--dim);font-variant-numeric:tabular-nums}
.folderrow a{color:var(--fg);text-decoration:none}
.player{flex:none;display:flex;flex-direction:column;gap:5px;padding:7px 12px 9px;background:var(--head);
        border-top:1px solid var(--line)}
.prow{display:flex;align-items:center;gap:12px;min-width:0}
.player .art{width:46px;height:46px;font-size:15px}
.np{flex:1;min-width:0;overflow:hidden}
.np .t{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.np .a{color:var(--dim);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ctrls{display:flex;align-items:center;gap:6px}
.ctrls button{background:none;border:0;color:var(--fg);cursor:pointer;font-size:15px;width:32px;height:32px;
               border-radius:50%;display:inline-flex;align-items:center;justify-content:center;padding:0;line-height:1}
.ctrls button svg{display:block}
.ctrls button:hover{background:var(--hover)}
.ctrls button.main{background:var(--sel);color:#fff;width:36px;height:36px}
.ctrls button.main:hover{background:#1abc9c}
.ctrls button.on{color:var(--sel)}
.bar{display:flex;align-items:center;gap:9px;width:100%}
input[type=range]{width:100%;accent-color:var(--sel)}
.time{color:var(--dim);font-size:12px;font-variant-numeric:tabular-nums;width:42px;text-align:center}
.vol{width:96px;flex:none;display:flex;align-items:center;gap:6px}
@media(max-width:760px){
  .side{display:none}
  .vol{display:none}
  .player .art{width:38px;height:38px;font-size:13px}
  .prow{gap:8px}
  .ctrls{gap:2px}
  .ctrls button{width:30px;height:30px}
  .ctrls button.main{width:34px;height:34px}
  .toolbar{flex-wrap:wrap;gap:6px}
  #ytUrl{width:100%;min-width:0}
  #ytForm{flex:1 1 100%}
}
</style></head><body>

<div class="toolbar">
  <a href="?d=">All folders</a>
  <?php if ($parent !== null): ?><a href="?d=<?= urlencode($parent) ?>">↑ Up</a><?php endif; ?>
  <span class="grow"></span>
  <form id="ytForm" onsubmit="return startYt(event)">
    <input id="ytUrl" type="url" placeholder="Paste a YouTube URL to download here" spellcheck="false">
    <button class="ytbtn" type="submit" id="ytGo">Download</button>
  </form>
</div>

<div class="main">
 <nav class="side">
   <h2>Folders</h2>
   <?php foreach ($albums as $a): ?>
     <a class="<?= $a === $dirRel ? 'on' : '' ?>" href="?d=<?= urlencode($a) ?>"><?= $h($a) ?></a>
   <?php endforeach; ?>
   <?php if (!$albums): ?><a style="color:var(--dim)">No folders</a><?php endif; ?>
 </nav>

 <div class="view">
  <?php if ($dirRel !== ''): ?>
   <div class="albumhead">
     <div class="art"><img src="?cover=<?= urlencode($dirRel) ?>" alt="" onerror="this.remove()"></div>
     <div>
       <h1><?= $h(basename($dirRel)) ?></h1>
       <div class="meta"><?= count($tracks) ?> track<?= count($tracks) === 1 ? '' : 's' ?><?= $total > 0 ? ' · ' . $h($hm($total)) : '' ?></div>
       <?php if ($tracks): ?><button class="btn" onclick="playFolder(DIR)">▶ Play folder</button><?php endif; ?>
     </div>
   </div>
  <?php endif; ?>

  <table>
  <?php foreach ($folders as $f): $p = ($dirRel === '' ? '' : $dirRel . '/') . $f; ?>
    <tr class="folderrow" data-folder="<?= $h($p) ?>" onclick="selectFolder(this)"
        ondblclick="location.href='?d=<?= urlencode($p) ?>'"
        title="Click to select, double-click or click the name to open">
      <td class="num">📁</td>
      <td colspan="3"><a href="?d=<?= urlencode($p) ?>" onclick="event.stopPropagation()"><?= $h($f) ?></a></td></tr>
  <?php endforeach; ?>
  <?php foreach ($tracks as $i => $t): ?>
    <tr id="tr<?= $i ?>" onclick="play(<?= $i ?>)">
      <td class="num"><?= $t['track'] > 0 ? (int)$t['track'] : $i + 1 ?></td>
      <td class="t"><?= $h($t['title']) ?></td>
      <td class="ar"><?= $h($t['artist']) ?></td>
      <td class="d"><?= $h($hm((float)$t['dur'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$folders && !$tracks): ?><tr><td style="color:var(--dim);padding:22px">Nothing here.</td></tr><?php endif; ?>
  </table>
 </div>
</div>

<div class="player">
  <div class="bar">
    <span class="time" id="cur">0:00</span>
    <input type="range" id="seek" value="0" min="0" max="1000" step="1">
    <span class="time" id="dur">0:00</span>
  </div>
  <div class="prow">
  <div class="art"><?php if ($dirRel !== ''): ?><img id="art" alt="" src="?cover=<?= urlencode($dirRel) ?>" onerror="this.remove()"><?php endif; ?></div>
  <div class="np"><div class="t" id="npT">Nothing playing</div><div class="a" id="npA"></div></div>
  <div class="ctrls">
    <button onclick="prev()" title="Previous">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M4 3h2v10H4zM13 3v10L6.5 8z"/></svg></button>
    <button class="main" id="pp" onclick="toggle()" title="Play/Pause">
      <svg id="ppIcon" width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M4.5 2.6l9 5.4-9 5.4z"/></svg></button>
    <button onclick="next()" title="Next">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M12 3h2v10h-2zM3 3v10l6.5-5z"/></svg></button>
    <button id="shf" onclick="toggleShuffle()" title="Shuffle">🔀</button>
    <button id="rpt" onclick="toggleRepeat()" title="Repeat">🔁</button>
  </div>
  <div class="vol">🔊<input type="range" id="vol" min="0" max="100" value="100"></div>
  </div>
</div>

<div id="ytPanel">
  <header><b>Downloading</b><span id="ytState" style="color:var(--dim)"></span>
    <button class="tb" style="background:none;border:0;color:var(--fg);cursor:pointer" onclick="document.getElementById('ytPanel').classList.remove('on')">✕</button>
  </header>
  <pre id="ytLog"></pre>
</div>
<audio id="au"></audio>
<script>
const DIR = <?= json_encode($dirRel) ?>;
const TRACKS = <?= json_encode(array_map(fn($t) => ['file'=>$t['file'],'title'=>$t['title'],'artist'=>$t['artist']], $tracks)) ?>;
const au=document.getElementById('au'), pp=document.getElementById('pp'),
      seek=document.getElementById('seek'), curEl=document.getElementById('cur'), durEl=document.getElementById('dur');
let idx=-1, shuffle=false, repeat=false, order=[];

const fmt = s => (!isFinite(s)||s<=0) ? '0:00' : Math.floor(s/60)+':'+String(Math.floor(s%60)).padStart(2,'0');
function buildOrder(){
  order = TRACKS.map((_,i)=>i);
  if (shuffle) for (let i=order.length-1;i>0;i--){ const j=Math.floor(Math.random()*(i+1)); [order[i],order[j]]=[order[j],order[i]]; }
}
function play(i){
  if (!TRACKS[i]) return;
  idx = i;
  au.src = '?stream=' + encodeURIComponent(TRACKS[i].file);
  au.play().catch(e => { document.getElementById('npT').textContent = 'Cannot play: ' + e.message; });
  document.getElementById('npT').textContent = TRACKS[i].title;
  document.getElementById('npA').textContent = TRACKS[i].artist;
  document.querySelectorAll('tr.playing').forEach(t=>t.classList.remove('playing'));
  document.getElementById('tr'+i)?.classList.add('playing');
  if (!order.length) buildOrder();
  updateSession(i);
}
let picked = null;   // a folder row clicked in the list, if any

function selectFolder(tr){
  const was = tr.classList.contains('picked');
  document.querySelectorAll('tr.folderrow.picked').forEach(x => x.classList.remove('picked'));
  if (!was) { tr.classList.add('picked'); picked = tr.dataset.folder; }
  else { picked = null; }
}

// Queue every track under a folder, including its subfolders, and start playing.
async function playFolder(dir){
  const r = await fetch('?tracks=' + encodeURIComponent(dir)).then(x => x.json()).catch(() => null);
  if (!r || !r.ok) { document.getElementById('npT').textContent = 'Could not read that folder'; return; }
  if (!r.tracks.length) { document.getElementById('npT').textContent = 'No audio files in there'; return; }
  TRACKS.length = 0; TRACKS.push(...r.tracks);
  // The rows on screen are only this folder's tracks, so highlighting by row
  // index no longer applies once the queue spans subfolders.
  document.querySelectorAll('tr.playing').forEach(t => t.classList.remove('playing'));
  buildOrder();
  play(order[0]);
}

// Play with nothing playing: the folder selected in the list, else the folder
// being viewed - in both cases including everything below it.
function playAll(){ playFolder(picked !== null ? picked : DIR); }
function toggle(){ if (idx<0) return playAll(); au.paused ? au.play() : au.pause(); }
function step(dir){
  if (!order.length) buildOrder();
  const at = order.indexOf(idx);
  const nxt = order[(at + dir + order.length) % order.length];
  play(nxt);
}
const next = () => step(1), prev = () => (au.currentTime > 3 ? au.currentTime = 0 : step(-1));
function toggleShuffle(){ shuffle=!shuffle; buildOrder(); document.getElementById('shf').classList.toggle('on',shuffle); }
function toggleRepeat(){ repeat=!repeat; document.getElementById('rpt').classList.toggle('on',repeat); }

// The play triangle is drawn slightly right of centre on purpose: a centred
// bounding box makes a triangle look left-heavy, so its visual centre is offset.
const ICON_PLAY  = 'M4.5 2.6l9 5.4-9 5.4z';
const ICON_PAUSE = 'M4.5 2.5h3v11h-3zM8.5 2.5h3v11h-3z';
const setIcon = d => document.querySelector('#ppIcon path').setAttribute('d', d);
au.onplay  = () => { setIcon(ICON_PAUSE); if (hasMS) navigator.mediaSession.playbackState = 'playing'; updatePosition(); };
au.onpause = () => { setIcon(ICON_PLAY);  if (hasMS) navigator.mediaSession.playbackState = 'paused'; };
au.onloadedmetadata = updatePosition;
au.onended = () => { if (repeat) { au.currentTime = 0; au.play(); } else next(); };
let posTick = 0;
au.ontimeupdate = () => {
  // The notification scrubber only needs an occasional update; doing it on every
  // timeupdate is wasteful and some platforms throttle it anyway.
  if (++posTick % 8 === 0) updatePosition();
  curEl.textContent = fmt(au.currentTime);
  if (au.duration) { durEl.textContent = fmt(au.duration); seek.value = String(au.currentTime/au.duration*1000); }
};
seek.oninput = () => { if (au.duration) au.currentTime = seek.value/1000*au.duration; };
document.getElementById('vol').oninput = e => au.volume = e.target.value/100;
addEventListener('keydown', e => {
  if (/^(INPUT|TEXTAREA)$/.test(e.target.tagName)) return;
  if (e.code === 'Space') { e.preventDefault(); toggle(); }
  else if (e.key === 'ArrowRight' && e.shiftKey) next();
  else if (e.key === 'ArrowLeft'  && e.shiftKey) prev();
});
/* ---------- media session: what keeps playback alive in the background ----
   Android will only continue audio from a backgrounded tab when the page owns
   a media session it can surface as a notification. Metadata alone is not
   enough - without play/pause handlers the notification has no controls and the
   session is treated as inert, so handlers are registered for everything the
   platform may offer. */
const hasMS = 'mediaSession' in navigator;

function folderOf(file){ const i = file.lastIndexOf('/'); return i < 0 ? '' : file.slice(0, i); }

function updateSession(i){
  if (!hasMS || !TRACKS[i]) return;
  const dir = folderOf(TRACKS[i].file);
  const art = new URL('?cover=' + encodeURIComponent(dir), location.href).href;
  navigator.mediaSession.metadata = new MediaMetadata({
    title:  TRACKS[i].title,
    artist: TRACKS[i].artist || '',
    album:  dir.split('/').pop() || 'Music',
    // If the folder has no cover this 404s and the platform just shows nothing,
    // which is better than omitting artwork entirely on devices that do have it.
    artwork: [{src: art, sizes: '400x400', type: 'image/jpeg'}],
  });
}

function updatePosition(){
  if (!hasMS || !navigator.mediaSession.setPositionState) return;
  if (!isFinite(au.duration) || au.duration <= 0) return;
  try {
    navigator.mediaSession.setPositionState({
      duration: au.duration,
      playbackRate: au.playbackRate || 1,
      position: Math.min(au.currentTime, au.duration),
    });
  } catch (e) { /* some versions reject odd values mid-seek */ }
}

if (hasMS) {
  const handlers = {
    play:          () => au.play(),
    pause:         () => au.pause(),
    stop:          () => { au.pause(); au.currentTime = 0; },
    nexttrack:     next,
    previoustrack: prev,
    seekbackward:  d => { au.currentTime = Math.max(0, au.currentTime - (d && d.seekOffset ? d.seekOffset : 10)); },
    seekforward:   d => { au.currentTime = Math.min(au.duration || 0, au.currentTime + (d && d.seekOffset ? d.seekOffset : 10)); },
    seekto:        d => { if (d && d.seekTime != null) { au.currentTime = d.seekTime; updatePosition(); } },
  };
  for (const [action, fn] of Object.entries(handlers)) {
    // Not every platform supports every action; an unsupported one throws.
    try { navigator.mediaSession.setActionHandler(action, fn); } catch (e) {}
  }
}

/* ---------- download from YouTube into the folder being viewed ---------- */
const ytPanel = document.getElementById('ytPanel'), ytLog = document.getElementById('ytLog'),
      ytState = document.getElementById('ytState'), ytGo = document.getElementById('ytGo');
async function startYt(ev){
  ev.preventDefault();
  const url = document.getElementById('ytUrl').value.trim();
  if (!url) return false;
  ytGo.disabled = true;
  ytPanel.classList.add('on'); ytState.textContent = 'starting…'; ytLog.textContent = '';
  const res = await fetch('?yt=1', {method:'POST', headers:{'Content-Type':'application/json'},
                                   body: JSON.stringify({url, dest: DIR})});
  const j = await res.json().catch(() => ({ok:false, error:'bad response'}));
  if (!j.ok) { ytState.textContent = ''; ytLog.textContent = 'Error: ' + (j.error || res.status); ytGo.disabled = false; return false; }
  poll(j.id);
  return false;
}
async function poll(id){
  ytState.textContent = 'running…';
  const tick = async () => {
    const r = await fetch('?ytstatus=' + id).then(x => x.json()).catch(() => null);
    if (!r || !r.ok) { ytState.textContent = 'lost track of the job'; ytGo.disabled = false; return; }
    ytLog.textContent = r.log || '(no output yet)';
    ytLog.scrollTop = ytLog.scrollHeight;
    if (r.running) { setTimeout(tick, 1500); return; }
    ytState.textContent = 'finished — reloading';
    ytGo.disabled = false;
    setTimeout(() => location.href = '?d=' + encodeURIComponent(DIR), 1200);
  };
  tick();
}
</script>
</body></html>
