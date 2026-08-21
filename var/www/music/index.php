<?php
// Entry point for the music player at coredump.ws/music.
//
// The player itself lives under the client-certificate vhost, because
// hiawatha's RequiredCA is a per-binding setting: demanding a certificate on
// 443 would demand one for the whole public site. This only redirects.
$host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'coredump.ws'))[0];
$to   = 'https://' . $host . ':8443/music/';
header('Location: ' . $to, true, 302);
$e = htmlspecialchars($to, ENT_QUOTES);
?><!doctype html><meta charset="utf-8"><title>Music</title>
<body style="font:15px -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#31363b;color:#eff0f1;padding:44px">
<p>The music player needs your client certificate, which is required on its own port.</p>
<p><a style="color:#16a085" href="<?= $e ?>"><?= $e ?></a></p>
