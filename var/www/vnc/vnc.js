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

    paste(str) {
         for (let i = 0, len = str.length; i < len; i++) {
           this.rfb.sendKey(str.charCodeAt(i));
         }
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

        // Set parameters that can be changed on an active connection
        this.rfb.viewOnly = this.readQueryVariable('view_only', false);
        this.rfb.scaleViewport = this.readQueryVariable('scale', true);
    }

}
