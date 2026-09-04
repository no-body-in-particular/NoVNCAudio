
// Plays the desktop's audio, which tcpulse serves as fragmented MP4/AAC over a
// WebSocket on its own port, into a detached <audio> element via MediaSource.
//
// The previous version started a stream per keydown. Its re-entrancy guard was
// `if (this.socket && this.socket.readyState <= 1) return`, but this.socket is
// only assigned from the MediaSource 'sourceopen' handler, which fires a frame
// or two after start() returns. Two keys pressed inside that window - ordinary
// typing - each built a MediaSource, an <audio> element and a socket, and the
// last one to open won this.buffer while an earlier socket was still appending
// into this.queue. The result was one byte stream interleaved from two encoders
// (each of which starts with its own ftyp+moov), so appendBuffer() threw inside
// a setInterval callback, nothing caught it, and the session was left with a
// dead SourceBuffer and a pile of half-open sockets. That is why the server saw
// tens of thousands of connections that never got as far as spawning a single
// encoder.
//
// The fix is to make intent explicit and asynchronous work cancellable:
//   - `wanted` records whether audio should be playing, and start()/stop() are
//     idempotent with respect to it.
//   - `generation` is bumped on every start and stop; every asynchronous
//     callback compares its captured generation against the current one and
//     returns if it is stale, so a superseded attempt can never touch the
//     live stream.
//   - every appendBuffer() is wrapped, and a failure restarts the stream once
//     after a delay rather than tearing it down permanently or reconnecting in
//     a loop.

const OFF = 'off', CONNECTING = 'connecting', PLAYING = 'playing',
      BLOCKED = 'blocked', ERROR = 'error';

export default class WebAudio {
    constructor(url, onState) {
        this.url = url;
        this.onState = onState || (() => {});

        // Constants for audio behaviour.
        this.maximumAudioLag = 0.5;      // seconds we may fall behind the server
        this.syncLagInterval = 5000;     // check that often for having fallen behind
        this.updateBufferEvery = 200;    // feed the player buffer that often
        this.reduceBufferInterval = 500; // trim the buffer that often, so it cannot grow
        this.connectionCheckInterval = 500;
        this.stallTimeout = 10000;       // no data for this long means the stream is dead
        this.retryDelay = 2000;          // wait before rebuilding a failed stream

        this.wanted = false;             // whether audio should be playing at all
        this.generation = 0;             // bumped on start/stop; stale callbacks bail out
        this.state = OFF;
        this.retryAt = 0;

        this.socket = null;
        this.mediaSource = null;
        this.buffer = null;
        this.audio = null;
        this.queue = null;
        this.lastPacket = null;

        // Background timers. These are created once and run for the lifetime of
        // the object, independent of any individual stream.
        setInterval(() => this.updateQueue(), this.updateBufferEvery);
        setInterval(() => this.syncInterval(), this.syncLagInterval);
        setInterval(() => this.reduceBuffer(), this.reduceBufferInterval);
        setInterval(() => this.watchdog(), this.connectionCheckInterval);
    }

    setState(state) {
        if (this.state === state) { return; }
        this.state = state;
        try { this.onState(state); } catch (e) { /* a broken indicator may not stop audio */ }
    }

    // Start playing. Safe to call repeatedly - including several times inside one
    // event loop turn, which is what the keydown trigger does.
    //
    // Must be called from a user gesture the first time: play() is invoked
    // synchronously here so the browser's autoplay policy sees the gesture.
    start() {
        if (this.wanted) { return; }
        this.wanted = true;
        this.open();
    }

    // Try play() again on the stream that is already running. Called from a
    // fresh user gesture after the browser refused the first attempt, so the
    // buffered audio becomes audible without dropping and rebuilding anything.
    retryPlay() {
        if (!this.wanted || !this.audio || this.state !== BLOCKED) { return; }
        const gen = this.generation;
        const playing = this.audio.play();
        if (playing && playing.then) {
            playing.then(() => {
                if (gen === this.generation) { this.setState(PLAYING); }
            }).catch(() => { /* still refused; the label stays on Blocked */ });
        }
    }

    stop() {
        this.wanted = false;
        this.teardown();
        this.setState(OFF);
    }

    toggle() {
        if (this.wanted) { this.stop(); } else { this.start(); }
        return this.wanted;
    }

    // Build a fresh stream. Everything asynchronous below is tagged with the
    // generation current at this point.
    open() {
        this.teardown();            // bumps the generation for us

        const gen = this.generation;
        this.setState(CONNECTING);
        this.lastPacket = Date.now();       // the stall watchdog starts counting now
        this.queue = null;

        this.mediaSource = new MediaSource();
        this.mediaSource.addEventListener('sourceopen', () => this.onSourceOpen(gen));

        this.audio = document.createElement('audio');
        this.audio.autoplay = true;
        this.audio.src = window.URL.createObjectURL(this.mediaSource);

        // play() rejects when the browser has not seen a gesture it accepts. That
        // is worth showing, because nothing else about the stream looks wrong.
        const playing = this.audio.play();
        if (playing && playing.catch) {
            playing.catch(err => {
                if (gen !== this.generation) { return; }
                // NotAllowedError is the autoplay policy and is the only
                // rejection the user can do anything about. Anything else is a
                // fault in this stream and is reported as one.
                if (err && err.name === 'NotAllowedError') { this.setState(BLOCKED); }
                else { this.scheduleRetry(ERROR); }
            });
        }
    }

    // Drop the current stream and everything attached to it. The socket's
    // listener is removed before it is closed so a late frame cannot land in the
    // queue of the stream that replaces it.
    teardown() {
        // Invalidate every callback still in flight for the stream being
        // dropped. Without this the play() promise below rejects with an
        // AbortError *because* this method paused the element, the rejection
        // arrives after teardown, and a plain stream failure gets reported as
        // "Blocked" - which points the diagnosis at the browser's autoplay
        // policy when the actual fault was the socket.
        this.generation++;
        if (this.socket) {
            this.socket.onmessage = null;
            this.socket.onerror = null;
            this.socket.onclose = null;
            try { this.socket.close(); } catch (e) { /* already closing */ }
            this.socket = null;
        }
        if (this.audio) {
            try { this.audio.pause(); } catch (e) { /* nothing to pause */ }
            if (this.audio.src) { window.URL.revokeObjectURL(this.audio.src); }
            this.audio.removeAttribute('src');
            this.audio.remove();
            this.audio = null;
        }
        this.mediaSource = null;
        this.buffer = null;
        this.queue = null;
        this.lastPacket = null;
    }

    // Rebuild the stream after a failure, once, after a delay. The watchdog does
    // the actual work so several failures inside one stream collapse into one
    // reconnect instead of a burst.
    scheduleRetry(state) {
        if (!this.wanted) { return; }
        this.teardown();
        this.setState(state || ERROR);
        this.retryAt = Date.now() + this.retryDelay;
    }

    onSourceOpen(gen) {
        if (gen !== this.generation || !this.mediaSource) { return; }
        try {
            this.buffer = this.mediaSource.addSourceBuffer('audio/mp4; codecs="mp4a.40.2"');
        } catch (e) {
            this.scheduleRetry(ERROR);
            return;
        }
        this.wsConnect(gen);
    }

    wsConnect(gen) {
        let socket;
        try {
            socket = new WebSocket(this.url);
        } catch (e) {
            this.scheduleRetry(ERROR);
            return;
        }
        socket.binaryType = 'arraybuffer';
        socket.onmessage = e => {
            if (gen !== this.generation) { return; }
            this.lastPacket = Date.now();
            // Data arriving does not mean it is audible: if play() was refused
            // the stream still buffers, and saying "on" would be a lie.
            if (this.state !== BLOCKED) { this.setState(PLAYING); }
            this.queue = this.queue == null ? e.data : this.concat(this.queue, e.data);
        };
        // A refused socket is nearly always a missing client certificate for this
        // origin - the audio port is its own origin and needs its own decision.
        socket.onerror = () => { if (gen === this.generation) { this.scheduleRetry(ERROR); } };
        socket.onclose = () => { if (gen === this.generation) { this.scheduleRetry(ERROR); } };
        this.socket = socket;
    }

    // Move whatever has arrived into the player buffer.
    updateQueue() {
        if (!this.queue || !this.buffer || this.buffer.updating) { return; }
        if (!this.mediaSource || this.mediaSource.readyState !== 'open') { return; }

        const data = this.queue;
        this.queue = null;
        try {
            this.buffer.appendBuffer(data);
        } catch (e) {
            // A corrupt or interleaved byte stream, or a full buffer. Either way
            // this SourceBuffer cannot recover; build a new stream instead.
            this.scheduleRetry(ERROR);
        }
    }

    // Keep the buffer down to roughly the last second of audio.
    reduceBuffer() {
        if (!this.buffer || this.buffer.updating || !this.audio) { return; }
        if (!this.audio.currentTime || this.audio.currentTime <= 1) { return; }
        if (!this.mediaSource || this.mediaSource.readyState !== 'open') { return; }
        try {
            this.buffer.remove(0, this.audio.currentTime - 1);
        } catch (e) { /* a busy or detached buffer is trimmed on the next tick */ }
    }

    // Jump forward when playback has drifted behind what the server has sent.
    syncInterval() {
        if (!this.audio || !this.buffer || !this.audio.currentTime) { return; }
        if (this.audio.currentTime <= 1) { return; }
        let buffered;
        try { buffered = this.buffer.buffered; } catch (e) { return; }
        if (!buffered || buffered.length < 1) { return; }

        const currentTime = this.audio.currentTime;
        const targetTime = buffered.end(buffered.length - 1);
        if (targetTime > currentTime + this.maximumAudioLag) {
            // fastSeek is not in every browser; currentTime is the portable form.
            if (this.audio.fastSeek) { this.audio.fastSeek(targetTime); }
            else { this.audio.currentTime = targetTime; }
        }
    }

    // Rebuild a stream that has gone quiet, and carry out a scheduled retry.
    // Both paths run here so a failure can only ever produce one reconnect.
    watchdog() {
        if (!this.wanted) { return; }

        if (this.retryAt) {
            if (Date.now() >= this.retryAt) { this.retryAt = 0; this.open(); }
            return;
        }
        if (this.lastPacket && (Date.now() - this.lastPacket) > this.stallTimeout) {
            this.scheduleRetry(ERROR);
        }
    }

    // Joins two array buffers - helper function.
    concat(buffer1, buffer2) {
        const tmp = new Uint8Array(buffer1.byteLength + buffer2.byteLength);
        tmp.set(new Uint8Array(buffer1), 0);
        tmp.set(new Uint8Array(buffer2), buffer1.byteLength);
        return tmp.buffer;
    }
}
