import{g as b,a as L,t as s,s as k,c as v}from"./index-CwIPLMrY.chunk.mjs";import{r as M,i as $,a as E}from"./markdown-DEwWHUvw.chunk.mjs";const y=b("/apps/crm_notes/api"),V={note:"M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z",openInNew:"M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z",file:"M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z",chevronDown:"M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z"};function h(t,n=16){return`<svg viewBox="0 0 24 24" width="${n}" height="${n}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${V[t]}" /></svg>`}function w(){return`<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${s("crm_notes","Loading…")}</span>
	</span>`}function N(t){if(typeof t!="string")return"var(--color-text-maxcontrast)";const n=t.trim();return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(n)||/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(n)?n:"var(--color-text-maxcontrast)"}function _(t){const n=t.dataset?.contactUid||t.closest("[data-contact-uid]")?.dataset?.contactUid;if(n)return n;const e=window.location.hash.match(/contact:([^/]+)/)||window.location.pathname.match(/contact:([^/]+)/);return e?decodeURIComponent(e[1]):null}async function A(t){const{data:n}=await v.get(`${y}/notes/contact/${encodeURIComponent(t)}`);return n}let u=null;async function T(){return u||(u=v.get(`${y}/note-types`).then(({data:t})=>{const n={};for(const e of t)n[e.id]={name:e.name,color:e.color,icon:e.icon};return n}).catch(t=>{throw u=null,t})),u.catch(()=>({}))}const I=new Intl.DateTimeFormat(L().replace("_","-"),{year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});function q(t){if(!t)return"";const n=new Date(t);return isNaN(n.getTime())?"":I.format(n)}function z(t,n={}){const e=document.createElement("div");e.className="crm-contacts-note-item";const c=n[t.noteTypeId]||t.noteType||{},o=document.createElement("span");o.className="crm-contacts-type-badge";const i=N(c.color);o.style.background=i,o.style.color=M(i);const p=$(c.icon);if(p){const r=document.createElement("span");r.className="crm-contacts-type-badge-icon",r.setAttribute("aria-hidden","true"),r.innerHTML=`<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${p}" /></svg>`,o.appendChild(r)}const l=document.createElement("span");l.textContent=c.name||"",o.appendChild(l);const d=document.createElement("div");d.className="crm-contacts-note-header",d.appendChild(o);const a=document.createElement("strong");if(a.textContent=t.title||"",d.appendChild(a),e.appendChild(d),t.content){const r=document.createElement("div");r.className="crm-contacts-note-content",r.innerHTML=E(t.content),e.appendChild(r)}const g=document.createElement("span");return g.className="crm-contacts-note-date",g.textContent=q(t.createdAt),e.appendChild(g),e}async function R(t){if(t.querySelector(".crm-contacts-notes-panel"))return;const n=_(t);if(!n)return;const e=document.createElement("div");e.className="crm-contacts-notes-panel";const c=s("crm_notes","Open in CRM Notes (opens in a new tab)"),o=`crm-contacts-notes-body-${Math.random().toString(36).slice(2,10)}`;if(e.innerHTML=`
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${o}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${h("chevronDown",18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${h("note",18)}</span>
				<span>${s("crm_notes","CRM Notes")}</span>
			</button>
			<a class="crm-contacts-open-app"
				href="${b("/apps/crm_notes")}#contact/${encodeURIComponent(n)}"
				title="${c}"
				aria-label="${c}"
				target="_blank"
				rel="noopener">${h("openInNew",14)}</a>
		</div>
		<div id="${o}" class="crm-contacts-notes-body">
			${w()}
		</div>
	`,t.appendChild(e),!document.getElementById("crm-contacts-integration-style")){const a=document.createElement("style");a.id="crm-contacts-integration-style",a.textContent=`
			.crm-contacts-notes-panel {
				margin: 12px 0;
				border-top: 1px solid var(--color-border, #ddd);
				padding-top: 8px;
			}
			.crm-contacts-notes-header {
				display: flex;
				align-items: center;
				gap: 8px;
				padding: 8px 16px;
			}
			.crm-contacts-notes-toggle {
				display: flex;
				align-items: center;
				gap: 8px;
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
				gap: 8px;
				padding: 8px 0;
				color: var(--color-text-maxcontrast, #888);
				font-size: 13px;
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
				font-size: 14px;
				text-decoration: none;
				color: var(--color-primary-element);
			}
			.crm-contacts-notes-body {
				padding: 4px 16px;
			}
			.crm-contacts-note-item {
				padding: 8px 0;
				border-bottom: 1px solid var(--color-border, #ddd);
				font-size: 13px;
			}
			.crm-contacts-note-item:last-child { border-bottom: none; }
			.crm-contacts-note-header {
				display: flex;
				align-items: center;
				gap: 6px;
				margin-bottom: 4px;
			}
			.crm-contacts-type-badge {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 1px 8px;
				border-radius: 8px;
				color: var(--color-main-text);
				font-size: 11px;
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
				margin: 2px 0 4px;
				line-height: 1.5;
				/* Break long unbroken strings (pasted URLs/tokens) so the note
				   body wraps inside the tab instead of overflowing horizontally. */
				overflow-wrap: anywhere;
			}
			.crm-contacts-note-content p { margin: 0 0 6px; }
			.crm-contacts-note-content p:last-child { margin-bottom: 0; }
			.crm-contacts-note-content ul,
			.crm-contacts-note-content ol { padding-left: 18px; margin: 0 0 6px; }
			.crm-contacts-note-content h4,
			.crm-contacts-note-content h5,
			.crm-contacts-note-content h6 {
				font-weight: 600;
				margin: 6px 0 2px;
				color: var(--color-main-text);
			}
			.crm-contacts-note-content code {
				font-family: var(--font-face-monospace, monospace);
				background: var(--color-background-dark);
				padding: 1px 4px;
				border-radius: 3px;
			}
			.crm-contacts-note-content pre {
				background: var(--color-background-dark);
				padding: 8px;
				border-radius: var(--border-radius);
				overflow-x: auto;
			}
			.crm-contacts-note-content a { color: var(--color-primary-element); }
			.crm-contacts-note-date {
				font-size: 11px;
				color: var(--color-text-maxcontrast, #999);
			}
			.crm-contacts-notes-empty {
				color: var(--color-text-maxcontrast, #888);
				font-size: 13px;
				padding: 8px 0;
			}
			.crm-contacts-notes-retry {
				display: inline-block;
				margin: 0 0 8px;
				padding: 4px 12px;
				border: 1px solid var(--color-border-dark, #ccc);
				border-radius: var(--border-radius, 4px);
				background: var(--color-main-background);
				color: var(--color-main-text);
				font: inherit;
				font-size: 13px;
				cursor: pointer;
			}
			.crm-contacts-notes-retry:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
			}
			.crm-contacts-notes-retry:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		`,document.head.appendChild(a)}const i=e.querySelector(".crm-contacts-notes-toggle"),p=e.querySelector(".crm-contacts-notes-body"),l=e.querySelector(".crm-contacts-notes-chevron");i.addEventListener("click",()=>{const a=i.getAttribute("aria-expanded")!=="false";i.setAttribute("aria-expanded",a?"false":"true"),p.style.display=a?"none":"",l&&l.classList.toggle("crm-contacts-notes-chevron--collapsed",a)});const d=e.querySelector(".crm-contacts-notes-body");C(d,n)}async function C(t,n){t.innerHTML=w();try{const[e,c]=await Promise.all([A(n),T()]);if(t.innerHTML="",e.length)e.forEach(o=>t.appendChild(z(o,c)));else{const o=document.createElement("p");o.className="crm-contacts-notes-empty",o.textContent=s("crm_notes","No notes yet"),t.appendChild(o)}}catch{t.innerHTML="";const e=document.createElement("p");e.className="crm-contacts-notes-empty",e.textContent=s("crm_notes","Could not load notes."),t.appendChild(e);const c=document.createElement("button");c.type="button",c.className="crm-contacts-notes-retry",c.textContent=s("crm_notes","Retry"),c.addEventListener("click",()=>C(t,n)),t.appendChild(c),k(s("crm_notes","Failed to load CRM notes."))}}let m=null;function H(){if(m&&m.isConnected&&m.querySelector(".crm-contacts-notes-panel"))return;const t=[".contact-details",".contact__details",'[class*="contact-detail"]',".app-content-detail"];for(const n of t){const e=document.querySelector(n);if(e){R(e),m=e;return}}m=null}let x=!1;function f(){x||(x=!0,requestAnimationFrame(()=>{x=!1,H()}))}const S=new MutationObserver(()=>{f()});document.addEventListener("DOMContentLoaded",()=>{S.observe(document.body,{childList:!0,subtree:!0}),H()}),window.addEventListener("hashchange",()=>{setTimeout(f,200)}),window.addEventListener("popstate",()=>{setTimeout(f,200)});
//# sourceMappingURL=crm_notes-contacts-integration.mjs.map
