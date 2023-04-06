
export default class WebAudio {
    constructor(url) {
        this.url = url

        //constants for audio behavoir
        this.maximumAudioLag = 0.5; //amount of seconds we can potentially be behind the server audio stream
        this.syncLagInterval = 5000; //check every x milliseconds if we are behind the server audio stream
        this.updateBufferEvery = 200; //add recieved data to the player buffer every x milliseconds
        this.reduceBufferInterval = 500; //trim the output audio stream buffer every x milliseconds so we don't overflow
        this.maximumSecondsOfBuffering = 1; //maximum amount of data to store in the play buffer
        this.connectionCheckInterval = 500; //check the connection every x milliseconds

        //register all our background timers. these need to be created only once - and will run independent of the object's streams/properties
        setInterval(() => this.updateQueue(), this.updateBufferEvery);
        setInterval(() => this.syncInterval(), this.syncLagInterval);
        setInterval(() => this.reduceBuffer(), this.reduceBufferInterval);
        setInterval(() => this.tryLastPacket(), this.connectionCheckInterval);

    }

    //starts the web audio stream. only call this method on button click.
    start() {
        if (this.socket && this.socket.readyState<=1 ) return;

        this.lastPacket=null;
        this.queue = null;
        this.mediaSource = new MediaSource()
        this.mediaSource.addEventListener('sourceopen', e => this.onSourceOpen())
        //first we need a media source - and an audio object that contains it.
        if(this.audio)this.audio.remove();
        this.audio = document.createElement('audio');
        this.audio.src = window.URL.createObjectURL(this.mediaSource);
        //start our stream - we can only do this on user input
        this.audio.play();
    }

    wsConnect() {
        if (this.socket) this.socket.close();

        this.socket = new WebSocket(this.url)
        this.socket.binaryType = 'arraybuffer'
        this.socket.addEventListener('message', e => this.websocketDataArrived(e), false);
    }

    //this is called when the media source contains data
    onSourceOpen(e) {
        this.buffer = this.mediaSource.addSourceBuffer('audio/mp4; codecs="mp4a.40.2"')
        this.wsConnect();
    }

    //whenever data arrives in our websocket this is called.
    websocketDataArrived(e) {
        this.lastPacket = Date.now();
        this.queue = this.queue == null ? e.data : this.concat(this.queue, e.data);
    }


    tryLastPacket() {
        if (this.lastPacket == null) return;
        if ((Date.now() - this.lastPacket) > 10000) {
            this.socket.close(); //timeout, packets do not usually talke this long
        }
    }

    //this updates the buffer with the data from our queue
    updateQueue() {
        if (!(!!this.queue && !!this.buffer && !this.buffer.updating)) {
            return;
        }

        this.buffer.appendBuffer(this.queue);
        this.queue = null;
    }

    //reduces the stream buffer to the minimal size that we need for streaming
    reduceBuffer() {
        if (!(this.buffer && !this.buffer.updating && !!this.audio && !!this.audio.currentTime && this.audio.currentTime > 1)) {
            return;
        }

        this.buffer.remove(0, this.audio.currentTime - 1);
    }

    //synchronizes the current time of the stream with the server
    syncInterval() {
        if (!(this.audio && this.audio.currentTime && this.audio.currentTime > 1 && this.buffer && this.buffer.buffered && this.buffer.buffered.length > 1)) {
            return;
        }

        var currentTime = this.audio.currentTime;
        var targetTime = this.buffer.buffered.end(this.buffer.buffered.length - 1);

        if (targetTime > (currentTime + this.maximumAudioLag)) this.audio.fastSeek(targetTime);
    }

    //joins two data arrays - helper function
    concat(buffer1, buffer2) {
        var tmp = new Uint8Array(buffer1.byteLength + buffer2.byteLength);
        tmp.set(new Uint8Array(buffer1), 0);
        tmp.set(new Uint8Array(buffer2), buffer1.byteLength);
        return tmp.buffer;
    };
}
