(function(){
"use strict";
const BOOT=JSON.parse($("#boot").textContent);
const S={prizes:BOOT.prizes||[],finalPrize:BOOT.finalPrize||"Premio final",current:null,spinning:false};
const kiosk=()=>location.hash==="#kiosco";

/* ---------- Navegación ---------- */
document.addEventListener("click",e=>{
  const go=e.target.closest("[data-go]");
  if(go){e.preventDefault();const t=document.getElementById(go.dataset.go);t&&t.scrollIntoView({behavior:RM?"auto":"smooth"})}
  const cl=e.target.closest("[data-close]");if(cl){document.getElementById(cl.dataset.close).hidden=true}
});
$("#cta3d")?.addEventListener("click",e=>{e.preventDefault();$("#ov3d").hidden=false});
document.addEventListener("keydown",e=>{if(e.key==="Escape"){$("#ov3d").hidden=true}});
const applyKiosk=()=>document.body.classList.toggle("kiosk",kiosk());
window.addEventListener("hashchange",applyKiosk);applyKiosk();

/* ---------- Registro ---------- */
$("#regForm").addEventListener("submit",async e=>{
  e.preventDefault();
  const f=e.target,msg=$("#regMsg");msg.className="msg";msg.textContent="";
  f.querySelectorAll(".field.err").forEach(x=>x.classList.remove("err"));
  const v=n=>f.elements[n].value.trim();
  const d={nombre:v("nombre"),empresa:v("empresa"),cargo:v("cargo"),telefono:v("telefono"),email:v("email").toLowerCase(),rubro:f.elements.rubro.value,
    desafio:[...f.querySelectorAll('input[name="desafio"]:checked')].map(i=>i.value),consent:$("#f_ok").checked,device:kiosk()?"kiosco":"web"};
  const bad=[];
  if(d.nombre.length<3)bad.push("nombre");
  if(!d.empresa)bad.push("empresa");
  if(!d.cargo)bad.push("cargo");
  if(d.telefono.replace(/\D/g,"").length<7)bad.push("telefono");
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email))bad.push("email");
  if(!d.rubro)bad.push("rubro");
  const markBad=list=>{list.forEach(n=>$("#f_"+n)?.closest(".field").classList.add("err"));msg.className="msg bad";msg.textContent="Revisá los campos marcados en rojo.";$("#f_"+list[0])?.focus()};
  if(bad.length)return markBad(bad);
  if(!d.consent){msg.className="msg bad";msg.textContent="Para participar tenés que aceptar las bases y condiciones.";return}
  const btn=$("#regBtn");btn.disabled=true;btn.textContent="Registrando…";
  try{
    const r=await api("register",d);
    S.current={token:r.token,nombre:r.nombre};
    try{S.prizes=(await api("state")).prizes}catch(err){}
    f.reset();openGame();
  }catch(err){
    if(err.fields)markBad(err.fields);
    msg.className="msg bad";msg.textContent=err.error||"No pudimos guardar el registro. Revisá la conexión e intentá de nuevo.";
  }finally{btn.disabled=false;btn.textContent="Registrarme y jugar"}
});

/* ---------- Ruleta ---------- */
let segs=[],rot=0;
function buildSegs(){
  const avail=S.prizes.slice(0,8);
  const loseN=Math.max(3,avail.length);segs=[];
  for(let i=0;i<Math.max(avail.length,loseN);i++){if(avail[i])segs.push({type:"win",prize:avail[i]});if(i<loseN)segs.push({type:"lose"})}
  while(segs.length<6)segs.push({type:"lose"});
}
function drawWheel(){
  const cv=$("#wheel"),box=cv.parentElement,sz=box.clientWidth||400,dpr=Math.min(2,devicePixelRatio||1);
  if(cv.width!==Math.round(sz*dpr)){cv.width=cv.height=Math.round(sz*dpr)}
  const x=cv.getContext("2d");x.setTransform(dpr,0,0,dpr,0,0);x.clearRect(0,0,sz,sz);
  const r=sz/2,n=segs.length,a=2*Math.PI/n;
  x.save();x.translate(r,r);
  x.beginPath();x.arc(0,0,r-2,0,2*Math.PI);x.fillStyle="#ffffff";x.fill();
  x.rotate(rot);
  let wi=0;
  segs.forEach((s,i)=>{
    const fill=s.type==="lose"?(i%4===1?"#dfe6ee":"#eef2f6"):(wi++%2===0?"#004479":"#f3b80a");
    x.beginPath();x.moveTo(0,0);x.arc(0,0,r-10,i*a,(i+1)*a);x.closePath();x.fillStyle=fill;x.fill();
    x.strokeStyle="rgba(255,255,255,.9)";x.lineWidth=2;x.stroke();
    x.save();x.rotate((i+.5)*a);x.textAlign="right";x.textBaseline="middle";
    x.fillStyle=s.type==="lose"?"#53647a":(fill==="#004479"?"#ffffff":"#1d1600");
    const fs=Math.max(11,Math.min(17,sz/28));x.font="800 "+fs+"px Archivo, 'Arial Narrow', sans-serif";
    const lines=wrapText(s.type==="lose"?"Seguí participando":s.prize.name,16),lh=fs*1.08;
    lines.forEach((ln,k)=>x.fillText(ln.toUpperCase(),r-24,(k-(lines.length-1)/2)*lh));
    x.restore();
  });
  x.restore();
  x.beginPath();x.arc(r,r,r-5,0,2*Math.PI);x.lineWidth=8;x.strokeStyle="#004479";x.stroke();
  for(let i=0;i<n;i++){const ang=rot+i*a;x.beginPath();x.arc(r+Math.cos(ang)*(r-5),r+Math.sin(ang)*(r-5),3.2,0,2*Math.PI);x.fillStyle="#f3b80a";x.fill()}
}
function wrapText(t,max){const w=String(t).split(/\s+/),out=[];let cur="";w.forEach(p=>{if((cur+" "+p).trim().length>max&&cur){out.push(cur);cur=p}else cur=(cur+" "+p).trim()});if(cur)out.push(cur);return out.slice(0,2)}
function openGame(){
  buildSegs();rot=-Math.PI/2-Math.PI/segs.length;
  $("#gameHello").textContent="Hola, "+(S.current.nombre||"").split(" ")[0];
  $("#gameSide").hidden=false;$("#gameResult").hidden=true;$("#spinBtn").disabled=false;
  $("#gameOv").hidden=false;requestAnimationFrame(drawWheel);$("#spinBtn").focus();
}
window.addEventListener("resize",()=>{if(!$("#gameOv").hidden)drawWheel()});

$("#spinBtn").addEventListener("click",async()=>{
  if(S.spinning||!S.current)return;S.spinning=true;const sb=$("#spinBtn");sb.disabled=true;sb.textContent="Girando…";
  let res;
  try{res=await api("spin",{token:S.current.token})}
  catch(e){
    S.spinning=false;sb.textContent="Girar la ruleta";
    if(e.status===409||e.status===404){toast(e.error);setTimeout(closeGame,2500)}else{sb.disabled=false;toast(e.error||"No se pudo registrar el giro. Intentá de nuevo.")}
    return;
  }
  S.current.code=res.code;
  let idx=segs.map((s,i)=>i).filter(i=>res.result==="win"?(segs[i].type==="win"&&segs[i].prize.id===res.prize.id):segs[i].type==="lose");
  if(!idx.length){segs.push(res.result==="win"?{type:"win",prize:res.prize}:{type:"lose"});idx=[segs.length-1]}
  const n=segs.length,a=2*Math.PI/n,i=idx[Math.floor(Math.random()*idx.length)];
  const target=-Math.PI/2-(i+.5)*a+(Math.random()-.5)*a*.6;
  const start=rot,tau=2*Math.PI,delta=((target-start)%tau+tau)%tau+tau*(RM?1:6),dur=RM?900:5600,t0=performance.now();
  const ease=t=>1-Math.pow(1-t,4);
  await new Promise(done=>{(function f(now){const t=Math.min(1,(now-t0)/dur);rot=start+delta*ease(t);drawWheel();t<1?requestAnimationFrame(f):done()})(t0)});
  showResult(res);S.spinning=false;sb.textContent="Girar la ruleta";
});
let resetTimer=null;
function showResult(res){
  const box=$("#gameResult"),fp=S.finalPrize;
  box.className="who result "+(res.result==="win"?"win":"lose");
  box.innerHTML=res.result==="win"
    ?'<div class="eyebrow" style="color:#9cc4ea">Premio instantáneo</div><div class="big">¡Ganaste!</div><div class="prize">'+esc(res.prize.name)+'</div><div class="code">'+esc(res.code)+'</div><small>Presentá este código en el mostrador del stand para retirar tu premio. Además seguís participando del sorteo final: '+esc(fp)+'.</small>'
    :'<div class="eyebrow" style="color:#9cc4ea">Resultado</div><div class="big">Esta vez no tocó</div><div class="prize">Ya estás en el sorteo final</div><small>Tu registro participa del sorteo de '+esc(fp)+'. Gracias por visitar a Petersen Industria.</small>';
  const b=document.createElement("button");b.className="btn btn-accent";b.style.marginTop="10px";b.style.width="max-content";
  b.textContent=kiosk()?"Siguiente participante":"Listo";b.onclick=closeGame;box.append(b);
  if(kiosk()){let s=25;const sm=document.createElement("small");box.append(sm);const tick=()=>{sm.textContent="La pantalla se reinicia en "+s+" s";if(s--<=0)closeGame()};tick();clearInterval(resetTimer);resetTimer=setInterval(tick,1000)}
  $("#gameSide").hidden=true;box.hidden=false;b.focus();
  if(res.result==="win")confetti();
}
function closeGame(){clearInterval(resetTimer);$("#gameOv").hidden=true;S.current=null;if(kiosk()){scrollTo(0,0)}else{$("#registro").scrollIntoView()}}
})();
