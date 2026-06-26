import{g as x,a as $,t as c,s as y,c as w}from"./index-CwIPLMrY.chunk.mjs";import{r as N,i as V,a as _}from"./markdown-DEwWHUvw.chunk.mjs";const C=x("/apps/crm_notes/api"),z={note:"M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z",openInNew:"M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z",file:"M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z",chevronDown:"M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z"};function h(t,n=16){return`<svg viewBox="0 0 24 24" width="${n}" height="${n}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${z[t]}" /></svg>`}function L(){return`<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${c("crm_notes","Loading…")}</span>
	</span>`}function A(t){if(typeof t!="string")return"var(--color-text-maxcontrast)";const n=t.trim();return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(n)||/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(n)?n:"var(--color-text-maxcontrast)"}function T(t){const n=t.dataset?.contactUid||t.closest("[data-contact-uid]")?.dataset?.contactUid;if(n)return n;const e=window.location.hash.match(/contact:([^/]+)/)||window.location.pathname.match(/contact:([^/]+)/);return e?decodeURIComponent(e[1]):null}const u=50;async function H(t,n=u,e=0){const{data:a}=await w.get(`${C}/notes/contact/${encodeURIComponent(t)}`,{params:{limit:n,offset:e}});return a}let g=null;async function S(){return g||(g=w.get(`${C}/note-types`).then(({data:t})=>{const n={};for(const e of t)n[e.id]={name:e.name,color:e.color,icon:e.icon};return n}).catch(t=>{throw g=null,t})),g.catch(()=>({}))}const I=new Intl.DateTimeFormat($().replace("_","-"),{year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});function R(t){if(!t)return"";const n=new Date(t);return isNaN(n.getTime())?"":I.format(n)}function k(t,n={}){const e=document.createElement("div");e.className="crm-contacts-note-item";const a=n[t.noteTypeId]||t.noteType||{},o=document.createElement("span");o.className="crm-contacts-type-badge";const s=A(a.color);o.style.background=s,o.style.color=N(s);const i=V(a.icon);if(i){const l=document.createElement("span");l.className="crm-contacts-type-badge-icon",l.setAttribute("aria-hidden","true"),l.innerHTML=`<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${i}" /></svg>`,o.appendChild(l)}const d=document.createElement("span");d.textContent=a.name||"",o.appendChild(d);const m=document.createElement("div");m.className="crm-contacts-note-header",m.appendChild(o);const r=document.createElement("h2");if(r.className="crm-contacts-note-title",r.textContent=t.title||"",m.appendChild(r),e.appendChild(m),t.content){const l=document.createElement("div");l.className="crm-contacts-note-content",l.innerHTML=_(t.content),e.appendChild(l)}const f=document.createElement("span");return f.className="crm-contacts-note-date",f.textContent=R(t.createdAt),e.appendChild(f),e}async function q(t){if(t.querySelector(".crm-contacts-notes-panel"))return;const n=T(t);if(!n)return;const e=document.createElement("div");e.className="crm-contacts-notes-panel";const a=c("crm_notes","Open in CRM Notes (opens in a new tab)"),o=`crm-contacts-notes-body-${Math.random().toString(36).slice(2,10)}`;if(e.innerHTML=`
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${o}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${h("chevronDown",18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${h("note",18)}</span>
				<span>${c("crm_notes","CRM Notes")}</span>
			</button>
			<a class="crm-contacts-open-app"
				href="${x("/apps/crm_notes")}#contact/${encodeURIComponent(n)}"
				title="${a}"
				aria-label="${a}"
				target="_blank"
				rel="noopener">${h("openInNew",14)}</a>
		</div>
		<div id="${o}" class="crm-contacts-notes-body">
			${L()}
		</div>
	`,t.appendChild(e),!document.getElementById("crm-contacts-integration-style")){const r=document.createElement("style");r.id="crm-contacts-integration-style",r.textContent=`
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
		`,document.head.appendChild(r)}const s=e.querySelector(".crm-contacts-notes-toggle"),i=e.querySelector(".crm-contacts-notes-body"),d=e.querySelector(".crm-contacts-notes-chevron");s.addEventListener("click",()=>{const r=s.getAttribute("aria-expanded")!=="false";s.setAttribute("aria-expanded",r?"false":"true"),i.style.display=r?"none":"",d&&d.classList.toggle("crm-contacts-notes-chevron--collapsed",r)});const m=e.querySelector(".crm-contacts-notes-body");E(m,n)}async function E(t,n){t.innerHTML=L();try{const[e,a]=await Promise.all([H(n,u,0),S()]);if(t.innerHTML="",e.length)e.forEach(o=>t.appendChild(k(o,a))),e.length===u&&U(t,n,a,e.length);else{const o=document.createElement("p");o.className="crm-contacts-notes-empty",o.textContent=c("crm_notes","No notes yet"),t.appendChild(o)}}catch{t.innerHTML="";const e=document.createElement("p");e.className="crm-contacts-notes-empty",e.textContent=c("crm_notes","Could not load notes."),t.appendChild(e);const a=document.createElement("button");a.type="button",a.className="crm-contacts-notes-retry",a.textContent=c("crm_notes","Retry"),a.addEventListener("click",()=>E(t,n)),t.appendChild(a),y(c("crm_notes","Failed to load CRM notes."))}}function U(t,n,e,a){const o=document.createElement("button");o.type="button",o.className="crm-contacts-notes-retry",o.textContent=c("crm_notes","Show more");let s=a;o.addEventListener("click",async()=>{o.disabled=!0,o.textContent=c("crm_notes","Loading…");try{const i=await H(n,u,s);i.forEach(d=>t.insertBefore(k(d,e),o)),s+=i.length,i.length===u?(o.disabled=!1,o.textContent=c("crm_notes","Show more")):o.remove()}catch{o.disabled=!1,o.textContent=c("crm_notes","Show more"),y(c("crm_notes","Failed to load more notes."))}}),t.appendChild(o)}let p=null;function M(){if(p&&p.isConnected&&p.querySelector(".crm-contacts-notes-panel"))return;const t=[".contact-details",".contact__details",'[class*="contact-detail"]',".app-content-detail"];for(const n of t){const e=document.querySelector(n);if(e){q(e),p=e;return}}p=null}let v=!1;function b(){v||(v=!0,requestAnimationFrame(()=>{v=!1,M()}))}const F=new MutationObserver(()=>{b()});document.addEventListener("DOMContentLoaded",()=>{F.observe(document.body,{childList:!0,subtree:!0}),M()}),window.addEventListener("hashchange",()=>{setTimeout(b,200)}),window.addEventListener("popstate",()=>{setTimeout(b,200)});
//# sourceMappingURL=crm_notes-contacts-integration.mjs.map
