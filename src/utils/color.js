/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Pick a readable foreground (black or white) for a given background color so
 * badge text stays legible on user-chosen colors. Handles #rgb / #rrggbb and
 * hsl()/hsla(); anything unparseable falls back to the NC main-text token.
 *
 * @param {string} bg background color
 * @return {string} a CSS color for the foreground text
 */
export function readableTextColor(bg) {
	if (typeof bg !== 'string') return 'var(--color-main-text)'
	const c = bg.trim()

	let r, g, b
	const hexMatch = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.exec(c)
	const hslMatch = /^hsla?\(\s*(\d{1,3})\s*,\s*(\d{1,3})%\s*,\s*(\d{1,3})%/.exec(c)
	if (hexMatch) {
		let hex = c
		if (hex.length === 4) {
			hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3]
		}
		r = parseInt(hex.slice(1, 3), 16) / 255
		g = parseInt(hex.slice(3, 5), 16) / 255
		b = parseInt(hex.slice(5, 7), 16) / 255
	} else if (hslMatch) {
		[r, g, b] = hslToRgb(
			Number(hslMatch[1]) / 360,
			Number(hslMatch[2]) / 100,
			Number(hslMatch[3]) / 100,
		)
	} else {
		return 'var(--color-main-text)'
	}

	const lin = (v) => (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4))
	const luminance = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
	return luminance > 0.5 ? '#000000' : '#ffffff'
}

function hslToRgb(h, s, l) {
	if (s === 0) return [l, l, l]
	const hue2rgb = (p, q, t) => {
		if (t < 0) t += 1
		if (t > 1) t -= 1
		if (t < 1 / 6) return p + (q - p) * 6 * t
		if (t < 1 / 2) return q
		if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6
		return p
	}
	const q = l < 0.5 ? l * (1 + s) : l + s - l * s
	const p = 2 * l - q
	return [hue2rgb(p, q, h + 1 / 3), hue2rgb(p, q, h), hue2rgb(p, q, h - 1 / 3)]
}
