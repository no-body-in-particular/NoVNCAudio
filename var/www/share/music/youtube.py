#!/usr/bin/env python3
"""Script to download Audio files from YouTube links.

Dependencies:
- yt-dlp (Gentoo: net-misc/yt-dlp)
- ffmpeg, with libmp3lame (Gentoo: media-video/ffmpeg USE="mp3")
"""

import os
import sys

from yt_dlp import YoutubeDL


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
        "postprocessors": [
            {
                "key": "FFmpegExtractAudio",
                "preferredcodec": "mp3",
                "preferredquality": quality,
            }
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
