// Paso 2 de la conversión de la maqueta: raw.glb -> stand.glb liviano para la web.
// Simplifica cada malla según su categoría (nombre puesto por extract.py), pasa
// las texturas a WebP y comprime la geometría con EXT_meshopt_compression.
//
// Uso: node optimize.mjs build/raw.glb ../../assets/maqueta/stand.glb
import { NodeIO } from '@gltf-transform/core';
import { ALL_EXTENSIONS } from '@gltf-transform/extensions';
import { dedup, prune, weld, simplifyPrimitive, compactPrimitive, textureCompress, meshopt } from '@gltf-transform/functions';
import { MeshoptSimplifier, MeshoptEncoder, MeshoptDecoder } from 'meshoptimizer';
import sharp from 'sharp';

const [src, dst] = process.argv.slice(2);
await MeshoptSimplifier.ready;
await MeshoptEncoder.ready;
const io = new NodeIO().registerExtensions(ALL_EXTENSIONS).registerDependencies({
  'meshopt.encoder': MeshoptEncoder, 'meshopt.decoder': MeshoptDecoder,
});

// ratio = fracción a conservar; error = tope relativo al radio de la malla.
// sloppy: ignora la topología (árboles/arbustos/vehículos son miles de piezas
// sueltas sin UVs que el simplificador normal no puede colapsar).
function rule(name, tris) {
  if (tris < 300_000) return tris > 50_000 ? { ratio: 0.25, error: 0.004 } : null;
  if (name.startsWith('tree')) return { ratio: 0.012, error: 0.03 };
  if (name.startsWith('shrub')) return { ratio: 0.012, error: 0.03 };
  if (name.startsWith('vehicle')) return { ratio: 0.03, error: 0.01 };
  if (tris > 300_000) return { ratio: 0.05, error: 0.01 };  // estaciones Meshy y piezas STEP densas
  if (tris > 50_000) return { ratio: 0.25, error: 0.004 };
  return null;
}
const triCount = (p) => (p.getIndices() ? p.getIndices().getCount() : p.getAttribute('POSITION').getCount()) / 3;

function simplifySloppy(prim, { ratio, error }) {
  const indices = prim.getIndices();
  const pos = prim.getAttribute('POSITION').getArray();
  const target = Math.floor((indices.getCount() * ratio) / 3) * 3;
  const [out] = MeshoptSimplifier.simplifySloppy(Uint32Array.from(indices.getArray()), pos, 3, null, target, error);
  indices.setArray(new Uint32Array(out));
  compactPrimitive(prim);
}

const doc = await io.read(src);
// Árboles, arbustos y vehículos vienen con una normal por cara (vértices sin
// compartir): sin normales se sueldan y se pueden simplificar. El visor los
// muestra con flat shading.
const FLAT = /^(tree|shrub|vehicle)/;
for (const mesh of doc.getRoot().listMeshes()) {
  if (FLAT.test(mesh.getName())) mesh.listPrimitives().forEach((p) => p.setAttribute('NORMAL', null));
}
await doc.transform(dedup(), weld());
let before = 0, after = 0;
for (const mesh of doc.getRoot().listMeshes()) {
  for (const prim of mesh.listPrimitives()) {
    const t0 = triCount(prim);
    const r = rule(mesh.getName(), t0);
    if (r?.sloppy) simplifySloppy(prim, r);
    else if (r) simplifyPrimitive(prim, { simplifier: MeshoptSimplifier, ...r });
    const t1 = triCount(prim);
    before += t0; after += t1;
    if (t0 > 20_000) console.log(`${mesh.getName().padEnd(14)} ${t0.toLocaleString().padStart(10)} -> ${t1.toLocaleString()}`);
  }
}
console.log(`total ${before.toLocaleString()} -> ${after.toLocaleString()} triángulos`);

await doc.transform(
  prune(),
  textureCompress({ encoder: sharp, targetFormat: 'webp', resize: [2048, 2048], quality: 82 }),
  meshopt({ encoder: MeshoptEncoder, level: 'medium' }),
);
await io.write(dst, doc);
console.log('ok', dst);
