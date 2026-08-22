/*
 * noVNC: HTML5 VNC client
 * Copyright (C) 2020 The noVNC Authors
 * Licensed under MPL 2.0 (see LICENSE.txt)
 *
 * See README.md for usage and integration instructions.
 */

import { inflateInit, inflate, inflateReset } from "./vendor/pako/lib/zlib/inflate.js";
import ZStream from "./vendor/pako/lib/zlib/zstream.js";
import * as Log from './util/logging.js';

/*
 * Inflate is on the hot path: Tight keeps four zlib streams and ZRLE one, and
 * every rect is decompressed on the main thread. pako is a JavaScript zlib and
 * costs roughly twice what compiled zlib does - measured on this box, per
 * inflate:
 *
 *      12 KB out   pako 0.034ms   wasm 0.015ms
 *     196 KB out   pako 0.447ms   wasm 0.205ms
 *    1.44 MB out   pako 3.532ms   wasm 1.831ms
 *
 * so a full-screen repaint spends several ms in pako that it need not.
 *
 * The obvious alternative, the browser's native DecompressionStream, is async,
 * while these decoders pull an exact byte count synchronously from a long-lived
 * stream - that is an architectural change, not a swap. WebAssembly is
 * synchronous, so zlib compiled to wasm drops straight in and reaches the same
 * speed as native (0.205ms vs node's 0.210ms at 196 KB).
 *
 * Note it is *compiled code* that wins, not vectorisation: a -msimd128 build
 * measured identically (0.205 vs 0.208ms), because inflate is branch- and
 * table-driven with nothing to vectorise. The shipped module is the scalar one.
 *
 * If the module is missing, fails to compile, or every stream slot is taken,
 * each Inflate falls back to pako on its own, so the client keeps working.
 */

let wasm = null;
const freeSlots = [];

// Reclaim a stream slot once its Inflate is collected. Each RFB connection
// builds five of them, so without this a few reconnects would exhaust the pool
// and silently drop everyone back to pako.
const slotRegistry = (typeof FinalizationRegistry !== 'undefined')
    ? new FinalizationRegistry((slot) => {
        if (wasm) {
            try { wasm.exports.zs_end(slot); } catch (e) { /* module gone */ }
            freeSlots.push(slot);
        }
    })
    : null;

/*
 * Compile the wasm inflate. Call and await this once before connecting - the
 * Inflate constructor is synchronous (the decoders build theirs in their own
 * constructors), so the module has to be ready beforehand or pako is used.
 *
 * Deliberately instantiate() over instantiateStreaming(): the latter requires
 * the server to send application/wasm, and falling back on a MIME mismatch is
 * more moving parts than just fetching the bytes.
 */
export async function initInflateWasm(url) {
    if (wasm) return true;
    try {
        const resp = await fetch(url);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const bytes = await resp.arrayBuffer();
        const { instance } = await WebAssembly.instantiate(bytes, {});
        const e = instance.exports;
        for (const fn of ['zs_init', 'zs_reset', 'zs_end', 'zs_inflate',
                          'zs_in_ptr', 'zs_out_ptr', 'zs_in_cap', 'zs_out_cap',
                          'zs_produced']) {
            if (typeof e[fn] !== 'function') throw new Error('missing export ' + fn);
        }
        wasm = {
            exports: e,
            inPtr: e.zs_in_ptr(), outPtr: e.zs_out_ptr(),
            inCap: e.zs_in_cap(), outCap: e.zs_out_cap(),
        };
        const slots = 64;
        for (let i = slots - 1; i >= 0; i--) freeSlots.push(i);
        Log.Info('inflate: using wasm zlib');
        return true;
    } catch (err) {
        wasm = null;
        Log.Warn('inflate: wasm unavailable, using pako: ' + err);
        return false;
    }
}

export default class Inflate {
    constructor() {
        this._slot = -1;

        if (wasm && freeSlots.length > 0) {
            const slot = freeSlots.pop();
            // windowBits 5 is what this client has always asked for; the wasm
            // side clamps it to a legal value (real zlib rejects <8, pako did
            // not, which is why this went unnoticed).
            if (wasm.exports.zs_init(slot, 5) === 0) {
                this._slot = slot;
                this._pending = null;
                if (slotRegistry) slotRegistry.register(this, slot);
                return;
            }
            freeSlots.push(slot);
        }

        this.strm = new ZStream();
        this.chunkSize = 1024 * 10 * 10;
        this.strm.output = new Uint8Array(this.chunkSize);
        this.windowBits = 5;

        inflateInit(this.strm, this.windowBits);
    }

    setInput(data) {
        if (this._slot >= 0) {
            // Staged into wasm memory on the next inflate(), so that a
            // setInput(null) after a rect costs nothing.
            this._pending = data || null;
            return;
        }

        if (!data) {
            //FIXME: flush remaining data.
            /* eslint-disable camelcase */
            this.strm.input = null;
            this.strm.avail_in = 0;
            this.strm.next_in = 0;
        } else {
            this.strm.input = data;
            this.strm.avail_in = this.strm.input.length;
            this.strm.next_in = 0;
            /* eslint-enable camelcase */
        }
    }

    inflate(expected) {
        if (this._slot >= 0) {
            const e = wasm.exports;
            let inLen = -1;                       // -1: keep draining this stream
            if (this._pending) {
                if (this._pending.length > wasm.inCap) {
                    throw new Error("zlib input larger than wasm staging buffer");
                }
                new Uint8Array(e.memory.buffer).set(this._pending, wasm.inPtr);
                inLen = this._pending.length;
                this._pending = null;
            }
            if (expected > wasm.outCap) {
                throw new Error("zlib output larger than wasm staging buffer");
            }
            const ret = e.zs_inflate(this._slot, inLen, expected);
            if (ret < 0) {
                throw new Error("zlib inflate failed");
            }
            const got = e.zs_produced();
            if (got !== expected) {
                throw new Error("Incomplete zlib block");
            }
            // Copy out: the caller keeps the result past the next inflate, and
            // wasm memory can also be replaced wholesale if it ever grows.
            return new Uint8Array(e.memory.buffer, wasm.outPtr, got).slice();
        }

        // resize our output buffer if it's too small
        // (we could just use multiple chunks, but that would cause an extra
        // allocation each time to flatten the chunks)
        if (expected > this.chunkSize) {
            this.chunkSize = expected;
            this.strm.output = new Uint8Array(this.chunkSize);
        }

        /* eslint-disable camelcase */
        this.strm.next_out = 0;
        this.strm.avail_out = expected;
        /* eslint-enable camelcase */

        let ret = inflate(this.strm, 0); // Flush argument not used.
        if (ret < 0) {
            throw new Error("zlib inflate failed");
        }

        if (this.strm.next_out != expected) {
            throw new Error("Incomplete zlib block");
        }

        return new Uint8Array(this.strm.output.buffer, 0, this.strm.next_out);
    }

    reset() {
        if (this._slot >= 0) {
            wasm.exports.zs_reset(this._slot);
            this._pending = null;
            return;
        }
        inflateReset(this.strm);
    }
}
