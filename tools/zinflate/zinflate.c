/*
 * Freestanding wasm wrapper around zlib's inflate, shaped to match what noVNC's
 * inflator.js needs: several independent long-lived streams, each fed compressed
 * chunks and asked for an exact number of output bytes.
 *
 * Built with -DZ_SOLO so zlib pulls in no stdio/stdlib and uses its own
 * zmemcpy/zmemzero. The only thing zlib still needs from us is an allocator, and
 * it lets us supply one per stream via zalloc/zfree plus an opaque pointer.
 *
 * Allocation strategy: each stream slot owns a fixed arena and zalloc just bumps
 * a pointer inside it. inflate allocates exactly twice per stream (state, then
 * window) and frees both at inflateEnd, so a bump pointer reset on end is a
 * complete and leak-free story - no general-purpose allocator needed.
 */

#include "zlib.h"

#define N_SLOTS      64     /* 5 streams per RFB connection; room for reconnects */
#define ARENA_BYTES  (64 * 1024)      /* state ~7K + window <=32K for wbits 15 */
#define IN_CAP       (4  << 20)       /* 4 MB compressed staging */
#define OUT_CAP      (8  << 20)       /* 8 MB decompressed staging */

typedef struct {
    unsigned char arena[ARENA_BYTES];
    unsigned      used;
    z_stream      strm;
    int           active;
} slot_t;

static slot_t slots[N_SLOTS];
static unsigned char in_buf[IN_CAP];
static unsigned char out_buf[OUT_CAP];

/* results of the last zs_inflate, read back by JS */
static int last_consumed;
static int last_produced;

static void *arena_alloc(void *opaque, unsigned items, unsigned size) {
    slot_t *s = (slot_t *)opaque;
    unsigned n = items * size;
    n = (n + 7u) & ~7u;                       /* keep 8-byte alignment */
    if (s->used + n > ARENA_BYTES) return Z_NULL;
    void *p = s->arena + s->used;
    s->used += n;
    return p;
}

static void arena_free(void *opaque, void *addr) {
    (void)opaque; (void)addr;                 /* freed wholesale in zs_end */
}

__attribute__((export_name("zs_in_ptr")))
unsigned char *zs_in_ptr(void)  { return in_buf; }

__attribute__((export_name("zs_out_ptr")))
unsigned char *zs_out_ptr(void) { return out_buf; }

__attribute__((export_name("zs_in_cap")))
int zs_in_cap(void)  { return IN_CAP; }

__attribute__((export_name("zs_out_cap")))
int zs_out_cap(void) { return OUT_CAP; }

__attribute__((export_name("zs_consumed")))
int zs_consumed(void) { return last_consumed; }

__attribute__((export_name("zs_produced")))
int zs_produced(void) { return last_produced; }

__attribute__((export_name("zs_init")))
int zs_init(int slot, int window_bits) {
    if (slot < 0 || slot >= N_SLOTS) return Z_STREAM_ERROR;
    slot_t *s = &slots[slot];
    if (s->active) inflateEnd(&s->strm);
    s->used = 0;
    s->strm.zalloc  = arena_alloc;
    s->strm.zfree   = arena_free;
    s->strm.opaque  = s;
    s->strm.next_in = Z_NULL;
    s->strm.avail_in = 0;
    /* noVNC asks for windowBits 5. pako tolerates that (it inflates streams
     * compressed with a full 32K window correctly anyway), but real zlib
     * rejects anything outside 8..15 with Z_STREAM_ERROR. For inflate a larger
     * window is always safe - it only has to be at least as big as the one the
     * compressor used - so clamp undersized requests up to the maximum. */
    if (window_bits != 0 && window_bits < 8) window_bits = 15;
    int rc = inflateInit2(&s->strm, window_bits);
    s->active = (rc == Z_OK);
    return rc;
}

__attribute__((export_name("zs_reset")))
int zs_reset(int slot) {
    if (slot < 0 || slot >= N_SLOTS || !slots[slot].active) return Z_STREAM_ERROR;
    return inflateReset(&slots[slot].strm);
}

__attribute__((export_name("zs_end")))
int zs_end(int slot) {
    if (slot < 0 || slot >= N_SLOTS || !slots[slot].active) return Z_STREAM_ERROR;
    slot_t *s = &slots[slot];
    int rc = inflateEnd(&s->strm);
    s->used = 0;
    s->active = 0;
    return rc;
}

/*
 * Inflate from in_buf[0..in_len) into out_buf[0..out_want).
 *
 * Mirrors pako's use in inflator.js: the caller knows exactly how many bytes it
 * wants and the stream carries over between calls, so leftover input stays in
 * zlib's stream state and a later call continues from it. Pass in_len < 0 to
 * mean "no new input, keep draining what the stream already holds".
 */
__attribute__((export_name("zs_inflate")))
int zs_inflate(int slot, int in_len, int out_want) {
    if (slot < 0 || slot >= N_SLOTS || !slots[slot].active) return Z_STREAM_ERROR;
    if (out_want < 0 || out_want > OUT_CAP) return Z_BUF_ERROR;
    slot_t *s = &slots[slot];
    z_stream *z = &s->strm;

    if (in_len >= 0) {
        if (in_len > IN_CAP) return Z_BUF_ERROR;
        z->next_in  = in_buf;
        z->avail_in = (unsigned)in_len;
    }
    unsigned char *in_start = z->next_in;

    z->next_out  = out_buf;
    z->avail_out = (unsigned)out_want;

    int rc = inflate(z, Z_SYNC_FLUSH);

    last_produced = out_want - (int)z->avail_out;
    last_consumed = in_start ? (int)(z->next_in - in_start) : 0;
    return rc;
}
