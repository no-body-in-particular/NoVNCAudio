<?php
// Restart the VNC service.
//
// Deliberately lives on the client-certificate vhost rather than the public
// site. `/etc/init.d/vnc restart` runs `pkill -U 1000` in its stop(), which
// tears down the whole X session and every application in it, and it needs
// root. Exposed on the public site that would be an unauthenticated one-click
// denial of service on the desktop, and would additionally require giving the
// web server sudo. Here PHP already runs as admin via cgi-wrapper, and admin
// already has sudo, so no new privilege is granted to anything.

declare(strict_types=1);
umask(0022);

const MTLS_PORT = '8443';
const LOG       = '/var/log/vnc-restart.log';

if (($_SERVER['SERVER_PORT'] ?? '') !== MTLS_PORT) {
    $host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'coredump.ws'))[0];
    $to   = 'https://' . $host . ':' . MTLS_PORT . '/vnc-restart.php';
    header('Location: ' . $to, true, 302);
    $e = htmlspecialchars($to, ENT_QUOTES);
    exit('<!doctype html><meta charset="utf-8"><title>Restart VNC</title>'
       . '<body style="font:15px system-ui;background:#31363b;color:#eff0f1;padding:44px">'
       . '<p>Restarting the VNC server needs your client certificate.</p>'
       . '<p><a style="color:#16a085" href="' . $e . '">' . $e . '</a></p>');
}

// Is the VNC port accepting connections again?
function vnc_up(): bool {
    $s = @fsockopen('127.0.0.1', 5802, $e, $s2, 1.5);
    if ($s) { fclose($s); return true; }
    return false;
}

if (isset($_GET['status'])) {
    header('Content-Type: application/json');
    echo json_encode(['up' => vnc_up()]);
    exit;
}

$started = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    // Detached on purpose. The init script's stop() runs `pkill -U 1000`, and
    // this PHP process *is* uid 1000 - run inline it would kill itself midway
    // and could leave the service stopped. sudo makes the child root, so the
    // pkill does not reach it, and setsid puts it outside this process group.
    // The redirect has to happen *inside* sudo. Written as
    //     setsid sudo -n /etc/init.d/vnc restart > /var/log/... &
    // the shell performs the redirect before sudo elevates, i.e. as the CGI
    // user, and /var/log is root-owned - so it failed with "Permission denied"
    // and the restart never ran at all, silently and with no log to show for it.
    $inner = sprintf('/etc/init.d/vnc restart > %s 2>&1', escapeshellarg(LOG));
    $cmd   = sprintf('setsid sudo -n /bin/sh -c %s &', escapeshellarg($inner));
    @exec('/bin/sh -c ' . escapeshellarg($cmd) . ' >/dev/null 2>&1');
    $started = true;
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Restart VNC</title>
<style>
:root{--win:#31363b;--view:#232629;--fg:#eff0f1;--dim:#9aa2a8;--line:#4d5257;--sel:#16a085;--warn:#da4453}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:var(--win);color:var(--fg);display:flex;align-items:center;
     justify-content:center;padding:24px;
     font:14px/1.55 "Noto Sans","DejaVu Sans",Cantarell,-apple-system,BlinkMacSystemFont,sans-serif}
.card{background:var(--view);border:1px solid var(--line);border-radius:10px;padding:26px 30px;
      max-width:560px;width:100%;box-shadow:0 14px 40px rgba(0,0,0,.45)}
h1{margin:0 0 6px;font-size:19px}
.sub{color:var(--dim);margin-bottom:18px}
ul{margin:0 0 20px;padding-left:20px;color:var(--dim)}
li{margin:3px 0}
.warn{border-left:3px solid var(--warn);padding:9px 13px;background:rgba(218,68,83,.10);
      border-radius:0 5px 5px 0;margin-bottom:20px}
button{background:var(--warn);color:#fff;border:0;border-radius:5px;padding:9px 20px;font:inherit;
       font-weight:600;cursor:pointer}
button:hover{background:#e2564f}
a.back{color:var(--dim);text-decoration:none;margin-left:14px}
a.back:hover{color:var(--sel)}
pre{background:#1b1e20;border:1px solid var(--line);border-radius:6px;padding:11px 13px;overflow:auto;
    font:12px/1.5 "DejaVu Sans Mono",ui-monospace,monospace;color:var(--dim);max-height:40vh}
.state{display:flex;align-items:center;gap:9px;margin-bottom:14px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--warn)}
.dot.up{background:var(--sel)}
</style></head><body><div class="card">

<?php if (!$started): ?>
  <h1>Restart the VNC server</h1>
  <div class="sub">This restarts <code>/etc/init.d/vnc</code>.</div>
  <div class="warn"><b>This ends the desktop session.</b> The init script's stop step runs
    <code>pkill -U 1000</code>, so everything running as your user is terminated.</div>
  <ul>
    <li>Every open application closes — browsers, editors, terminals, unsaved work included</li>
    <li>X restarts, so the session comes back empty</li>
    <li>Your VNC connection drops and needs reconnecting (your certificate still applies)</li>
    <li>Audio comes back with it; the file share and this page are unaffected</li>
  </ul>
  <form method="post">
    <input type="hidden" name="confirm" value="yes">
    <button type="submit">Restart VNC now</button>
    <a class="back" href="/">Cancel</a>
  </form>
<?php else: ?>
  <h1>Restarting…</h1>
  <div class="sub">The service is restarting in the background. This usually takes 10–30 seconds.</div>
  <div class="state"><span class="dot" id="dot"></span><span id="msg">waiting for the VNC port…</span></div>
  <pre id="log">(waiting for output)</pre>
  <a class="back" href="/vnc-restart.php">Back</a>
  <script>
  const dot = document.getElementById('dot'), msg = document.getElementById('msg');
  let tries = 0;
  (async function poll(){
    tries++;
    const r = await fetch('?status=1').then(x => x.json()).catch(() => null);
    if (r && r.up) {
      dot.classList.add('up');
      msg.textContent = 'VNC is accepting connections again.';
      document.getElementById('log').textContent =
        'Reconnect at https://' + location.hostname + '/vnc/';
      return;
    }
    msg.textContent = 'waiting for the VNC port… (' + tries + ')';
    if (tries < 40) setTimeout(poll, 1500);
    else msg.textContent = 'Still not up after a minute — check ' + <?= json_encode(LOG) ?>;
  })();
  </script>
<?php endif; ?>
</div></body></html>
