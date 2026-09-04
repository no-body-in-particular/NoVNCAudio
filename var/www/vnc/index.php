<?php
// Compress this page if the client will take it.
//
// Hiawatha gzips static files - rfb.js goes out as 24 kB of its 120 kB - but it
// does not touch CGI output, so this page was leaving as 16.8 kB of markup
// where 5.4 kB does. It is also the first thing that has to arrive before any
// of the session can begin, so it is the one page where the saving is on the
// critical path rather than in parallel with everything else.
//
// Not set globally in php.ini on purpose: share/index.php and share/music
// stream downloads and a zip that is built as it is sent, and compressing those
// a second time would burn CPU to make them slightly larger, and buffering them
// would undo the streaming. This is the page that benefits, so this is the page
// that asks.
//
// ob_gzhandler reads Accept-Encoding itself and does nothing for a client that
// did not offer gzip; the plain buffer is the fallback if zlib is unavailable.
if (!@ob_start('ob_gzhandler')) {
    ob_start();
}
?>


<!doctype html>
<html>
   <head>
      <title>Remote admin</title>
      <meta charset="utf-8">
      <meta http-equiv="X-UA-Compatible" content="IE=edge" />
      <style>
            body {
            margin: 0;
            background-color: dimgrey;
            height: 100%;
            display: flex;
            flex-direction: column;
         }
         html {
            height: 100%;
         }
         #screen {
            flex: 1; /* fill remaining space */
            /* Centre the desktop without scaling it. This only does anything
               when clipViewport is off (?clip=false): with clipping on, which
               is the default, noVNC stretches the canvas to exactly fill this
               box and there is nothing left to position.
               `safe centre` centres it while there is room and falls back to
               top-left once there is not, so an oversized desktop stays
               reachable by scrolling instead of having its top and left edges
               centred off screen where nothing can get at them. overflow:auto
               rather than hidden for the same reason - the alternative is
               losing the edges outright. */
            overflow: auto;
            display: flex;
            align-items: safe center;
            justify-content: safe center;
         }
         #menu {
            position: fixed;
            right: -14.75em;
            top: 50%;
            box-sizing: border-box;
            width: 15em;
            margin: 0;
            transform: translateY(-50%);
            padding: 0.9em 1em;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.85em;
            background: hsla(222, 18%, 27%, 0.86);
            -webkit-backdrop-filter: blur(12px);
            backdrop-filter: blur(12px);
            border: 1px solid hsla(0, 0%, 100%, 0.14);
            border-right: none;
            border-radius: 10px 0 0 10px;
            box-shadow: -6px 0 24px hsla(222, 40%, 4%, 0.28);
            color: #e6eaf2;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            transition: right 0.22s ease;
         }
         #menu:hover, #menu:focus-within { right: 0 }
         #menu label {
            display: block;
            margin-bottom: 0.4em;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: hsla(220, 20%, 100%, 0.68);
         }
         #menu input[type=range] {
            width: 100%;
            margin: 0;
            accent-color: #6c8cff;
            cursor: pointer;
         }
         .myButton {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5em;
            padding: 0.55em 0.9em;
            background: #4f6bf0;
            border: 1px solid hsla(0, 0%, 100%, 0.14);
            border-radius: 8px;
            color: #ffffff;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            cursor: pointer;
            user-select: none;
            box-shadow: 0 1px 2px hsla(222, 40%, 4%, 0.4);
            transition: background 0.15s ease, transform 0.08s ease, box-shadow 0.15s ease;
         }
         .myButton svg { flex: none }
         /* Fullscreen and Audio sit two-up. Each takes half the panel, which is
            narrower than either label wants, so they are set smaller and tighter
            than the stacked buttons and the label ellipsizes rather than wraps -
            a wrapped label would make one button taller than its neighbour.
            min-width:0 is the load-bearing part: a flex item defaults to
            min-width:auto and will not shrink below its content, so without it
            the pair overflows the panel instead of sharing it. */
         #menu .row {
            display: flex;
            gap: 0.5em;
         }
         #menu .row .myButton {
            flex: 1 1 0;
            min-width: 0;
            padding: 0.55em 0.5em;
            gap: 0.35em;
            font-size: 12px;
         }
         #menu .row .myButton span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
         }
         .myButton:hover {
            background: #6079f4;
            box-shadow: 0 3px 10px hsla(228, 80%, 50%, 0.35);
         }
         .myButton:active {
            background: #4058d8;
            transform: translateY(1px);
            box-shadow: 0 1px 2px hsla(222, 40%, 4%, 0.4);
         }
         /* Files overlay: the share, framed over the session. It is a separate
            origin (its own port, because the client certificate is required
            per-binding), so the page cannot see events happening inside the
            frame - hence the explicit close button, since Escape only reaches
            this page when focus is not inside the frame. */
         #filesOverlay {
            position: fixed;
            inset: 3vh 3vw;
            z-index: 9999;
            display: none;
            flex-direction: column;
            background: #232629;
            border: 1px solid #4d5257;
            border-radius: 10px;
            box-shadow: 0 24px 70px rgba(0,0,0,.6);
            overflow: hidden;
         }
         #filesOverlay.open { display: flex }
         #filesBar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 12px;
            background: #31363b;
            border-bottom: 1px solid #4d5257;
            color: #eff0f1;
            font: 600 13px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            flex: none;
         }
         #filesBar .grow { flex: 1 }
         #filesBar .hint { font-weight: 400; color: #9aa2a8; font-size: 12px }
         #filesClose {
            background: none; border: 0; color: #eff0f1; font-size: 16px;
            width: 26px; height: 26px; border-radius: 5px; cursor: pointer;
         }
         #filesClose:hover { background: #da4453; color: #fff }
         #filesFrame { flex: 1; width: 100%; border: 0; background: #232629 }
         .myButton:focus-visible {
            outline: 2px solid #9db1ff;
            outline-offset: 2px;
         }
      </style>
      <!-- Promise polyfill for IE11 -->
      <script src="promise.js"></script>
      <!-- ES2015/ES6 modules polyfill -->
      <script nomodule src="browser-es-module-loader.js"></script>
      <!-- actual script modules -->
      <script type="module" crossorigin="anonymous" >
         // Cache-bust the module graph on every change. A browser that has cached
         // an older vnc.js/webaudio.js keeps running it across an ordinary reload,
         // which makes a fixed bug look unfixed - and the old audio client opened a
         // socket per keystroke, so a stale copy is loud about it. filemtime means
         // this never needs remembering; webaudio.js carries the same query through
         // vnc.js's own import.
         import VNC from './vnc.js?v=<?= filemtime(__DIR__ . '/vnc.js') ?>';
         
         let vnc = new VNC();

         // Prime the VNC origin's certificate BEFORE opening the socket.
         //
         // The session socket is wss://host:5802, a different origin from this
         // page (the certificate is required per binding, so it needs its own
         // port). A browser picks a certificate for a navigation but not for a
         // WebSocket a script opens on an origin it has no decision for yet.
         // On a browser that had already visited :5802 the decision was cached
         // and this worked; on a fresh machine it is not, so the handshake was
         // abandoned and the server logged:
         //   SSL_accept() *FATAL: -1 SSL FAILED
         //   error:0A000126:SSL routines::unexpected eof while reading
         // which looks like a connection problem but is a missing certificate.
         // Loading the origin in a frame first is a navigation, so the choice
         // is made and cached, and the WebSocket then reuses it.
         (function primeThenStart() {
            const host = window.location.hostname;
            const vncFrame = document.getElementById('certPrimeVnc');
            const audioFrame = document.getElementById('certPrime');

            // Navigate a hidden frame to an origin and resolve once the browser
            // has made its certificate decision - either outcome will do, since
            // neither x11vnc nor tcpulse is a web server and the request itself
            // is expected to fail after the handshake.
            const settle = (frame, url) => new Promise((resolve) => {
               let done = false;
               const go = () => { if (!done) { done = true; resolve(); } };
               frame.addEventListener('load', go);
               frame.addEventListener('error', go);
               setTimeout(go, 8000);   // never let a hung prime block the session
               frame.src = url;
            });

            // These MUST run one after another, not together. A browser offers
            // one client-certificate picker at a time, so two simultaneous
            // navigations to different ports race and the loser is left with no
            // decision; its socket then dies in the TLS handshake, which tcpulse
            // logs as "Failed to accept SSL connection / Connection closed
            // during handshake". Priming :5702 alone used to work for exactly
            // that reason - it had the picker to itself until :5802 was added.
            settle(vncFrame, 'https://' + host + ':5802/')
               .then(() => settle(audioFrame, 'https://' + host + ':5702/'))
               // Compile the wasm zlib before connecting: the decoders build
               // their Inflate objects inside the synchronous RFB constructor,
               // so the module has to be ready by then or pako is used.
               .then(() => import('./inflator.js')
                             .then(m => m.initInflateWasm('./zinflate.wasm')))
               .catch(() => {})        // nothing here may stop the session
               .finally(() => vnc.start());
         })();

         document.getElementById('quality').onchange = x =>  vnc.setQuality(x.srcElement.value);
         document.getElementById('compression').onchange = x =>  vnc.setCompression(x.srcElement.value);
         document.getElementById('fullscreen').onclick = x => {vnc.toggleFullscreen();}
         document.getElementById('audio').onclick = e => { e.preventDefault(); vnc.toggleAudio(); };
         // (the audio origin is primed in primeThenStart() above, in sequence
         // with the session origin - see the note there about the picker race)

         const filesUrl = 'https://' + window.location.hostname + ':8443/';
         const overlay  = document.getElementById('filesOverlay');
         const frame    = document.getElementById('filesFrame');

         function showFiles(e) {
             if (e) { e.preventDefault(); }
             if (frame.src !== filesUrl) { frame.src = filesUrl; }   // load once, keep state
             overlay.classList.add('open');
             overlay.setAttribute('aria-hidden', 'false');
         }
         function hideFiles() {
             overlay.classList.remove('open');
             overlay.setAttribute('aria-hidden', 'true');
             document.getElementById('screen').focus();
         }

         document.getElementById('files').onclick = showFiles;
         document.getElementById('filesClose').onclick = hideFiles;

         // Capture phase, so Escape closes the overlay instead of being
         // forwarded into the remote session by noVNC's own key handling.
         window.addEventListener('keydown', e => {
             if (e.key === 'Escape' && overlay.classList.contains('open')) {
                 e.preventDefault();
                 e.stopImmediatePropagation();
                 hideFiles();
             }
         }, true);

         document.getElementById('screen').focus();;
         
      </script>
   </head>
   <body>
      <ul id=menu>
         <!-- Fullscreen and Audio share one row above Files: both are momentary
              toggles for how the session is presented, as against Files, which
              opens a panel. See #menu .row for why their labels are set to
              ellipsize rather than wrap. -->
         <li class="row">
            <a href="#" class="myButton" id="fullscreen" title="Toggle fullscreen">
               <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
               <span>Fullscreen</span>
            </a>
            <!-- Audio is a separate stream on its own port; a browser will only
                 start it from a user gesture, so it needs something to press.
                 It also starts on the first keypress or click in the session -
                 this button is how it is turned back off, and how its state
                 (connecting / on / blocked / failed) becomes visible instead of
                 failing silently. -->
            <a href="#" class="myButton" id="audio" data-state="off" title="Toggle desktop audio">
               <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/></svg>
               <span id="audioLabel">Audio</span>
            </a>
         </li>
         <li>
            <!-- The file browser needs the client certificate, which is only required on
                 its own binding, so it lives on port 8443 rather than under this page. -->
            <a href="#" class="myButton" id="files" title="Browse and transfer files in the home folder">
               <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
               <span>Files</span>
            </a>
         </li>
         <li>
            <label for="quality">Quality</label>
            <input id="quality" type="range" min="0" max="9" value="3">
         </li>
         <li>
            <label for="compression">Compression</label>
            <input id="compression" type="range" min="0" max="9" value="6">
         </li>
      </ul>
      <div id="filesOverlay" aria-hidden="true">
         <div id="filesBar">
            <span>Files</span>
            <span class="grow"></span>
            <span class="hint">Esc to close</span>
            <button id="filesClose" title="Close">&#10005;</button>
         </div>
         <iframe id="filesFrame" title="File share" referrerpolicy="no-referrer"></iframe>
      </div>
      <!-- Primes the client certificate for the audio origin.
           The audio socket is wss://host:5702, a different origin from this page
           (the certificate is required per binding, so it needs its own port).
           A browser will pick a certificate for a navigation but not for a
           WebSocket a script opens on an origin it has no decision for yet, so
           the audio connection was refused while the VNC one worked. Loading
           that origin in a frame is a navigation: the TLS handshake happens, the
           certificate choice is made and cached for the origin, and the audio
           WebSocket then reuses it. tcpulse is not a web server, so the request
           itself fails after the handshake - which is fine, the handshake was
           the point, and it spawns no encoder. -->
      <iframe id="certPrime" aria-hidden="true" tabindex="-1" title=""
              style="position:absolute;width:0;height:0;border:0;visibility:hidden"></iframe>
      <!-- Same trick for the session origin (wss://host:5802). Without this a
           browser that has never navigated to :5802 has no certificate decision
           for it, so the session WebSocket is refused and the session cannot be
           taken over from a new machine. -->
      <iframe id="certPrimeVnc" aria-hidden="true" tabindex="-1" title=""
              style="position:absolute;width:0;height:0;border:0;visibility:hidden"></iframe>
      <div id="screen"></div>
   </body>
</html>

