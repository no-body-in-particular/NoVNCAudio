

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
            overflow: hidden;
         }
         #menu {
            position: fixed;
            right: -12.75em;
            top: 50%;
            box-sizing: border-box;
            width: 13em;
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
         import VNC from './vnc.js';
         
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
            const frame = document.getElementById('certPrimeVnc');
            let started = false;
            const go = () => { if (!started) { started = true; vnc.start(); } };
            // x11vnc is not a web server, so the request itself fails after the
            // handshake - fine, the handshake was the point. Either outcome
            // means the certificate decision has been made.
            frame.addEventListener('load', go);
            frame.addEventListener('error', go);
            // Never let a hung or slow prime keep the session from starting.
            setTimeout(go, 8000);
            frame.src = 'https://' + window.location.hostname + ':5802/';
         })();

         document.getElementById('quality').onchange = x =>  vnc.setQuality(x.srcElement.value);
         document.getElementById('compression').onchange = x =>  vnc.setCompression(x.srcElement.value);
         document.getElementById('fullscreen').onclick = x => {vnc.toggleFullscreen();}
         // Prime the audio origin's certificate before any audio connection is made.
         document.getElementById('certPrime').src = 'https://' + window.location.hostname + ':5702/';

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
         <li>
            <a href="#" class="myButton" id="fullscreen" title="Toggle fullscreen">
               <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
               <span>Fullscreen</span>
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

