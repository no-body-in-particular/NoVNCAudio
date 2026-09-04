// RFB holds the API to connect and communicate with a VNC server
import RFB from './rfb.js';
// webaudio.js is imported with this module's own ?v= query so the two can
// never be served from cache as a mismatched pair. A computed specifier
// cannot be a static import, hence the top-level await.
const WebAudio = (await import('./webaudio.js?v='
    + (new URL(import.meta.url).searchParams.get('v') || '0'))).default;


export default class VNC {
    constructor() {
        this.password = this.readQueryVariable('password');
        this.protocol = (window.location.protocol === 'https:' ? 'wss' : 'ws');
        // hostname, not host: host carries this page's own port, which would be
        // spliced in ahead of the session/audio port and give an invalid URL on
        // any deployment that is not served on 443.
        const peer = this.protocol + '://' + window.location.hostname;
        this.url = peer + ':5802/websockify';
        this.audio = new WebAudio(peer + ':5702/websockify', s => this.onAudioState(s));
        this.audioOff = false;
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
        this.hookAudioStart();
    }

    // Audio may only be started from a user gesture, so a first keypress or
    // click in the session turns it on. This used to attach a keydown listener
    // to the canvas from connectedToServer(), which had two faults: a reconnect
    // builds a fresh rfb and so stacked another listener every time, and a
    // gesture that landed on the container rather than on the canvas itself
    // never reached it at all. One listener on the document, attached once and
    // in the capture phase, catches both without stacking.
    //
    // Gestures inside the menu are explicitly not a trigger. mousedown fires
    // before click and this listener is on the capture phase, so a press on the
    // Audio button itself would otherwise start the stream a moment before the
    // button's own click handler ran, saw it already playing, and stopped it
    // again - leaving the button unable to ever turn audio on, and latching
    // audioOff so the keyboard could not start it either.
    hookAudioStart() {
        if (this._audioHooked) { return; }
        this._audioHooked = true;

        const kick = e => {
            if (e.target && e.target.closest && e.target.closest('#menu')) { return; }
            if (this.audioOff) { return; }
            // A browser that refused the first play() needs another gesture to
            // try again, not another stream.
            if (this.audio.wanted) { this.audio.retryPlay(); } else { this.audio.start(); }
        };
        document.addEventListener('keydown', kick, true);
        document.addEventListener('mousedown', kick, true);
    }

    // Turning audio off with the button keeps it off: the next keystroke must
    // not silently start it again.
    toggleAudio() {
        // A stream that is running but silent because play() was refused should
        // retry on this press - the press is the gesture it was waiting for -
        // instead of being torn down.
        if (this.audio.wanted && this.audio.state === 'blocked') {
            this.audio.retryPlay();
            return true;
        }
        this.audioOff = this.audio.wanted;
        return this.audio.toggle();
    }

    // Reflect the stream's state on the Audio button, if the page has one.
    // The button is half the panel wide, so the label is a word and the whole
    // sentence goes in the tooltip.
    onAudioState(state) {
        const labels = { off: 'Audio', connecting: 'Audio', playing: 'Audio on',
                         blocked: 'Blocked', error: 'Failed' };
        const titles = {
            off: 'Desktop audio is off - click to start it',
            connecting: 'Connecting to the desktop audio stream...',
            playing: 'Desktop audio is playing - click to stop it',
            blocked: 'The browser refused to play audio; click the button to allow it',
            error: 'The audio stream failed. If this persists, open https://'
                   + window.location.hostname + ':5702/ in a tab and choose the '
                   + 'client certificate - the audio port is its own origin and '
                   + 'needs its own decision.'
        };
        const el = document.getElementById('audioLabel');
        if (el) { el.textContent = labels[state] || 'Audio'; }
        const btn = document.getElementById('audio');
        if (btn) {
            btn.setAttribute('data-state', state);
            btn.title = titles[state] || 'Toggle desktop audio';
        }
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
        // Scaling off: the framebuffer is drawn at its native size, so pixels
        // map 1:1, the browser resamples nothing and the picture stays sharp.
        // ?scale=true fits it to the window instead.
        this.rfb.scaleViewport = this.readQueryFlag('scale', false);

        // Apply the image settings at connect. They were only ever set from the slider's onchange,
        // so at load neither was applied and noVNC quietly used its own defaults - the 9 and 4 the
        // sliders showed were never in effect. Both are read from the URL now, defaulting now to the
        // light end - quality 3, compression 6 - which keeps the stream responsive over a slow
        // link at the cost of some sharpness. A fast connection can turn it up: ?quality=9&compression=2.
        this.rfb.qualityLevel = parseInt(this.readQueryVariable('quality', 3), 10);
        this.rfb.compressionLevel = parseInt(this.readQueryVariable('compression', 6), 10);

        // Clipping tracks scaling rather than being a fixed value. With
        // scaling off - the default - this is on: noVNC sizes the canvas to the
        // window and paints a sub-region of the framebuffer into it, which you
        // drag to reach the rest, keeping the whole desktop reachable at 1:1
        // with no scrollbars and no resampling. With ?scale=true the canvas
        // always fits, so clipping would be pointless and this turns itself off.
        //
        // While clipping is on the canvas exactly fills #screen, so the centring
        // rule there has nothing to position; the viewport starts at the
        // framebuffer's top left. ?clip=false gives a real-size canvas that
        // #screen will centre instead.
        this.rfb.clipViewport = this.readQueryFlag('clip', !this.rfb.scaleViewport);
    }

}

