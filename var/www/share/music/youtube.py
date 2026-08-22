#!/usr/bin/env python3
"""Script to download Audio files from YouTube links.

Downloads are tagged and carry the video thumbnail as album art. Without that
yt-dlp writes the audio stream and nothing else: every file used to arrive with
no artist, no title and no picture, which left the music player showing bare
filenames and sent organize.py off to the iTunes API to reconstruct metadata
that was available at download time all along.

Dependencies:
- yt-dlp (Gentoo: net-misc/yt-dlp)
- ffmpeg, with libmp3lame (Gentoo: media-video/ffmpeg USE="mp3")
"""

import os
import sys

from yt_dlp import YoutubeDL
from yt_dlp.postprocessor import MetadataParserPP


def download(playlist_url, destination_path="/tmp/", quality="192"):
    """
    Download MP3 files from items of a YouTube playlist.

    Args:
        playlist_url: str, URL to YouTube playlist
        destination_path: str, path to folder where the downloaded files will be stored
        quality: str, MP3 bitrate in kbps

    Returns:
        int, number of items yt-dlp failed to download (0 means everything succeeded)

    Examples:
          >>> from youtube import download
          >>> playlist_url = "..."  # fill with playlist url
          >>> download(playlist_url,
          >>>          destination_path="/tmp/")
    """

    # Checks on parameters
    assert os.path.exists(destination_path), f"Not valid path '{destination_path}' for destination"

    # Files land in a subfolder named after the playlist (falling back to the
    # video title for a bare video URL). yt-dlp creates the folder itself.
    outtmpl = os.path.join(
        os.path.abspath(destination_path),
        "%(playlist_title,title)s",
        "%(title)s.%(ext)s",
    )

    options = {
        "format": "bestaudio/best",
        "outtmpl": outtmpl,
        # Fetch the thumbnail alongside the audio; EmbedThumbnail below has
        # nothing to work with otherwise.
        "writethumbnail": True,
        "postprocessors": [
            # Most music videos are titled "Artist - Track", so split the title
            # into those two fields before anything else runs. This is a
            # best-effort parse: when the title has no " - " the fields stay
            # unset and the metadata step falls back to the channel name for
            # artist and the full title for track, which is still better than
            # the nothing that was written before.
            {
                "key": "MetadataParser",
                "when": "pre_process",
                "actions": [
                    (MetadataParserPP.Actions.INTERPRET, "title", "%(artist)s - %(track)s"),
                ],
            },
            {
                "key": "FFmpegExtractAudio",
                "preferredcodec": "mp3",
                "preferredquality": quality,
            },
            # Writes artist/track/album/date into the ID3 tags. Runs after the
            # audio has been extracted so it tags the mp3, not the source
            # container.
            {
                "key": "FFmpegMetadata",
                "add_metadata": True,
            },
            # YouTube serves webp thumbnails, which ffmpeg will not put in an
            # ID3 APIC frame and most players cannot read. Convert first.
            {
                "key": "FFmpegThumbnailsConvertor",
                "format": "jpg",
                "when": "before_dl",
            },
            # Note the picture keeps the video's 16:9 shape rather than being
            # cropped square, so it shows up letterboxed in players that expect
            # a square cover. Cropping would throw away parts of the image, so
            # the frame is left as the uploader made it.
            {
                "key": "EmbedThumbnail",
                "already_have_thumbnail": False,
            },
        ],
        # Skip unavailable/private items instead of aborting the whole playlist
        "ignoreerrors": True,
        # Don't re-download items already present in the destination folder
        "overwrites": False,
    }

    with YoutubeDL(options) as ydl:
        return ydl.download([playlist_url])


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(f"usage: {sys.argv[0]} PLAYLIST_URL [DESTINATION_PATH]")

    url = sys.argv[1]
    destination = sys.argv[2] if len(sys.argv) > 2 else "/tmp/"
    sys.exit(download(url, destination_path=destination))
