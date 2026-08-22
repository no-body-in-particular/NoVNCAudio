#!/bin/sh
# Rebuild var/www/vnc/zinflate.wasm - zlib's inflate compiled freestanding for
# wasm32, so the client can decompress at compiled-C speed synchronously.
#
# Needs clang with the WebAssembly target and wasm-ld (Gentoo: llvm-core/lld).
# ZLIB should point at a zlib checkout (https://github.com/madler/zlib).
#
#   ZLIB=~/src/zlib ./build.sh
#
# -DZ_SOLO drops zlib's stdio/stdlib dependencies so it links with -nostdlib;
# the only thing left to supply is an allocator, which zinflate.c does per
# stream via zalloc/zfree.
#
# Deliberately NOT -msimd128: a SIMD build measured identically (0.205ms vs
# 0.208ms on a 196KB inflate). inflate is branch- and table-driven, so there is
# nothing to vectorise - the win is compiled code replacing JavaScript.
set -e
: "${ZLIB:?set ZLIB to a zlib source checkout}"
OUT="$(dirname "$0")/../../var/www/vnc/zinflate.wasm"
clang --target=wasm32 -O3 -DZ_SOLO -DNO_GZIP -I"$ZLIB" \
  -nostdlib -Wl,--no-entry -Wl,--export-dynamic \
  -Wl,--initial-memory=33554432 -Wl,--max-memory=67108864 \
  -Wl,--stack-first -Wl,-z,stack-size=65536 \
  -o "$OUT" \
  "$(dirname "$0")/zinflate.c" \
  "$ZLIB/inflate.c" "$ZLIB/inftrees.c" "$ZLIB/inffast.c" \
  "$ZLIB/adler32.c" "$ZLIB/crc32.c" "$ZLIB/zutil.c"
echo "built $OUT ($(stat -c%s "$OUT") bytes)"
