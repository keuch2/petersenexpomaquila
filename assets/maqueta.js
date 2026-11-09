// Visor de la maqueta 3D del stand (three.js servido desde assets/vendor).
// La geometría sale de tools/maqueta (Rhino -> glb). Los rubros se ubican con los
// carteles del modelo: cada material de cartel se llama como su imagen ("aire comprimido.jpg").
import * as THREE from "three";
import {GLTFLoader} from "three/addons/loaders/GLTFLoader.js";
import {OrbitControls} from "three/addons/controls/OrbitControls.js";
import {RoomEnvironment} from "three/addons/environments/RoomEnvironment.js";
import {MeshoptDecoder} from "three/addons/libs/meshopt_decoder.module.js";

const $=s=>document.querySelector(s);
const esc=s=>String(s??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const boot=JSON.parse($("#boot").textContent);
const kiosk=location.hash==="#kiosco";
const reduced=matchMedia("(prefers-reduced-motion: reduce)").matches;

const ZONES=[
  {n:"01",key:"aire comprimido",name:"Aire comprimido",color:"#f3b41c",brands:["Metalplan","Sullair","Emax"],desc:"Compresores, secadores y tratamiento de aire para planta."},
  {n:"02",key:"vapor y fluidos",name:"Vapor y fluidos",color:"#2b4fa0",brands:["Spirax Sarco"],desc:"Soluciones para líneas de vapor, condensado y fluidos de proceso."},
  {n:"03",key:"bombeo",name:"Bombeo y movimiento de fluidos",color:"#2a8bd0",brands:["Grundfos"],desc:"Bombas y sistemas de presurización para industria."},
  {n:"04",key:"automatizacion",name:"Automatización y control",color:"#7d7d7d",brands:["Danfoss"],desc:"Variadores y control para motores y procesos."},
  {n:"05",key:"sellado",name:"Sellado y control de fluidos",color:"#1a8a35",brands:["Klinger","Genebre"],desc:"Válvulas, juntas y sellado industrial."},
  {n:"06",key:"mantenimiento",name:"Mantenimiento industrial",color:"#e86a1a",brands:["Truper","Starrett","Neo"],desc:"Taller de herramientas para el mantenimiento diario."},
  {n:"07",key:"metalmecanica",name:"Metalmecánica y soldadura",color:"#e03030",brands:["ESAB","Sumig","Thor","Conarco","Tyrolit","Starrett","ECEF"],desc:"Soldadura, corte, abrasivos y consumibles."},
  {n:"08",key:"logistica",name:"Logística",color:"#9b78b6",brands:["Truper","Deltaplus"],desc:"Manejo de materiales y equipos de protección personal."},
  {n:"09",key:"linea de produccion",name:"Línea de producción",color:"#86b82a",brands:["Rexnord","Maskin","Emax","Metalplan"],desc:"Componentes industriales para transmisión y producción."},
  {n:"10",key:"control de calidad",name:"Control de calidad",color:"#8f8f8f",brands:["Starrett"],desc:"Metrología e instrumentos de medición de precisión."},
];

// Kiosco: los enlaces vuelven al inicio en modo kiosco y la página se reinicia sola.
if(kiosk){
  document.querySelectorAll(".mq-home").forEach(a=>a.href=boot.home+"#kiosco");
  let idle;const arm=()=>{clearTimeout(idle);idle=setTimeout(()=>location.href=boot.home+"#kiosco",90000)};
  ["pointerdown","wheel","keydown"].forEach(ev=>addEventListener(ev,arm,{passive:true}));arm();
}

const canvas=$("#mqCanvas");
let renderer;
try{renderer=new THREE.WebGLRenderer({canvas,antialias:true,alpha:true,powerPreference:"high-performance"})}
catch(e){$("#mqLoading").hidden=true;$("#mqFallback").hidden=false;throw e}
renderer.setPixelRatio(Math.min(devicePixelRatio,2));
renderer.toneMapping=THREE.NeutralToneMapping;renderer.toneMappingExposure=1;

const scene=new THREE.Scene();
const pmrem=new THREE.PMREMGenerator(renderer);
scene.environment=pmrem.fromScene(new RoomEnvironment(),0.04).texture;
scene.environmentIntensity=0.55;
scene.add(new THREE.HemisphereLight(0xffffff,0x6f7b88,0.7));
const sun=new THREE.DirectionalLight(0xffffff,1.6);sun.position.set(5,10,7);scene.add(sun);

const camera=new THREE.PerspectiveCamera(40,1,0.05,200);
const controls=new OrbitControls(camera,canvas);
Object.assign(controls,{enableDamping:true,dampingFactor:0.08,maxPolarAngle:Math.PI*0.47,minDistance:1.2,maxDistance:22,screenSpacePanning:false,autoRotateSpeed:0.6,zoomToCursor:true});

let dirty=true,bounds=new THREE.Box3(),home=null,tween=null,current=-1;
const pins=[];
const resize=()=>{const w=canvas.clientWidth,hh=canvas.clientHeight;renderer.setSize(w,hh,false);camera.aspect=w/Math.max(hh,1);camera.updateProjectionMatrix();dirty=true};
new ResizeObserver(resize).observe(canvas);
controls.addEventListener("change",()=>dirty=true);
controls.addEventListener("start",()=>{tween=null;hideHint();setSpin(false)});

const loader=new GLTFLoader().setMeshoptDecoder(MeshoptDecoder);
loader.load(boot.model,gltf=>{
  const root=gltf.scene;scene.add(root);
  root.traverse(o=>{
    if(!o.isMesh)return;
    const m=o.material;
    if(m.transparent){m.depthWrite=false;o.renderOrder=2}
    // Árboles, arbustos y vehículos llegan sin normales (estilo low-poly).
    if(!o.geometry.attributes.normal){m.flatShading=true;m.needsUpdate=true}
    const name=(m.name||"").toLowerCase();
    const z=ZONES.find(z=>name.includes(z.key)&&!z.mesh);
    if(z)z.mesh=o;
  });
  bounds.setFromObject(root);
  const size=bounds.getSize(new THREE.Vector3()),c=bounds.getCenter(new THREE.Vector3());
  const r=size.length()/2;
  home={target:new THREE.Vector3(c.x,size.y*0.15,c.z),r};
  controls.maxDistance=r*4.5;
  setupZones();
  goHome(false);
  $("#mqLoading").classList.add("done");setTimeout(()=>$("#mqLoading").hidden=true,600);
  if(!reduced&&!kiosk)setSpin(true);
  dirty=true;
},ev=>{
  if(ev.total){const p=ev.loaded/ev.total;$("#mqBar").style.width=(p*100).toFixed(1)+"%";$("#mqLoadTxt").textContent=`Cargando la maqueta… ${Math.round(p*100)} %`}
},err=>{console.error(err);$("#mqLoadTxt").textContent="No pudimos cargar la maqueta. Revisá tu conexión y recargá la página."});

function setupZones(){
  const strip=$("#mqZones"),pinBox=$("#mqPins");
  ZONES.forEach((z,i)=>{
    if(!z.mesh)return;
    const b=new THREE.Box3().setFromObject(z.mesh),c=b.getCenter(new THREE.Vector3()),s=b.getSize(new THREE.Vector3());
    // El cartel mira hacia +Z; el box está delante de él, a nivel del piso.
    z.anchor=new THREE.Vector3(c.x,b.max.y+s.y*0.35,c.z);
    z.target=new THREE.Vector3(c.x,0.3,c.z+0.85);
    const pin=document.createElement("button");
    pin.type="button";pin.className="mq-pin";pin.style.setProperty("--zc",z.color);
    pin.innerHTML=`<b>${esc(z.n)}</b><span>${esc(z.name)}</span>`;
    pin.setAttribute("aria-label",`Rubro ${z.n}: ${z.name}`);
    pin.addEventListener("click",()=>focusZone(i));
    pinBox.append(pin);pins.push({el:pin,z});
    const chip=document.createElement("button");
    chip.type="button";chip.className="mq-zone";chip.style.setProperty("--zc",z.color);
    chip.innerHTML=`<b>${esc(z.n)}</b>${esc(z.name)}`;
    chip.addEventListener("click",()=>focusZone(i));
    strip.append(chip);z.chip=chip;
  });
}

function fly(pos,target,ms=1100){
  if(reduced)ms=0;
  tween={t0:performance.now(),ms,fromP:camera.position.clone(),fromT:controls.target.clone(),toP:pos,toT:target};dirty=true;
}
// Vista general desde la fachada; la distancia se ajusta al aspecto (en vertical se aleja).
function homePos(){
  const vf=THREE.MathUtils.degToRad(camera.fov),hf=2*Math.atan(Math.tan(vf/2)*camera.aspect);
  const d=home.r/Math.tan(Math.min(vf,hf)/2)*0.63;
  return home.target.clone().add(new THREE.Vector3(0.95,0.95,1.1).normalize().multiplyScalar(d));
}
function goHome(anim=true){
  current=-1;$("#mqCard").hidden=true;ZONES.forEach(z=>z.chip?.classList.remove("on"));
  document.body.classList.remove("mq-focus");
  if(anim)fly(homePos(),home.target.clone());else{camera.position.copy(homePos());controls.target.copy(home.target);controls.update()}
}
function focusZone(i){
  const z=ZONES[i];if(!z?.target)return;
  current=i;setSpin(false);hideHint();
  fly(z.target.clone().add(new THREE.Vector3(0.6,3.4,2.6)),z.target.clone());
  $("#mqNum").textContent=z.n;$("#mqNum").style.background=z.color;
  $("#mqName").textContent=z.name;$("#mqDesc").textContent=z.desc;
  $("#mqBrands").innerHTML=z.brands.map(b=>`<li>${esc(b)}</li>`).join("");
  $("#mqCard").hidden=false;$("#mqCard").style.setProperty("--zc",z.color);
  document.body.classList.add("mq-focus");
  ZONES.forEach((x,j)=>x.chip?.classList.toggle("on",j===i));
  z.chip?.scrollIntoView({behavior:reduced?"auto":"smooth",inline:"center",block:"nearest"});
}
const step=d=>{const n=ZONES.length;focusZone(current<0?(d>0?0:n-1):(current+d+n)%n)};
$("#mqPrev").addEventListener("click",()=>step(-1));
$("#mqNext").addEventListener("click",()=>step(1));
$("#mqClose").addEventListener("click",()=>goHome());
$("#mqReset").addEventListener("click",()=>goHome());
function setSpin(on){controls.autoRotate=on;$("#mqSpin").setAttribute("aria-pressed",String(on));dirty=true}
$("#mqSpin").addEventListener("click",()=>setSpin(!controls.autoRotate));
addEventListener("keydown",e=>{
  if(e.target.closest("input,textarea,select"))return;
  if(e.key==="Escape")goHome();else if(e.key==="ArrowRight")step(1);else if(e.key==="ArrowLeft")step(-1);
});
function hideHint(){$("#mqIntro").classList.add("quiet")}
setTimeout(hideHint,8000);

const v=new THREE.Vector3(),ease=t=>t<.5?4*t*t*t:1-Math.pow(-2*t+2,3)/2;
function updatePins(){
  const w=canvas.clientWidth,hh=canvas.clientHeight;
  for(const {el,z} of pins){
    v.copy(z.anchor).project(camera);
    const vis=v.z<1&&Math.abs(v.x)<1.1&&Math.abs(v.y)<1.1;
    el.style.transform=`translate(${(v.x*.5+.5)*w}px,${(-v.y*.5+.5)*hh}px)`;
    el.classList.toggle("off",!vis);
    el.classList.toggle("on",ZONES[current]===z);
  }
}
renderer.setAnimationLoop(now=>{
  if(tween){
    const k=tween.ms?Math.min(1,(now-tween.t0)/tween.ms):1,e=ease(k);
    camera.position.lerpVectors(tween.fromP,tween.toP,e);controls.target.lerpVectors(tween.fromT,tween.toT,e);
    if(k>=1)tween=null;dirty=true;
  }
  if(controls.update())dirty=true;
  // El paneo no se va más allá del stand.
  if(home&&!bounds.isEmpty()){const t=controls.target,b=bounds;t.x=Math.min(b.max.x,Math.max(b.min.x,t.x));t.z=Math.min(b.max.z,Math.max(b.min.z,t.z));t.y=Math.min(b.max.y,Math.max(0,t.y))}
  if(!dirty)return;
  dirty=false;renderer.render(scene,camera);updatePins();
});
