import{g as C,a as z,t as r,s as H,c as L}from"./NcNoteCard-DZSuYX4--CUtNY3w_.chunk.mjs";import{r as A,i as T,a as S}from"./markdown-C6ChqIen.chunk.mjs";const k=C("/apps/crm_notes/api"),I={note:"M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z",openInNew:"M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z",file:"M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z",pin:"M16,12V4H17V2H7V4H8V12L6,14V16H11.2V22H12.8V16H18V14L16,12Z",chevronDown:"M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z"};function u(t,n=16){return`<svg viewBox="0 0 24 24" width="${n}" height="${n}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${I[t]}" /></svg>`}function E(){return`<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${r("crm_notes","Loading…")}</span>
	</span>`}function R(t){if(typeof t!="string")return"var(--color-text-maxcontrast)";const n=t.trim();return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(n)||/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(n)?n:"var(--color-text-maxcontrast)"}function q(t){const n=t.dataset?.contactUid||t.closest("[data-contact-uid]")?.dataset?.contactUid;if(n)return n;const e=window.location.hash.match(/contact:([^/]+)/)||window.location.pathname.match(/contact:([^/]+)/);return e?decodeURIComponent(e[1]):null}const f=50;async function V(t,n=f,e=0){const{data:o}=await L.get(`${k}/notes/contact/${encodeURIComponent(t)}`,{params:{limit:n,offset:e}});return o}let h=null;async function P(){return h||(h=L.get(`${k}/note-types`).then(({data:t})=>{const n={};for(const e of t)n[e.id]={name:e.name,color:e.color,icon:e.icon};return n}).catch(t=>{throw h=null,t})),h.catch(()=>({}))}const U=new Intl.DateTimeFormat(z().replace("_","-"),{year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});function F(t){if(!t)return"";const n=new Date(t);return isNaN(n.getTime())?"":U.format(n)}function B(t){return t.name?t.name:t.filePath?t.filePath.split("/").pop():r("crm_notes","Attachment")}function M(t,n={}){const e=document.createElement("div");e.className="crm-contacts-note-item";const o=n[t.noteTypeId]||t.noteType||{},a=document.createElement("span");a.className="crm-contacts-type-badge";const i=R(o.color);a.style.background=i,a.style.color=A(i);const l=T(o.icon);if(l){const c=document.createElement("span");c.className="crm-contacts-type-badge-icon",c.setAttribute("aria-hidden","true"),c.innerHTML=`<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${l}" /></svg>`,a.appendChild(c)}const d=document.createElement("span");d.textContent=o.name||"",a.appendChild(d);const m=document.createElement("div");m.className="crm-contacts-note-header",m.appendChild(a);const s=document.createElement("h2");if(s.className="crm-contacts-note-title",s.textContent=t.title||"",m.appendChild(s),t.isPinned){const c=document.createElement("span");c.className="crm-contacts-pin-indicator",c.setAttribute("role","img"),c.setAttribute("aria-label",r("crm_notes","Pinned")),c.innerHTML=u("pin",16),m.appendChild(c)}if(e.appendChild(m),t.content){const c=document.createElement("div");c.className="crm-contacts-note-content",c.innerHTML=S(t.content),e.appendChild(c)}if(Array.isArray(t.files)&&t.files.length){const c=document.createElement("div");c.className="crm-contacts-note-files";for(const _ of t.files){const g=document.createElement("span");g.className="crm-contacts-file-chip";const b=document.createElement("span");b.className="crm-contacts-file-chip-icon",b.innerHTML=u("file",12);const x=document.createElement("span");x.className="crm-contacts-file-chip-label",x.textContent=B(_),g.appendChild(b),g.appendChild(x),c.appendChild(g)}e.appendChild(c)}const v=document.createElement("span");return v.className="crm-contacts-note-date",v.textContent=F(t.createdAt),e.appendChild(v),e}async function D(t){if(t.querySelector(".crm-contacts-notes-panel"))return;const n=q(t);if(!n)return;const e=document.createElement("div");e.className="crm-contacts-notes-panel";const o=r("crm_notes","Open in CRM Notes (opens in a new tab)"),a=`crm-contacts-notes-body-${Math.random().toString(36).slice(2,10)}`;if(e.innerHTML=`
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${a}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${u("chevronDown",18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${u("note",18)}</span>
				<span>${r("crm_notes","CRM Notes")}</span>
			</button>
			<a class="crm-contacts-open-app"
				href="${C("/apps/crm_notes")}#contact/${encodeURIComponent(n)}"
				title="${o}"
				aria-label="${o}"
				target="_blank"
				rel="noopener">${u("openInNew",14)}</a>
		</div>
		<div id="${a}" class="crm-contacts-notes-body">
			${E()}
		</div>
	`,t.appendChild(e),!document.getElementById("crm-contacts-integration-style")){const s=document.createElement("style");s.id="crm-contacts-integration-style",s.textContent=`
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
		`,document.head.appendChild(s)}const i=e.querySelector(".crm-contacts-notes-toggle"),l=e.querySelector(".crm-contacts-notes-body"),d=e.querySelector(".crm-contacts-notes-chevron");i.addEventListener("click",()=>{const s=i.getAttribute("aria-expanded")!=="false";i.setAttribute("aria-expanded",s?"false":"true"),l.style.display=s?"none":"",d&&d.classList.toggle("crm-contacts-notes-chevron--collapsed",s)});const m=e.querySelector(".crm-contacts-notes-body");N(m,n)}async function N(t,n){t.innerHTML=E();try{const[e,o]=await Promise.all([V(n,f,0),P()]);if(t.innerHTML="",e.length)e.forEach(a=>t.appendChild(M(a,o))),e.length===f&&Z(t,n,o,e.length);else{const a=document.createElement("p");a.className="crm-contacts-notes-empty",a.textContent=r("crm_notes","No notes yet"),t.appendChild(a)}}catch{t.innerHTML="";const e=document.createElement("p");e.className="crm-contacts-notes-empty",e.textContent=r("crm_notes","Could not load notes."),t.appendChild(e);const o=document.createElement("button");o.type="button",o.className="crm-contacts-notes-retry",o.textContent=r("crm_notes","Retry"),o.addEventListener("click",()=>N(t,n)),t.appendChild(o),H(r("crm_notes","Failed to load CRM notes."))}}function Z(t,n,e,o){const a=document.createElement("button");a.type="button",a.className="crm-contacts-notes-retry",a.textContent=r("crm_notes","Show more");let i=o;a.addEventListener("click",async()=>{a.disabled=!0,a.textContent=r("crm_notes","Loading…");try{const l=await V(n,f,i);l.forEach(d=>t.insertBefore(M(d,e),a)),i+=l.length,l.length===f?(a.disabled=!1,a.textContent=r("crm_notes","Show more")):a.remove()}catch{a.disabled=!1,a.textContent=r("crm_notes","Show more"),H(r("crm_notes","Failed to load more notes."))}}),t.appendChild(a)}let p=null;function $(){if(p&&p.isConnected&&p.querySelector(".crm-contacts-notes-panel"))return;const t=[".contact-details",".contact__details",'[class*="contact-detail"]',".app-content-detail"];for(const n of t){const e=document.querySelector(n);if(e){D(e),p=e;return}}p=null}let y=!1;function w(){y||(y=!0,requestAnimationFrame(()=>{y=!1,$()}))}const O=new MutationObserver(()=>{w()});document.addEventListener("DOMContentLoaded",()=>{O.observe(document.body,{childList:!0,subtree:!0}),$()}),window.addEventListener("hashchange",()=>{setTimeout(w,200)}),window.addEventListener("popstate",()=>{setTimeout(w,200)});
//# sourceMappingURL=crm_notes-contacts-integration.mjs.map
