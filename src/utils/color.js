/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// WCAG 2.x AA minimum contrast ratio for normal-size text. Badge text is small
// (13px) and not "large text" by the WCAG definition, so 4.5:1 is the bar.
const WCAG_AA_NORMAL = 4.5

/**
 * Relative luminance of a sRGB colour per WCAG 2.x, from channel values in 0..1.
 *
 * @param {number} r red channel, 0..1
 * @param {number} g green channel, 0..1
 * @param {number} b blue channel, 0..1
 * @return {number} relative luminance, 0..1
 */
function relativeLuminance(r, g, b) {
	const lin = (v) => (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4))
	return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}

/**
 * WCAG contrast ratio between two relative luminances: (L1+0.05)/(L2+0.05) with
 * L1 the lighter of the two. Ranges 1 (identical) .. 21 (black vs white).
 *
 * @param {number} l1 a relative luminance
 * @param {number} l2 another relative luminance
 * @return {number} the contrast ratio, >= 1
 */
function contrastRatio(l1, l2) {
	const lighter = Math.max(l1, l2)
	const darker = Math.min(l1, l2)
	return (lighter + 0.05) / (darker + 0.05)
}

/**
 * Parse a CSS colour string (#rgb / #rrggbb or hsl()/hsla()) into normalised
 * sRGB channels in 0..1, or null if it cannot be parsed.
 *
 * @param {string} c a trimmed colour string
 * @return {?number[]} [r, g, b] in 0..1, or null
 */
function parseRgb(c) {
	const hexMatch = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.exec(c)
	if (hexMatch) {
		let hex = c
		if (hex.length === 4) {
			hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3]
		}
		return [
			parseInt(hex.slice(1, 3), 16) / 255,
			parseInt(hex.slice(3, 5), 16) / 255,
			parseInt(hex.slice(5, 7), 16) / 255,
		]
	}
	const hslMatch = /^hsla?\(\s*(\d{1,3})\s*,\s*(\d{1,3})%\s*,\s*(\d{1,3})%/.exec(c)
	if (hslMatch) {
		return hslToRgb(
			Number(hslMatch[1]) / 360,
			Number(hslMatch[2]) / 100,
			Number(hslMatch[3]) / 100,
		)
	}
	return null
}

/**
 * Pick a readable foreground for a given background colour so badge text stays
 * legible on user-chosen colours. Handles #rgb / #rrggbb and hsl()/hsla().
 *
 * Rather than a luminance-midpoint heuristic (which does not guarantee any
 * particular contrast ratio for mid-luminance saturated colours), this computes
 * the actual WCAG contrast ratio of the background against both pure black and
 * pure white and returns whichever yields the higher ratio. If even the better
 * of the two fails AA (4.5:1) — which only happens for mid-luminance colours no
 * pure foreground can sit on legibly — it falls back to the NC main-text token,
 * which the badge styling pairs with a subtle background so text stays readable.
 * Anything unparseable likewise falls back to that token.
 *
 * @param {string} bg background color
 * @return {string} a CSS color for the foreground text
 */
export function readableTextColor(bg) {
	if (typeof bg !== 'string') return 'var(--color-main-text)'
	const rgb = parseRgb(bg.trim())
	if (!rgb) return 'var(--color-main-text)'

	const bgLum = relativeLuminance(rgb[0], rgb[1], rgb[2])
	// Luminance of pure white is 1, pure black is 0.
	const contrastWithWhite = contrastRatio(bgLum, 1)
	const contrastWithBlack = contrastRatio(bgLum, 0)
	const best = Math.max(contrastWithWhite, contrastWithBlack)

	// No pure foreground reaches AA on this background: defer to the theme token
	// rather than assert a "guaranteed AA" foreground the maths can't deliver.
	if (best < WCAG_AA_NORMAL) return 'var(--color-main-text)'

	return contrastWithBlack >= contrastWithWhite ? '#000000' : '#ffffff'
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
