(function(){
"use strict";
const S={prizes:[],draws:[],sample:[],count:0,drawing:false};
const sel=$("#prizeSel");

async function load(){
  try{
    const d=await api("admin_state");
    S.prizes=d.prizes.filter(p=>p.type==="sorteo"&&p.active);S.draws=d.draws;
    const keep=sel.value;
    sel.innerHTML=S.prizes.length?S.prizes.map(p=>{const left=p.stock-p.awarded;return '<option value="'+p.id+'"'+(left<=0?" disabled":"")+'>'+esc(p.name)+' — '+(left>0?"quedan "+left+" de "+p.stock:"sorteado")+'</option>'}).join(""):'<option value="">No hay premios de sorteo activos</option>';
    const firstFree=S.prizes.find(p=>p.stock>p.awarded);
    if(S.prizes.length&&!firstFree)sel.insertAdjacentHTML("afterbegin",'<option value="">Todos los premios ya fueron sorteados</option>');
    sel.value=S.prizes.some(p=>String(p.id)===keep&&p.stock>p.awarded)?keep:(firstFree?String(firstFree.id):"");
    if(!S.drawing)showPrize();renderHist();
  }catch(e){toast(e.error||"No se pudieron cargar los premios.")}
}
function current(){return S.prizes.find(p=>String(p.id)===sel.value)}
function showPrize(){const p=current();$("#prizeName").textContent=p?p.name:"No hay premios disponibles para sortear"}
async function updatePool(){
  try{const r=await api("draw_info",null,"exWin="+($("#exWin").checked?1:"")+"&exDraw="+($("#exDraw").checked?1:""));S.count=r.count;S.sample=r.sample;$("#poolN")&&($("#poolN").textContent=r.count)}
  catch(e){toast(e.error||"No se pudo calcular el padrón.")}
}
function renderHist(){
  const D=S.draws.slice(0,12);
  $("#histList").innerHTML=D.length?D.map(d=>'<div class="hist-row"><div><b>'+esc(d.winner_name)+'</b><div class="meta">'+esc(d.empresa)+' · '+esc(d.prize_name)+' · '+fmtDate(d.at)+' · entre '+d.pool+' participantes</div></div><span class="pill '+(d.status==="anulado"?"void":"win")+'">'+(d.status==="anulado"?"Anulado":"Válido")+'</span><span></span></div>').join(""):'<p class="sub" style="margin:0">Todavía no se realizó ningún sorteo.</p>';
}
function resetTicker(){
  $("#ticker").classList.remove("done");
  $("#ticker").innerHTML='<div><div class="name" id="tkName">Petersen Industria</div><div class="co" id="tkCo"><span id="poolN">'+S.count+'</span> participantes en el sorteo</div></div>';
}

sel.addEventListener("change",()=>{if(S.drawing)return;showPrize();resetTicker()});
$("#exWin").addEventListener("change",async()=>{await updatePool();resetTicker()});
$("#exDraw").addEventListener("change",async()=>{await updatePool();resetTicker()});
$("#fsBtn").onclick=()=>{const st=$("#stage");(st.requestFullscreen?st.requestFullscreen():Promise.reject()).catch(()=>toast("La pantalla completa no está disponible en este navegador."))};

$("#drawBtn").addEventListener("click",async()=>{
  const p=current();
  if(S.drawing)return;
  if(!p||p.stock<=p.awarded){toast("Elegí un premio con unidades disponibles.");return}
  if(!S.count){toast("No hay participantes para sortear.");return}
  S.drawing=true;showPrize();const btn=$("#drawBtn");btn.disabled=true;sel.disabled=$("#exWin").disabled=$("#exDraw").disabled=true;
  let res;
  try{res=await api("draw",{prize_id:p.id,exWin:$("#exWin").checked,exDraw:$("#exDraw").checked})}
  catch(e){toast(e.error||"No se pudo realizar el sorteo.");S.drawing=false;btn.disabled=false;sel.disabled=$("#exWin").disabled=$("#exDraw").disabled=false;return}
  resetTicker();
  const tk=$("#ticker"),names=S.sample.length?S.sample:[res.winner];
  const dur=RM?600:4200,t0=performance.now();
  await new Promise(done=>{(function f(){const el=performance.now()-t0;if(el>=dur)return done();const r=names[Math.random()*names.length|0];$("#tkName").textContent=r.nombre;$("#tkCo").textContent=r.empresa;setTimeout(f,40+Math.pow(el/dur,3)*320)})()});
  $("#tkName").textContent=res.winner.nombre;$("#tkCo").textContent=[res.winner.empresa,res.winner.cargo].filter(Boolean).join(" · ");tk.classList.add("done");confetti();
  await load();await updatePool();
  setTimeout(()=>{S.drawing=false;btn.disabled=false;btn.textContent="Sortear de nuevo";sel.disabled=$("#exWin").disabled=$("#exDraw").disabled=false},1200);
});

load().then(updatePool);
})();
