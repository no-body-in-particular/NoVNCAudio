

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
         vnc.start();
         
         document.getElementById('quality').onchange = x =>  vnc.setQuality(x.srcElement.value);
         document.getElementById('compression').onchange = x =>  vnc.setCompression(x.srcElement.value);
         document.getElementById('fullscreen').onclick = x => {vnc.toggleFullscreen();}
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
            <label for="quality">Quality</label>
            <input id="quality" type="range" min="0" max="9" value="3">
         </li>
         <li>
            <label for="compression">Compression</label>
            <input id="compression" type="range" min="0" max="9" value="6">
         </li>
      </ul>
      <div id="screen"></div>
   </body>
</html>

