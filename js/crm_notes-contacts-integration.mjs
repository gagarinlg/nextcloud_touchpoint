import{g as H,a as T,t as r,s as L,c as k}from"./NcNoteCard-DZSuYX4--CUtNY3w_.chunk.mjs";import{r as I,i as S,a as U}from"./markdown-BmXc_xIq.chunk.mjs";const E=H("/apps/crm_notes/api"),R={note:"M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z",openInNew:"M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z",file:"M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z",pin:"M16,12V4H17V2H7V4H8V12L6,14V16H11.2V22H12.8V16H18V14L16,12Z",chevronDown:"M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z"};function f(t,e=16){return`<svg viewBox="0 0 24 24" width="${e}" height="${e}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${R[t]}" /></svg>`}function V(){return`<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${r("crm_notes","Loading…")}</span>
	</span>`}function q(t){if(typeof t!="string")return"var(--color-text-maxcontrast)";const e=t.trim();return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(e)||/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(e)?e:"var(--color-text-maxcontrast)"}function P(t){try{const e=decodeURIComponent(t);try{return atob(e)}catch{return atob(e.replace(/-/g,"+").replace(/_/g,"/"))}}catch{return null}}function M(t){const e=t.dataset?.contactUid||t.closest("[data-contact-uid]")?.dataset?.contactUid;if(e)return e;const n=window.location.pathname.split("/").filter(Boolean).pop();if(n){const a=P(n);if(a&&a.includes("~"))return a.substring(0,a.lastIndexOf("~"))}const o=window.location.hash.match(/contact:([^/]+)/)||window.location.pathname.match(/contact:([^/]+)/);return o?decodeURIComponent(o[1]):null}const g=50;async function N(t,e=g,n=0){const{data:o}=await k.get(`${E}/notes/contact/${encodeURIComponent(t)}`,{params:{limit:e,offset:n}});return o}let b=null;async function B(){return b||(b=k.get(`${E}/note-types`).then(({data:t})=>{const e={};for(const n of t)e[n.id]={name:n.name,color:n.color,icon:n.icon};return e}).catch(t=>{throw b=null,t})),b.catch(()=>({}))}const F=new Intl.DateTimeFormat(T().replace("_","-"),{year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});function D(t){if(!t)return"";const e=new Date(t);return isNaN(e.getTime())?"":F.format(e)}function Z(t){return t.name?t.name:t.filePath?t.filePath.split("/").pop():r("crm_notes","Attachment")}function _(t,e={}){const n=document.createElement("div");n.className="crm-contacts-note-item";const o=e[t.noteTypeId]||t.noteType||{},a=document.createElement("span");a.className="crm-contacts-type-badge";const l=q(o.color);a.style.background=l,a.style.color=I(l);const i=S(o.icon);if(i){const c=document.createElement("span");c.className="crm-contacts-type-badge-icon",c.setAttribute("aria-hidden","true"),c.innerHTML=`<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${i}" /></svg>`,a.appendChild(c)}const m=document.createElement("span");m.textContent=o.name||"",a.appendChild(m);const d=document.createElement("div");d.className="crm-contacts-note-header",d.appendChild(a);const u=document.createElement("h2");if(u.className="crm-contacts-note-title",u.textContent=t.title||"",d.appendChild(u),t.isPinned){const c=document.createElement("span");c.className="crm-contacts-pin-indicator",c.setAttribute("role","img"),c.setAttribute("aria-label",r("crm_notes","Pinned")),c.innerHTML=f("pin",16),d.appendChild(c)}if(n.appendChild(d),t.content){const c=document.createElement("div");c.className="crm-contacts-note-content",c.innerHTML=U(t.content),n.appendChild(c)}if(Array.isArray(t.files)&&t.files.length){const c=document.createElement("div");c.className="crm-contacts-note-files";for(const A of t.files){const h=document.createElement("span");h.className="crm-contacts-file-chip";const v=document.createElement("span");v.className="crm-contacts-file-chip-icon",v.innerHTML=f("file",12);const x=document.createElement("span");x.className="crm-contacts-file-chip-label",x.textContent=Z(A),h.appendChild(v),h.appendChild(x),c.appendChild(h)}n.appendChild(c)}const s=document.createElement("span");return s.className="crm-contacts-note-date",s.textContent=D(t.createdAt),n.appendChild(s),n}async function O(t){const e=M(t);if(!e)return;const n=t.querySelector(".crm-contacts-notes-panel");if(n){if(n.dataset.crmContactUid===e)return;n.remove()}const o=document.createElement("div");o.className="crm-contacts-notes-panel",o.dataset.crmContactUid=e;const a=r("crm_notes","Open in CRM Notes (opens in a new tab)"),l=`crm-contacts-notes-body-${Math.random().toString(36).slice(2,10)}`;if(o.innerHTML=`
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${l}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${f("chevronDown",18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${f("note",18)}</span>
				<span>${r("crm_notes","CRM Notes")}</span>
			</button>
			<a class="crm-contacts-open-app"
				href="${H("/apps/crm_notes")}#contact/${encodeURIComponent(e)}"
				title="${a}"
				aria-label="${a}"
				target="_blank"
				rel="noopener">${f("openInNew",14)}</a>
		</div>
		<div id="${l}" class="crm-contacts-notes-body">
			${V()}
		</div>
	`,t.appendChild(o),!document.getElementById("crm-contacts-integration-style")){const s=document.createElement("style");s.id="crm-contacts-integration-style",s.textContent=`
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
		`,document.head.appendChild(s)}const i=o.querySelector(".crm-contacts-notes-toggle"),m=o.querySelector(".crm-contacts-notes-body"),d=o.querySelector(".crm-contacts-notes-chevron");i.addEventListener("click",()=>{const s=i.getAttribute("aria-expanded")!=="false";i.setAttribute("aria-expanded",s?"false":"true"),m.style.display=s?"none":"",d&&d.classList.toggle("crm-contacts-notes-chevron--collapsed",s)});const u=o.querySelector(".crm-contacts-notes-body");$(u,e)}async function $(t,e){t.innerHTML=V();try{const[n,o]=await Promise.all([N(e,g,0),B()]);if(t.innerHTML="",n.length)n.forEach(a=>t.appendChild(_(a,o))),n.length===g&&j(t,e,o,n.length);else{const a=document.createElement("p");a.className="crm-contacts-notes-empty",a.textContent=r("crm_notes","No notes yet"),t.appendChild(a)}}catch{t.innerHTML="";const n=document.createElement("p");n.className="crm-contacts-notes-empty",n.textContent=r("crm_notes","Could not load notes."),t.appendChild(n);const o=document.createElement("button");o.type="button",o.className="crm-contacts-notes-retry",o.textContent=r("crm_notes","Retry"),o.addEventListener("click",()=>$(t,e)),t.appendChild(o),L(r("crm_notes","Failed to load CRM notes."))}}function j(t,e,n,o){const a=document.createElement("button");a.type="button",a.className="crm-contacts-notes-retry",a.textContent=r("crm_notes","Show more");let l=o;a.addEventListener("click",async()=>{a.disabled=!0,a.textContent=r("crm_notes","Loading…");try{const i=await N(e,g,l);i.forEach(m=>t.insertBefore(_(m,n),a)),l+=i.length,i.length===g?(a.disabled=!1,a.textContent=r("crm_notes","Show more")):a.remove()}catch{a.disabled=!1,a.textContent=r("crm_notes","Show more"),L(r("crm_notes","Failed to load more notes."))}}),t.appendChild(a)}let p=null;function z(){if(p&&p.isConnected){const e=p.querySelector(".crm-contacts-notes-panel");if(e&&e.dataset.crmContactUid===M(p))return}const t=[".contact-details",".contact__details",'[class*="contact-detail"]',".app-content-detail"];for(const e of t){const n=document.querySelector(e);if(n){O(n),p=n;return}}p=null}let y=!1;function w(){y||(y=!0,requestAnimationFrame(()=>{y=!1,z()}))}const G=new MutationObserver(()=>{w()});function C(){G.observe(document.body,{childList:!0,subtree:!0}),z()}document.readyState==="loading"?document.addEventListener("DOMContentLoaded",C):C(),window.addEventListener("hashchange",()=>{setTimeout(w,200)}),window.addEventListener("popstate",()=>{setTimeout(w,200)});
//# sourceMappingURL=crm_notes-contacts-integration.mjs.map
