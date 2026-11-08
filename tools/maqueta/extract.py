#!/usr/bin/env python3
"""Paso 1 de la conversión de la maqueta: Rhino .3dm -> .glb crudo.

Aplana bloques, resuelve materiales/texturas embebidas, convierte los decals
de los carteles en UVs planares y agrupa la geometría por (categoría, material).
La simplificación y compresión se hacen después en optimize.mjs.

Uso:
  python3 -I extract.py "maqueta/Petersen render final.3dm" /ruta/salida
Requiere: pip install rhino3dm trimesh numpy pillow
"""
import os, re, sys, json, time
import numpy as np
import rhino3dm as r
import trimesh
from PIL import Image

# Zona del stand en mm (coordenadas Rhino). Afuera hay restos sueltos que no van.
REGION = (2850.0, 3750.0, 150.0, 1000.0)
SCALE = 0.01  # mm -> unidades de escena (el stand queda en ~7 x 6 unidades)

src, out = sys.argv[1], sys.argv[2]
os.makedirs(os.path.join(out, 'tex'), exist_ok=True)
t0 = time.time()
model = r.File3dm.Read(src)
print(f'leído en {time.time()-t0:.0f}s')
layers = {l.Index: l for l in model.Layers}

def layer_visible(idx):
    l = layers.get(idx)
    while l is not None:
        if not l.Visible:
            return False
        l = next((x for x in layers.values() if x.Id == l.ParentLayerId), None)
    return True

# ---------- texturas embebidas ----------
emb = {}
for e in model.EmbeddedFiles:
    base = re.split(r'[\\/]', e.Filename)[-1]
    path = os.path.join(out, 'tex', base)
    if not os.path.exists(path):
        e.Write(path)
    emb[base] = path

def tex_path(filename):
    return emb.get(re.split(r'[\\/]', filename or '')[-1]) if filename else None

# ---------- materiales ----------
rc_by_id = {str(c.Id): c for c in model.RenderContent if c.Kind == 'material'}
tex_by_id = {str(c.Id).lower(): c for c in model.RenderContent if c.Kind == 'texture'}

def xml_color(xml, *names):
    for n in names:
        mm = re.search(r'name="%s">([-\d.e,]+)<' % re.escape(n), xml)
        if mm:
            return [float(x) for x in mm.group(1).split(',')]
    return None

avg_cache = {}
def avg_color(path):
    if path not in avg_cache:
        im = Image.open(path).convert('RGB').resize((16, 16))
        avg_cache[path] = [c / 255 for c in np.asarray(im).reshape(-1, 3).mean(0)]
    return avg_cache[path]

DEFAULT = {'key': 'default', 'color': [0.94, 0.94, 0.94, 1], 'tex': None, 'kind': 'plaster'}
mat_cache = {}
def material(idx):
    if idx is None or idx < 0 or idx >= len(model.Materials):
        return DEFAULT
    if idx in mat_cache:
        return mat_cache[idx]
    m = model.Materials[idx]
    rc = rc_by_id.get(str(m.RenderMaterialInstanceId))
    xml = rc.XML(False) if rc else ''
    tname = rc.TypeName if rc else ''
    kind = ('glass' if 'glass' in tname else 'metal' if 'metal' in tname else
            'plastic' if 'plastic' in tname else 'paint' if 'paint' in tname else 'plaster')
    col = xml_color(xml, 'color', 'diffuse')
    if col is None:
        c = m.DiffuseColor
        col = [c[0] / 255, c[1] / 255, c[2] / 255, 1]
    tex = m.GetBitmapTexture()
    tp = tex_path(tex.FileName) if tex else None
    key = str(m.RenderMaterialInstanceId)
    res = {'key': key, 'color': col[:3] + [1], 'tex': tp, 'kind': kind, 'name': m.Name[:24]}
    if kind == 'glass':
        res['color'] = [0.78, 0.86, 0.92, 0.28]
    mat_cache[idx] = res
    return res

FROM_LAYER = r.ObjectMaterialSource.MaterialFromLayer
FROM_OBJ = r.ObjectMaterialSource.MaterialFromObject
def mat_index(attr, parent):
    s = attr.MaterialSource
    if s == FROM_OBJ:
        return attr.MaterialIndex
    if s == FROM_LAYER:
        return layers[attr.LayerIndex].RenderMaterialIndex
    return parent if parent is not None else layers[attr.LayerIndex].RenderMaterialIndex

# ---------- geometría ----------
def xf_np(x):
    return np.array([[x.M00, x.M01, x.M02, x.M03], [x.M10, x.M11, x.M12, x.M13],
                     [x.M20, x.M21, x.M22, x.M23], [x.M30, x.M31, x.M32, x.M33]])

def mesh_data(mesh):
    """Arrays numpy (V, F, N, UV|None, C|None) de un rhino3dm.Mesh."""
    mesh.Faces.ConvertQuadsToTriangles()
    nv, nf = len(mesh.Vertices), mesh.Faces.Count
    if nv == 0 or nf == 0:
        return None
    V = np.array([(p.X, p.Y, p.Z) for p in mesh.Vertices.ToPoint3fArray()], dtype=np.float64)
    F = np.array([mesh.Faces[i][:3] for i in range(nf)], dtype=np.int64)
    if len(mesh.Normals) != nv:
        mesh.Normals.ComputeNormals()
    N = np.array([(n.X, n.Y, n.Z) for n in (mesh.Normals[i] for i in range(nv))], dtype=np.float64) if len(mesh.Normals) == nv else None
    tc = mesh.TextureCoordinates
    UV = np.array([(t.X, t.Y) for t in (tc[i] for i in range(nv))], dtype=np.float64) if len(tc) == nv else None
    vc = mesh.VertexColors
    C = np.array([vc[i] for i in range(nv)], dtype=np.uint8) if len(vc) == nv else None
    return V, F, N, UV, C

def geom_meshes(g):
    """Lista de rhino3dm.Mesh para una geometría (malla, extrusión o brep)."""
    if isinstance(g, r.Mesh):
        return [g]
    if isinstance(g, r.Extrusion):
        m = g.GetMesh(r.MeshType.Render)
        if m is not None:
            return [m]
        g = g.ToBrep(True)
    if isinstance(g, r.Brep):
        return [m for m in (f.GetMesh(r.MeshType.Render) for f in g.Faces) if m is not None]
    return []

CATS = {'arboll': 'tree', 'arbstos': 'shrub', 'arbustos': 'shrub', 'camiones': 'vehicle'}
buckets = {}  # (cat, matkey) -> dict(mat, parts=[...])
missing = []

def add(cat, mat, V, F, N, UV, C):
    key = (cat, mat['key'] + ('+uv' if UV is not None and mat['tex'] else '') + ('+vc' if C is not None else ''))
    b = buckets.setdefault(key, {'mat': mat, 'parts': []})
    b['parts'].append((V, F, N, UV if mat['tex'] else None, C))

def emit(obj_attr, g, X, parent_mat, depth=0):
    if isinstance(g, r.InstanceReference):
        idef = model.InstanceDefinitions.FindId(g.ParentIdefId)
        if idef is None:
            return
        Xc = X @ xf_np(g.Xform)
        pm = mat_index(obj_attr, parent_mat)
        for oid in idef.GetObjectIds():
            o = model.Objects.FindId(oid)
            if o is None or not o.Attributes.Visible or not layer_visible(o.Attributes.LayerIndex):
                continue
            emit(o.Attributes, o.Geometry, Xc, pm, depth + 1)
        return
    meshes = geom_meshes(g)
    if not meshes:
        if isinstance(g, (r.Brep, r.Extrusion)):
            missing.append(layers[obj_attr.LayerIndex].FullPath)
        return
    lname = layers[obj_attr.LayerIndex].FullPath
    cat = CATS.get(lname.split('::')[0], 'misc')
    mat = material(mat_index(obj_attr, parent_mat))
    decal = obj_attr.Decals[0] if len(obj_attr.Decals) else None
    R3 = X[:3, :3]
    for msh in meshes:
        d = mesh_data(msh)
        if d is None:
            continue
        V, F, N, UV, C = d
        V = (np.c_[V, np.ones(len(V))] @ X.T)[:, :3]
        if N is not None:
            N = N @ R3.T
            N /= np.maximum(np.linalg.norm(N, axis=1, keepdims=True), 1e-12)
        if mat['tex'] and UV is None and C is None:
            mat = dict(mat, tex=None, color=avg_color(mat['tex']) + [1], key=mat['key'] + ':avg')
        if decal is not None:
            o = np.array([decal.Origin.X, decal.Origin.Y, decal.Origin.Z])
            a = np.array([decal.VectorAcross.X, decal.VectorAcross.Y, decal.VectorAcross.Z])
            u = np.array([decal.VectorUp.X, decal.VectorUp.Y, decal.VectorUp.Z])
            fn = np.cross(V[F[:, 1]] - V[F[:, 0]], V[F[:, 2]] - V[F[:, 0]])
            proj = np.cross(a, u)
            proj /= np.linalg.norm(proj)
            if (fn @ proj).sum() > 0 and np.abs(fn @ proj).sum() > 0.7 * np.linalg.norm(fn, axis=1).sum():
                tex = tex_by_id.get(str(decal.TextureInstanceId).lower())
                tp = tex_path(tex.FileName) if tex else None
                if tp:
                    UVd = np.c_[(V - o) @ a / (a @ a), (V - o) @ u / (u @ u)]
                    add('decal', {'key': 'decal:' + os.path.basename(tp), 'color': [1, 1, 1, 1], 'tex': tp,
                                  'kind': 'decal', 'name': os.path.basename(tp)}, V, F, N, UVd, None)
                    continue
        add(cat, mat, V, F, N, UV, C)

t0 = time.time()
I4 = np.eye(4)
for o in model.Objects:
    a = o.Attributes
    if a.IsInstanceDefinitionObject or not a.Visible or not layer_visible(a.LayerIndex):
        continue
    g = o.Geometry
    if not isinstance(g, (r.Mesh, r.Brep, r.Extrusion, r.InstanceReference)):
        continue
    bb = g.GetBoundingBox()
    cx, cy = (bb.Min.X + bb.Max.X) / 2, (bb.Min.Y + bb.Max.Y) / 2
    if not (REGION[0] < cx < REGION[1] and REGION[2] < cy < REGION[3]):
        continue
    emit(a, g, I4, None)
print(f'geometría extraída en {time.time()-t0:.0f}s; breps sin malla de render: {len(missing)}', sorted(set(missing)))

# ---------- escena glTF ----------
allV = np.concatenate([p[0] for b in buckets.values() for p in b['parts']])
lo, hi = allV.min(0), allV.max(0)
center = np.array([(lo[0] + hi[0]) / 2, (lo[1] + hi[1]) / 2, lo[2]])
# Rhino Z-up (mm) -> glTF Y-up: (x, y, z) -> (x, z, -y)
ROT = np.array([[1, 0, 0], [0, 0, 1], [0, -1, 0]], dtype=np.float64)

img_cache = {}
def img(path):
    if path not in img_cache:
        im = Image.open(path)
        img_cache[path] = im.convert('RGBA' if im.mode in ('RGBA', 'LA', 'P') else 'RGB')
    return img_cache[path]

PBR = {'metal': (0.7, 0.35), 'plastic': (0.0, 0.45), 'glass': (0.0, 0.05), 'paint': (0.0, 0.6),
       'plaster': (0.0, 0.9), 'decal': (0.0, 0.6)}
scene = trimesh.Scene()
report = []
for (cat, mkey), b in sorted(buckets.items(), key=lambda kv: kv[0]):
    mat = b['mat']
    Vs, Fs, Ns, UVs, Cs, off = [], [], [], [], [], 0
    has_uv = all(p[3] is not None for p in b['parts'])
    has_c = all(p[4] is not None for p in b['parts'])
    has_n = all(p[2] is not None for p in b['parts'])
    for V, F, N, UV, C in b['parts']:
        Vs.append(((V - center) @ ROT.T) * SCALE)
        Fs.append(F + off)
        off += len(V)
        if has_n: Ns.append(N @ ROT.T)
        if has_uv: UVs.append(UV)
        if has_c: Cs.append(C)
    V = np.concatenate(Vs); F = np.concatenate(Fs)
    metal, rough = PBR.get(mat['kind'], (0, 0.8))
    pm = trimesh.visual.material.PBRMaterial(
        name=(mat.get('name') or mat['key'])[:40], baseColorFactor=[int(round(c * 255)) for c in mat['color']],
        metallicFactor=metal, roughnessFactor=rough,
        alphaMode='BLEND' if mat['color'][3] < 1 else None,
        doubleSided=True,
        baseColorTexture=img(mat['tex']) if (mat['tex'] and has_uv) else None)
    if has_c:
        visual = trimesh.visual.ColorVisuals(vertex_colors=np.concatenate(Cs))
    else:
        visual = trimesh.visual.TextureVisuals(uv=np.concatenate(UVs) if (has_uv and mat['tex']) else None, material=pm)
    tm = trimesh.Trimesh(vertices=V, faces=F, vertex_normals=np.concatenate(Ns) if has_n else None,
                         visual=visual, process=False)
    if has_c:
        tm.visual.material = pm  # trimesh no lo usa en ColorVisuals; queda el default
    name = f'{cat}__{len(report):03d}'
    scene.add_geometry(tm, geom_name=name, node_name=name)
    report.append({'name': name, 'cat': cat, 'mat': mat.get('name') or mat['key'], 'faces': int(len(F)),
                   'uv': bool(has_uv and mat['tex']), 'vc': bool(has_c)})

glb = scene.export(file_type='glb', include_normals=True)
with open(os.path.join(out, 'raw.glb'), 'wb') as fh:
    fh.write(glb)
with open(os.path.join(out, 'report.json'), 'w') as fh:
    json.dump({'center_mm': center.tolist(), 'scale': SCALE, 'size_mm': (hi - lo).tolist(), 'meshes': report}, fh, indent=1)
print(f'raw.glb {len(glb)/1e6:.0f} MB, {sum(x["faces"] for x in report):,} caras, {len(report)} mallas')
