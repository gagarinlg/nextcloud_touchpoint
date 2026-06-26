/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Shared mapping from a note-type's stored `icon` value (the legacy `icon-*`
 * tokens offered by NoteTypeModal) to a renderable icon.
 *
 * Two surfaces consume this:
 *  - The Vue UI (NoteTypeBadge) needs a vue-material-design-icons component.
 *  - The non-Vue Contacts-tab island needs raw SVG path data to inline.
 *
 * Keep the keys in sync with NoteTypeModal.vue's iconOptions.
 */
import IconComment from 'vue-material-design-icons/CommentOutline.vue'
import IconPhone from 'vue-material-design-icons/Phone.vue'
import IconCalendar from 'vue-material-design-icons/Calendar.vue'
import IconMail from 'vue-material-design-icons/Email.vue'
import IconCheck from 'vue-material-design-icons/CheckCircleOutline.vue'
import IconStar from 'vue-material-design-icons/Star.vue'
import IconLink from 'vue-material-design-icons/LinkVariant.vue'
import IconNote from 'vue-material-design-icons/NoteOutline.vue'

const ICON_COMPONENTS = {
	'icon-comment': IconComment,
	'icon-phone': IconPhone,
	'icon-calendar-dark': IconCalendar,
	'icon-mail': IconMail,
	'icon-checkmark': IconCheck,
	'icon-star': IconStar,
	'icon-link': IconLink,
	'icon-category-office': IconNote,
	// Legacy aliases for tokens that older rows / the column default
	// ('icon-note') may still carry, so those badges keep
	// rendering an icon instead of silently showing none. Kept in sync with
	// NoteTypeService::ALLOWED_ICONS.
	'icon-calendar': IconCalendar,
	'icon-note': IconNote,
}

// Raw MDI path data for the same glyphs, for the non-Vue Contacts integration.
// These match the path geometry of the components above.
const ICON_PATHS = {
	'icon-comment': 'M9,22A1,1 0 0,1 8,21V18H4A2,2 0 0,1 2,16V4C2,2.89 2.9,2 4,2H20A2,2 0 0,1 22,4V16A2,2 0 0,1 20,18H13.9L10.2,21.71C10,21.9 9.75,22 9.5,22V22H9Z',
	'icon-phone': 'M6.62,10.79C8.06,13.62 10.38,15.94 13.21,17.38L15.41,15.18C15.69,14.9 16.08,14.82 16.43,14.93C17.55,15.3 18.75,15.5 20,15.5A1,1 0 0,1 21,16.5V20A1,1 0 0,1 20,21A17,17 0 0,1 3,4A1,1 0 0,1 4,3H7.5A1,1 0 0,1 8.5,4C8.5,5.25 8.7,6.45 9.07,7.57C9.18,7.92 9.1,8.31 8.82,8.59L6.62,10.79Z',
	'icon-calendar-dark': 'M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z',
	'icon-mail': 'M20,8L12,13L4,8V6L12,11L20,6M20,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V6C22,4.89 21.1,4 20,4Z',
	'icon-checkmark': 'M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M11,16.5L6.5,12L7.91,10.59L11,13.67L16.59,8.09L18,9.5L11,16.5Z',
	'icon-star': 'M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z',
	'icon-link': 'M10.59,13.41C11,13.8 11,14.44 10.59,14.83C10.2,15.22 9.56,15.22 9.17,14.83C7.22,12.88 7.22,9.71 9.17,7.76V7.76L12.71,4.22C14.66,2.27 17.83,2.27 19.78,4.22C21.73,6.17 21.73,9.34 19.78,11.29L18.29,12.78C18.3,11.96 18.17,11.14 17.89,10.36L18.36,9.88C19.54,8.71 19.54,6.81 18.36,5.64C17.19,4.46 15.29,4.46 14.12,5.64L10.59,9.17C9.41,10.34 9.41,12.24 10.59,13.41M13.41,9.17C13.8,8.78 14.44,8.78 14.83,9.17C16.78,11.12 16.78,14.29 14.83,16.24V16.24L11.29,19.78C9.34,21.73 6.17,21.73 4.22,19.78C2.27,17.83 2.27,14.66 4.22,12.71L5.71,11.22C5.7,12.04 5.83,12.86 6.11,13.65L5.64,14.12C4.46,15.29 4.46,17.19 5.64,18.36C6.81,19.54 8.71,19.54 9.88,18.36L13.41,14.83C14.59,13.66 14.59,11.76 13.41,10.59C13,10.2 13,9.56 13.41,9.17Z',
	'icon-category-office': 'M14,10V4.5L19.5,10M5,3C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V8L14,1H5M14,17H7V15H14M17,13H7V11H17V13Z',
	// Legacy aliases — see ICON_COMPONENTS above. icon-calendar reuses the
	// calendar geometry; icon-note reuses the office/document geometry.
	'icon-calendar': 'M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z',
	'icon-note': 'M14,10V4.5L19.5,10M5,3C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V8L14,1H5M14,17H7V15H14M17,13H7V11H17V13Z',
}

/**
 * Resolve a note-type icon value to a vue-material-design-icons component, or
 * null when the value is unset/unknown (so callers can omit the icon entirely).
 * @param {string|null|undefined} icon stored icon token
 * @return {object|null}
 */
export function iconComponentForType(icon) {
	return ICON_COMPONENTS[icon] || null
}

/**
 * Resolve a note-type icon value to raw SVG path data, or null when unknown.
 * @param {string|null|undefined} icon stored icon token
 * @return {string|null}
 */
export function iconPathForType(icon) {
	return ICON_PATHS[icon] || null
}
