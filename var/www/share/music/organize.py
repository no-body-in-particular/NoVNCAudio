#!/usr/bin/env python3
"""Give every track in ~/Music its tags and cover art.

The library this was written for is built by youtube.py, and older downloads
carry no tags at all - nothing but `encoder=Lavf...`, no artist, no album, no
embedded picture. So the metadata cannot be read out of the files and has to be
reconstructed from their names.

By default files are tagged where they lie: the per-playlist folders yt-dlp
creates are the layout this library is kept in, and this script does not
disturb them. `--sort` additionally reorganises everything into Artist/Album
folders, which is a large and rarely wanted change - the undo script it writes
puts every file back if you try it and dislike the result.

Two sources of truth, in order of trust:

  1. The filename. 74% of this library is named "Artist - Title.mp3", which
     yt-dlp took from the video title. When that pattern is present the artist
     is taken from it and never overridden - a web lookup is not allowed to
     move a file away from an artist the filename already states.
  2. A web search, for everything else, and for album + artwork in both cases.
     Deezer is asked first because its public API needs no key and tolerates
     the request rate a library this size demands; iTunes is the fallback for
     what Deezer does not know. Both return metadata and a cover URL in one
     request.

The lookup is deliberately distrusted. Searching for a bare title is
guesswork: "Anne Bloom - SAD" comes back as Gracie Abrams / "Close To You",
and "SHOW UP LIKE THIS" as an AI-generated compilation. So a result is only
allowed to name the artist of a file that has no artist in its filename when
the title matches almost exactly AND the artist is corroborated (see
`decide()`). Everything else lands in "Unknown Artist" rather than somewhere
plausible but wrong - a file you can still find beats a file filed under a
stranger.

Nothing is written without --apply. The default run prints the plan and exits.

Dependencies: ffmpeg + ffprobe (already required by index.php). No Python
packages beyond the standard library - mutagen is not installed on this box,
so tags and artwork are written with an ffmpeg stream copy, which rewrites the
container without touching the audio.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter, defaultdict
from difflib import SequenceMatcher

ROOT = "/home/admin/Music"
CACHE = os.path.expanduser("~/.cache/share-music/organize")
PLAYER_CACHE = os.path.expanduser("~/.cache/share-music")
UNKNOWN_ARTIST = "Unknown Artist"
UNKNOWN_ALBUM = "Unknown Album"

AUDIO_EXT = {".mp3", ".flac", ".ogg", ".oga", ".opus", ".m4a", ".aac",
             ".wav", ".webm", ".wma", ".aiff", ".alac"}

# Cover art the player already recognises, so a folder that has one is left
# alone: index.php prefers a cover file over the embedded picture.
COVER_NAME = "cover.jpg"

USER_AGENT = "coredump-music-organizer/1.0 (+https://coredump.ws)"

# yt-dlp keeps the whole video title, so the filename carries a lot that is not
# part of the song. Strip it before searching or the query never matches.
NOISE = re.compile(
    r"""\s*(?:
        \((?:\s*(?:official\s*)?(?:music\s*)?video|official\s*audio|official|
             lyrics?|lyrics?\s*video|audio|visuali[sz]er|hd|hq|4k|
             full\s*album|live|remaster(?:ed)?(?:\s*\d{4})?)\s*[^)]*\)
      | \[[^\]]*\]
      | \{[^}]*\}
    )""",
    re.IGNORECASE | re.VERBOSE,
)

# A fullwidth pipe is what YouTube titles use as a separator, and yt-dlp keeps
# it because it is legal in a filename where "|" would have been sanitised.
TRAILER = re.compile(r"\s*[｜|·•]\s*.*$")

# "Artist - Title". The separator may be a hyphen, en dash or em dash. Bounded
# on the left so a title that merely contains a dash does not split.
ARTIST_TITLE = re.compile(r"^\s*(?P<artist>.{2,60}?)\s+[-–—]\s+(?P<title>.+?)\s*$")

ILLEGAL = re.compile(r"[/\x00-\x1f]")


def log(msg: str = "") -> None:
    print(msg, flush=True)


# --------------------------------------------------------------------------
# name handling
# --------------------------------------------------------------------------

def clean_title(name: str) -> str:
    """Filename stem -> something worth sending to a search engine."""
    n = TRAILER.sub("", name)
    n = NOISE.sub("", n)
    n = re.sub(r"\s+", " ", n)
    return n.strip(" -–—_")


def split_artist(stem: str) -> tuple[str | None, str]:
    """Return (artist or None, title) from a filename stem."""
    cleaned = clean_title(stem)
    m = ARTIST_TITLE.match(cleaned)
    if not m:
        return None, cleaned
    artist = m.group("artist").strip()
    title = m.group("title").strip()
    # "Mix - Evanescence" style folder prefixes are not artists, and a numeric
    # left side is a track number, not a name.
    if not title or artist.lower() in {"mix", "various", "va"} or artist.isdigit():
        return None, cleaned
    return artist, title


def norm(s: str) -> str:
    """Fold for comparison only - never for display or paths."""
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not unicodedata.combining(c))
    s = s.lower()
    s = re.sub(r"\b(feat|ft|featuring|with)\b.*$", "", s)
    s = re.sub(r"[^a-z0-9]+", "", s)
    return s


def similar(a: str, b: str) -> float:
    na, nb = norm(a), norm(b)
    if not na or not nb:
        return 0.0
    if na == nb:
        return 1.0
    return SequenceMatcher(None, na, nb).ratio()


def safe_name(s: str, fallback: str) -> str:
    """A path component that is safe on this filesystem and still readable."""
    s = ILLEGAL.sub("-", s).strip()
    s = re.sub(r"\s+", " ", s)
    s = s.rstrip(". ")               # trailing dots/spaces confuse some clients
    s = s[:120].rstrip(". ")
    return s or fallback


# --------------------------------------------------------------------------
# metadata + artwork lookup, cached on disk
# --------------------------------------------------------------------------

class Lookup:
    """Track metadata and cover art: Deezer first, iTunes as a fallback.

    Deezer's public search needs no key and tolerates roughly 50 requests every
    5 seconds, which is what makes a library this size practical. The same run
    against iTunes drew HTTP 403 and 429 after about 75 lookups and then spent
    all its time in backoff, so iTunes is now only asked about the tracks
    Deezer does not know, paced far more slowly, and dropped for the rest of
    the run once it starts refusing - a fallback that stalls the whole job is
    worse than no fallback at all.

    Both providers are normalised to the same four keys, and every response is
    cached on disk per provider and query, empty ones included, so a rerun
    costs no requests.
    """

    ITUNES_STRIKES = 3

    def __init__(self, delay: float, offline: bool = False) -> None:
        self.dir = os.path.join(CACHE, "lookup")
        os.makedirs(self.dir, exist_ok=True)
        self.delay = delay
        self.itunes_delay = max(delay, 3.0)
        self.offline = offline
        self.last = {"deezer": 0.0, "itunes": 0.0}
        self.requests = 0
        self.cached = 0
        self.strikes = 0
        self.itunes_dead = False

    def _path(self, provider: str, q: str) -> str:
        import hashlib
        return os.path.join(
            self.dir, provider + "-" + hashlib.sha1(q.encode()).hexdigest() + ".json")

    def _get(self, provider: str, url: str, delay: float) -> dict | None:
        """One paced request. None means the provider refused."""
        backoff = 2.0
        for attempt in range(3):
            wait = delay - (time.monotonic() - self.last[provider])
            if wait > 0:
                time.sleep(wait)
            try:
                req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
                with urllib.request.urlopen(req, timeout=20) as fh:
                    data = json.load(fh)
                self.last[provider] = time.monotonic()
                self.requests += 1
                return data
            except urllib.error.HTTPError as e:
                self.last[provider] = time.monotonic()
                if e.code in (403, 429, 503):
                    return None
                return {}
            except (urllib.error.URLError, ValueError, TimeoutError):
                self.last[provider] = time.monotonic()
                if attempt < 2:
                    time.sleep(backoff)
                    backoff *= 2
                    continue
                return {}
        return {}

    def _cached(self, provider: str, q: str) -> list[dict] | None:
        cf = self._path(provider, q)
        if os.path.isfile(cf):
            try:
                with open(cf) as fh:
                    self.cached += 1
                    return json.load(fh)
            except (OSError, ValueError):
                pass
        return None

    def _store(self, provider: str, q: str, results: list[dict]) -> None:
        try:
            with open(self._path(provider, q), "w") as out:
                json.dump(results, out)
        except OSError:
            pass

    def _deezer(self, term: str, limit: int) -> list[dict]:
        q = urllib.parse.urlencode({"q": term, "limit": limit})
        hit = self._cached("deezer", q)
        if hit is not None:
            return hit
        if self.offline:
            return []
        data = self._get("deezer", "https://api.deezer.com/search?" + q, self.delay)
        if data is None:
            return []
        out = []
        for x in data.get("data") or []:
            album = x.get("album") or {}
            out.append({
                "artistName": (x.get("artist") or {}).get("name", ""),
                "trackName": x.get("title") or "",
                "collectionName": album.get("title") or "",
                "artUrl": album.get("cover_xl") or album.get("cover_big") or "",
            })
        self._store("deezer", q, out)
        return out

    def _itunes(self, term: str, limit: int) -> list[dict]:
        q = urllib.parse.urlencode(
            {"term": term, "media": "music", "entity": "song", "limit": limit})
        hit = self._cached("itunes", q)
        if hit is not None:
            return hit
        if self.offline or self.itunes_dead:
            return []
        data = self._get("itunes", "https://itunes.apple.com/search?" + q,
                         self.itunes_delay)
        if data is None:
            self.strikes += 1
            if self.strikes >= self.ITUNES_STRIKES:
                self.itunes_dead = True
                log("    ! iTunes is rate limiting; using Deezer only from here on")
            return []
        self.strikes = 0
        out = []
        for r in data.get("results") or []:
            art = r.get("artworkUrl100") or r.get("artworkUrl60") or ""
            out.append({
                "artistName": r.get("artistName", ""),
                "trackName": r.get("trackName", ""),
                "collectionName": r.get("collectionName", ""),
                # iTunes hands out a 100px thumbnail; the same path serves 600.
                "artUrl": re.sub(r"/\d+x\d+bb\.(jpg|png)$", r"/600x600bb.\1", art),
            })
        self._store("itunes", q, out)
        return out

    def search(self, term: str, limit: int = 8) -> list[dict]:
        results = self._deezer(term, limit)
        if not results:
            results = self._itunes(term, limit)
        return results


# --------------------------------------------------------------------------
# deciding who a track is by
# --------------------------------------------------------------------------

def decide(stem: str, folder: str, lookup: Lookup, threshold: float,
           loose: bool) -> dict:
    """Work out artist, album and artwork URL for one file.

    Returns a dict with artist/album/title/art/why. `why` explains the choice
    in one line so --dry-run output can be audited rather than trusted.
    """
    name_artist, title = split_artist(stem)
    term = f"{name_artist} {title}" if name_artist else title
    results = lookup.search(term) if term else []

    def score_split(artist: str | None, track: str) -> tuple[dict | None, float]:
        best_r, best_s = None, 0.0
        for r in results:
            s = similar(track, r.get("trackName", ""))
            if artist:
                # The filename already names the artist; a result that disagrees
                # about the artist is about a different song with a similar title.
                s = s * 0.5 + similar(artist, r.get("artistName", "")) * 0.5
            if s > best_s:
                best_r, best_s = r, s
        return best_r, best_s

    best, best_score = score_split(name_artist, title)

    # yt-dlp names some files "Title - Channel" rather than "Artist - Title",
    # which would invent an artist out of a song title. Both readings are
    # scored against the same search results and the better one wins; no extra
    # request is needed because the query is the whole stem either way.
    if name_artist:
        alt, alt_score = score_split(title, name_artist)
        if alt_score > best_score + 0.15:
            name_artist, title = title, name_artist
            best, best_score = alt, alt_score

    if name_artist:
        # Trusted path: the artist comes from the filename either way. The
        # lookup only contributes album and artwork, and only if it is talking
        # about the same record.
        if best and best_score >= threshold:
            return {
                "artist": best.get("artistName") or name_artist,
                "album": best.get("collectionName") or UNKNOWN_ALBUM,
                "title": best.get("trackName") or title,
                "art": art_url(best),
                "why": f"filename artist, lookup confirmed ({best_score:.2f})",
            }
        return {
            "artist": name_artist,
            "album": UNKNOWN_ALBUM,
            "title": title,
            "art": None,
            "why": f"filename artist, no confident lookup ({best_score:.2f})",
        }

    # Untrusted path: nothing but a title. An exact-looking title match is not
    # enough on its own, because a generic title matches something in a
    # catalogue of 100M tracks every time. Require corroboration:
    #   - the artist's name shows up in the source folder name, or
    #   - two or more of the top results agree on that artist for this title.
    # Without one of those the file goes to Unknown Artist, where it is still
    # findable, instead of being filed under a stranger.
    if best and best_score >= threshold:
        artist = best.get("artistName", "")
        exact = [r for r in results
                 if similar(title, r.get("trackName", "")) >= threshold]
        agree = sum(1 for r in exact if norm(r.get("artistName", "")) == norm(artist))
        in_folder = norm(artist) and norm(artist) in norm(folder)
        if in_folder or agree >= 2 or loose:
            reason = ("artist in folder name" if in_folder
                      else f"{agree} results agree" if agree >= 2 else "--loose")
            return {
                "artist": artist,
                "album": best.get("collectionName") or UNKNOWN_ALBUM,
                "title": best.get("trackName") or title,
                "art": art_url(best),
                "why": f"title-only match ({best_score:.2f}), {reason}",
            }
        # No artwork either. A match not trusted enough to name the artist is
        # not trusted enough to supply the cover: stamping this record's art on
        # the file would assert exactly the identification just rejected, and
        # do it invisibly, inside the file.
        return {
            "artist": UNKNOWN_ARTIST, "album": UNKNOWN_ALBUM, "title": title,
            "art": None,
            "why": f"title matched {artist!r} ({best_score:.2f}) but uncorroborated",
        }

    return {
        "artist": UNKNOWN_ARTIST, "album": UNKNOWN_ALBUM, "title": title,
        "art": None,
        "why": f"no usable match ({best_score:.2f})",
    }


def art_url(result: dict) -> str | None:
    """Cover URL from a normalised search result, if it has one."""
    return result.get("artUrl") or None


# --------------------------------------------------------------------------
# artwork
# --------------------------------------------------------------------------

def fetch_art(url: str) -> bytes | None:
    import hashlib
    os.makedirs(os.path.join(CACHE, "art"), exist_ok=True)
    cf = os.path.join(CACHE, "art", hashlib.sha1(url.encode()).hexdigest() + ".jpg")
    if os.path.isfile(cf) and os.path.getsize(cf) > 0:
        with open(cf, "rb") as fh:
            return fh.read()
    try:
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        with urllib.request.urlopen(req, timeout=25) as fh:
            data = fh.read()
    except (urllib.error.URLError, TimeoutError, ValueError):
        return None
    if not data.startswith(b"\xff\xd8"):          # not a JPEG, don't cache it
        return None
    try:
        with open(cf, "wb") as out:
            out.write(data)
    except OSError:
        pass
    return data


# --------------------------------------------------------------------------
# writing the file
# --------------------------------------------------------------------------

def duration(path: str) -> float:
    try:
        out = subprocess.run(
            ["ffprobe", "-v", "quiet", "-show_entries", "format=duration",
             "-of", "csv=p=0", path],
            capture_output=True, text=True, timeout=60).stdout.strip()
        return float(out)
    except (OSError, ValueError, subprocess.SubprocessError):
        return 0.0


def probe(path: str) -> tuple[bool, str]:
    """(has embedded picture, artist tag) in one ffprobe call."""
    try:
        out = subprocess.run(
            ["ffprobe", "-v", "quiet", "-print_format", "json",
             "-show_format", "-show_streams", path],
            capture_output=True, text=True, timeout=60).stdout
        d = json.loads(out)
    except (OSError, ValueError, subprocess.SubprocessError):
        return False, ""
    art = any(st.get("codec_type") == "video" for st in d.get("streams", []))
    tags = {k.lower(): v for k, v in (d.get("format", {}).get("tags") or {}).items()}
    return art, str(tags.get("artist", ""))


def write_track(src: str, dst: str, meta: dict, art: bytes | None,
                art_path: str | None) -> tuple[bool, str]:
    """Remux src to dst with tags and (if given) an embedded cover.

    The audio is stream-copied, so this is a container rewrite and the samples
    are untouched. The result is written to a temporary name in the
    destination directory and only renamed into place once ffprobe agrees the
    duration survived - an ffmpeg that fails halfway must never be able to
    leave a truncated file where the original used to be.
    """
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    tmp = dst + ".organize-tmp" + os.path.splitext(dst)[1]

    cmd = ["ffmpeg", "-nostdin", "-loglevel", "error", "-i", src]
    if art_path:
        cmd += ["-i", art_path, "-map", "0:a", "-map", "1:v",
                "-c:v", "mjpeg", "-disposition:v", "attached_pic",
                "-metadata:s:v", "title=Album cover",
                "-metadata:s:v", "comment=Cover (front)"]
    else:
        cmd += ["-map", "0:a"]
    cmd += ["-c:a", "copy", "-id3v2_version", "3",
            "-metadata", f"artist={meta['artist']}",
            "-metadata", f"album_artist={meta['artist']}",
            "-metadata", f"album={meta['album']}",
            "-metadata", f"title={meta['title']}",
            "-y", tmp]

    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
    except subprocess.SubprocessError as e:
        return False, f"ffmpeg failed to run: {e}"
    if r.returncode != 0 or not os.path.isfile(tmp) or os.path.getsize(tmp) == 0:
        if os.path.exists(tmp):
            os.unlink(tmp)
        return False, f"ffmpeg exit {r.returncode}: {r.stderr.strip()[:160]}"

    d_src, d_tmp = duration(src), duration(tmp)
    if d_src > 0 and abs(d_src - d_tmp) > 1.0:
        os.unlink(tmp)
        return False, f"duration changed {d_src:.1f}s -> {d_tmp:.1f}s, refusing"

    os.replace(tmp, dst)
    return True, ""


def unique(path: str) -> str:
    """Never overwrite: two different recordings can share a title."""
    if not os.path.exists(path):
        return path
    stem, ext = os.path.splitext(path)
    for n in range(2, 999):
        cand = f"{stem} ({n}){ext}"
        if not os.path.exists(cand):
            return cand
    raise RuntimeError(f"cannot find a free name for {path}")


# --------------------------------------------------------------------------
# main
# --------------------------------------------------------------------------

def collect(root: str) -> list[str]:
    out = []
    for d, dirs, files in os.walk(root):
        dirs[:] = [x for x in dirs if not x.startswith(".")]
        for f in sorted(files):
            if os.path.splitext(f)[1].lower() in AUDIO_EXT:
                out.append(os.path.join(d, f))
    return sorted(out)


def prune_empty(root: str, apply: bool) -> int:
    """Remove directories left empty by the move. Never touches the root."""
    removed = 0
    for d, dirs, files in os.walk(root, topdown=False):
        if os.path.realpath(d) == os.path.realpath(root):
            continue
        try:
            if not os.listdir(d):
                if apply:
                    os.rmdir(d)
                removed += 1
        except OSError:
            pass
    return removed


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Tag ~/Music and embed cover art; optionally reorganise it.",
        epilog="Without --apply nothing is written; the plan is printed and that is all.")
    ap.add_argument("--apply", action="store_true",
                    help="actually retag, embed art and move files")
    ap.add_argument("--root", default=ROOT, help=f"library root (default {ROOT})")
    ap.add_argument("--limit", type=int, default=0,
                    help="only process the first N files (for a trial run)")
    ap.add_argument("--threshold", type=float, default=0.90,
                    help="similarity a lookup must reach to be believed (default 0.90)")
    ap.add_argument("--loose", action="store_true",
                    help="accept the best title match even without corroboration")
    ap.add_argument("--no-art", action="store_true", help="skip artwork entirely")
    ap.add_argument("--sort", action="store_true",
                    help="also reorganise into Artist/Album folders; off by default,\n"
                         "                          because the playlist folders yt-dlp"
                         " creates are the\n                          layout this"
                         " library is kept in")
    ap.add_argument("--delay", type=float, default=0.2,
                    help="seconds between Deezer calls (default 0.2); the iTunes\n                          fallback is never paced faster than 3s")
    ap.add_argument("--offline", action="store_true",
                    help="use only cached lookups, make no network requests")
    ap.add_argument("--clear-player-cache", action="store_true",
                    help="drop index.php's ffprobe cache when done")
    args = ap.parse_args()

    root = os.path.realpath(args.root)
    if not os.path.isdir(root):
        log(f"no such directory: {root}")
        return 1
    for tool in ("ffmpeg", "ffprobe"):
        if not shutil.which(tool):
            log(f"{tool} is not installed")
            return 1

    os.makedirs(CACHE, exist_ok=True)
    files = collect(root)
    if args.limit:
        files = files[:args.limit]
    if not files:
        log(f"no audio files under {root}")
        return 0

    mode = "APPLY" if args.apply else "DRY RUN - nothing will be written"
    log(f"{len(files)} audio files under {root}    [{mode}]")
    log("")

    lookup = Lookup(args.delay, offline=args.offline)

    # Pass 1: decide. Done for every file before anything is written, so that
    # the artist spellings can be reconciled first - "NOVELISTS" and
    # "Novelists" are one artist and must not become two folders.
    plans = []
    for i, src in enumerate(files, 1):
        stem = os.path.splitext(os.path.basename(src))[0]
        folder = os.path.basename(os.path.dirname(src))
        d = decide(stem, folder, lookup, args.threshold, args.loose)
        d["src"] = src
        plans.append(d)
        if i % 25 == 0 or i == len(files):
            log(f"  identified {i}/{len(files)}"
                f"  ({lookup.requests} requests, {lookup.cached} cached)")

    spellings: dict[str, Counter] = defaultdict(Counter)
    for p in plans:
        spellings[norm(p["artist"])][p["artist"]] += 1
    canonical = {k: c.most_common(1)[0][0] for k, c in spellings.items()}
    for p in plans:
        p["artist"] = canonical[norm(p["artist"])]

    # Pass 2: work out destinations, so collisions are visible in the dry run.
    used: set[str] = set()
    for p in plans:
        ext = os.path.splitext(p["src"])[1].lower()
        artist_dir = safe_name(p["artist"], UNKNOWN_ARTIST)
        album_dir = safe_name(p["album"], UNKNOWN_ALBUM)
        fname = safe_name(p["title"], os.path.splitext(os.path.basename(p["src"]))[0]) + ext
        if not args.sort:
            p["dst"] = p["src"]
        else:
            dst = os.path.join(root, artist_dir, album_dir, fname)
            while dst in used or (os.path.exists(dst) and dst != p["src"]):
                stem, e = os.path.splitext(dst)
                m = re.match(r"^(.*) \((\d+)\)$", stem)
                dst = f"{m.group(1)} ({int(m.group(2)) + 1}){e}" if m else f"{stem} (2){e}"
            used.add(dst)
            p["dst"] = dst

    by_artist = Counter(p["artist"] for p in plans)
    unknown = by_artist.get(UNKNOWN_ARTIST, 0)
    log("")
    log(f"  {len(by_artist)} artists;  {unknown} track(s) -> {UNKNOWN_ARTIST}")
    log(f"  {sum(1 for p in plans if p['art'])} track(s) have artwork available")
    log("")

    if not args.apply:
        for p in plans:
            log(f"  {os.path.relpath(p['src'], root)}")
            log(f"    -> {os.path.relpath(p['dst'], root)}")
            log(f"       art={'yes' if p['art'] else 'NO '}  {p['why']}")
        log("")
        log("Nothing was written. Re-run with --apply to carry this out.")
        return 0

    # Pass 3: write. An undo log is appended as we go, so an interrupted run is
    # still reversible - it is written before the move, not after the batch.
    undo_path = os.path.join(CACHE, f"undo-{int(time.time())}.sh")
    undo = open(undo_path, "w")
    undo.write("#!/bin/sh\n# Undo an organize.py run: moves every file back.\n"
               "# Tags and embedded artwork are NOT removed by this.\nset -e\n")
    os.chmod(undo_path, 0o755)

    done = failed = skipped = 0
    covers_written: set[str] = set()
    for i, p in enumerate(plans, 1):
        src, dst = p["src"], p["dst"]
        art_bytes = None
        if p["art"] and not args.no_art:
            art_bytes = fetch_art(p["art"])

        # Nothing to do for a file that is staying put, already names an
        # artist and already carries a picture (or has none on offer). Without
        # this a second run would remux the whole library to write the tags it
        # wrote last time.
        if src == dst:
            had_art, had_artist = probe(src)
            if had_artist and (had_art or not art_bytes):
                skipped += 1
                continue

        art_tmp = None
        if art_bytes:
            art_tmp = os.path.join(CACHE, "current-cover.jpg")
            with open(art_tmp, "wb") as fh:
                fh.write(art_bytes)

        ok, err = write_track(src, dst, p, art_bytes, art_tmp)
        if not ok:
            log(f"  FAILED {os.path.relpath(src, root)}: {err}")
            failed += 1
            continue

        if dst != src:
            undo.write(f'mkdir -p {shell_quote(os.path.dirname(src))}\n')
            undo.write(f'mv -n {shell_quote(dst)} {shell_quote(src)}\n')
            undo.flush()
            os.unlink(src)

        # A cover file next to the tracks: index.php prefers it over the
        # embedded picture and serves it without shelling out to ffmpeg.
        #
        # Only when sorting, because only then is the folder an album. Left in
        # for an in-place run it drops one track's cover into a playlist folder
        # holding hundreds of different records, and since the player prefers a
        # folder cover to the embedded picture, that one image would then stand
        # in for every track in the folder.
        album_dir = os.path.dirname(dst)
        if args.sort and art_bytes and album_dir not in covers_written:
            cover = os.path.join(album_dir, COVER_NAME)
            if not os.path.exists(cover):
                try:
                    with open(cover, "wb") as fh:
                        fh.write(art_bytes)
                except OSError:
                    pass
            covers_written.add(album_dir)

        done += 1
        if i % 20 == 0 or i == len(plans):
            log(f"  written {i}/{len(plans)}  ({done} ok, {failed} failed)")

    undo.close()

    pruned = prune_empty(root, apply=True) if args.sort else 0

    if args.clear_player_cache:
        n = 0
        for f in os.listdir(PLAYER_CACHE):
            if f.endswith(".json") or f.endswith(".jpg"):
                try:
                    os.unlink(os.path.join(PLAYER_CACHE, f))
                    n += 1
                except OSError:
                    pass
        log(f"  cleared {n} player cache entries")

    log("")
    log(f"done: {done} written, {failed} failed, {skipped} skipped, "
        f"{pruned} empty folder(s) removed")
    log(f"undo script: {undo_path}")
    return 1 if failed else 0


def shell_quote(s: str) -> str:
    return "'" + s.replace("'", "'\\''") + "'"


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        log("\ninterrupted")
        sys.exit(130)
