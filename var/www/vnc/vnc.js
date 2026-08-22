// RFB holds the API to connect and communicate with a VNC server
import RFB from './rfb.js';
import WebAudio from './webaudio.js';

export default class VNC {
    constructor() {
        this.password = this.readQueryVariable('password');
        this.protocol = (window.location.protocol === 'https:' ? 'wss' : 'ws');
        this.url = this.protocol + '://'+ window.location.host +':5802/websockify';
        this.audio = new WebAudio(this.protocol + '://' + window.location.host+':5702/websockify');
    }


    toggleFullscreen() {
        if (document.fullscreenElement || // alternative standard method
            document.mozFullScreenElement || // currently working methods
            document.webkitFullscreenElement ||
            document.msFullscreenElement) {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.mozCancelFullScreen) {
                document.mozCancelFullScreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }
        } else {
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen();
            } else if (document.documentElement.mozRequestFullScreen) {
                document.documentElement.mozRequestFullScreen();
            } else if (document.documentElement.webkitRequestFullscreen) {
                document.documentElement.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT);
            } else if (document.body.msRequestFullscreen) {
                document.body.msRequestFullscreen();
            }
        }
    }

    stopReconnect() {
        if (this.reconnectTimer) {
            clearInterval(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }

    // When this function is called we have
    // successfully connected to a server
    connectedToServer() {
        this.stopReconnect();
        var myself = this;
        var viewer = document.getElementsByTagName('canvas')[0];
        viewer.addEventListener('keydown', e => myself.startAudio());
    }

    static get XK_Shift_L()   { return 0xffe1; }
    static get XK_Control_L() { return 0xffe3; }
    static get XK_Super_L()   { return 0xffeb; }
    static get XK_Insert()    { return 0xff63; }

    // Type a string into the session one key at a time.
    //
    // The old version sent each character's keysym with no modifier - right for a server that
    // reads keysyms, but a server that maps keysym onto a hardware scancode needs Shift actually
    // held to produce a shifted glyph, so '!', '@', '?', capitals and the rest came out as '1',
    // '2', '/', lower case. Shifted characters are wrapped in a real Shift press now.
    paste(str) {
        for (const ch of str) {                     // by code point, surrogate pairs stay whole
            const cp = ch.codePointAt(0);
            const keysym = cp < 0x100 ? cp : 0x01000000 + cp;   // X11: Latin-1 direct, rest via the plane
            const shifted = VNC.needsShift(ch);

            if (shifted) { this.rfb.sendKey(VNC.XK_Shift_L, "ShiftLeft", true); }
            this.rfb.sendKey(keysym);               // down + up
            if (shifted) { this.rfb.sendKey(VNC.XK_Shift_L, "ShiftLeft", false); }
        }
    }

    // Capitals and the shifted symbols of a US layout.
    static needsShift(ch) {
        if (ch.length === 1 && ch >= 'A' && ch <= 'Z') { return true; }
        return '~!@#$%^&*()_+{}|:\"<>?'.indexOf(ch) !== -1;
    }

    // Share the clipboard with the host both ways, without the textbox. The server sends whatever
    // is copied inside the session as a clipboard event, which is written to the browser clipboard
    // so it can be pasted on this machine. A Ctrl+V while the session is focused hands the browser
    // clipboard to the server, to be pasted inside the guest - the paste gesture grants the read,
    // so no permission prompt.
    hookClipboard() {
        // Host -> this machine: whatever is copied inside the session arrives as a clipboard
        // event and is written to the browser clipboard, so it can be pasted locally.
        this.rfb.addEventListener("clipboard", e => {
            const text = e.detail && e.detail.text;
            if (!text) { return; }
            this._remoteClipboard = text;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).catch(() => {});
            }
        });

        if (this._clipboardHooked) { return; }       // guard: a reconnect builds a fresh rfb
        this._clipboardHooked = true;

        // This machine -> host, on Ctrl+V (or Cmd+V). It is caught in the capture phase and
        // stopped there, so noVNC does not also forward the physical Ctrl+V to the guest - which
        // it would send *before* the clipboard update reached the server, pasting the previous
        // contents. Instead the clipboard is set first, then the guest's own paste shortcut is
        // pressed, so the order is right and the paste happens exactly once.
        //
        // Reading the clipboard needs the async API, which prompts once. Where it is not available
        // the key is left alone and the fallback paste listener below sets the clipboard instead,
        // leaving the actual paste keystroke to the user.
        const canRead = navigator.clipboard && navigator.clipboard.readText;

        window.addEventListener('keydown', e => {
            if (!this.rfb || !canRead) { return; }

            const key = e.key ? e.key.toLowerCase() : '';
            const paste = (e.ctrlKey || e.metaKey) && !e.altKey && key === 'v';

            if (!paste) { return; }

            const t = e.target;
            if (t && /^(INPUT|TEXTAREA)$/.test(t.tagName)) { return; }   // leave the textbox alone

            e.preventDefault();
            e.stopImmediatePropagation();               // keep it away from noVNC's own handler

            navigator.clipboard.readText().then(text => this.pasteToGuest(text)).catch(() => {});
        }, true);

        // The matching key ups, so noVNC does not forward a lone 'v' up for a press it never saw.
        window.addEventListener('keyup', e => {
            if (!this.rfb || !canRead) { return; }
            const key = e.key ? e.key.toLowerCase() : '';
            if ((e.ctrlKey || e.metaKey) && key === 'v') {
                const t = e.target;
                if (t && /^(INPUT|TEXTAREA)$/.test(t.tagName)) { return; }
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }, true);

        // Fallback for a browser without clipboard.readText: set the host clipboard from the paste
        // event and let the user press the guest's paste key themselves.
        document.addEventListener('paste', e => {
            if (!this.rfb || canRead) { return; }
            const t = e.target;
            if (t && /^(INPUT|TEXTAREA)$/.test(t.tagName)) { return; }
            const data = e.clipboardData || window.clipboardData;
            const text = data ? data.getData('text') : '';
            if (text) { this.rfb.clipboardPasteFrom(text); }
        });
    }

    // Which keystroke pastes on the host. Ctrl+V by default; a guest that pastes with Shift+Insert
    // (many terminals) or with a Super/Cmd based shortcut can be selected with ?pastekey=...
    guestPasteCombo() {
        switch ((this.readQueryVariable('pastekey', 'ctrl-v') || 'ctrl-v').toLowerCase()) {
            case 'shift-insert':
                return { mods: [[VNC.XK_Shift_L, 'ShiftLeft']], key: [VNC.XK_Insert, 'Insert'] };
            case 'super-v':
            case 'cmd-v':
                return { mods: [[VNC.XK_Super_L, 'MetaLeft']], key: [0x76, 'KeyV'] };
            case 'ctrl-shift-v':
                return { mods: [[VNC.XK_Control_L, 'ControlLeft'], [VNC.XK_Shift_L, 'ShiftLeft']], key: [0x76, 'KeyV'] };
            default:
                return { mods: [[VNC.XK_Control_L, 'ControlLeft']], key: [0x76, 'KeyV'] };
        }
    }

    // Press a modifier+key chord on the guest: modifiers down, key down and up, modifiers up.
    sendCombo(combo) {
        for (const [ks, code] of combo.mods) { this.rfb.sendKey(ks, code, true); }
        this.rfb.sendKey(combo.key[0], combo.key[1], true);
        this.rfb.sendKey(combo.key[0], combo.key[1], false);
        for (const [ks, code] of combo.mods.slice().reverse()) { this.rfb.sendKey(ks, code, false); }
    }

    // Put the text on the host clipboard, then press the host's paste shortcut. The clipboard
    // message is queued on the socket before the key events, so a short delay is only insurance
    // against the extended-clipboard handshake, which is a round trip rather than a single message.
    pasteToGuest(text) {
        if (!text) { return; }
        this.rfb.clipboardPasteFrom(text);
        setTimeout(() => this.sendCombo(this.guestPasteCombo()), 60);
    }

  // This function is called when we are disconnected
    disconnectedFromServer() {
//        this.reconnectTimer = setInterval(() => this.start(), 3500);
    }

    // When this function is called, the server requires
    // credentials to authenticate
    credentialsAreRequired() {
        this.password = prompt("Password Required:");
        this.rfb.sendCredentials({
            password: this.password
        });
    }


    // This function extracts the value of one variable from the
    // query string. If the variable isn't defined in the URL
    // it returns the default value instead.
    readQueryVariable(name, defaultValue) {
        const re = new RegExp('.*[?&]' + name + '=([^&#]*)'),
            match = document.location.href.match(re);

        if (match) {
            // We have to decode the URL since want the cleartext value
            return decodeURIComponent(match[1]);
        }

        return defaultValue;
    }

    // Query variables arrive as strings, and every non-empty string is truthy -
    // so ?scale=false used to switch scaling ON. Anything meant as a flag has to
    // come through here instead.
    readQueryFlag(name, defaultValue) {
        const raw = this.readQueryVariable(name, null);
        if (raw === null) {
            return defaultValue;
        }
        return !/^(0|false|no|off)$/i.test(raw.trim());
    }

    setQuality(quality) {
        this.rfb.qualityLevel = parseInt(quality);
    }

    setCompression(level) {
        this.rfb.compressionLevel = parseInt(level);
    }
    startAudio() {
        this.audio.start();
    }
    start() {
        // Creating a new RFB object will start a new connection
        this.rfb = new RFB(document.getElementById('screen'), this.url, {
            credentials: {
                password: this.password
            }
        });

        // Add listeners to important events from the RFB module
        this.rfb.addEventListener("connect", () => this.connectedToServer());
        this.rfb.addEventListener("disconnect", () => this.disconnectedFromServer());
        this.rfb.addEventListener("credentialsrequired", () => this.credentialsAreRequired());

        // share the clipboard with the host both ways, no textbox needed
        this.hookClipboard();

        // Set parameters that can be changed on an active connection
        this.rfb.viewOnly = this.readQueryFlag('view_only', false);
        // Scaling off by default: the framebuffer is a fixed 1920x1080, so
        // fitting it to the window resamples every frame and, on a HiDPI
        // display, never lines up with device pixels - permanently soft. Off,
        // pixels map 1:1 and the browser does no resampling at all.
        // Re-enable per session with ?scale=true.
        this.rfb.scaleViewport = this.readQueryFlag('scale', false);

        // Apply the image settings at connect. They were only ever set from the slider's onchange,
        // so at load neither was applied and noVNC quietly used its own defaults - the 9 and 4 the
        // sliders showed were never in effect. Both are read from the URL now, defaulting now to the
        // light end - quality 3, compression 6 - which keeps the stream responsive over a slow
        // link at the cost of some sharpness. A fast connection can turn it up: ?quality=9&compression=2.
        this.rfb.qualityLevel = parseInt(this.readQueryVariable('quality', 3), 10);
        this.rfb.compressionLevel = parseInt(this.readQueryVariable('compression', 6), 10);

        // Clipping must follow scaling, not be chosen independently. #screen is
        // overflow:hidden, so an unscaled 1920x1080 canvas in a smaller window
        // would have its right and bottom edges cut off with no scrollbars and
        // no way to reach them. clipViewport gives a draggable viewport
        // instead, which is what makes scaling-off usable. If scaling is turned
        // back on the canvas always fits, so clipping is not wanted - hence the
        // default tracks scaleViewport rather than being a fixed value.
        this.rfb.clipViewport = this.readQueryFlag('clip', !this.rfb.scaleViewport);
    }

}

