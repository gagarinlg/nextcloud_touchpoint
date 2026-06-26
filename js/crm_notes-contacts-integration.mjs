import{g as N,a as I,t as r,s as C,c as k,b as U}from"./NcNoteCard-DZSuYX4--CIiEIm0E.chunk.mjs";import{r as R,i as P,a as B}from"./markdown-BGv0WaE3.chunk.mjs";const V=N("/apps/crm_notes/api"),F={note:"M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z",openInNew:"M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z",file:"M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z",pin:"M16,12V4H17V2H7V4H8V12L6,14V16H11.2V22H12.8V16H18V14L16,12Z",chevronDown:"M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z",plus:"M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"};function b(t,n=16){return`<svg viewBox="0 0 24 24" width="${n}" height="${n}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${F[t]}" /></svg>`}function M(){return`<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${r("crm_notes","Loading…")}</span>
	</span>`}function D(t){if(typeof t!="string")return"var(--color-text-maxcontrast)";const n=t.trim();return/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(n)||/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(n)?n:"var(--color-text-maxcontrast)"}function Z(t){try{const n=decodeURIComponent(t);try{return atob(n)}catch{return atob(n.replace(/-/g,"+").replace(/_/g,"/"))}}catch{return null}}function S(t){const n=t.dataset?.contactUid||t.closest("[data-contact-uid]")?.dataset?.contactUid;if(n)return n;const a=window.location.pathname.split("/").filter(Boolean).pop();if(a){const e=Z(a);if(e&&e.includes("~"))return e.substring(0,e.lastIndexOf("~"))}const o=window.location.hash.match(/contact:([^/]+)/)||window.location.pathname.match(/contact:([^/]+)/);return o?decodeURIComponent(o[1]):null}const x=50;async function A(t,n=x,a=0){const{data:o}=await k.get(`${V}/notes/contact/${encodeURIComponent(t)}`,{params:{limit:n,offset:a}});return o}let w=null;async function z(){return w||(w=k.get(`${V}/note-types`).then(({data:t})=>{const n={};for(const a of t)n[a.id]={name:a.name,color:a.color,icon:a.icon};return n}).catch(t=>{throw w=null,t})),w.catch(()=>({}))}const O=new Intl.DateTimeFormat(I().replace("_","-"),{year:"numeric",month:"short",day:"numeric",hour:"2-digit",minute:"2-digit"});function j(t){if(!t)return"";const n=new Date(t);return isNaN(n.getTime())?"":O.format(n)}function W(t){return t.name?t.name:t.filePath?t.filePath.split("/").pop():r("crm_notes","Attachment")}function E(t,n={}){const a=document.createElement("div");a.className="crm-contacts-note-item";const o=n[t.noteTypeId]||t.noteType||{},e=document.createElement("span");e.className="crm-contacts-type-badge";const l=D(o.color);e.style.background=l,e.style.color=R(l);const s=P(o.icon);if(s){const c=document.createElement("span");c.className="crm-contacts-type-badge-icon",c.setAttribute("aria-hidden","true"),c.innerHTML=`<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${s}" /></svg>`,e.appendChild(c)}const m=document.createElement("span");m.textContent=o.name||"",e.appendChild(m);const u=document.createElement("div");u.className="crm-contacts-note-header",u.appendChild(e);const d=document.createElement("h2");if(d.className="crm-contacts-note-title",d.textContent=t.title||"",u.appendChild(d),t.isPinned){const c=document.createElement("span");c.className="crm-contacts-pin-indicator",c.setAttribute("role","img"),c.setAttribute("aria-label",r("crm_notes","Pinned")),c.innerHTML=b("pin",16),u.appendChild(c)}if(a.appendChild(u),t.content){const c=document.createElement("div");c.className="crm-contacts-note-content",c.innerHTML=B(t.content),a.appendChild(c)}if(Array.isArray(t.files)&&t.files.length){const c=document.createElement("div");c.className="crm-contacts-note-files";for(const y of t.files){const i=document.createElement("span");i.className="crm-contacts-file-chip";const f=document.createElement("span");f.className="crm-contacts-file-chip-icon",f.innerHTML=b("file",12);const g=document.createElement("span");g.className="crm-contacts-file-chip-label",g.textContent=W(y),i.appendChild(f),i.appendChild(g),c.appendChild(i)}a.appendChild(c)}const p=document.createElement("span");return p.className="crm-contacts-note-date",p.textContent=j(t.createdAt),a.appendChild(p),a}async function G(t){const n=S(t);if(!n)return;const a=t.querySelector(".crm-contacts-notes-panel");if(a){if(a.dataset.crmContactUid===n)return;a.remove()}const o=document.createElement("div");o.className="crm-contacts-notes-panel",o.dataset.crmContactUid=n;const e=r("crm_notes","Open in CRM Notes (opens in a new tab)"),l=r("crm_notes","Add note"),s=`crm-contacts-notes-body-${Math.random().toString(36).slice(2,10)}`;if(o.innerHTML=`
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${s}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${b("chevronDown",18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${b("note",18)}</span>
				<span>${r("crm_notes","CRM Notes")}</span>
			</button>
			<button type="button" class="crm-contacts-notes-add" title="${l}" aria-label="${l}" aria-expanded="false">${b("plus",16)}</button>
			<a class="crm-contacts-open-app"
				href="${N("/apps/crm_notes")}#contact/${encodeURIComponent(n)}"
				title="${e}"
				aria-label="${e}"
				target="_blank"
				rel="noopener">${b("openInNew",14)}</a>
		</div>
		<form class="crm-contacts-notes-addform" hidden>
			<input type="text" class="crm-contacts-addform-title" maxlength="255" placeholder="${r("crm_notes","Title")}" />
			<select class="crm-contacts-addform-type" aria-label="${r("crm_notes","Note type")}"></select>
			<textarea class="crm-contacts-addform-content" rows="3" placeholder="${r("crm_notes","Write a note…")}"></textarea>
			<div class="crm-contacts-addform-actions">
				<button type="button" class="crm-contacts-addform-cancel">${r("crm_notes","Cancel")}</button>
				<button type="submit" class="crm-contacts-addform-save">${r("crm_notes","Save")}</button>
			</div>
		</form>
		<div id="${s}" class="crm-contacts-notes-body">
			${M()}
		</div>
	`,t.appendChild(o),!document.getElementById("crm-contacts-integration-style")){const c=document.createElement("style");c.id="crm-contacts-integration-style",c.textContent=`
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
			.crm-contacts-notes-addform {
				display: flex;
				flex-direction: column;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				padding: 0 calc(var(--default-grid-baseline, 4px) * 4) calc(var(--default-grid-baseline, 4px) * 2);
			}
			.crm-contacts-addform-title,
			.crm-contacts-addform-type,
			.crm-contacts-addform-content {
				width: 100%;
				box-sizing: border-box;
			}
			.crm-contacts-addform-content {
				resize: vertical;
				min-height: 56px;
			}
			.crm-contacts-addform-actions {
				display: flex;
				justify-content: flex-end;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
			}
		`,document.head.appendChild(c)}const m=o.querySelector(".crm-contacts-notes-toggle"),u=o.querySelector(".crm-contacts-notes-body"),d=o.querySelector(".crm-contacts-notes-chevron");m.addEventListener("click",()=>{const c=m.getAttribute("aria-expanded")!=="false";m.setAttribute("aria-expanded",c?"false":"true"),u.style.display=c?"none":"",d&&d.classList.toggle("crm-contacts-notes-chevron--collapsed",c)});const p=o.querySelector(".crm-contacts-notes-body");J(o,p,n),q(p,n)}function J(t,n,a){const o=t.querySelector(".crm-contacts-notes-add"),e=t.querySelector(".crm-contacts-notes-addform");if(!o||!e)return;const l=e.querySelector(".crm-contacts-addform-title"),s=e.querySelector(".crm-contacts-addform-type"),m=e.querySelector(".crm-contacts-addform-content"),u=e.querySelector(".crm-contacts-addform-cancel"),d=e.querySelector(".crm-contacts-addform-save"),p=t.querySelector(".crm-contacts-notes-toggle");let c={};z().then(i=>{c=i,s.innerHTML="";for(const[f,g]of Object.entries(i)){const v=document.createElement("option");v.value=f,v.textContent=g.name,s.appendChild(v)}});function y(){e.hidden=!0,o.setAttribute("aria-expanded","false"),e.reset()}o.addEventListener("click",()=>{const i=e.hidden;e.hidden=!i,o.setAttribute("aria-expanded",i?"true":"false"),i&&(p&&p.getAttribute("aria-expanded")==="false"&&p.click(),l.focus())}),u.addEventListener("click",y),e.addEventListener("submit",async i=>{i.preventDefault();const f=l.value.trim();if(!f){l.focus();return}if(!s.value){C(r("crm_notes","Please pick a note type first."));return}d.disabled=!0;const g=d.textContent;d.textContent=r("crm_notes","Saving…");try{const{data:v}=await k.post(`${V}/notes`,{contactUid:a,noteTypeId:Number(s.value),title:f,content:m.value||null}),_=n.querySelector(".crm-contacts-notes-empty");_&&_.remove(),n.insertBefore(E(v,c),n.firstChild),y(),U(r("crm_notes","Note added."))}catch{C(r("crm_notes","Failed to add note."))}finally{d.disabled=!1,d.textContent=g}})}async function q(t,n){t.innerHTML=M();try{const[a,o]=await Promise.all([A(n,x,0),z()]);if(t.innerHTML="",a.length)a.forEach(e=>t.appendChild(E(e,o))),a.length===x&&K(t,n,o,a.length);else{const e=document.createElement("p");e.className="crm-contacts-notes-empty",e.textContent=r("crm_notes","No notes yet"),t.appendChild(e)}}catch{t.innerHTML="";const a=document.createElement("p");a.className="crm-contacts-notes-empty",a.textContent=r("crm_notes","Could not load notes."),t.appendChild(a);const o=document.createElement("button");o.type="button",o.className="crm-contacts-notes-retry",o.textContent=r("crm_notes","Retry"),o.addEventListener("click",()=>q(t,n)),t.appendChild(o),C(r("crm_notes","Failed to load CRM notes."))}}function K(t,n,a,o){const e=document.createElement("button");e.type="button",e.className="crm-contacts-notes-retry",e.textContent=r("crm_notes","Show more");let l=o;e.addEventListener("click",async()=>{e.disabled=!0,e.textContent=r("crm_notes","Loading…");try{const s=await A(n,x,l);s.forEach(m=>t.insertBefore(E(m,a),e)),l+=s.length,s.length===x?(e.disabled=!1,e.textContent=r("crm_notes","Show more")):e.remove()}catch{e.disabled=!1,e.textContent=r("crm_notes","Show more"),C(r("crm_notes","Failed to load more notes."))}}),t.appendChild(e)}let h=null;function T(){if(h&&h.isConnected){const n=h.querySelector(".crm-contacts-notes-panel");if(n&&n.dataset.crmContactUid===S(h))return}const t=[".contact-details-wrapper",".contact-details",".contact__details",'[class*="contact-detail"]',".app-content-detail"];for(const n of t){const a=document.querySelector(n);if(a){G(a),h=a;return}}h=null}let H=!1;function L(){H||(H=!0,requestAnimationFrame(()=>{H=!1,T()}))}const Q=new MutationObserver(()=>{L()});function $(){Q.observe(document.body,{childList:!0,subtree:!0}),T()}document.readyState==="loading"?document.addEventListener("DOMContentLoaded",$):$(),window.addEventListener("hashchange",()=>{setTimeout(L,200)}),window.addEventListener("popstate",()=>{setTimeout(L,200)});
//# sourceMappingURL=crm_notes-contacts-integration.mjs.map
