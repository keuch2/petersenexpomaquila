"use strict";
const $=s=>document.querySelector(s);
const esc=v=>String(v??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const RM=matchMedia("(prefers-reduced-motion: reduce)").matches;
const fmtDate=t=>t?new Date(t).toLocaleString("es-PY",{day:"2-digit",month:"2-digit",hour:"2-digit",minute:"2-digit"}):"";
const META=n=>document.querySelector('meta[name="'+n+'"]')?.content||"";
const BASE=META("app-base")||"/";
const media=p=>!p?"":/^(https?:|data:)/.test(p)?p:BASE+p;

function toast(t){const el=$("#toast");el.textContent=t;el.hidden=false;clearTimeout(toast._t);toast._t=setTimeout(()=>el.hidden=true,3200)}

/* Llama a api.php. data: objeto (JSON) o FormData. Lanza {error,status,fields} si falla. */
async function api(action,data,qs){
  const o={method:data?"POST":"GET",headers:{"X-CSRF":META("csrf")},credentials:"same-origin"};
  if(data instanceof FormData)o.body=data;
  else if(data){o.headers["Content-Type"]="application/json";o.body=JSON.stringify(data)}
  let r,j;
  try{r=await fetch(BASE+"api.php?action="+action+(qs?"&"+qs:""),o)}catch(e){throw {error:"Sin conexión. Revisá la red e intentá de nuevo."}}
  try{j=await r.json()}catch(e){j={error:"Respuesta inválida del servidor."}}
  if(!r.ok||j.error){j.status=r.status;if(r.status===401&&META("admin")){location.href=BASE+"admin/login.php"}throw j}
  return j;
}

function confetti(){
  if(RM)return;const cv=$("#confetti");cv.hidden=false;const x=cv.getContext("2d");cv.width=innerWidth;cv.height=innerHeight;
  const cols=["#f3b80a","#ffffff","#62a8ea","#004479"],P=Array.from({length:160},()=>({x:innerWidth/2+(Math.random()-.5)*200,y:innerHeight*.35,vx:(Math.random()-.5)*14,vy:-Math.random()*14-4,s:4+Math.random()*6,c:cols[Math.random()*4|0],r:Math.random()*6}));
  const t0=performance.now();(function f(now){x.clearRect(0,0,cv.width,cv.height);P.forEach(p=>{p.vy+=.35;p.x+=p.vx;p.y+=p.vy;p.r+=.2;x.save();x.translate(p.x,p.y);x.rotate(p.r);x.fillStyle=p.c;x.fillRect(-p.s/2,-p.s/4,p.s,p.s/2);x.restore()});now-t0<2800?requestAnimationFrame(f):(cv.hidden=true)})(t0);
}

/* Botón de confirmación en dos toques, como en el demo. Devuelve true en el segundo toque. */
function confirmTap(btn,label){
  if(btn.dataset.confirm==="1")return true;
  const orig=btn.textContent;btn.dataset.confirm="1";btn.textContent=label||"¿Confirmar?";btn.style.color="var(--bad)";
  setTimeout(()=>{if(btn.isConnected){btn.dataset.confirm="";btn.textContent=orig;btn.style.color=""}},3000);
  return false;
}
