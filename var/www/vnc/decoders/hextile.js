/*
 * noVNC: HTML5 VNC client
 * Copyright (C) 2019 The noVNC Authors
 * Licensed under MPL 2.0 (see LICENSE.txt)
 *
 * See README.md for usage and integration instructions.
 *
 */

import * as Log from '../util/logging.js';

// Pack a colour into one little-endian RGBA word (alpha forced opaque), so a
// pixel can be written with a single 32-bit store.
function pixel32(red, green, blue) {
    return ((255 << 24) | (blue << 16) | (green << 8) | red) >>> 0;
}

export default class HextileDecoder {
    constructor() {
        this._tiles = 0;
        this._lastsubencoding = 0;
        this._tileBuffer = new Uint8Array(16 * 16 * 4);
        // 32-bit view of the same bytes. Solid fills below write whole pixels
        // through this instead of four byte stores each: V8 lowers
        // Uint32Array.fill() to a vectorized memset. Safe to alias because the
        // buffer is freshly allocated, so its byteOffset is 0 (4-byte aligned).
        this._tileBuffer32 = new Uint32Array(this._tileBuffer.buffer);
    }

    decodeRect(x, y, width, height, sock, display, depth) {
        if (this._tiles === 0) {
            this._tilesX = Math.ceil(width / 16);
            this._tilesY = Math.ceil(height / 16);
            this._totalTiles = this._tilesX * this._tilesY;
            this._tiles = this._totalTiles;
        }

        while (this._tiles > 0) {
            let bytes = 1;

            if (sock.rQwait("HEXTILE", bytes)) {
                return false;
            }

            let rQ = sock.rQ;
            let rQi = sock.rQi;

            let subencoding = rQ[rQi];  // Peek
            if (subencoding > 30) {  // Raw
                throw new Error("Illegal hextile subencoding (subencoding: " +
                            subencoding + ")");
            }

            const currTile = this._totalTiles - this._tiles;
            const tileX = currTile % this._tilesX;
            const tileY = Math.floor(currTile / this._tilesX);
            const tx = x + tileX * 16;
            const ty = y + tileY * 16;
            const tw = Math.min(16, (x + width) - tx);
            const th = Math.min(16, (y + height) - ty);

            // Figure out how much we are expecting
            if (subencoding & 0x01) {  // Raw
                bytes += tw * th * 4;
            } else {
                if (subencoding & 0x02) {  // Background
                    bytes += 4;
                }
                if (subencoding & 0x04) {  // Foreground
                    bytes += 4;
                }
                if (subencoding & 0x08) {  // AnySubrects
                    bytes++;  // Since we aren't shifting it off

                    if (sock.rQwait("HEXTILE", bytes)) {
                        return false;
                    }

                    let subrects = rQ[rQi + bytes - 1];  // Peek
                    if (subencoding & 0x10) {  // SubrectsColoured
                        bytes += subrects * (4 + 2);
                    } else {
                        bytes += subrects * 2;
                    }
                }
            }

            if (sock.rQwait("HEXTILE", bytes)) {
                return false;
            }

            // We know the encoding and have a whole tile
            rQi++;
            if (subencoding === 0) {
                if (this._lastsubencoding & 0x01) {
                    // Weird: ignore blanks are RAW
                    Log.Debug("     Ignoring blank after RAW");
                } else {
                    display.fillRect(tx, ty, tw, th, this._background);
                }
            } else if (subencoding & 0x01) {  // Raw
                let pixels = tw * th;
                // Max sure the image is fully opaque
                for (let i = 0;i <  pixels;i++) {
                    rQ[rQi + i * 4 + 3] = 255;
                }
                display.blitImage(tx, ty, tw, th, rQ, rQi);
                rQi += bytes - 1;
            } else {
                if (subencoding & 0x02) {  // Background
                    this._background = [rQ[rQi], rQ[rQi + 1], rQ[rQi + 2], rQ[rQi + 3]];
                    rQi += 4;
                }
                if (subencoding & 0x04) {  // Foreground
                    this._foreground = [rQ[rQi], rQ[rQi + 1], rQ[rQi + 2], rQ[rQi + 3]];
                    rQi += 4;
                }

                this._startTile(tx, ty, tw, th, this._background);
                if (subencoding & 0x08) {  // AnySubrects
                    let subrects = rQ[rQi];
                    rQi++;

                    for (let s = 0; s < subrects; s++) {
                        let color;
                        if (subencoding & 0x10) {  // SubrectsColoured
                            color = [rQ[rQi], rQ[rQi + 1], rQ[rQi + 2], rQ[rQi + 3]];
                            rQi += 4;
                        } else {
                            color = this._foreground;
                        }
                        const xy = rQ[rQi];
                        rQi++;
                        const sx = (xy >> 4);
                        const sy = (xy & 0x0f);

                        const wh = rQ[rQi];
                        rQi++;
                        const sw = (wh >> 4) + 1;
                        const sh = (wh & 0x0f) + 1;

                        this._subTile(sx, sy, sw, sh, color);
                    }
                }
                this._finishTile(display);
            }
            sock.rQi = rQi;
            this._lastsubencoding = subencoding;
            this._tiles--;
        }

        return true;
    }

    // start updating a tile
    _startTile(x, y, width, height, color) {
        this._tileX = x;
        this._tileY = y;
        this._tileW = width;
        this._tileH = height;

        const red = color[0];
        const green = color[1];
        const blue = color[2];

        // One vectorized fill instead of 4 byte stores per pixel (~2.9x on a
        // 16x16 tile, byte-identical output).
        this._tileBuffer32.fill(pixel32(red, green, blue), 0, width * height);
    }

    // update sub-rectangle of the current tile
    _subTile(x, y, w, h, color) {
        const red = color[0];
        const green = color[1];
        const blue = color[2];
        const xend = x + w;
        const yend = y + h;

        const data32 = this._tileBuffer32;
        const width = this._tileW;
        const packed = pixel32(red, green, blue);
        // Each row of the sub-rectangle is contiguous, so fill it in one go.
        for (let j = y; j < yend; j++) {
            const row = j * width;
            data32.fill(packed, row + x, row + xend);
        }
    }

    // draw the current tile to the screen
    _finishTile(display) {
        display.blitImage(this._tileX, this._tileY,
                          this._tileW, this._tileH,
                          this._tileBuffer, 0);
    }
}
