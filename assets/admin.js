(function(){
"use strict";
const TABS=["resumen","participantes","ganadores","sorteos","premios","admins","contenido"];
const editTabs=["premios","admins","contenido"];
const S={rev:"",d:null,tab:TABS.includes(location.hash.slice(1))?location.hash.slice(1):"resumen"};
const pct=n=>(+n||0).toLocaleString("es-PY",{maximumFractionDigits:1});

/* ---------- Datos ---------- */
async function load(force){
  try{
    const j=await api("admin_state",null,force?"":"rev="+encodeURIComponent(S.rev));
    if(j.same)return;S.d=j;S.rev=j.rev;renderTab(!!force);
  }catch(e){if(e.status!==401)toast(e.error||"No se pudo actualizar el panel.")}
}
async function act(action,data,okMsg){
  try{const r=await api(action,data);if(okMsg)toast(okMsg);document.activeElement?.blur?.();await load(true);return r}
  catch(e){toast(e.error||"No se pudo guardar.");return null}
}
setInterval(()=>{if(!document.hidden)load()},5000);

/* ---------- Pestañas ---------- */
function selectTab(t){
  S.tab=t;history.replaceState(null,"","#"+t);
  document.querySelectorAll("#tabs button").forEach(b=>b.setAttribute("aria-selected",b.dataset.tab===t));
  renderTab(true);
}
$("#tabs").addEventListener("click",e=>{const b=e.target.closest("[data-tab]");if(b)selectTab(b.dataset.tab)});
function renderTab(force){
  if(!S.d)return;const b=$("#admBody");
  if(!force&&editTabs.includes(S.tab)&&b.contains(document.activeElement)&&document.activeElement!==document.body)return;
  ({resumen:tResumen,participantes:tPart,ganadores:tWin,sorteos:tDraws,premios:tPrem,admins:tAdmins,contenido:tCont})[S.tab](b);
}
selectTab(S.tab);load(true);

$("#logoutBtn").addEventListener("click",async()=>{try{await api("logout",{})}catch(e){}location.href=BASE+"admin/login.php"});
document.addEventListener("click",e=>{const cl=e.target.closest("[data-close]");if(cl)document.getElementById(cl.dataset.close).hidden=true});
document.addEventListener("keydown",e=>{if(e.key==="Escape")$("#editOv").hidden=true});

/* ---------- Resumen ---------- */
const ruleta=()=>S.d.prizes.filter(p=>p.type==="ruleta");
const sorteo=()=>S.d.prizes.filter(p=>p.type==="sorteo");
function stats(){
  const P=S.d.participants;
  return {reg:P.length,spun:P.filter(p=>p.spun).length,win:P.filter(p=>p.result==="win").length,deliv:P.filter(p=>p.result==="win"&&p.delivered).length,
    rem:ruleta().reduce((a,p)=>a+Math.max(0,p.stock-p.awarded),0),draws:S.d.draws.filter(d=>d.status==="valido").length};
}
function stockRows(list){
  return list.length?list.map(p=>{const pc=p.stock?Math.min(100,p.awarded/p.stock*100):0;
    return '<div class="stock-row"><span>'+esc(p.name)+(p.active?'':' <span class="pill lose">inactivo</span>')+'</span><span class="num">'+p.awarded+' / '+p.stock+'</span><div class="bar"><i class="'+(p.awarded>=p.stock?"out":"")+'" style="width:'+pc+'%"></i></div></div>'}).join(""):'<p class="sub">Todavía no cargaste premios.</p>';
}
function resPill(p){return !p.spun?'<span class="pill pend">Sin girar</span>':p.result==="win"?'<span class="pill win">'+esc(p.prize_name)+'</span>':'<span class="pill lose">Sin premio</span>'}
function tResumen(b){
  const s=stats();
  b.innerHTML='<div class="kpis">'+[["Registrados",s.reg],["Giraron la ruleta",s.spun],["Premios ruleta ganados",s.win],["Premios ruleta entregados",s.deliv],["Stock ruleta restante",s.rem],["Ganadores sorteo",s.draws]].map(([k,v])=>'<div class="kpi"><small>'+k+'</small><b>'+v+'</b></div>').join("")+'</div>'+
  '<div class="grid2"><div><div class="card"><h3>Stock ruleta</h3><div class="stock">'+stockRows(ruleta())+'</div></div><div class="card"><h3>Premios del sorteo final</h3><div class="stock">'+stockRows(sorteo())+'</div></div></div>'+
  '<div class="card"><h3>Últimos registros</h3>'+(S.d.participants.length?'<div class="hist">'+S.d.participants.slice(0,10).map(p=>'<div class="hist-row"><div><b>'+esc(p.nombre)+'</b><div class="meta">'+esc(p.empresa)+' · '+fmtDate(p.created_at)+(p.device==="kiosco"?' · kiosco':'')+'</div></div>'+resPill(p)+'<span></span></div>').join("")+'</div>':'<p class="sub">Los registros aparecen aquí en tiempo real.</p>')+'</div></div>';
}

/* ---------- Participantes ---------- */
function tPart(b){
  if(!b.querySelector("#pSearch")){
    b.innerHTML='<div class="toolbar"><input type="search" id="pSearch" placeholder="Buscar por nombre, empresa, teléfono, email o código"><a class="btn btn-blue btn-sm" href="'+BASE+'api.php?action=export_csv">Exportar CSV</a><span class="num" id="pCount"></span></div>'+
    '<div class="tbl-wrap"><table style="min-width:1180px"><thead><tr><th>Fecha</th><th>Nombre</th><th>Empresa</th><th>Cargo</th><th>Teléfono</th><th>Email</th><th>Rubro</th><th>Mayor desafío</th><th>Ruleta</th><th>Código</th><th>Sorteo</th><th></th></tr></thead><tbody id="pBody"></tbody></table></div>';
    $("#pSearch").addEventListener("input",fillPart);
  }
  fillPart();
}
function fillPart(){
  const P=S.d.participants,q=($("#pSearch")?.value||"").toLowerCase().trim();
  const L=P.filter(p=>!q||[p.nombre,p.empresa,p.email,p.telefono,p.cargo,p.code].join(" ").toLowerCase().includes(q));
  $("#pCount").textContent=L.length+" de "+P.length;
  $("#pBody").innerHTML=L.length?L.map(p=>'<tr><td class="mono">'+fmtDate(p.created_at)+'</td><td>'+esc(p.nombre)+'</td><td>'+esc(p.empresa)+'</td><td>'+esc(p.cargo)+'</td><td class="mono">'+esc(p.telefono)+'</td><td>'+esc(p.email)+'</td><td>'+esc(p.rubro)+'</td><td>'+esc(p.desafio.join(", "))+'</td><td>'+resPill(p)+'</td><td class="mono">'+esc(p.code||"")+'</td><td>'+(p.draw_wins?'<span class="pill win">Ganó sorteo</span>':'')+'</td>'+
    '<td><div class="row-actions"><button class="btn btn-ghost btn-sm" data-edit="'+p.id+'">Editar</button>'+(p.spun?'<button class="btn btn-ghost btn-sm" data-reset="'+p.id+'" title="Permite volver a girar; si ganó y no se entregó, el premio vuelve al stock">Resetear giro</button>':'')+'<button class="btn btn-ghost btn-sm" data-delpart="'+p.id+'">Eliminar</button></div></td></tr>').join("")
    :'<tr><td colspan="12" style="color:var(--muted)">'+(P.length?"Sin coincidencias.":"Todavía no hay registros. Aparecen aquí apenas alguien completa el formulario.")+'</td></tr>';
}
document.addEventListener("click",async e=>{
  const ed=e.target.closest("[data-edit]");
  if(ed){const p=S.d.participants.find(x=>x.id===+ed.dataset.edit);if(!p)return;const f=$("#editForm");
    ["id","nombre","empresa","cargo","telefono","email","rubro"].forEach(k=>f.elements[k].value=p[k]);
    $("#editMsg").textContent="";f.querySelectorAll(".field.err").forEach(x=>x.classList.remove("err"));
    $("#editOv").hidden=false;f.elements.nombre.focus();return}
  const rs=e.target.closest("[data-reset]");
  if(rs){if(!confirmTap(rs,"¿Resetear?"))return;rs.disabled=true;await act("participant_reset",{id:+rs.dataset.reset},"Giro reseteado");return}
  const dp=e.target.closest("[data-delpart]");
  if(dp){if(!confirmTap(dp,"¿Eliminar?"))return;dp.disabled=true;await act("participant_delete",{id:+dp.dataset.delpart},"Participante eliminado");return}
});
$("#editForm").addEventListener("submit",async e=>{
  e.preventDefault();const f=e.target,data=Object.fromEntries(new FormData(f));data.id=+data.id;
  f.querySelectorAll(".field.err").forEach(x=>x.classList.remove("err"));
  try{await api("participant_save",data);$("#editOv").hidden=true;toast("Participante actualizado");await load(true)}
  catch(err){$("#editMsg").textContent=err.error||"No se pudo guardar.";(err.fields||[]).forEach(n=>$("#e_"+n)?.closest(".field").classList.add("err"))}
});

/* ---------- Ganadores ruleta ---------- */
function tWin(b){
  if(!b.querySelector("#wSearch")){
    b.innerHTML='<div class="card"><h3>Ganadores de la ruleta</h3><p class="sub">Marcá cada premio como entregado cuando el visitante presente su código en el mostrador.</p>'+
    '<div class="toolbar"><input type="search" id="wSearch" placeholder="Buscar por código, nombre, empresa o teléfono"><select id="wFilter" class="sel"><option value="">Todos</option><option value="pend">Pendientes de entrega</option><option value="ok">Entregados</option></select><span class="num" id="wCount"></span></div><div class="hist" id="wList"></div></div>';
    $("#wSearch").addEventListener("input",fillWin);$("#wFilter").addEventListener("change",fillWin);
  }
  fillWin();
}
function fillWin(){
  const W=S.d.participants.filter(p=>p.result==="win").sort((a,b)=>b.spun_at-a.spun_at);
  const q=$("#wSearch").value.toLowerCase().trim(),fl=$("#wFilter").value;
  const L=W.filter(p=>(!q||[p.code,p.nombre,p.empresa,p.telefono].join(" ").toLowerCase().includes(q))&&(!fl||(fl==="ok")===!!p.delivered));
  $("#wCount").textContent=L.length+" de "+W.length;
  $("#wList").innerHTML=L.length?L.map(p=>'<div class="hist-row"><div><b>'+esc(p.prize_name)+'</b><div class="meta">'+esc(p.nombre)+' · '+esc(p.empresa)+' · '+esc(p.telefono)+' · '+fmtDate(p.spun_at)+(p.delivered?' · entregado '+fmtDate(p.delivered_at):'')+'</div></div><span class="num" style="font-size:16px;color:var(--fg)">'+esc(p.code)+'</span><button class="btn btn-sm '+(p.delivered?"btn-ghost":"btn-blue")+'" data-deliver="'+p.id+'">'+(p.delivered?"Entregado ✓":"Marcar entregado")+'</button></div>').join("")
    :'<p class="sub" style="margin:0">'+(W.length?"Sin coincidencias.":"Todavía no hay ganadores.")+'</p>';
}
document.addEventListener("click",async e=>{
  const d=e.target.closest("[data-deliver]");if(!d)return;d.disabled=true;
  await act("deliver",{id:+d.dataset.deliver});
});

/* ---------- Ganadores sorteo ---------- */
function tDraws(b){
  const D=S.d.draws;
  const st=d=>d.status==="anulado"?'<span class="pill void">Anulado</span>':d.delivered?'<span class="pill win">Entregado</span>':'<span class="pill pend">Pendiente</span>';
  b.innerHTML='<div class="toolbar"><a class="btn btn-accent btn-sm" href="'+BASE+'sorteo.php" target="_blank" rel="noopener">Abrir pantalla de sorteo</a><span class="num">'+D.filter(d=>d.status==="valido").length+' ganadores válidos</span></div>'+
  '<div class="card"><h3>Ganadores del sorteo final</h3><p class="sub">Si el ganador no se presenta o no cumple las bases, anulá el resultado y volvé a sortear para elegir un suplente.</p>'+
  (D.length?'<div class="hist">'+D.map(d=>'<div class="hist-row draw-row"><div><b>'+esc(d.winner_name)+'</b><div class="meta">'+esc(d.prize_name)+' · '+esc(d.empresa)+' · '+esc(d.telefono)+' · '+esc(d.email)+'</div><div class="meta">'+fmtDate(d.at)+' · entre '+d.pool+' participantes'+(d.admin_name?' · por '+esc(d.admin_name):'')+(d.status==="anulado"?' · anulado '+fmtDate(d.voided_at):'')+'</div></div>'+st(d)+
    '<div class="row-actions">'+(d.status==="valido"?'<button class="btn btn-sm '+(d.delivered?"btn-ghost":"btn-blue")+'" data-ddeliver="'+d.id+'">'+(d.delivered?"Desmarcar":"Marcar entregado")+'</button><button class="btn btn-ghost btn-sm" data-void="'+d.id+'">Anular</button>':'')+'</div></div>').join("")+'</div>'
  :'<p class="sub" style="margin:0">Todavía no se realizó ningún sorteo.</p>')+'</div>';
}
document.addEventListener("click",async e=>{
  const v=e.target.closest("[data-void]");
  if(v){if(!confirmTap(v,"¿Anular?"))return;v.disabled=true;await act("draw_void",{id:+v.dataset.void},"Resultado anulado");return}
  const d=e.target.closest("[data-ddeliver]");
  if(d){d.disabled=true;await act("draw_deliver",{id:+d.dataset.ddeliver})}
});

/* ---------- Premios ---------- */
function tPrem(b){
  const R=ruleta(),F=sorteo();
  const sum=R.filter(p=>p.active).reduce((a,p)=>a+(+p.prob||0),0);
  const del=p=>'<button class="btn btn-ghost btn-sm" type="button" data-delprize="'+p.id+'">Eliminar</button>';
  b.innerHTML='<div class="card"><h3>Premios de la ruleta</h3><p class="sub">La probabilidad es el porcentaje de giros que gana ese premio mientras tenga stock. Lo que falta para 100% es "Seguí participando". Al agotarse un premio, sale de la ruleta.</p><div class="prize-rows">'+
  (R.length?R.map(p=>'<form class="prize-row" data-prize="'+p.id+'"><div class="field"><label>Premio</label><input name="name" value="'+esc(p.name)+'" required></div><div class="field"><label>Stock</label><input name="stock" type="number" min="0" value="'+p.stock+'"></div><div class="field"><label>Prob. %</label><input name="prob" type="number" min="0" max="100" step="0.5" value="'+p.prob+'"></div><div class="delivered">Ganados<br><b>'+p.awarded+'</b></div><label class="tog"><input type="checkbox" name="active" '+(p.active?"checked":"")+'>Activo</label><div class="row-actions"><button class="btn btn-blue btn-sm" type="submit">Guardar</button>'+del(p)+'</div></form>').join(""):'<p class="sub" style="margin:0">Sin premios de ruleta.</p>')+
  '</div><div class="prob-sum"><span>Suma de premios activos: <b>'+pct(sum)+'%</b></span><span>Seguí participando: <b>'+pct(Math.max(0,100-sum))+'%</b></span>'+(sum>100?'<span style="color:var(--bad)">La suma supera 100%: bajá alguna probabilidad.</span>':'')+'</div>'+
  '<form class="add-row" data-newprize="ruleta"><h3 style="font-size:18px;margin:22px 0 10px">Agregar premio de ruleta</h3><div class="fields" style="grid-template-columns:2fr 1fr 1fr auto;align-items:end"><div class="field"><label>Premio</label><input name="name" required placeholder="Ej.: Kit de seguridad industrial"></div><div class="field"><label>Stock</label><input name="stock" type="number" min="1" value="10"></div><div class="field"><label>Prob. %</label><input name="prob" type="number" min="0" max="100" step="0.5" value="5"></div><button class="btn btn-accent btn-sm" style="min-height:46px">Agregar</button></div></form></div>'+

  '<div class="card"><h3>Premios del sorteo final</h3><p class="sub">Cada unidad se sortea por separado desde la pantalla de sorteo. Si anulás un ganador, la unidad vuelve a estar disponible.</p><div class="prize-rows">'+
  (F.length?F.map(p=>'<form class="prize-row no-prob" data-prize="'+p.id+'"><div class="field"><label>Premio</label><input name="name" value="'+esc(p.name)+'" required></div><div class="field"><label>Cantidad</label><input name="stock" type="number" min="0" value="'+p.stock+'"></div><div class="delivered">Sorteados<br><b>'+p.awarded+'</b></div><label class="tog"><input type="checkbox" name="active" '+(p.active?"checked":"")+'>Activo</label><div class="row-actions"><button class="btn btn-blue btn-sm" type="submit">Guardar</button>'+del(p)+'</div></form>').join(""):'<p class="sub" style="margin:0">Sin premios de sorteo.</p>')+
  '</div><form class="add-row" data-newprize="sorteo"><h3 style="font-size:18px;margin:22px 0 10px">Agregar premio de sorteo</h3><div class="fields" style="grid-template-columns:2fr 1fr auto;align-items:end"><div class="field"><label>Premio</label><input name="name" required placeholder="Ej.: Hidrolavadora industrial"></div><div class="field"><label>Cantidad</label><input name="stock" type="number" min="1" value="1"></div><button class="btn btn-accent btn-sm" style="min-height:46px">Agregar</button></div></form></div>';
}
document.addEventListener("submit",async e=>{
  const f=e.target;
  if(f.matches("[data-prize]")){e.preventDefault();const fd=new FormData(f);
    await act("prize_save",{id:+f.dataset.prize,name:fd.get("name"),stock:+fd.get("stock")||0,prob:+fd.get("prob")||0,active:!!fd.get("active")},"Premio guardado");return}
  if(f.matches("[data-newprize]")){e.preventDefault();const fd=new FormData(f);if(!String(fd.get("name")).trim())return;
    await act("prize_add",{type:f.dataset.newprize,name:fd.get("name"),stock:+fd.get("stock")||1,prob:+fd.get("prob")||0},"Premio agregado");return}
  if(f.matches("[data-admin]")){e.preventDefault();const fd=new FormData(f);
    await act("admin_save",{id:+f.dataset.admin,name:fd.get("name"),password:fd.get("password"),active:!!fd.get("active")},"Administrador guardado");return}
  if(f.id==="newAdmin"){e.preventDefault();const fd=new FormData(f);
    await act("admin_add",Object.fromEntries(fd),"Administrador creado");return}
});
document.addEventListener("click",async e=>{
  const d=e.target.closest("[data-delprize]");if(!d)return;
  if(!confirmTap(d))return;d.disabled=true;await act("prize_delete",{id:+d.dataset.delprize},"Premio eliminado");
});

/* ---------- Administradores ---------- */
function tAdmins(b){
  const A=S.d.admins;
  b.innerHTML='<div class="card"><h3>Administradores</h3><p class="sub">Usuarios que pueden entrar al panel y a la pantalla de sorteo. Dejá la contraseña vacía para no cambiarla.</p><div class="prize-rows">'+
  A.map(a=>{const self=a.id===S.d.me;return '<form class="prize-row admin-row" data-admin="'+a.id+'"><div class="field"><label>Usuario</label><input value="'+esc(a.username)+'" disabled></div><div class="field"><label>Nombre</label><input name="name" value="'+esc(a.name)+'"></div><div class="field"><label>Nueva contraseña</label><input name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Sin cambios"></div><label class="tog"><input type="checkbox" name="active" '+(a.active?"checked":"")+(self?" disabled":"")+'>Activo</label>'+
    (self?'<input type="hidden" name="active" value="1">':'')+
    '<div class="row-actions"><button class="btn btn-blue btn-sm" type="submit">Guardar</button>'+(self?'<span class="num">(vos)</span>':'<button class="btn btn-ghost btn-sm" type="button" data-deladmin="'+a.id+'">Eliminar</button>')+'</div>'+
    '<div class="meta-line num">Último acceso: '+(a.last_login_at?fmtDate(a.last_login_at):"nunca")+'</div></form>'}).join("")+'</div>'+
  '<form id="newAdmin" autocomplete="off"><h3 style="font-size:18px;margin:22px 0 10px">Agregar administrador</h3><div class="fields" style="grid-template-columns:1fr 1fr 1fr auto;align-items:end"><div class="field"><label for="na_user">Usuario</label><input id="na_user" name="username" required pattern="[A-Za-z0-9._-]{3,32}" placeholder="ej.: maria"></div><div class="field"><label for="na_name">Nombre</label><input id="na_name" name="name" placeholder="María González"></div><div class="field"><label for="na_pass">Contraseña</label><input id="na_pass" name="password" type="password" minlength="8" autocomplete="new-password" required placeholder="Mínimo 8 caracteres"></div><button class="btn btn-accent btn-sm" style="min-height:46px">Agregar</button></div></form></div>';
}
document.addEventListener("click",async e=>{
  const d=e.target.closest("[data-deladmin]");if(!d)return;
  if(!confirmTap(d))return;d.disabled=true;await act("admin_delete",{id:+d.dataset.deladmin},"Administrador eliminado");
});

/* ---------- Contenido ---------- */
function tCont(b){
  const c=S.d.content,ct=c.contact,so=c.social;
  const F=(id,l,v,ph,full)=>'<div class="field'+(full?" full":"")+'"><label for="'+id+'">'+l+'</label><input id="'+id+'" value="'+esc(v)+'" placeholder="'+esc(ph||"")+'"></div>';
  b.innerHTML='<form class="card" id="contentForm"><h3>Portada</h3><div class="fields">'+
   F("c_eyebrow","Línea superior",c.heroEyebrow,"Expo Maquila 2026 · Stand Petersen Industria",1)+F("c_title","Título principal (la parte después de la coma se destaca en amarillo)",c.heroTitle,"Recorré nuestro Stand Virtual",1)+
   '<div class="field full"><label for="c_text">Texto de portada</label><textarea id="c_text" rows="2">'+esc(c.heroText)+'</textarea></div>'+
   F("c_3d","Enlace de la maqueta 3D",c.threeDUrl,"https://… (vacío muestra «Próximamente»)",1)+
   '</div><h3 style="margin-top:24px">Contacto</h3><div class="fields">'+F("c_addr","Dirección",ct.address,"",1)+F("c_phone","Teléfono",ct.phone)+F("c_wa","WhatsApp",ct.whatsapp)+F("c_mail","Correo",ct.email)+F("c_web","Sitio web",ct.web)+
   '</div><h3 style="margin-top:24px">Redes sociales</h3><div class="fields">'+F("s_ig","Instagram",so.instagram,"https://instagram.com/…")+F("s_fb","Facebook",so.facebook,"https://facebook.com/…")+F("s_li","LinkedIn",so.linkedin,"https://linkedin.com/company/…")+F("s_yt","YouTube",so.youtube,"https://youtube.com/@…")+F("s_tt","TikTok",so.tiktok,"https://tiktok.com/@…")+
   '</div><div class="form-actions"><button class="btn btn-accent" type="submit">Guardar contenido</button></div></form>'+
   '<div class="card"><h3>Imagen de portada</h3><p class="sub">Opcional. Reemplaza la ilustración del stand. Recomendado 2400×1400 px, JPG.</p><div class="toolbar">'+(c.heroImage?'<img src="'+esc(media(c.heroImage))+'" alt="" style="height:64px;border-radius:4px">':'')+'<label class="btn btn-blue btn-sm file-btn">Subir imagen<input type="file" accept="image/png,image/jpeg,image/webp" id="heroUp"></label>'+(c.heroImage?'<button class="btn btn-ghost btn-sm" id="heroRm">Quitar imagen</button>':'')+'</div></div>'+
   '<div class="card"><h3>Marcas</h3><p class="sub">Subí el logo de cada marca (PNG, JPG o WEBP). Sin logo se muestra el nombre. Los cambios de nombre y enlace se guardan al salir del campo.</p><div class="brand-admin">'+
   S.d.brands.map(br=>'<div class="brand-adm-row" data-brand="'+br.id+'"><div class="logo-prev">'+(br.logo?'<img src="'+esc(media(br.logo))+'" alt="">':'Sin logo')+'</div><input data-bf="name" value="'+esc(br.name)+'" aria-label="Nombre"><input data-bf="link" value="'+esc(br.link)+'" placeholder="Enlace (opcional)" aria-label="Enlace"><label class="btn btn-blue btn-sm file-btn">Logo<input type="file" accept="image/png,image/jpeg,image/webp" data-blogo="'+br.id+'"></label><button class="btn btn-ghost btn-sm" type="button" data-delbrand="'+br.id+'">Eliminar</button></div>').join("")+
   '</div><div class="toolbar" style="margin-top:12px"><input id="nb_name" class="inp" placeholder="Nombre de la marca" style="flex:1 1 220px"><button class="btn btn-accent btn-sm" id="nbAdd">Agregar marca</button></div></div>';
  $("#contentForm").onsubmit=async e=>{e.preventDefault();const v=id=>$(id).value.trim();
    await act("content_save",{heroEyebrow:v("#c_eyebrow"),heroTitle:v("#c_title"),heroText:$("#c_text").value.trim(),threeDUrl:v("#c_3d"),
      contact:{address:v("#c_addr"),phone:v("#c_phone"),whatsapp:v("#c_wa"),email:v("#c_mail"),web:v("#c_web")},
      social:{instagram:v("#s_ig"),facebook:v("#s_fb"),linkedin:v("#s_li"),youtube:v("#s_yt"),tiktok:v("#s_tt")}},"Contenido guardado")};
  $("#heroUp").onchange=e=>upload(e.target.files[0],{kind:"hero"},"Imagen de portada actualizada");
  if($("#heroRm"))$("#heroRm").onclick=()=>act("hero_remove",{},"Imagen quitada");
  $("#nbAdd").onclick=async()=>{const n=$("#nb_name").value.trim();if(!n)return;await act("brand_add",{name:n},"Marca agregada")};
}
async function upload(file,fields,okMsg){
  if(!file)return;
  if(file.size>8*1024*1024){toast("La imagen supera 8 MB. Reducila antes de subirla.");return}
  const fd=new FormData();fd.append("file",file);Object.entries(fields).forEach(([k,v])=>fd.append(k,v));
  toast("Subiendo…");await act("upload",fd,okMsg);
}
document.addEventListener("change",e=>{
  const l=e.target.closest("[data-blogo]");if(l)upload(l.files[0],{kind:"brand",id:l.dataset.blogo},"Logo actualizado");
});
document.addEventListener("focusout",async e=>{
  const i=e.target.closest("[data-bf]");if(!i)return;const row=i.closest("[data-brand]");const br=S.d.brands.find(b=>b.id===+row.dataset.brand);if(!br)return;
  const k=i.dataset.bf,v=i.value.trim();if((br[k]||"")===v)return;
  const data={id:br.id,name:br.name,link:br.link,[k]:v};
  try{await api("brand_save",data);br[k]=v;toast("Marca guardada");S.rev=""}catch(err){toast(err.error||"No se pudo guardar.")}
});
document.addEventListener("click",async e=>{
  const d=e.target.closest("[data-delbrand]");if(!d)return;
  if(!confirmTap(d))return;d.disabled=true;await act("brand_delete",{id:+d.dataset.delbrand},"Marca eliminada");
});
})();
