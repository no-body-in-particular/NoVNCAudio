# Copyright 1999-2026 Gentoo Authors
# Distributed under the terms of the GNU General Public License v2

EAPI=8

inherit autotools

DESCRIPTION="VNC server for real X displays"
HOMEPAGE="https://libvnc.github.io/"
SRC_URI="https://github.com/LibVNC/x11vnc/archive/${PV}.tar.gz -> ${P}.tar.gz"

LICENSE="GPL-2+-with-openssl-exception"
SLOT="0"
KEYWORDS="amd64"
IUSE="crypt drm fbcon ssl +xcomposite +xdamage +xfixes xinerama +xrandr zeroconf"

COMMON_DEPEND="
	>=net-libs/libvncserver-0.9.8[ssl=]
	x11-libs/libX11
	x11-libs/libXcursor
	x11-libs/libXext
	>=x11-libs/libXtst-1.1.0
	virtual/libcrypt:=
	drm? ( x11-libs/libdrm )
	ssl? ( dev-libs/openssl:0= )
	xcomposite? ( x11-libs/libXcomposite )
	xdamage? ( x11-libs/libXdamage )
	xfixes? ( x11-libs/libXfixes )
	xinerama? ( x11-libs/libXinerama )
	xrandr? ( x11-libs/libXrandr )
	zeroconf? ( >=net-dns/avahi-0.6.4 )
"
DEPEND="${COMMON_DEPEND}
	x11-base/xorg-proto
	x11-libs/libXt
"
# https://bugzilla.redhat.com/show_bug.cgi?id=920554
RDEPEND="${COMMON_DEPEND}
	dev-lang/tk:0
"

DOCS=( NEWS README doc/. )

PATCHES=(
	# carried from ::gentoo - https://github.com/LibVNC/x11vnc/pull/268
	"${FILESDIR}"/${PN}-${PV}-implicit-function-declaration.patch
	# local: copy_tiles() computed its tile hint width after the memcpy that
	# made the two buffers identical, so the narrowing never took effect and
	# every changed tile was sent at full width. See the patch header.
	"${FILESDIR}"/${PN}-${PV}-copy_tiles-hint-width.patch
)

src_prepare() {
	default
	eautoreconf
}

src_configure() {
	# --without-v4l because of missing video4linux 2.x support wrt #389079
	local myconf=(
		--without-v4l
		--without-xkeyboard
		--without-fbpm
		--without-dpms
		$(use_with crypt)
		$(use_with drm)
		$(use_with fbcon fbdev)
		$(use_with ssl)
		$(use_with ssl crypto)
		$(use_with xcomposite)
		$(use_with xdamage)
		$(use_with xfixes)
		$(use_with xinerama)
		$(use_with xrandr)
		$(use_with zeroconf avahi)
	)
	econf "${myconf[@]}"
}

src_install() {
	default
	# NOTE: the ::gentoo ebuild also installs its own x11vnc init/conf scripts
	# from FILESDIR here. This box drives x11vnc from /etc/init.d/vnc (see the
	# NoVNCAudio repo), so they are deliberately not installed.
}
