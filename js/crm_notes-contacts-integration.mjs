import{g as q,a as D,t as r,c as E,s as F,b as V}from"./NcNoteCard-DZSuYX4--BfYn75fP.chunk.mjs";import{r as P,i as O,a as Z}from"./markdown-D3usn99V.chunk.mjs";const M=q("/apps/crm_notes/api"),j={note:"M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z",openInNew:"M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z",file:"M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z",pin:"M16,12V4H17V2H7V4H8V12L6,14V16H11.2V22H12.8V16H18V14L16,12Z",chevronDown:"M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z",plus:"M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"};function C(t,n=16){return`<svg viewBox="0 0 24 24" width="${n}" height="${n}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${j[t]}" /></svg>`}function u(t){return String(t).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}function T(){return`<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${u(r("crm_notes","Loading…"))}</span>
	</span>`}function W(t){if(typeof t!="string")return"var(--color-text-maxcontrast)";const n=t.trim();return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(n)||/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(n)?n:"var(--color-text-maxcontrast)"}const G=new TextDecoder("utf-8",{fatal:!0});function J(t){try{const n=decodeURIComponent(t);let a;try{a=atob(n)}catch{a=atob(n.replace(/-/g,"+").replace(/_/g,"/"))}const o=Uint8Array.from(a,e=>e.charCodeAt(0));return G.decode(o)}catch{return null}}function A(t){const n=t.dataset?.contactUid||t.closest("[data-contact-uid]")?.dataset?.contactUid;if(n)return n;const a=window.location.pathname.split("/").filter(Boolean).pop();if(a){const e=J(a);if(e!==null&&e.includes("~")){const l=e.substring(0,e.lastIndexOf("~"));if(l)return l}}const o=window.location.hash.match(/contact:([^/]+)/)||window.location.pathname.match(/contact:([^/]+)/);return o?decodeURIComponent(o[1]):null}const k=50;async function I(t,n=k,a=0){const{data:o}=await E.get(`${M}/notes/contact/${encodeURIComponent(t)}`,{params:{limit:n,offset:a}});return o}let H=null;async function U(){return H||(H=E.get(`${M}/note-types`).then(({data:t})=>{const n={};for(const a of t)n[a.id]={name:a.name,color:a.color,icon:a.icon};return n}).catch(t=>{throw H=null,t})),H.catch(()=>({}))}const K=new Intl.DateTimeFormat(D().replace("_","-"),{year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});function Q(t){if(!t)return"";const n=new Date(t);return isNaN(n.getTime())?"":K.format(n)}function X(t){return t.name?t.name:t.filePath?t.filePath.split("/").pop():r("crm_notes","Attachment")}function S(t,n={}){const a=document.createElement("div");a.className="crm-contacts-note-item",t.id!=null&&(a.dataset.noteId=String(t.id));const o=n[t.noteTypeId]||t.noteType||{},e=document.createElement("span");e.className="crm-contacts-type-badge";const l=W(o.color);e.style.background=l,e.style.color=P(l);const i=O(o.icon);if(i){const s=document.createElement("span");s.className="crm-contacts-type-badge-icon",s.setAttribute("aria-hidden","true"),s.innerHTML=`<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${i}" /></svg>`,e.appendChild(s)}const f=document.createElement("span");f.textContent=o.name||"",e.appendChild(f);const c=document.createElement("div");c.className="crm-contacts-note-header",c.appendChild(e);const g=document.createElement("h2");if(g.className="crm-contacts-note-title",g.textContent=t.title||"",c.appendChild(g),t.isPinned){const s=document.createElement("span");s.className="crm-contacts-pin-indicator",s.setAttribute("role","img"),s.setAttribute("aria-label",r("crm_notes","Pinned")),s.innerHTML=C("pin",16),c.appendChild(s)}if(a.appendChild(c),t.content){const s=document.createElement("div");s.className="crm-contacts-note-content",s.innerHTML=Z(t.content),a.appendChild(s)}if(Array.isArray(t.files)&&t.files.length){const s=document.createElement("div");s.className="crm-contacts-note-files";for(const v of t.files){const p=document.createElement("span");p.className="crm-contacts-file-chip";const h=document.createElement("span");h.className="crm-contacts-file-chip-icon",h.innerHTML=C("file",12);const b=document.createElement("span");b.className="crm-contacts-file-chip-label",b.textContent=X(v),p.appendChild(h),p.appendChild(b),s.appendChild(p)}a.appendChild(s)}const m=document.createElement("span");return m.className="crm-contacts-note-date",m.textContent=Q(t.createdAt),a.appendChild(m),a}async function Y(t){const n=A(t);if(!n)return;const a=t.querySelector(".crm-contacts-notes-panel");if(a){if(a.dataset.crmContactUid===n)return;a.remove()}const o=document.createElement("div");o.className="crm-contacts-notes-panel",o.dataset.crmContactUid=n;const e=r("crm_notes","Open in CRM Notes (opens in a new tab)"),l=r("crm_notes","Add note"),i=Math.random().toString(36).slice(2,10),f=`crm-contacts-notes-body-${i}`,c=`crm-contacts-addform-${i}`;if(o.innerHTML=`
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${f}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${C("chevronDown",18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${C("note",18)}</span>
				<span>${u(r("crm_notes","CRM Notes"))}</span>
			</button>
			<button type="button" class="crm-contacts-notes-add" title="${u(l)}" aria-label="${u(l)}" aria-expanded="false" aria-controls="${c}">${C("plus",16)}</button>
			<a class="crm-contacts-open-app"
				href="${q("/apps/crm_notes")}#contact/${encodeURIComponent(n)}"
				title="${u(e)}"
				aria-label="${u(e)}"
				target="_blank"
				rel="noopener">${C("openInNew",14)}</a>
		</div>
		<form id="${c}" class="crm-contacts-notes-addform" hidden>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${c}-title">${u(r("crm_notes","Title"))}<span class="crm-contacts-addform-required" aria-hidden="true">*</span></label>
				<input id="${c}-title" type="text" class="crm-contacts-addform-title" maxlength="255" required placeholder="${u(r("crm_notes","Note title"))}" />
			</div>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${c}-type">${u(r("crm_notes","Type"))}<span class="crm-contacts-addform-required" aria-hidden="true">*</span></label>
				<select id="${c}-type" class="crm-contacts-addform-type" required></select>
			</div>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${c}-content">${u(r("crm_notes","Content"))}</label>
				<textarea id="${c}-content" class="crm-contacts-addform-content" rows="3" placeholder="${u(r("crm_notes","Write a note…"))}"></textarea>
			</div>
			<p class="crm-contacts-addform-hint" role="status" aria-live="polite" hidden></p>
			<div class="crm-contacts-addform-actions">
				<button type="button" class="crm-contacts-addform-cancel">${u(r("crm_notes","Cancel"))}</button>
				<button type="submit" class="crm-contacts-addform-save">${u(r("crm_notes","Save"))}</button>
			</div>
		</form>
		<div id="${f}" class="crm-contacts-notes-body">
			${T()}
		</div>
	`,t.appendChild(o),!document.getElementById("crm-contacts-integration-style")){const p=document.createElement("style");p.id="crm-contacts-integration-style",p.textContent=`
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
			.crm-contacts-note-content h3,
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
		`,document.head.appendChild(p)}const g=o.querySelector(".crm-contacts-notes-toggle"),m=o.querySelector(".crm-contacts-notes-body"),s=o.querySelector(".crm-contacts-notes-chevron");g.addEventListener("click",()=>{const p=g.getAttribute("aria-expanded")!=="false";g.setAttribute("aria-expanded",p?"false":"true"),m.style.display=p?"none":"",s&&s.classList.toggle("crm-contacts-notes-chevron--collapsed",p)});const v=o.querySelector(".crm-contacts-notes-body");tt(o,v,n),R(v,n)}function tt(t,n,a){const o=t.querySelector(".crm-contacts-notes-add"),e=t.querySelector(".crm-contacts-notes-addform");if(!o||!e)return;const l=e.querySelector(".crm-contacts-addform-title"),i=e.querySelector(".crm-contacts-addform-type"),f=e.querySelector(".crm-contacts-addform-content"),c=e.querySelector(".crm-contacts-addform-hint"),g=e.querySelector(".crm-contacts-addform-cancel"),m=e.querySelector(".crm-contacts-addform-save"),s=t.querySelector(".crm-contacts-notes-toggle");let v={};m.disabled=!0,U().then(d=>{v=d,i.innerHTML="";for(const[$,N]of Object.entries(d)){const y=document.createElement("option");y.value=$,y.textContent=N.name,i.appendChild(y)}const x=Object.keys(d);x.length?(i.value=x[0],m.disabled=!1,c.dataset.crmNoTypes==="1"&&(delete c.dataset.crmNoTypes,c.hidden=!0,c.textContent="")):(m.disabled=!0,c.dataset.crmNoTypes="1",c.textContent=r("crm_notes","Create a note type first."),c.hidden=!1)}).catch(()=>{m.disabled=!0,c.dataset.crmNoTypes="1",c.textContent=r("crm_notes","Could not load note types."),c.hidden=!1});function p(){const d=[];if(l.value.trim()||d.push(r("crm_notes","Title")),i.value||d.push(r("crm_notes","Type")),!d.length){c.hidden=!0,c.textContent="";return}c.textContent=r("crm_notes","Required: {fields}",{fields:d.join(", ")}),c.hidden=!1}function h(){e.hidden=!0,o.setAttribute("aria-expanded","false"),e.reset(),o.focus(),c.dataset.crmNoTypes!=="1"&&(c.hidden=!0,c.textContent="")}e.addEventListener("keydown",d=>{d.key==="Escape"&&!e.hidden&&(d.preventDefault(),h())}),o.addEventListener("click",()=>{const d=e.hidden;e.hidden=!d,o.setAttribute("aria-expanded",d?"true":"false"),d&&(s&&s.getAttribute("aria-expanded")==="false"&&s.click(),l.focus())}),g.addEventListener("click",h);const b=()=>{c.hidden||p()};l.addEventListener("input",b),i.addEventListener("change",b),e.addEventListener("submit",async d=>{d.preventDefault();const x=l.value.trim();if(!x||!i.value){p(),x?i.focus():l.focus();return}c.hidden=!0,c.textContent="",m.disabled=!0;const $=m.textContent;m.textContent=r("crm_notes","Saving…");try{const{data:N}=await E.post(`${M}/notes`,{contactUid:a,noteTypeId:Number(i.value),title:x,content:f.value||null}),y=n.querySelector(".crm-contacts-notes-empty");y&&y.remove(),n.insertBefore(S(N,v),n.firstChild),h(),F(r("crm_notes","Note added."))}catch{V(r("crm_notes","Failed to add note."))}finally{m.disabled=!1,m.textContent=$}})}async function R(t,n){t.innerHTML=T();try{const[a,o]=await Promise.all([I(n,k,0),U()]);if(t.innerHTML="",a.length)a.forEach(e=>t.appendChild(S(e,o))),a.length===k&&et(t,n,o,a.length);else{const e=document.createElement("p");e.className="crm-contacts-notes-empty",e.textContent=r("crm_notes","No notes yet"),t.appendChild(e)}}catch{t.innerHTML="";const a=document.createElement("p");a.className="crm-contacts-notes-empty",a.textContent=r("crm_notes","Could not load notes."),t.appendChild(a);const o=document.createElement("button");o.type="button",o.className="crm-contacts-notes-retry",o.textContent=r("crm_notes","Retry"),o.addEventListener("click",()=>R(t,n)),t.appendChild(o),V(r("crm_notes","Failed to load CRM notes."))}}function et(t,n,a,o){const e=document.createElement("button");e.type="button",e.className="crm-contacts-notes-retry",e.textContent=r("crm_notes","Show more");let l=o;e.addEventListener("click",async()=>{e.disabled=!0,e.textContent=r("crm_notes","Loading…");try{const i=await I(n,k,l);l+=i.length,i.forEach(f=>{f.id!=null&&t.querySelector(`.crm-contacts-note-item[data-note-id="${f.id}"]`)||t.insertBefore(S(f,a),e)}),i.length===k?(e.disabled=!1,e.textContent=r("crm_notes","Show more")):e.remove()}catch{e.disabled=!1,e.textContent=r("crm_notes","Show more"),V(r("crm_notes","Failed to load more notes."))}}),t.appendChild(e)}let w=null;function B(){if(w&&w.isConnected){const n=w.querySelector(".crm-contacts-notes-panel");if(n&&n.dataset.crmContactUid===A(w))return}const t=[".contact-details-wrapper",".contact-details",".contact__details",'[class*="contact-detail"]',".app-content-detail"];for(const n of t){const a=document.querySelector(n);if(a){Y(a),w=a;return}}w=null}let L=!1;function _(){L||(L=!0,requestAnimationFrame(()=>{L=!1,B()}))}const nt=new MutationObserver(()=>{_()});function z(){nt.observe(document.body,{childList:!0,subtree:!0}),B()}document.readyState==="loading"?document.addEventListener("DOMContentLoaded",z):z(),window.addEventListener("hashchange",()=>{setTimeout(_,200)}),window.addEventListener("popstate",()=>{setTimeout(_,200)});
//# sourceMappingURL=crm_notes-contacts-integration.mjs.map
