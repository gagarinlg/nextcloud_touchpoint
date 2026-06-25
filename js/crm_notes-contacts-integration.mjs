import{g as b,a as H,t as s,s as k,c as y}from"./index-CwIPLMrY.chunk.mjs";import{r as M,i as L,a as $}from"./markdown-DbYEM_2Q.chunk.mjs";const v=b("/apps/crm_notes/api"),E={note:"M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z",openInNew:"M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z",file:"M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z"};function h(t,n=16){return`<svg viewBox="0 0 24 24" width="${n}" height="${n}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${E[t]}" /></svg>`}function V(t){if(typeof t!="string")return"var(--color-text-maxcontrast)";const n=t.trim();return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(n)||/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(n)?n:"var(--color-text-maxcontrast)"}function N(t){const n=t.dataset?.contactUid||t.closest("[data-contact-uid]")?.dataset?.contactUid;if(n)return n;const e=window.location.hash.match(/contact:([^/]+)/)||window.location.pathname.match(/contact:([^/]+)/);return e?decodeURIComponent(e[1]):null}async function _(t){const{data:n}=await y.get(`${v}/notes/contact/${encodeURIComponent(t)}`);return n}let p=null;async function A(){return p||(p=y.get(`${v}/note-types`).then(({data:t})=>{const n={};for(const e of t)n[e.id]={name:e.name,color:e.color,icon:e.icon};return n}).catch(t=>{throw p=null,t})),p.catch(()=>({}))}const T=new Intl.DateTimeFormat(H().replace("_","-"),{year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});function I(t){if(!t)return"";const n=new Date(t);return isNaN(n.getTime())?"":T.format(n)}function R(t,n={}){const e=document.createElement("div");e.className="crm-contacts-note-item";const c=n[t.noteTypeId]||t.noteType||{},o=document.createElement("span");o.className="crm-contacts-type-badge";const i=V(c.color);o.style.background=i,o.style.color=M(i);const l=L(c.icon);if(l){const r=document.createElement("span");r.className="crm-contacts-type-badge-icon",r.setAttribute("aria-hidden","true"),r.innerHTML=`<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${l}" /></svg>`,o.appendChild(r)}const m=document.createElement("span");m.textContent=c.name||"",o.appendChild(m);const a=document.createElement("div");a.className="crm-contacts-note-header",a.appendChild(o);const x=document.createElement("strong");if(x.textContent=t.title||"",a.appendChild(x),e.appendChild(a),t.content){const r=document.createElement("div");r.className="crm-contacts-note-content",r.innerHTML=$(t.content),e.appendChild(r)}const u=document.createElement("span");return u.className="crm-contacts-note-date",u.textContent=I(t.createdAt),e.appendChild(u),e}async function q(t){if(t.querySelector(".crm-contacts-notes-panel"))return;const n=N(t);if(!n)return;const e=document.createElement("div");e.className="crm-contacts-notes-panel";const c=s("crm_notes","Open in CRM Notes (opens in a new tab)"),o=`crm-contacts-notes-body-${Math.random().toString(36).slice(2,10)}`;if(e.innerHTML=`
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${o}">
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
			<div class="crm-contacts-notes-loading">${s("crm_notes","Loading…")}</div>
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
				color: var(--color-text-maxcontrast, #666);
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
		`,document.head.appendChild(a)}const i=e.querySelector(".crm-contacts-notes-toggle"),l=e.querySelector(".crm-contacts-notes-body");i.addEventListener("click",()=>{const a=i.getAttribute("aria-expanded")!=="false";i.setAttribute("aria-expanded",a?"false":"true"),l.style.display=a?"none":""});const m=e.querySelector(".crm-contacts-notes-body");C(m,n)}async function C(t,n){t.innerHTML=`<div class="crm-contacts-notes-loading">${s("crm_notes","Loading…")}</div>`;try{const[e,c]=await Promise.all([_(n),A()]);if(t.innerHTML="",e.length)e.forEach(o=>t.appendChild(R(o,c)));else{const o=document.createElement("p");o.className="crm-contacts-notes-empty",o.textContent=s("crm_notes","No notes yet"),t.appendChild(o)}}catch{t.innerHTML="";const e=document.createElement("p");e.className="crm-contacts-notes-empty",e.textContent=s("crm_notes","Could not load notes."),t.appendChild(e);const c=document.createElement("button");c.type="button",c.className="crm-contacts-notes-retry",c.textContent=s("crm_notes","Retry"),c.addEventListener("click",()=>C(t,n)),t.appendChild(c),k(s("crm_notes","Failed to load CRM notes."))}}let d=null;function w(){if(d&&d.isConnected&&d.querySelector(".crm-contacts-notes-panel"))return;const t=[".contact-details",".contact__details",'[class*="contact-detail"]',".app-content-detail"];for(const n of t){const e=document.querySelector(n);if(e){q(e),d=e;return}}d=null}let g=!1;function f(){g||(g=!0,requestAnimationFrame(()=>{g=!1,w()}))}const S=new MutationObserver(()=>{f()});document.addEventListener("DOMContentLoaded",()=>{S.observe(document.body,{childList:!0,subtree:!0}),w()}),window.addEventListener("hashchange",()=>{setTimeout(f,200)}),window.addEventListener("popstate",()=>{setTimeout(f,200)});
//# sourceMappingURL=crm_notes-contacts-integration.mjs.map
