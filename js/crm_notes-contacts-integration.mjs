import{g as S,a as B,t as c,c as N,s as F,b as _}from"./NcNoteCard-DZSuYX4--BfYn75fP.chunk.mjs";import{r as P,i as D,a as Z}from"./markdown-DnIbHOwK.chunk.mjs";const E=S("/apps/crm_notes/api"),O={note:"M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z",openInNew:"M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z",file:"M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z",pin:"M16,12V4H17V2H7V4H8V12L6,14V16H11.2V22H12.8V16H18V14L16,12Z",chevronDown:"M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z",plus:"M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"};function x(t,n=16){return`<svg viewBox="0 0 24 24" width="${n}" height="${n}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${O[t]}" /></svg>`}function q(){return`<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${c("crm_notes","Loading…")}</span>
	</span>`}function j(t){if(typeof t!="string")return"var(--color-text-maxcontrast)";const n=t.trim();return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(n)||/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(n)?n:"var(--color-text-maxcontrast)"}function W(t){try{const n=decodeURIComponent(t);try{return atob(n)}catch{return atob(n.replace(/-/g,"+").replace(/_/g,"/"))}}catch{return null}}function A(t){const n=t.dataset?.contactUid||t.closest("[data-contact-uid]")?.dataset?.contactUid;if(n)return n;const a=window.location.pathname.split("/").filter(Boolean).pop();if(a){const e=W(a);if(e&&e.includes("~"))return e.substring(0,e.lastIndexOf("~"))}const o=window.location.hash.match(/contact:([^/]+)/)||window.location.pathname.match(/contact:([^/]+)/);return o?decodeURIComponent(o[1]):null}const C=50;async function T(t,n=C,a=0){const{data:o}=await N.get(`${E}/notes/contact/${encodeURIComponent(t)}`,{params:{limit:n,offset:a}});return o}let k=null;async function I(){return k||(k=N.get(`${E}/note-types`).then(({data:t})=>{const n={};for(const a of t)n[a.id]={name:a.name,color:a.color,icon:a.icon};return n}).catch(t=>{throw k=null,t})),k.catch(()=>({}))}const G=new Intl.DateTimeFormat(B().replace("_","-"),{year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});function J(t){if(!t)return"";const n=new Date(t);return isNaN(n.getTime())?"":G.format(n)}function K(t){return t.name?t.name:t.filePath?t.filePath.split("/").pop():c("crm_notes","Attachment")}function V(t,n={}){const a=document.createElement("div");a.className="crm-contacts-note-item";const o=n[t.noteTypeId]||t.noteType||{},e=document.createElement("span");e.className="crm-contacts-type-badge";const d=j(o.color);e.style.background=d,e.style.color=P(d);const i=D(o.icon);if(i){const r=document.createElement("span");r.className="crm-contacts-type-badge-icon",r.setAttribute("aria-hidden","true"),r.innerHTML=`<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${i}" /></svg>`,e.appendChild(r)}const u=document.createElement("span");u.textContent=o.name||"",e.appendChild(u);const s=document.createElement("div");s.className="crm-contacts-note-header",s.appendChild(e);const f=document.createElement("h2");if(f.className="crm-contacts-note-title",f.textContent=t.title||"",s.appendChild(f),t.isPinned){const r=document.createElement("span");r.className="crm-contacts-pin-indicator",r.setAttribute("role","img"),r.setAttribute("aria-label",c("crm_notes","Pinned")),r.innerHTML=x("pin",16),s.appendChild(r)}if(a.appendChild(s),t.content){const r=document.createElement("div");r.className="crm-contacts-note-content",r.innerHTML=Z(t.content),a.appendChild(r)}if(Array.isArray(t.files)&&t.files.length){const r=document.createElement("div");r.className="crm-contacts-note-files";for(const v of t.files){const l=document.createElement("span");l.className="crm-contacts-file-chip";const g=document.createElement("span");g.className="crm-contacts-file-chip-icon",g.innerHTML=x("file",12);const h=document.createElement("span");h.className="crm-contacts-file-chip-label",h.textContent=K(v),l.appendChild(g),l.appendChild(h),r.appendChild(l)}a.appendChild(r)}const p=document.createElement("span");return p.className="crm-contacts-note-date",p.textContent=J(t.createdAt),a.appendChild(p),a}async function Q(t){const n=A(t);if(!n)return;const a=t.querySelector(".crm-contacts-notes-panel");if(a){if(a.dataset.crmContactUid===n)return;a.remove()}const o=document.createElement("div");o.className="crm-contacts-notes-panel",o.dataset.crmContactUid=n;const e=c("crm_notes","Open in CRM Notes (opens in a new tab)"),d=c("crm_notes","Add note"),i=Math.random().toString(36).slice(2,10),u=`crm-contacts-notes-body-${i}`,s=`crm-contacts-addform-${i}`;if(o.innerHTML=`
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${u}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${x("chevronDown",18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${x("note",18)}</span>
				<span>${c("crm_notes","CRM Notes")}</span>
			</button>
			<button type="button" class="crm-contacts-notes-add" title="${d}" aria-label="${d}" aria-expanded="false" aria-controls="${s}">${x("plus",16)}</button>
			<a class="crm-contacts-open-app"
				href="${S("/apps/crm_notes")}#contact/${encodeURIComponent(n)}"
				title="${e}"
				aria-label="${e}"
				target="_blank"
				rel="noopener">${x("openInNew",14)}</a>
		</div>
		<form id="${s}" class="crm-contacts-notes-addform" hidden>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${s}-title">${c("crm_notes","Title")}<span class="crm-contacts-addform-required" aria-hidden="true">*</span></label>
				<input id="${s}-title" type="text" class="crm-contacts-addform-title" maxlength="255" required placeholder="${c("crm_notes","Note title")}" />
			</div>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${s}-type">${c("crm_notes","Type")}<span class="crm-contacts-addform-required" aria-hidden="true">*</span></label>
				<select id="${s}-type" class="crm-contacts-addform-type" required></select>
			</div>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${s}-content">${c("crm_notes","Content")}</label>
				<textarea id="${s}-content" class="crm-contacts-addform-content" rows="3" placeholder="${c("crm_notes","Write a note…")}"></textarea>
			</div>
			<p class="crm-contacts-addform-hint" role="status" aria-live="polite" hidden></p>
			<div class="crm-contacts-addform-actions">
				<button type="button" class="crm-contacts-addform-cancel">${c("crm_notes","Cancel")}</button>
				<button type="submit" class="crm-contacts-addform-save">${c("crm_notes","Save")}</button>
			</div>
		</form>
		<div id="${u}" class="crm-contacts-notes-body">
			${q()}
		</div>
	`,t.appendChild(o),!document.getElementById("crm-contacts-integration-style")){const l=document.createElement("style");l.id="crm-contacts-integration-style",l.textContent=`
			.crm-contacts-notes-panel {
				margin: calc(var(--default-grid-baseline, 4px) * 3) 0;
				border-top: 1px solid var(--color-border, #ddd);
				padding-top: calc(var(--default-grid-baseline, 4px) * 2);
			}
			.crm-contacts-notes-header {
				display: flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				padding: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 4);
			}
			.crm-contacts-notes-toggle {
				display: flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				flex: 1;
				min-width: 0;
				padding: 0;
				border: none;
				background: none;
				font: inherit;
				font-weight: 600;
				color: inherit;
				cursor: pointer;
				user-select: none;
				text-align: left;
			}
			.crm-contacts-notes-toggle:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
			}
			.crm-contacts-notes-chevron {
				display: inline-flex;
				align-items: center;
				color: var(--color-text-maxcontrast, #888);
				transition: transform 0.15s ease-in-out;
			}
			/* Collapsed: chevron rotated -90deg so the down-chevron points right (closed affordance). */
			.crm-contacts-notes-chevron--collapsed {
				transform: rotate(-90deg);
			}
			.crm-contacts-notes-loading {
				display: flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
				color: var(--color-text-maxcontrast, #888);
				font-size: var(--font-size-small, 13px);
			}
			.crm-contacts-spinner {
				display: inline-block;
				width: 20px;
				height: 20px;
				border: 2px solid var(--color-border, #ddd);
				border-top-color: var(--color-primary-element, #0082c9);
				border-radius: 50%;
				animation: crm-contacts-spin 0.8s linear infinite;
			}
			@keyframes crm-contacts-spin {
				to { transform: rotate(360deg); }
			}
			@media (prefers-reduced-motion: reduce) {
				.crm-contacts-spinner { animation-duration: 2s; }
				.crm-contacts-notes-chevron { transition: none; }
			}
			.crm-visually-hidden {
				position: absolute;
				width: 1px;
				height: 1px;
				margin: -1px;
				padding: 0;
				overflow: hidden;
				clip: rect(0, 0, 0, 0);
				white-space: nowrap;
				border: 0;
			}
			.crm-contacts-notes-toggle:focus-visible,
			.crm-contacts-open-app:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
			.crm-contacts-open-app {
				margin-left: auto;
				font-size: var(--font-size-small, 13px);
				text-decoration: none;
				color: var(--color-primary-element);
			}
			.crm-contacts-notes-body {
				padding: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 4);
			}
			.crm-contacts-note-item {
				padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
				border-bottom: 1px solid var(--color-border, #ddd);
				font-size: var(--font-size-small, 13px);
			}
			.crm-contacts-note-item:last-child { border-bottom: none; }
			.crm-contacts-note-header {
				display: flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 1.5);
				margin-bottom: calc(var(--default-grid-baseline, 4px) * 1);
			}
			.crm-contacts-note-title {
				/* Reset UA heading defaults so this <h2> renders like the former
				   bold inline title. */
				margin: 0;
				font-size: inherit;
				font-weight: 600;
				line-height: inherit;
			}
			.crm-contacts-type-badge {
				display: inline-flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 1);
				padding: 1px calc(var(--default-grid-baseline, 4px) * 2);
				border-radius: var(--border-radius-pill, 100px);
				color: var(--color-main-text);
				font-size: var(--font-size-small, 13px);
				font-weight: 600;
				white-space: nowrap;
			}
			.crm-contacts-type-badge-icon {
				display: inline-flex;
				align-items: center;
			}
			.crm-contacts-pin-indicator {
				/* Push the pin to the trailing edge of the header and tint it the
				   primary element colour, matching NoteItem.vue's .crm-pin-indicator. */
				margin-left: auto;
				display: inline-flex;
				align-items: center;
				color: var(--color-primary-element);
			}
			.crm-contacts-note-files {
				display: flex;
				flex-wrap: wrap;
				gap: calc(var(--default-grid-baseline, 4px) * 1.5);
				margin: calc(var(--default-grid-baseline, 4px) * 1.5) 0;
			}
			.crm-contacts-file-chip {
				display: inline-flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 1);
				background: var(--color-background-dark);
				border-radius: var(--border-radius);
				padding: 2px calc(var(--default-grid-baseline, 4px) * 2);
				font-size: var(--font-size-small, 13px);
				max-width: 100%;
				min-width: 0;
			}
			.crm-contacts-file-chip-icon {
				flex: 0 0 auto;
				display: inline-flex;
				align-items: center;
			}
			.crm-contacts-file-chip-label {
				min-width: 0;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
			.crm-contacts-note-content {
				/* Primary note substance — full reading contrast, matching
				   NoteItem.vue's .crm-note-content. --color-text-maxcontrast is
				   reserved for the secondary .crm-contacts-note-date meta line. */
				color: var(--color-main-text);
				margin: calc(var(--default-grid-baseline, 4px) * 0.5) 0 calc(var(--default-grid-baseline, 4px) * 1);
				line-height: 1.5;
				/* Break long unbroken strings (pasted URLs/tokens) so the note
				   body wraps inside the tab instead of overflowing horizontally. */
				overflow-wrap: anywhere;
			}
			.crm-contacts-note-content p { margin: 0 0 calc(var(--default-grid-baseline, 4px) * 1.5); }
			.crm-contacts-note-content p:last-child { margin-bottom: 0; }
			.crm-contacts-note-content ul,
			.crm-contacts-note-content ol { padding-left: calc(var(--default-grid-baseline, 4px) * 4.5); margin: 0 0 calc(var(--default-grid-baseline, 4px) * 1.5); }
			.crm-contacts-note-content h4,
			.crm-contacts-note-content h5,
			.crm-contacts-note-content h6 {
				font-weight: 600;
				margin: calc(var(--default-grid-baseline, 4px) * 1.5) 0 calc(var(--default-grid-baseline, 4px) * 0.5);
				color: var(--color-main-text);
			}
			.crm-contacts-note-content code {
				font-family: var(--font-face-monospace, monospace);
				background: var(--color-background-dark);
				padding: 1px calc(var(--default-grid-baseline, 4px) * 1);
				border-radius: var(--border-radius-small, 4px);
			}
			.crm-contacts-note-content pre {
				background: var(--color-background-dark);
				padding: calc(var(--default-grid-baseline, 4px) * 2);
				border-radius: var(--border-radius);
				overflow-x: auto;
			}
			.crm-contacts-note-content a { color: var(--color-primary-element); }
			.crm-contacts-note-date {
				font-size: var(--font-size-small, 13px);
				color: var(--color-text-maxcontrast, #999);
			}
			.crm-contacts-notes-empty {
				color: var(--color-text-maxcontrast, #888);
				font-size: var(--font-size-small, 13px);
				padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
			}
			.crm-contacts-notes-retry {
				display: inline-block;
				margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
				padding: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 3);
				border: 1px solid var(--color-border-dark, #ccc);
				border-radius: var(--border-radius, 4px);
				background: var(--color-main-background);
				color: var(--color-main-text);
				font: inherit;
				font-size: var(--font-size-small, 13px);
				cursor: pointer;
			}
			.crm-contacts-notes-retry:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
			}
			.crm-contacts-notes-retry:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
			.crm-contacts-notes-add {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				border: none;
				background: none;
				color: var(--color-text-maxcontrast, #888);
				cursor: pointer;
				padding: calc(var(--default-grid-baseline, 4px) * 1);
				border-radius: var(--border-radius, 4px);
			}
			.crm-contacts-notes-add:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
				color: var(--color-main-text);
			}
			.crm-contacts-notes-add:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
			.crm-contacts-notes-addform {
				display: flex;
				flex-direction: column;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				padding: 0 calc(var(--default-grid-baseline, 4px) * 4) calc(var(--default-grid-baseline, 4px) * 2);
			}
			/* Stacked label + control, mirroring NoteModal.vue's .crm-form-row so the
			   inline form reads as part of the design system: a visible bold caption
			   above each control (placeholders alone fail WCAG 3.3.2). */
			.crm-contacts-addform-row {
				display: flex;
				flex-direction: column;
				gap: calc(var(--default-grid-baseline, 4px) * 1);
			}
			.crm-contacts-addform-label {
				font-weight: 600;
				font-size: var(--font-size-small, 13px);
				color: var(--color-main-text);
			}
			.crm-contacts-addform-required {
				color: var(--color-error);
				margin-inline-start: 2px;
			}
			/* Missing-required-fields hint, matching NoteModal.vue's .crm-save-hint. */
			.crm-contacts-addform-hint {
				margin: 0;
				font-size: var(--font-size-small, 13px);
				color: var(--color-text-maxcontrast);
				text-align: end;
			}
			/* Mirror NoteModal.vue's .crm-markdown-editor: a 1px NC-token border,
			   NC radius and NC background/text tokens, so these native controls
			   read as part of the design system rather than raw UA widgets. */
			.crm-contacts-addform-title,
			.crm-contacts-addform-type,
			.crm-contacts-addform-content {
				width: 100%;
				box-sizing: border-box;
				border: 1px solid var(--color-border-dark, #ccc);
				border-radius: var(--border-radius, 4px);
				padding: calc(var(--default-grid-baseline, 4px) * 2);
				font: inherit;
				font-size: var(--font-size-small, 13px);
				background: var(--color-main-background);
				color: var(--color-main-text);
			}
			.crm-contacts-addform-title:hover,
			.crm-contacts-addform-type:hover,
			.crm-contacts-addform-content:hover {
				border-color: var(--color-primary-element);
			}
			.crm-contacts-addform-title:focus,
			.crm-contacts-addform-title:focus-visible,
			.crm-contacts-addform-type:focus,
			.crm-contacts-addform-type:focus-visible,
			.crm-contacts-addform-content:focus,
			.crm-contacts-addform-content:focus-visible {
				outline: none;
				border-color: var(--color-primary-element);
				box-shadow: 0 0 0 2px var(--color-primary-element);
			}
			.crm-contacts-addform-content {
				resize: vertical;
				min-height: 56px;
				font-family: var(--font-face-monospace, monospace);
			}
			.crm-contacts-addform-actions {
				display: flex;
				justify-content: flex-end;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
			}
			/* Themed action buttons matching the NcButton styling used everywhere
			   else: Cancel is a neutral/secondary control, Save is the primary
			   action tinted with --color-primary-element. */
			.crm-contacts-addform-cancel,
			.crm-contacts-addform-save {
				border: none;
				border-radius: var(--border-radius-element, var(--border-radius, 4px));
				padding: calc(var(--default-grid-baseline, 4px) * 1.5) calc(var(--default-grid-baseline, 4px) * 3);
				font: inherit;
				font-size: var(--font-size-small, 13px);
				font-weight: 600;
				cursor: pointer;
			}
			.crm-contacts-addform-cancel {
				background: var(--color-background-dark);
				color: var(--color-main-text);
			}
			.crm-contacts-addform-cancel:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
			}
			.crm-contacts-addform-save {
				background: var(--color-primary-element);
				color: var(--color-primary-element-text);
			}
			.crm-contacts-addform-save:hover {
				background: var(--color-primary-element-hover, var(--color-primary-element));
			}
			.crm-contacts-addform-save:disabled {
				opacity: 0.5;
				cursor: default;
			}
			.crm-contacts-addform-cancel:focus-visible,
			.crm-contacts-addform-save:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		`,document.head.appendChild(l)}const f=o.querySelector(".crm-contacts-notes-toggle"),p=o.querySelector(".crm-contacts-notes-body"),r=o.querySelector(".crm-contacts-notes-chevron");f.addEventListener("click",()=>{const l=f.getAttribute("aria-expanded")!=="false";f.setAttribute("aria-expanded",l?"false":"true"),p.style.display=l?"none":"",r&&r.classList.toggle("crm-contacts-notes-chevron--collapsed",l)});const v=o.querySelector(".crm-contacts-notes-body");X(o,v,n),U(v,n)}function X(t,n,a){const o=t.querySelector(".crm-contacts-notes-add"),e=t.querySelector(".crm-contacts-notes-addform");if(!o||!e)return;const d=e.querySelector(".crm-contacts-addform-title"),i=e.querySelector(".crm-contacts-addform-type"),u=e.querySelector(".crm-contacts-addform-content"),s=e.querySelector(".crm-contacts-addform-hint"),f=e.querySelector(".crm-contacts-addform-cancel"),p=e.querySelector(".crm-contacts-addform-save"),r=t.querySelector(".crm-contacts-notes-toggle");let v={};I().then(m=>{v=m,i.innerHTML="";for(const[y,H]of Object.entries(m)){const w=document.createElement("option");w.value=y,w.textContent=H.name,i.appendChild(w)}});function l(){const m=[];if(d.value.trim()||m.push(c("crm_notes","Title")),i.value||m.push(c("crm_notes","Type")),!m.length){s.hidden=!0,s.textContent="";return}s.textContent=c("crm_notes","Required: {fields}",{fields:m.join(", ")}),s.hidden=!1}function g(){e.hidden=!0,o.setAttribute("aria-expanded","false"),s.hidden=!0,s.textContent="",e.reset()}o.addEventListener("click",()=>{const m=e.hidden;e.hidden=!m,o.setAttribute("aria-expanded",m?"true":"false"),m&&(r&&r.getAttribute("aria-expanded")==="false"&&r.click(),d.focus())}),f.addEventListener("click",g);const h=()=>{s.hidden||l()};d.addEventListener("input",h),i.addEventListener("change",h),e.addEventListener("submit",async m=>{m.preventDefault();const y=d.value.trim();if(!y||!i.value){l(),y?i.focus():d.focus();return}s.hidden=!0,s.textContent="",p.disabled=!0;const H=p.textContent;p.textContent=c("crm_notes","Saving…");try{const{data:w}=await N.post(`${E}/notes`,{contactUid:a,noteTypeId:Number(i.value),title:y,content:u.value||null}),M=n.querySelector(".crm-contacts-notes-empty");M&&M.remove(),n.insertBefore(V(w,v),n.firstChild),g(),F(c("crm_notes","Note added."))}catch{_(c("crm_notes","Failed to add note."))}finally{p.disabled=!1,p.textContent=H}})}async function U(t,n){t.innerHTML=q();try{const[a,o]=await Promise.all([T(n,C,0),I()]);if(t.innerHTML="",a.length)a.forEach(e=>t.appendChild(V(e,o))),a.length===C&&Y(t,n,o,a.length);else{const e=document.createElement("p");e.className="crm-contacts-notes-empty",e.textContent=c("crm_notes","No notes yet"),t.appendChild(e)}}catch{t.innerHTML="";const a=document.createElement("p");a.className="crm-contacts-notes-empty",a.textContent=c("crm_notes","Could not load notes."),t.appendChild(a);const o=document.createElement("button");o.type="button",o.className="crm-contacts-notes-retry",o.textContent=c("crm_notes","Retry"),o.addEventListener("click",()=>U(t,n)),t.appendChild(o),_(c("crm_notes","Failed to load CRM notes."))}}function Y(t,n,a,o){const e=document.createElement("button");e.type="button",e.className="crm-contacts-notes-retry",e.textContent=c("crm_notes","Show more");let d=o;e.addEventListener("click",async()=>{e.disabled=!0,e.textContent=c("crm_notes","Loading…");try{const i=await T(n,C,d);i.forEach(u=>t.insertBefore(V(u,a),e)),d+=i.length,i.length===C?(e.disabled=!1,e.textContent=c("crm_notes","Show more")):e.remove()}catch{e.disabled=!1,e.textContent=c("crm_notes","Show more"),_(c("crm_notes","Failed to load more notes."))}}),t.appendChild(e)}let b=null;function R(){if(b&&b.isConnected){const n=b.querySelector(".crm-contacts-notes-panel");if(n&&n.dataset.crmContactUid===A(b))return}const t=[".contact-details-wrapper",".contact-details",".contact__details",'[class*="contact-detail"]',".app-content-detail"];for(const n of t){const a=document.querySelector(n);if(a){Q(a),b=a;return}}b=null}let $=!1;function L(){$||($=!0,requestAnimationFrame(()=>{$=!1,R()}))}const tt=new MutationObserver(()=>{L()});function z(){tt.observe(document.body,{childList:!0,subtree:!0}),R()}document.readyState==="loading"?document.addEventListener("DOMContentLoaded",z):z(),window.addEventListener("hashchange",()=>{setTimeout(L,200)}),window.addEventListener("popstate",()=>{setTimeout(L,200)});
//# sourceMappingURL=crm_notes-contacts-integration.mjs.map
