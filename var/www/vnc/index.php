

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
            right: -22em;
            top: 50%;
            width: 20em;
            background: hsla(80, 50%, 60%, 0.9);
            color: white;
            margin: -3em 0 0 0;
            padding: 0.5em 0.5em 0.5em 2.5em;
            transition: 0.2s
         }
         #menu:hover { right: 0 }
         .myButton {
            box-shadow:inset 0px 1px 3px 0px #91b8b3;
            background:linear-gradient(to bottom, #768d87 5%, #6c7c7c 100%);
            background-color:#768d87;
            border-radius:5px;
            border:1px solid #566963;
            display:inline-block;
            cursor:pointer;
            color:#ffffff;
            font-family:Arial;
            font-size:15px;
            font-weight:bold;
            padding:6px 10px;
            text-decoration:none;
            text-shadow:0px -1px 0px #2b665e;
            margin-left: 10px;
            margin-right: 10px;
         }
         .textbox { 
            border: 3px double #848484; 
            outline:0; 
            height:25px; 
            width: 50px;
            margin-left: 10px;
            margin-right: 10px; 
         }
         .myButton:hover {
            background:linear-gradient(to bottom, #6c7c7c 5%, #768d87 100%);
            background-color:#6c7c7c;
         }
         .myButton:active {
            position:relative;
            top:1px;
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
         document.getElementById('paste').onclick = x => vnc.paste(document.getElementById('cboard').value);
         document.getElementById('fullscreen').onclick = x => {vnc.toggleFullscreen();}
         document.getElementById('cboard').onclick = x => { document.getElementById('cboard').value=''; };
         document.getElementById('screen').focus();;
         
      </script>
   </head>
   <body>
      <ul id=menu>
         <a href="#" class="myButton" id="fullscreen">fullscreen</a><input type="text" id="cboard" name="cboard" size="10" /><a href="#" class="myButton" id="paste">paste</a>
         <li>
            <label for="quality">Quality:</label>
            <input id="quality" type="range" min="0" max="9" value="9">
         </li>
         <li>
            <label for="compression">Compression level:</label>
            <input id="compression" type="range" min="0" max="9" value="4">
         </li>
      </ul>
      <div id="screen"></div>
   </body>
</html>

