// ---------------- Spinner ----------------
function showSpinner() {
    document.getElementById('loadingSpinner').style.display = 'flex';
}

function hideSpinner() {
    document.getElementById('loadingSpinner').style.display = 'none';
}

// ---------------- Sort ----------------
let currentSort = {
  key: 'name',
  dir: 'asc'
};

function sortItems(arr) {
  const { key, dir } = currentSort;
  const factor = dir === 'asc' ? 1 : -1;

  return [...arr].sort((a, b) => {

    if (a.type === 'dir' && b.type !== 'dir') return -1;
    if (a.type !== 'dir' && b.type === 'dir') return 1;

    let A, B;

    switch (key) {
      case 'name':
        A = a.name; B = b.name;
        return A.localeCompare(B, undefined, { numeric: true, sensitivity: 'base' }) * factor;

      case 'type':
        A = a.type; B = b.type;
        return A.localeCompare(B) * factor;

      case 'size':
        return ((a.size || 0) - (b.size || 0)) * factor;

      case 'changed':
        return ((a.mtime || 0) - (b.mtime || 0)) * factor;
    }

    return 0;
  });
}

// ---------------- Chunk Size ----------------
const CHUNK_SIZE =
  Number(window.APP_CONFIG?.CHUNK_SIZE) || (2 * 1024 * 1024);

// ---------------- API ----------------
const API = (action, qs='') => `?api=1&action=${encodeURIComponent(action)}${qs ? '&'+qs : ''}`;
const elTree = document.getElementById('tree');
const elCrumbs = document.getElementById('crumbs');
const elPathInfo = document.getElementById('pathInfo');
const elListBody = document.querySelector('#list tbody');
const elListTable = document.getElementById('list');

// ---------------- Reload Explorer ----------------
async function reloadExplorerFull() {
  try {
    showSpinner();
    elStatus.textContent = 'Reloading…';
    await openDir(cwd);
    setTimeout(refreshRootSize, 50);
    showToast('Reloaded');
  } catch (e) {
    showToast('Reload failed');
  } finally {
    hideSpinner();
  }
}

// ---------------- List Sorting (Header Clicks) ----------------
document.querySelectorAll('#list thead th[data-sort]').forEach(th => {
  th.addEventListener('click', () => {
    const key = th.dataset.sort;

    if (currentSort.key === key) {
      currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
      currentSort.key = key;
      currentSort.dir = 'asc';
    }
    renderList();
    updateSortIcons();
  });
});

function updateSortIcons() {
  document.querySelectorAll('#list thead th[data-sort]').forEach(th => {
    const key = th.dataset.sort;
    const base = th.textContent.replace(/[▲▼]/g, '').trim();
    th.textContent = base;

    if (key === currentSort.key) {
      th.textContent += currentSort.dir === 'asc' ? ' ▲' : ' ▼';
    }
  });
}

// ---------------- Safari Fix: prevent text selection in the file table ----------------
elListTable.addEventListener('mousedown', (e) => {
  if (e.button !== 0) return;
  const tr = e.target.closest('tr');
  if (!tr) return;

  // Editor
  if (
    e.target.closest('input') ||
    e.target.closest('textarea') ||
    e.target.closest('[contenteditable]')
  ) return;

  if (e.shiftKey || e.metaKey || e.ctrlKey) {
    e.preventDefault();
  }
});

const elSearch = document.getElementById('search');
const elDrop = document.getElementById('dropzone');
const elBar = document.getElementById('bar');
const elStatus = document.getElementById('status');
const elSelTag = document.getElementById('selTag');
const elUploadTag = document.getElementById('uploadTag');
const fileInput = document.getElementById('fileInput');
const dirInput = document.getElementById('dirInput');

// ---------------- Browser Capability Detection ----------------
function supportsDirectoryDnD() {
  try {
    const dt = new DataTransfer();
    return (
      typeof dt.items !== 'undefined' &&
      typeof DataTransferItem !== 'undefined' &&
      'webkitGetAsEntry' in DataTransferItem.prototype
    );
  } catch {
    return false;
  }
}

const pvTitle = document.getElementById('pvTitle');
pvTitle.addEventListener('dblclick', () => {
  if (!currentPreview) return;
  openLightboxForItem(currentPreview);
});

const pvMeta  = document.getElementById('pvMeta');
const pvKind  = document.getElementById('pvKind');
const pvBody  = document.getElementById('pvBody');
pvBody.addEventListener('dblclick', () => {
  if (!currentPreview) return;
  openLightboxForItem(currentPreview);
});

const pvOpen = document.getElementById('pvOpen');
pvOpen.addEventListener('click', ()=>{
  if (!currentPreview) return;
  openLightboxForItem(currentPreview);
});

const editor  = document.getElementById('editor');
const toast = document.getElementById('toast');
const showToast = (msg, ms=2600) => {
  toast.textContent = msg;
  toast.classList.add('show');
  setTimeout(()=>toast.classList.remove('show'), ms);
};

const fmtSize = (n) => {
  n = Number(n||0);
  const u = ['B','KB','MB','GB','TB'];
  let i=0;
  while(n>=1024 && i<u.length-1){n/=1024;i++}
  return (i===0 ? n.toFixed(0) : n.toFixed(1))+' '+u[i];
};
const fmtTime = (ts) => {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleString('de-CH', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  });
};

let cwd = '';
let items = [];
let selected = new Set();
let lastClicked = null;
let currentPreview = null;
let hoverDest = null;
const expandedTreeNodes = new Set();

// ---------------- PATH HELPERS ----------------
function parentDir(path){
  if (!path) return '';
  const i = path.lastIndexOf('/');
  return i === -1 ? '' : path.slice(0, i);
}

function isSameOrChild(dest, src){
  if (!src) return false;
  return dest === src || dest.startsWith(src + '/');
}

function fixCwdAfterMove(paths, dest) {
  for (const p of paths) {
    if (cwd === p || cwd.startsWith(p + '/')) {
      cwd = dest;
      return;
    }
  }
}

// ---------------- HELPERS ----------------
async function apiJson(action, body=null, qs='') {
  const opt = body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)} : {};
  const r = await fetch(API(action, qs), {...opt, cache:'no-store'});
  const t = await r.text();
  let j;
  try { j = JSON.parse(t); } catch(e) { throw new Error(`Non-JSON (${r.status}): ${t.slice(0,180)}`); }
  if (!j.ok) {
    const err = j.error || 'Error';
    const e2 = new Error(err);
    e2.data = j; e2.status = r.status;
    throw e2;
  }
  return j;
}

// ---------------- Refresh Root Size ----------------
async function refreshRootSize() {
  const el = document.getElementById('rootSize');
  if (!el) return;
  try {
    const j = await apiJson('rootStats');
    el.textContent = '📦 Storage used: ' + j.formatted;
  } catch (e) {
    showToast('Root size refresh failed');
  }
}

// --- My Password ---
const btnMyPw = document.getElementById('btnMyPw');

btnMyPw?.addEventListener('click', async ()=>{
  try {
    const me = await apiJson('whoami');
    const user = me.user || '';
    if (!user) return showToast('No user found.', 3000);
    const p1 = prompt(`New Password for "${user}" (minimum 8 characters):`, '');
    if (p1 === null) return;
    if (String(p1).length < 8) return showToast('Password too short.', 3000);
    const p2 = prompt('Repeat Password:', '');
    if (p2 === null) return;
    if (p1 !== p2) return showToast('Passwords do not match.', 3000);
    await apiJson('userPw', { user, pass: p1, pass2: p2 });
    showToast('Password changed.');
  } catch(err){
    showToast('Password: ' + (err?.message || err), 4000);
  }
});

// --- Lightbox Navigation helpers ---
function lbMediaList() {
  return (items || []).filter(it =>
    it.type === 'file' && (isImageExt(it.name) || isVideoExt(it.name) || isPdf(it.name) || isAudioExt(it.name) || isTextExt(it.name)));
}

function lbIndexOf(list, item) {
  if (!item) return -1;
  return list.findIndex(x => x.path === item.path);
}

function lbGo(delta) {
  if (!lbItem) return;
  const list = lbMediaList();
  if (!list.length) return;
  let i = lbIndexOf(list, lbItem);
  if (i < 0) return;
  i = (i + delta) % list.length;
  if (i < 0) i += list.length;
  openLightboxForItem(list[i]);
}

// ---------------- Tree ----------------
function treeNodeEl(node, level=0) {
  const wrap = document.createElement('div');
  wrap.dataset.path = node.path;
  wrap.style.marginTop = '6px';
  const row = document.createElement('div');
  row.className = 'node' + (node.path === cwd ? ' active':'');
  row.draggable = true;
  row.dataset.path = node.path;
  const chev = document.createElement('span');
  chev.className = 'chev';
  chev.textContent = node.hasChildren ? '▸' : ' ';
  row.appendChild(chev);
  chev.addEventListener('click', async (e) => {
    e.stopPropagation();
    if (!node.hasChildren) return;
    const sub = wrap.querySelector(':scope > .indent');
    if (sub) {
      sub.remove();
      expandedTreeNodes.delete(node.path);
      chev.textContent = '▸';
      return;
    }
    expandedTreeNodes.add(node.path);
    chev.textContent = '▾';
    const children = await apiJson('treeChildren', { path: node.path });
    const indent = document.createElement('div');
    indent.className = 'indent';
    children.nodes.forEach(ch => indent.appendChild(treeNodeEl(ch, level + 1))
    );
    wrap.appendChild(indent);
});

  const name = document.createElement('span');
  name.className = 'folder';
  name.textContent = node.name;
  row.appendChild(name);

  // click open
  row.addEventListener('click', async (e) => {
    e.stopPropagation();
    await openDir(node.path);
  });

  // expand on double click
  row.addEventListener('dblclick', async (e) => {
    e.stopPropagation();
    if (!node.hasChildren) return;
    const sub = wrap.querySelector(':scope > .indent');
    if (sub) { sub.remove(); chev.textContent = '▸'; return; }
    chev.textContent = '▾';
    const children = await apiJson('treeChildren', {path: node.path});
    const indent = document.createElement('div');
    indent.className = 'indent';
    children.nodes.forEach(ch => indent.appendChild(treeNodeEl(ch, level+1)));
    wrap.appendChild(indent);
  });

  // Drag & drop move targets
  row.addEventListener('dragover', (e) => { e.preventDefault(); row.style.borderColor = 'rgba(90,167,255,.7)'; });
  row.addEventListener('dragleave', () => { row.style.borderColor = 'transparent'; });
  row.addEventListener('drop', async (e) => {
  e.preventDefault();
  row.style.borderColor = 'transparent';
  const src = e.dataTransfer.getData('text/x-explorer-path');
  if (!src) return;
  const paths = Array.from(selected.size ? selected : [src]);
  const isCopy = e.metaKey || e.ctrlKey || e.altKey;
  const action = isCopy ? 'copy' : 'move';
  try {
    await apiJson(action, { paths, dest: node.path });
    fixCwdAfterMove(paths, node.path);
    rebuildTreeStateAfterMove(node.path);
    await refreshAll();
    showToast(isCopy ? 'Copied.' : 'Moved.');
  } catch (err) {
    showToast((isCopy ? 'Copy' : 'Move') + ' failed: ' + (err?.message || err), 3500);
  }
});

// dragging
row.addEventListener('dragstart', (e) => {
  e.dataTransfer.setData('text/x-explorer-path', node.path);
  e.dataTransfer.effectAllowed = 'move';
});

wrap.appendChild(row);

// restore expanded state
if (expandedTreeNodes.has(node.path) && node.hasChildren) {
  (async () => {
    chev.textContent = '▾';
    const children = await apiJson('treeChildren', { path: node.path });
    const indent = document.createElement('div');
    indent.className = 'indent';
    children.nodes.forEach(ch => indent.appendChild(treeNodeEl(ch, level + 1)));
    wrap.appendChild(indent);
  })();
}

return wrap;
}

async function loadTree() {
  elTree.textContent = 'Loading…';
  try {
    const j = await apiJson('treeRoot');
    elTree.innerHTML = '';
    if (window.IS_ADMIN) {
      const root = document.createElement('div');
      root.className = 'node' + (cwd === '' ? ' active' : '');
      root.dataset.path = '';
      const chev = document.createElement('span');
      chev.className = 'chev';
      chev.textContent = ' ';
      const name = document.createElement('span');
      name.className = 'folder';
      name.textContent = '🏠 Root';
      root.appendChild(chev);
      root.appendChild(name);
      root.addEventListener('click', () => openDir(''));
      root.addEventListener('dragover', (e) => {
        e.preventDefault();
        root.style.borderColor = 'rgba(90,167,255,.7)';
        root.style.borderWidth = '1px';
        root.style.borderStyle = 'solid';
        root.style.borderRadius = '12px';
      });
      root.addEventListener('dragleave', () => {
        root.style.border = '';
      });
      root.addEventListener('drop', async (e) => {
        e.preventDefault();
        root.style.border = '';
        const src = e.dataTransfer.getData('text/x-explorer-path');
        if (!src) return;
        const paths = Array.from(selected.size ? selected : [src]);
        const isCopy = e.metaKey || e.ctrlKey || e.altKey;
        const action = isCopy ? 'copy' : 'move';
        try {
          await apiJson(action, { paths, dest: '' });
          fixCwdAfterMove(paths, '');
          rebuildTreeStateAfterMove('');
          await refreshAll();
          showToast(isCopy ? 'Copied.' : 'Moved.');
        } catch (err) {
          showToast(
            (isCopy ? 'Copy' : 'Move') + ' failed: ' + (err?.message || err),
            3500
          );
        }
      });
      elTree.appendChild(root);
    }
    if (!j.nodes || !j.nodes.length) {
      const msg = document.createElement('div');
      msg.style.marginTop = '15px';
      msg.style.marginLeft = '37px';
      msg.textContent = 'No folders available.';
      elTree.appendChild(msg);
      return;
    }
    j.nodes.forEach(n => elTree.appendChild(treeNodeEl(n)));
  } catch (err) {
    elTree.textContent = 'Tree error: ' + err.message;
  }
}

// ---------------- List ----------------
function renderCrumbs() {
  elCrumbs.innerHTML = '';
  const parts = cwd ? cwd.split('/') : [];
  const make = (label, path) => {
    const c = document.createElement('div');
    c.className = 'crumb';
    c.textContent = label;
    c.addEventListener('click', ()=>openDir(path));
    return c;
  };
  if (window.IS_ADMIN) {
    elCrumbs.appendChild(make('Root', ''));
  }
  let acc = '';
  for (const p of parts) {
    acc = acc ? acc + '/' + p : p;
    elCrumbs.appendChild(make(p, acc));
  }
  elPathInfo.textContent = cwd ? '/' + cwd : (window.IS_ADMIN ? '/' : '');
}

function setSelectedTag() {
  elSelTag.textContent = `${selected.size} selected`;
}

function updateHeaderButtons(){
  const has = selected.size > 0;
  const single = selected.size === 1;
  const it = single ? items.find(x => x.path === [...selected][0]) : null;
  const isZip = it && it.type === 'file' && /\.zip$/i.test(it.name);
  const map = {
    btnDelete: has,
    btnZip: has,
    btnDownload: has,
    btnCopy: has,
    btnMove: has,
    btnRename: single,
    btnShare: single,
    btnUnzip: isZip
  };
  for (const id in map) {
    const b = document.getElementById(id);
    if (b) b.disabled = !map[id];
  }
}

function renderList() {
  elListBody.innerHTML = '';
  const q = (elSearch.value || '').toLowerCase().trim();
  let filtered = items.filter(it => !q || it.name.toLowerCase().includes(q));
  filtered = sortItems(filtered);
  for (const it of filtered) {
    const tr = document.createElement('tr');
    tr.dataset.path = it.path;
    if (selected.has(it.path)) tr.classList.add('sel');
    tr.innerHTML = `
      <td>${it.type === 'dir' ? '📁 ' : '📄 '}${escapeHtml(it.name)}</td>
      <td>${it.type}</td>
      <td>${it.type === 'dir' ? '—' : fmtSize(it.size)}</td>
      <td>${fmtTime(it.mtime)}</td>
    `;
    tr.addEventListener('click', (e) => {
    const isMeta = e.metaKey || e.ctrlKey;
    const isShift = e.shiftKey;
    const q = (elSearch.value || '').toLowerCase().trim();
    let visible = items.filter(it => !q || it.name.toLowerCase().includes(q));
    visible = sortItems(visible);
    const idx = visible.findIndex(x => x.path === it.path);
    if (isShift && lastClicked) {
      const lastIdx = visible.findIndex(x => x.path === lastClicked);
      if (lastIdx !== -1 && idx !== -1) {
        selected.clear();
        const [from, to] = lastIdx < idx ? [lastIdx, idx] : [idx, lastIdx];
        for (let i = from; i <= to; i++) {
          selected.add(visible[i].path);
        }
      }
    }
    else if (isMeta) {
      if (selected.has(it.path)) selected.delete(it.path);
      else selected.add(it.path);
      lastClicked = it.path;
    }
    else {
      selected.clear();
      selected.add(it.path);
      lastClicked = it.path;
    }
    renderList();
    updateSortIcons();
    setSelectedTag();
    updateHeaderButtons();
    if (selected.size === 1) openPreview(it);
    else clearPreview();
  });


tr.addEventListener('dblclick', () => {
  if (it.type === 'dir') openDir(it.path);
});

tr.draggable = true;
tr.addEventListener('dragstart', (e) => {
  e.dataTransfer.setData('text/x-explorer-path', it.path);
  e.dataTransfer.effectAllowed = 'move';
});

// --- DROP TARGET ---
if (it.type === 'dir') {
  tr.addEventListener('dragover', (e) => {
    e.preventDefault();
    tr.style.outline = '2px solid rgba(90,167,255,.55)';
    tr.style.outlineOffset = '-2px';
  });

  tr.addEventListener('dragleave', () => {
    tr.style.outline = '';
    tr.style.outlineOffset = '';
  });

  tr.addEventListener('drop', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    tr.style.outline = '';
    tr.style.outlineOffset = '';
    const src = e.dataTransfer.getData('text/x-explorer-path');
    const paths = Array.from(selected.size ? selected : (src ? [src] : []));
    if (!paths.length) return;
    try {
      const isCopy = e.metaKey || e.ctrlKey || e.altKey;
      const action = isCopy ? 'copy' : 'move';
      await apiJson(action, { paths, dest: it.path });
      rebuildTreeStateAfterMove(it.path);
      await new Promise(r => setTimeout(r, 100));
      await refreshAll();
      showToast(isCopy ? 'Copied.' : 'Moved.');
    } catch (err) {
      showToast('Move failed: ' + (err?.message || err), 4000);
    }
  });
}

// --- Row Folder drop target ---
if (it.type === 'dir') {
  tr.addEventListener('dragenter', (e) => {
    e.preventDefault();
    hoverDest = it.path;
    tr.classList.add('dropTarget');
  });

  tr.addEventListener('dragover', (e) => {
    e.preventDefault();
    hoverDest = it.path;
  });

  tr.addEventListener('dragleave', (e) => {
    if (e.relatedTarget && tr.contains(e.relatedTarget)) return;
    tr.classList.remove('dropTarget');
  });

  tr.addEventListener('drop', (e) => {
    e.preventDefault();
    tr.classList.remove('dropTarget');
  });
}
  elListBody.appendChild(tr);
  }
}

// --- DROP TARGET ---
function setTableDropOutline(on){
  elListTable.style.outline = on ? '2px dashed rgba(90,167,255,.45)' : '';
  elListTable.style.outlineOffset = on ? '6px' : '';
  elListTable.style.borderRadius = on ? '14px' : '';
}

elListTable.addEventListener('dragover', (e) => {
  e.preventDefault();
  if (!hoverDest) hoverDest = cwd;
  setTableDropOutline(hoverDest === cwd);
});

elListTable.addEventListener('dragleave', (e) => {
  if (e.relatedTarget && elListTable.contains(e.relatedTarget)) return;
  setTableDropOutline(false);
  hoverDest = null;
  elListTable.querySelectorAll('tr.dropTarget').forEach(tr => tr.classList.remove('dropTarget'));
});

elListTable.addEventListener('drop', async (e) => {
  e.preventDefault();
  setTableDropOutline(false);
  const dest = (hoverDest !== null && hoverDest !== undefined) ? hoverDest : cwd;
  hoverDest = null;
  elListTable.querySelectorAll('tr.dropTarget').forEach(tr => tr.classList.remove('dropTarget'));
  const src = e.dataTransfer.getData('text/x-explorer-path');
  const paths = Array.from(selected.size ? selected : (src ? [src] : []));
  if (!paths.length) return;
  try {
    const isCopy = e.metaKey || e.ctrlKey || e.altKey;
    const action = isCopy ? 'copy' : 'move';
    await apiJson(action, { paths, dest });
    fixCwdAfterMove(paths, dest);
    rebuildTreeStateAfterMove(dest);
    await new Promise(r => setTimeout(r, 100));
    await refreshAll();
    showToast(isCopy ? 'Copied.' : (dest === cwd ? 'Moved.' : 'Moved to folder.'));
  } catch (err) {
    showToast((isCopy ? 'Copy' : 'Move') + ' failed: ' + (err?.message || err), 4000);
  }
});

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

async function openDir(path) {
  cwd = path || '';
  selected.clear();
  setSelectedTag();
  updateHeaderButtons();
  clearPreview();
  renderCrumbs();
  elStatus.textContent = 'Loading…';
  try{
    const j = await apiJson('list', null, 'path=' + encodeURIComponent(cwd));
    items = j.items || [];
    renderList();
    updateSortIcons();
    elStatus.textContent = `Ready. ${items.length} Elements`;
    loadTree();
  } catch(err) {
    elStatus.textContent = 'List error: ' + err.message;
  }
}

function expandToCwd() {
  let p = cwd;
  while (p) {
    expandedTreeNodes.add(p);
    p = parentDir(p);
  }
}

async function refreshAll() {
  expandedTreeNodes.clear();
  expandToCwd();
  await openDir(cwd);
  if (rootSize) {
    setTimeout(refreshRootSize, 100);
  }
}

function rebuildTreeStateAfterMove(dest) {
  expandedTreeNodes.clear();
  expandedTreeNodes.add('');
  if (dest) {
    let p = dest;
    while (p) {
      expandedTreeNodes.add(p);
      p = parentDir(p);
    }
  }
}

// ---------------- Preview / Editor ----------------
const btnSave = document.getElementById('btnSave');

function setEditorVisible(on){
  editor.style.display = on ? 'block' : 'none';
  if (btnSave) btnSave.style.display = on ? 'inline-flex' : 'none';
}

function clearPreview() {
  currentPreview = null;
  pvTitle.textContent = 'Nothing selected';
  pvMeta.textContent = '';
  pvKind.textContent = '—';
  pvOpen.style.display = 'none';
  pvBody.innerHTML = `<div class="small muted">Select a file to preview.</div>`;
  editor.value = '';

  setEditorVisible(false);
}

function isTextExt(name){
  return /\.(txt|md|json|xml|yml|yaml|ini|log|csv|tsv|php|js|ts|css|html?|py|go|java|c|cpp|h|hpp|sh|zsh|env|sql|xmp)$/i.test(name);
}

function isImageExt(name){ return /\.(png|jpe?g|gif|webp|svg|dng|heic|tiff)$/i.test(name); }
function isAudioExt(name){ return /\.(mp3|wav|ogg|m4a|aac)$/i.test(name); }
function isVideoExt(name){ return /\.(mp4|webm|mov|m4v)$/i.test(name); }
function isPdf(name){ return /\.pdf$/i.test(name); }

function setEditorReadonly(on){
  editor.readOnly = !!on;
  editor.classList.toggle('readonly', !!on);
}

async function openPreview(it) {
  currentPreview = it;
  setEditorVisible(false);
  editor.value = '';
  setEditorReadonly(true);
  pvTitle.textContent = it.name;
  pvMeta.textContent = `${it.type} • ${it.type==='file'?fmtSize(it.size):'—'} • ${fmtTime(it.mtime)}`;
  if (it.type === 'dir') {
    pvKind.textContent = 'Folder';
    pvBody.innerHTML = `<div class="small muted">Folder selected. You can download it as a ZIP.</div>`;
    editor.value = '';
    setEditorVisible(false);
    return;
  }
  const p = encodeURIComponent(it.path);
  const url = API('preview', 'path='+p+'&inline=1');
  const canLightbox = isImageExt(it.name) || isVideoExt(it.name) || isAudioExt(it.name) || isPdf(it.name) || isTextExt(it.name);
  pvOpen.style.display = canLightbox ? 'inline-flex' : 'none';
  if (isImageExt(it.name)) {
    pvKind.textContent = 'Image';
    pvBody.innerHTML = `<img src="${url}" style="max-width:100%; border-radius:12px">`;
    editor.value = '';
    return;
  }
  if (isPdf(it.name)) {
    pvKind.textContent = 'PDF';
    pvBody.innerHTML = `<iframe src="${url}" style="width:100%; height:48vh; border:0; border-radius:12px; background:#fff"></iframe>`;
    editor.value = '';
    return;
  }
  if (isAudioExt(it.name)) {
    pvKind.textContent = 'Audio';
    pvBody.innerHTML = `<audio controls src="${url}" style="width:100%"></audio>`;
    editor.value = '';
    return;
  }
  if (isVideoExt(it.name)) {
    pvKind.textContent = 'Video';
    pvBody.innerHTML = `<video controls src="${url}" style="width:100%; border-radius:12px"></video>`;
    editor.value = '';
    return;
  }
  if (isTextExt(it.name)) {
    pvKind.textContent = 'Text';
    pvBody.innerHTML = `<div class="small muted">Text file – loaded in the editor.</div>`;
    setEditorVisible(true);
    setEditorReadonly(!!window.IS_SHARE_VIEW);
    try {
      const j = await apiJson('readText', { path: it.path });
      editor.value = j.text || '';
    } catch (err) {
      editor.value = '';
      pvBody.innerHTML =
        `<div class="small muted">Cannot read: ${escapeHtml(err.message)}</div>`;
      setEditorVisible(false);
    }
    return;
  }
  pvKind.textContent = 'File';
  pvBody.innerHTML = `<div class="small muted">No preview available. Use download.</div>`;
  editor.value = '';
}

// --- LIGHTBOX ---
const lb = document.getElementById('lb');
const lbBody = document.getElementById('lbBody');
const lbTitle = document.getElementById('lbTitle');
const lbKind = document.getElementById('lbKind');
const lbClose = document.getElementById('lbClose');
const lbOpenBtn = document.getElementById('lbOpen');
const lbPrev = document.getElementById('lbPrev');
const lbNext = document.getElementById('lbNext');
lbPrev?.addEventListener('click', () => lbGo(-1));
lbNext?.addEventListener('click', () => lbGo(+1));
const lbEditorWrap = document.getElementById('lbEditorWrap');
const lbEditor = document.getElementById('lbEditor');
const lbSave = document.getElementById('lbSave');
let lbItem = null;
let lbStartX = null;
lbBody.addEventListener('pointerdown', (e) => {
  lbStartX = e.clientX;
});
lbBody.addEventListener('pointerup', (e) => {
  if (lbStartX === null) return;
  const dx = e.clientX - lbStartX;
  lbStartX = null;
  if (Math.abs(dx) > 60) {
    lbGo(dx < 0 ? +1 : -1);
  }
});

function lbShow(){ lb.style.display = 'block'; }

function lbHide(){
  lb.style.display = 'none';
  lbItem = null;
  lbTitle.textContent = 'Preview';
  if (lbKind) lbKind.textContent = '—';
  lbBody.innerHTML = '';
  lbBody.style.display = '';
  lbBody.classList.remove('pdf');
  lbEditor.value = '';
  lbEditorWrap.style.display = 'none';
  lbSave.style.display = 'none';
}

lbClose.addEventListener('click', lbHide);
lb.querySelector('.lb-backdrop')?.addEventListener('click', lbHide);
document.addEventListener('keydown', (e) => {
  if (lb.style.display !== 'block') return;
  const ae = document.activeElement;
  const inEditor = ae === lbEditor || ae === editor || (ae && ae.tagName === 'INPUT');
  if (inEditor && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) return;
  if (e.key === 'Escape') {
    lbHide();
    return;
  }
  if (e.key === 'ArrowRight') { e.preventDefault(); lbGo(+1); }
  if (e.key === 'ArrowLeft')  { e.preventDefault(); lbGo(-1); }
});

document.addEventListener('keydown', (e) => {
  const isSelectAll =
    (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a';
  if (!isSelectAll) return;
  if (lb.style.display === 'block') return;
  e.preventDefault();
  const ae = document.activeElement;
  if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA')) return;
  const q = (elSearch.value || '').toLowerCase().trim();
  selected.clear();
  for (const it of items) {
    if (!q || it.name.toLowerCase().includes(q)) {
      selected.add(it.path);
    }
  }
  renderList();
  setSelectedTag();
  updateHeaderButtons();
  if (selected.size !== 1) {
    clearPreview();
  }
});

lbOpenBtn.addEventListener('click', ()=>{
  if (!lbItem || lbItem.type !== 'file') return;
  const url = API('preview', 'path=' + encodeURIComponent(lbItem.path) + '&inline=1');
  window.open(url, '_blank', 'noopener');
});

async function openLightboxForItem(it){
  if (window.IS_SHARE_VIEW) {
    lbSave.style.display = 'none';
    lbEditorWrap.style.display = 'none';
  }
  if (!it) return;
  lbItem = it;
  lbBody.classList.remove('pdf');
  lbTitle.textContent = it.name || '—';
  lbBody.innerHTML = '';
  lbBody.style.display = '';
  lbBody.classList.remove('pdf');
  lbEditorWrap.style.display = 'none';
  lbSave.style.display = 'none';
  lbEditor.value = '';
  if (it.type === 'dir') {
    if (lbKind) lbKind.textContent = 'Folder';
    lbBody.innerHTML = `<div class="small muted">Folder can be downloaded as a ZIP.</div>`;
    lbShow();
    return;
  }
  const url = API('preview', 'path=' + encodeURIComponent(it.path) + '&inline=1');
  const name = it.name || '';
  if (isPdf(name)) {
    lbKind.textContent = 'PDF';
    lbBody.classList.add('pdf');
    lbBody.innerHTML = `<iframe src="${url}"></iframe>`;
    lbShow();
    return;
  }
  if (isTextExt(name)) {
    if (lbKind) lbKind.textContent = 'Text';
    lbBody.style.display = 'none';
    lbEditorWrap.style.display = 'flex';
    lbSave.style.display = window.IS_SHARE_VIEW ? 'none' : 'inline-flex';
    setEditorReadonly(!!window.IS_SHARE_VIEW);
    try {
      const j = await apiJson('readText', { path: it.path });
      lbEditor.value = j.text || '';
    } catch (err) {
      lbEditor.value = '';
      showToast('Cannot read: ' + err.message, 3500);
    }
    lbShow();
    return;
  }
  if (isImageExt(name)) {
    if (lbKind) lbKind.textContent = 'Image';
    lbBody.innerHTML = `<img src="${url}" alt="${escapeHtml(name)}" style="max-width:100%; max-height:100%; border-radius:14px">`;
    lbShow();
    return;
  }
  if (isVideoExt(name)) {
    if (lbKind) lbKind.textContent = 'Video';
    lbBody.innerHTML = `<video controls playsinline src="${url}" style="width:100%; max-height:100%; border-radius:14px"></video>`;
    lbShow();
    return;
  }
  if (isAudioExt(name)) {
    if (lbKind) lbKind.textContent = 'Audio';
    lbBody.innerHTML = `<audio controls src="${url}" style="width:100%"></audio>`;
    lbShow();
    return;
  }
  if (lbKind) lbKind.textContent = 'File';
  lbBody.innerHTML = `<div class="small muted">No preview available. Use download.</div>`;
  lbShow();
}

lbSave.addEventListener('click', async ()=>{
  if (window.IS_SHARE_VIEW) {
    showToast('Read-only preview.');
    return;
  }
  if (!lbItem || lbItem.type !== 'file') return;
  if (!isTextExt(lbItem.name)) return;
  try{
    await apiJson('saveText', { path: lbItem.path, text: lbEditor.value });
    showToast('Saved.');
    if (currentPreview && currentPreview.path === lbItem.path) {
      editor.value = lbEditor.value;
    }
    await refreshAll();
  } catch(err){
    showToast('Save: ' + err.message, 3500);
  }
});

// ---------- Folder Picker (Move/Copy) ----------
const fp = document.getElementById('folderPicker');
const fpTree = document.getElementById('fpTree');
const fpTitle = document.getElementById('fpTitle');
const fpSelected = document.getElementById('fpSelected');
const fpOk = document.getElementById('fpOk');
fpOk.addEventListener('click', () => {
  if (!fpResolve) return;
  const r = fpResolve;
  const dest = fpDest;
  fpResolve = null;
  fpHide();
  r(dest);
});

const fpClose = document.getElementById('fpClose');
fpClose.addEventListener('click', () => {
  if (!fpResolve) return;
  const r = fpResolve;
  fpResolve = null;
  fpHide();
  r(null);
});

const fpCancel = document.getElementById('fpCancel');
fpCancel.addEventListener('click', () => {
  if (!fpResolve) return;
  const r = fpResolve;
  fpResolve = null;
  fpHide();
  r(null);
});

fp.addEventListener('click', (e)=>{
  if (e.target === fp) {
    if (!fpResolve) return;
    const r = fpResolve;
    fpResolve = null;
    fpHide();
    r(null);
  }
});

let fpDest = '';
let fpResolve = null;

function fpShow(){
  fp.style.display = 'grid';
}

function fpHide(resetDest = true){
  fp.style.display = 'none';
  fpTree.innerHTML = '';
  fpResolve = null;
  if (resetDest) {
    fpDest = '';
    fpSelected.textContent = 'Destination: /';
  }
}

function fpSetDest(rel){
  fpDest = rel || '';
  fpSelected.textContent = 'Destination: ' + (fpDest ? '/'+fpDest : '/');
  fpTree.querySelectorAll('.fp-node').forEach(n=>{
    n.classList.toggle('sel', (n.dataset.path || '') === fpDest);
  });
}

function fpNodeEl(node){
  const wrap = document.createElement('div');
  const row = document.createElement('div');
  row.className = 'fp-node';
  row.dataset.path = node.path || '';
  const chev = document.createElement('span');
  chev.className = 'fp-chev';
  chev.textContent = node.hasChildren ? '▸' : ' ';
  row.appendChild(chev);
  const label = document.createElement('div');
  label.style.fontWeight = '650';
  label.textContent = node.name;
  row.appendChild(label);
  row.addEventListener('click', (e)=>{
    e.stopPropagation();
    fpSetDest(node.path || '');
  });
  async function toggleExpand(){
    if (!node.hasChildren) return;
    const existing = wrap.querySelector(':scope > .fp-indent');
    if (existing) {
      existing.remove();
      chev.textContent = '▸';
      return;
    }
    chev.textContent = '▾';
    const j = await apiJson('treeChildren', { path: node.path });
    const indent = document.createElement('div');
    indent.className = 'fp-indent';
    (j.nodes || []).forEach(ch => indent.appendChild(fpNodeEl(ch)));
    wrap.appendChild(indent);
  }
  row.addEventListener('dblclick', (e)=>{
    e.stopPropagation();
    toggleExpand().catch(()=>{});
  });
  chev.addEventListener('click', (e)=>{
    e.stopPropagation();
    toggleExpand().catch(()=>{});
  });
  wrap.appendChild(row);
  return wrap;
}

async function openFolderPicker({ title='Select destination folder', initial='' } = {}){
  fpTitle.textContent = title;
  fpTree.innerHTML = 'Loading folders…';
  fpSetDest(initial || '');
  let rootRow = null;
  if (window.IS_ADMIN) {
    rootRow = document.createElement('div');
    rootRow.className = 'fp-node';
    rootRow.dataset.path = '';
    rootRow.innerHTML =
      `<span class="fp-chev"> </span><div style="font-weight:650">🏠 Root</div>`;
    rootRow.addEventListener('click', ()=> fpSetDest(''));
  }
  try {
    const j = await apiJson('treeRoot');
    fpTree.innerHTML = '';
    if (rootRow) {
      fpTree.appendChild(rootRow);
    }
    (j.nodes || []).forEach(n =>
      fpTree.appendChild(fpNodeEl(n))
    );
    if (!window.IS_ADMIN && !initial && j.nodes?.[0]) {
      fpSetDest(j.nodes[0].path);
    } else {
      fpSetDest(initial || '');
    }
  } catch(err){
    fpTree.innerHTML =
      `<div class="small muted">Error: ${escapeHtml(err.message)}</div>`;
  }
  fpShow();
  return new Promise(resolve => { fpResolve = resolve; });
}

// ---------------- RENAME/OVERRIDE ----------------
function chooseOverwriteRename({ title, message }) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.background = 'rgba(0,0,0,.6)';
    overlay.style.zIndex = '30000';
    overlay.style.display = 'grid';
    overlay.style.placeItems = 'center';
    const box = document.createElement('div');
    box.style.background = 'rgba(17,24,36,.98)';
    box.style.border = '1px solid rgba(34,48,71,.9)';
    box.style.borderRadius = '16px';
    box.style.padding = '16px';
    box.style.width = '420px';
    box.style.maxWidth = '92vw';
    box.style.boxShadow = '0 18px 60px rgba(0,0,0,.55)';
    box.innerHTML = `
      <div style="font-weight:700; margin-bottom:6px">${title}</div>
      <div class="small muted" style="margin-bottom:14px; white-space:pre-line">
        ${message}
      </div>
      <div class="row" style="justify-content:flex-end; gap:10px">
        <button class="btn" data-act="cancel">Cancel</button>
        <button class="btn" data-act="rename">Rename</button>
        <button class="btn danger" data-act="overwrite">Overwrite</button>
      </div>
    `;
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    const done = (val) => {
      overlay.remove();
      resolve(val);
    };
    box.querySelector('[data-act="overwrite"]').onclick = () => done('overwrite');
    box.querySelector('[data-act="rename"]').onclick = () => done('rename');
    box.querySelector('[data-act="cancel"]').onclick = () => done(null);
    overlay.addEventListener('click', (e)=>{
      if (e.target === overlay) done(null);
    });
    document.addEventListener('keydown', function esc(e){
      if (e.key === 'Escape') {
        document.removeEventListener('keydown', esc);
        done(null);
      }
    });
  });
}

// ---------------- UPLOAD ----------------
function waitIfJobPaused(job){
  if (!job.paused) return Promise.resolve();
  return new Promise((resolve)=>{
    job._resume = () => { job._resume = null; resolve(); };
  });
}

const uploadsEl = document.getElementById('uploads');
const jobs = new Map();
const MAX_PARALLEL = 4;
let running = 0;

function makeJobId(){
  return 'u_' + Math.random().toString(16).slice(2) + Date.now().toString(16);
}

function jobRender(job){
  const pct = job.totalBytes ? (job.sentBytes/job.totalBytes)*100 : 0;
  job.el.querySelector('.u-name').textContent = job.displayName;
  job.el.querySelector('.u-meta').textContent =
    `${fmtSize(job.sentBytes)} / ${fmtSize(job.totalBytes)} • ${pct.toFixed(1)}% • ${fmtSize(job.speed||0)}/s • ${job.paused ? 'paused' : job.state}`;
  job.el.querySelector('.u-bar > div').style.width = Math.max(0, Math.min(100, pct)) + '%';
  const btnPause = job.el.querySelector('[data-act="pause"]');
  const btnCancel = job.el.querySelector('[data-act="cancel"]');
  btnPause.textContent = job.paused ? '▶ Continue' : '⏸ Pause';
  btnPause.disabled = (job.state === 'done' || job.state === 'error' || job.state === 'canceled');
  btnCancel.disabled = (job.state === 'done' || job.state === 'error' || job.state === 'canceled');
  renderGlobalUploadButtons();
}

function jobCreateCard(job){
  const el = document.createElement('div');
  el.className = 'u-card';
  el.innerHTML = `
    <div class="u-top">
      <div style="min-width:0">
        <div class="u-name"></div>
        <div class="u-meta"></div>
      </div>
      <span class="tag">${job.kind}</span>
    </div>
    <div class="u-bar"><div class="bar"></div></div>
    <div class="u-actions">
      <button class="btn" type="button" data-act="pause">⏸ Pause</button>
      <button class="btn danger" type="button" data-act="cancel">✖ Cancel</button>
    </div>
  `;
  const btnPause  = el.querySelector('[data-act="pause"]');
  const btnCancel = el.querySelector('[data-act="cancel"]');
  btnPause.addEventListener('pointerdown', (e)=>{
    e.preventDefault();
    e.stopPropagation();
    const wasPaused = job.paused;
    job.paused = !job.paused;
    if (!wasPaused && job.paused) {
      try { job.controller?.abort(); } catch {}
    }
    if (wasPaused && !job.paused && job._resume) job._resume();
    if (job.state === 'queued' || job.state === 'paused') {
      job.state = job.paused ? 'paused' : 'queued';
    }
    jobRender(job);
    showToast(job.paused ? 'Upload paused.' : 'Upload resumed.');
    schedule();
  });
  btnCancel.addEventListener('pointerdown', (e)=>{
    e.preventDefault();
    e.stopPropagation();
    cancelJob(job);
  });
  job.el = el;
  uploadsEl.prepend(el);
  jobRender(job);
}

const AUTO_REMOVE_DONE_MS = 500;
const AUTO_REMOVE_CANCELED_MS = 250;
const AUTO_REMOVE_ERROR_MS = 2500;

function removeJobUI(job, delayMs = 0){
  if (!job?.el) return;
  if (!jobs.has(job.id)) return;
  if (job._autoRemoveTimer) return;
  job._autoRemoveTimer = setTimeout(()=>{
    if (!jobs.has(job.id)) return;
    job.el.classList.add('removing');
    setTimeout(()=>{
      try { job.el.remove(); } catch {}
      jobs.delete(job.id);
      renderGlobalUploadButtons();
    }, 260);
  }, delayMs);
}

function cancelJob(job){
  if (!job) return;
  const isActive = !['done','error','canceled'].includes(job.state);
  if (isActive) {
    const label = job.displayName || job.file?.name || 'Upload';
    if (!confirm(`Do you really want to cancel this upload?\n\n${label}`)) return;
  }
  job.aborted = true;
  try { job.controller?.abort(); } catch {}
  if (job.uploadId) {
    apiJson('uploadAbort', { uploadId: job.uploadId }).catch(()=>{});
  }
  job.state = 'canceled';
  jobRender(job);
  removeJobUI(job, AUTO_REMOVE_CANCELED_MS);
}

function cancelJobSilent(job){
  if (!job) return;
  job.aborted = true;
  try { job.controller?.abort(); } catch {}
  if (job.uploadId) {
    apiJson('uploadAbort', { uploadId: job.uploadId }).catch(()=>{});
  }
  job.state = 'canceled';
  jobRender(job);
  removeJobUI(job, AUTO_REMOVE_CANCELED_MS);
}

async function uploadJob(job){
  job.state = 'init';
  jobRender(job);
  const init = await apiJson('uploadInit', {
    destDir: cwd,
    fileName: job.file.name,
    fileSize: job.file.size,
    lastModified: String(job.file.lastModified || ''),
    relativePath: job.relativePath || '',
    policy: 'ask'
  });
  job.uploadId = init.uploadId;
  job.state = 'checking';
  jobRender(job);
  const st = await apiJson('uploadStatus', { uploadId: job.uploadId });
  const have = new Set((st.chunks||[]).map(Number));
  const totalChunks = Math.ceil(job.file.size / CHUNK_SIZE) || 1;
  job.totalBytes = job.file.size;
  job.sentBytes = 0;
  for (const idx of have) {
    const start = idx * CHUNK_SIZE;
    const end = Math.min(job.file.size, start + CHUNK_SIZE);
    job.sentBytes += (end - start);
  }
  const t0 = performance.now();
  let lastTick = t0;
  let lastSent = job.sentBytes;
  job.state = 'uploading';
  jobRender(job);
  for (let index=0; index<totalChunks; index++){
    if (job.aborted) throw new Error('canceled');
    await waitIfJobPaused(job);
    if (job.aborted) throw new Error('canceled');
    if (have.has(index)) {
      const now = performance.now();
      if (now - lastTick > 400) {
        const dt = (now-lastTick)/1000;
        job.speed = (job.sentBytes - lastSent)/dt;
        lastTick = now; lastSent = job.sentBytes;
        jobRender(job);
      }
      continue;
    }
    const start = index*CHUNK_SIZE;
    const end = Math.min(job.file.size, start+CHUNK_SIZE);
    const blob = job.file.slice(start, end);
    const buildFd = () => {
    const fd = new FormData();
    fd.append('uploadId', job.uploadId);
    fd.append('index', String(index));
    fd.append('total', String(totalChunks));
    fd.append('chunk', blob, 'chunk.part');
    return fd;
  };

  const MAX_RETRIES = 5;
  let attempts = 0;
  while (true) {
    if (job.aborted) throw new Error('canceled');
    await waitIfJobPaused(job);
    if (job.aborted) throw new Error('canceled');
    if (job.paused) continue;
    job.controller = new AbortController();
    try {
      const r = await fetch(
        API(
          'uploadChunk',
          `uploadId=${encodeURIComponent(job.uploadId)}&index=${index}&total=${totalChunks}`
        ),
        {
          method: 'POST',
          body: buildFd(),
          cache: 'no-store',
          signal: job.controller.signal
        }
      );
      const t = await r.text();
      let j;
      try {
        j = JSON.parse(t);
      } catch {
        throw new Error(`Chunk non-JSON (HTTP ${r.status}): ${t.slice(0, 180)}`);
      }
      if (!j.ok) {
        throw new Error(j.error || 'Chunk failed');
      }
      break;
    } catch (err) {
      if (err?.name === 'AbortError' && job.paused) {
        await waitIfJobPaused(job);
        continue;
      }
      attempts++;
      if (attempts >= MAX_RETRIES) {
        throw new Error(`Chunk ${index} failed after ${attempts} retries`);
      }
      await new Promise(r => setTimeout(r, 600 * attempts));
      continue;
    } finally {
      job.controller = null;
    }
  }
  job.sentBytes += (end - start);
  const now = performance.now();
  const dt = (now - lastTick) / 1000;
  if (dt > 0.25) {
    job.speed = (job.sentBytes - lastSent) / dt;
    lastTick = now;
    lastSent = job.sentBytes;
  }
  jobRender(job);
}
  job.state = 'finalize';
  jobRender(job);
  try {
    await apiJson('uploadFinalize', { uploadId: job.uploadId, policy: 'ask' });
  } catch (err) {
    if (err.status === 409 && err.data && err.data.needsChoice) {
      const policy = await chooseOverwriteRename({
        title: 'File already exists',
        message: `The file\n\n${job.file.name}\n\nalready exists.`
      });
      if (!policy) {
        cancelJob(job);
        throw new Error('Upload canceled');
      }
      await apiJson('uploadFinalize', {
        uploadId: job.uploadId,
        policy
      });
    } else {
      throw err;
    }
  }
  job.state = 'done';
  job.speed = 0;
  jobRender(job);
  removeJobUI(job, AUTO_REMOVE_DONE_MS);
}

function schedule(){
  if (running >= MAX_PARALLEL) return;
  const next = Array.from(jobs.values()).find(j =>
    ['queued'].includes(j.state) && !j.paused && !j.aborted
  );
  if (!next) return;
  running++;
  next.state = 'starting';
  jobRender(next);
  uploadJob(next)
    .catch(async (err)=>{
      if (String(err?.message || err) === 'canceled') {
        next.state = 'canceled';
      } else {
        next.state = 'error';
        next.error = err?.message || String(err);
        showToast(`Upload error (${next.displayName}): ${next.error}`, 5000);
      }
      try {
        if (next.uploadId) await apiJson('uploadAbort', { uploadId: next.uploadId });
      } catch {}
      jobRender(next);
      removeJobUI(job, AUTO_REMOVE_DONE_MS);
    })
    .finally(async ()=>{
      running--;
      rebuildTreeStateAfterMove(cwd);
      await new Promise(r => setTimeout(r, 100));
      await refreshAll();
      schedule();
    });
  schedule();
}

function enqueueFiles(fileList, relativeFromInput=false){
  for (const f of fileList) {
    let relDir = '';
    if (relativeFromInput) {
      const rp = f.webkitRelativePath || '';
      relDir = rp ? rp.split('/').slice(0,-1).join('/') : '';
    } else {
      relDir = f._relativeDir || '';
    }
    const job = {
      id: makeJobId(),
      kind: relDir ? 'Folder' : 'File',
      file: f,
      relativePath: relDir,
      displayName: cwd ? (relDir ? `${cwd}/${relDir}/${f.name}` : `${cwd}/${f.name}`) : (relDir ? `${relDir}/${f.name}` : f.name),
      uploadId: null,
      state: 'queued',
      paused: false,
      aborted: false,
      controller: null,
      totalBytes: f.size,
      sentBytes: 0,
      speed: 0,
      el: null,
      _resume: null
    };
    jobs.set(job.id, job);
    jobCreateCard(job);
    document.getElementById('btnPause').style.display = 'inline-flex';
    document.getElementById('btnCancel').style.display = 'inline-flex';
  }
  renderGlobalUploadButtons();
  schedule();
}

function renderGlobalUploadButtons(){
  const btnPauseAll  = document.getElementById('btnPause');
  const btnCancelAll = document.getElementById('btnCancel');
  const active = Array.from(jobs.values()).filter(j => !['done','error','canceled'].includes(j.state));
  const show = active.length > 0;
  btnPauseAll.style.display  = show ? 'inline-flex' : 'none';
  btnCancelAll.style.display = show ? 'inline-flex' : 'none';
  if (!show) return;
  const anyNotPaused = active.some(j => !j.paused);
  btnPauseAll.textContent = anyNotPaused ? '⏸ Pause' : '▶ Continue';
}

// Drag & drop files/folders
async function traverseEntry(entry, parentDir = '', dirs = new Set()) {
  return new Promise((resolve) => {
    if (entry.isFile) {
      entry.file(file => {
        file._relativeDir = parentDir;
        resolve([file]);
      }, () => resolve([]));
      return;
    }
    if (entry.isDirectory) {
      const dirPath = parentDir ? `${parentDir}/${entry.name}` : entry.name;
      dirs.add(dirPath);
      const reader = entry.createReader();
      const files = [];
      const read = () => {
        reader.readEntries(async entries => {
          if (!entries.length) return resolve(files);
          for (const e of entries) {
            const childFiles = await traverseEntry(e, dirPath, dirs);
            files.push(...childFiles);
          }
          read();
        }, () => resolve(files));
      };
      read();
      return;
    }
    resolve([]);
  });
}

elDrop.addEventListener('dragover', (e)=>{ e.preventDefault(); elDrop.classList.add('drag'); });
elDrop.addEventListener('dragleave', (e)=>{
  if (e.relatedTarget && elDrop.contains(e.relatedTarget)) return;
  elDrop.classList.remove('drag');
});
elDrop.addEventListener('drop', async (e) => {
  e.preventDefault();
  elDrop.classList.remove('drag');
  const dt = e.dataTransfer;
  if (!dt) return;
  const dtItems = Array.from(dt.items || []);
  const hasFiles = dt.files && dt.files.length;
  if (supportsDirectoryDnD() && dtItems.length) {
    const entries = dtItems
      .map(it => it.webkitGetAsEntry?.())
      .filter(Boolean);
  if (!entries.length) return;
const dirs = new Set();
const results = await Promise.all(
  entries.map(e => traverseEntry(e, '', dirs))
);
const files = results.flat();
for (const dir of Array.from(dirs).sort((a, b) => a.length - b.length)) {
  const fullDir = cwd ? (dir ? `${cwd}/${dir}` : cwd) : dir;
  await apiJson('mkdir', {
    parent: fullDir.includes('/') ? fullDir.slice(0, fullDir.lastIndexOf('/')) : '',
    name: fullDir.split('/').pop()
  });
}
if (files.length) {
  enqueueFiles(files, false);
}
return;
}
  if (!supportsDirectoryDnD()) {
    showToast('Safari: Please select a folder…');
    dirInput.click();
    return;
  }
  if (hasFiles) {
    enqueueFiles(dt.files, false);
  }
});

// Buttons
document.getElementById('btnUpload').addEventListener('click', (e)=>{
  if (e.shiftKey || e.altKey) {
    dirInput.click();
  } else {
    fileInput.click();
  }
});

const btnPauseAll  = document.getElementById('btnPause');
const btnCancelAll = document.getElementById('btnCancel');

btnPauseAll.addEventListener('pointerdown', (e)=>{
  e.preventDefault();
  e.stopPropagation();
  const anyRunningOrQueued = Array.from(jobs.values()).some(j =>
    ['queued','starting','uploading','checking','init','finalize','paused'].includes(j.state)
  );
  if (!anyRunningOrQueued) return showToast('No active upload.');
  const shouldPause = Array.from(jobs.values()).some(j =>
    !j.paused && !['done','error','canceled'].includes(j.state)
  );
  for (const j of jobs.values()) {
    if (['done','error','canceled'].includes(j.state)) continue;
    const wasPaused = j.paused;
    j.paused = shouldPause;
    if (j.state === 'queued' || j.state === 'paused') {
      j.state = j.paused ? 'paused' : 'queued';
    }
    if (wasPaused && !j.paused && j._resume) j._resume();
    jobRender(j);
  }
  showToast(shouldPause ? 'All uploads paused.' : 'All uploads resumed.');
  schedule();
  renderGlobalUploadButtons();
});

btnCancelAll.addEventListener('pointerdown', async (e)=>{
  e.preventDefault();
  e.stopPropagation();
  const actives = Array.from(jobs.values()).filter(j => !['done','error','canceled'].includes(j.state));
  if (!actives.length) return showToast('No active upload.');
  if (!confirm(`Really cancel ALL uploads? (${actives.length})`)) return;
  for (const j of actives) {
  cancelJobSilent(j);
}
  showToast('All uploads canceled.');
  schedule();
  renderGlobalUploadButtons();
  await refreshAll();
});

fileInput.addEventListener('change', ()=> {
  if (fileInput.files && fileInput.files.length) enqueueFiles(fileInput.files, false);
  fileInput.value = '';
});

dirInput.addEventListener('change', () => {
  const files = Array.from(dirInput.files || []);
  if (!files.length) return;
  for (const f of files) {
    const rp = f.webkitRelativePath || '';
    const parts = rp.split('/');
    parts.pop(); // Dateiname weg
    f._relativeDir = parts.join('/');
  }
  enqueueFiles(files, false);
  dirInput.value = '';
});

document.getElementById('btnNewFolder').addEventListener('click', async ()=>{
  const name = prompt('Folder name:', 'New Folder');
  if (!name) return;
  try{ await apiJson('mkdir', {parent: cwd, name});
    rebuildTreeStateAfterMove(cwd || '');
    await new Promise(r => setTimeout(r, 100));
    await refreshAll();
    selected.clear();
    setSelectedTag();
    updateHeaderButtons();
    showToast('ZIP extracted.');
      }
  catch(err){ showToast('Mkdir: ' + err.message, 3500); }
});

document.getElementById('btnRename')?.addEventListener('click', async ()=>{
  if (selected.size !== 1) return showToast('Select exactly one item.');
  const p = Array.from(selected)[0];
  const it = items.find(x => x.path === p);
  if (!it) return;
  const nn = prompt('Rename:', it.name);
  if (!nn) return;
  try {
    await apiJson('rename', { path: it.path, newName: nn });
    await refreshAll();
    showToast('Renamed.');
  } catch (err) {
    showToast('Rename: ' + err.message, 4000);
  }
});

document.getElementById('btnCopy')?.addEventListener('click', async ()=>{
  if (!selected.size) return showToast('Nothing selected.');
  const dest = await openFolderPicker({ title:'📄 Copy to…', initial: cwd || '' });
  if (dest === null) return;
  await apiJson('copy', { paths: Array.from(selected), dest });
  await refreshAll();
  showToast('Copied.');
});

document.getElementById('btnMove')?.addEventListener('click', async ()=>{
  if (!selected.size) return showToast('Nothing selected.');
  const dest = await openFolderPicker({ title:'📦 Move to…', initial: cwd || '' });
  if (dest === null) return;
  await apiJson('move', { paths: Array.from(selected), dest });
  await refreshAll();
  showToast('Moved.');
});

document.getElementById('btnDelete').addEventListener('click', async ()=>{
  if (!selected.size) return showToast('Nothing selected.');
  if (!confirm(`Really delete? (${selected.size})`)) return;
  try{ await apiJson('delete', {paths: Array.from(selected)});
    selected.clear();
    setSelectedTag();
    updateHeaderButtons();
    rebuildTreeStateAfterMove(parentDir(cwd));
    await new Promise(r => setTimeout(r, 100));
    await refreshAll();
  }
  catch(err){ showToast('Delete: ' + err.message, 3500); }
});

document.getElementById('btnZip').addEventListener('click', async () => {
  if (!selected.size) return showToast('Nothing selected.');
  try {
    showSpinner();
    elStatus.textContent = 'Creating ZIP…';
    const j = await apiJson('zipCreate', {
      cwd,
      paths: Array.from(selected)
    });
    hideSpinner();
    elStatus.textContent = 'Ready.';
    await refreshAll();
    selected.clear();
    setSelectedTag();
    updateHeaderButtons();
    showToast('ZIP created.');
  } catch (err) {
    hideSpinner();
    elStatus.textContent = 'Ready.';
    showToast('ZIP: ' + err.message, 4000);
  }
});

document.getElementById('btnUnzip').addEventListener('click', async () => {
  if (selected.size !== 1) {
    return showToast('Please select exactly one ZIP file.');
  }
  const [p] = Array.from(selected);
  const it = items.find(x => x.path === p);
  if (!it || it.type !== 'file' || !/\.zip$/i.test(it.name)) {
    return showToast('Selected item is not a ZIP file.');
  }
  showSpinner();
  elStatus.textContent = 'Extracting ZIP…';
  try {
    await apiJson('unzip', {
      path: it.path,
      dest: cwd || '',
      policy: 'ask'
    });
  } catch (err) {
    if (err.status === 409 && err.data?.needsChoice) {
      const policy = await chooseOverwriteRename({
        title: 'File already exists',
        message: `Already exists during extraction:\n\n${err.data.path}`
      });
      if (!policy) {
        showToast('Extraction canceled.');
        return;
      }
      try {
        await apiJson('unzip', {
          path: it.path,
          dest: cwd || '',
          policy
        });
      } catch (e2) {
        showToast('Unzip failed: ' + (e2?.message || e2), 4000);
        return;
      }
    } else {
      showToast('Unzip failed: ' + (err?.message || err), 4000);
      return;
    }
  } finally {
    hideSpinner();
    elStatus.textContent = 'Ready.';
  }
  rebuildTreeStateAfterMove(cwd || '');
  await new Promise(r => setTimeout(r, 100));
  await refreshAll();
  selected.clear();
  setSelectedTag();
  updateHeaderButtons();
  showToast('ZIP extracted.');
});

document.getElementById('btnShare').addEventListener('click', async ()=>{
  if (selected.size !== 1) {
    return showToast('Please select exactly 1 item to share.', 3500);
  }
  let token = null;
  try {
    const j = await apiJson('shareCreate', { paths: Array.from(selected) });
    const url = j.url;
    token = j.token;
    let copied = false;
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(url);
        copied = true;
      } catch {}
    }
    if (!copied) {
      const ta = document.createElement('textarea');
      ta.value = url;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try {
        copied = document.execCommand('copy');
      } catch {}
      ta.remove();
    }
    if (!copied) {
      const res = prompt('Copy Share-Link:', url);
      if (res === null && token) {
        await apiJson('shareRevoke', { token });
        showToast('Share-Link creation canceled.');
        return;
      }
    }
    showToast('🔗 Share-Link created.');
  } catch (err) {
    if (token) {
      try { await apiJson('shareRevoke', { token }); } catch {}
    }
    showToast('Share: ' + err.message, 3500);
  }
});

const sharePanel = document.getElementById('sharePanel');
const shareListBox = document.getElementById('shareListBox');

document.getElementById('btnCloseShares').addEventListener('click', ()=>{
  sharePanel.style.display = 'none';
  shareListBox.innerHTML = '';
});

document.getElementById('btnShares').addEventListener('click', async ()=>{
  try {
    const j = await apiJson('shareList');
    const shares = j.shares || [];
    shareListBox.innerHTML = '';
    if (!shares.length) {
      shareListBox.innerHTML = '<div class="small muted">No active Share-Links.</div>';
    }
    for (const s of shares) {
      const row = document.createElement('div');
      row.className = 'row';
      row.style.justifyContent = 'space-between';
      row.style.alignItems = 'center';
      row.style.border = '1px solid rgba(34,48,71,.7)';
      row.style.borderRadius = '12px';
      row.style.padding = '10px';
      const info = document.createElement('div');
      info.innerHTML = `
        <div style="font-weight:600">
          ${s.type === 'dir' ? '📁' : '📄'} ${s.path || '/'}
          ${s.exists ? '' : '<span class="tag danger">MISSING</span>'}
        </div>
        <div class="small" style="margin-top: 10px;">
          <a href="${s.url}" target="_blank">${s.url}</a>
        </div>
      `;
      const actions = document.createElement('div');
      actions.className = 'row';
      const btnCopy = document.createElement('button');
      btnCopy.className = 'btn';
      btnCopy.textContent = '📋 Copy';
      btnCopy.onclick = async ()=>{
        try {
          await navigator.clipboard.writeText(s.url);
          showToast('Link copied.');
        } catch {
          prompt('Copy link:', s.url);
        }
      };
      const btnDel = document.createElement('button');
      btnDel.className = 'btn danger';
      btnDel.textContent = '🗑 Delete';
      btnDel.onclick = async ()=>{
        if (!confirm(`Really delete share?\n\n${s.path || '/'}`)) return;
        await apiJson('shareRevoke', { token: s.token });
        row.remove();
        showToast('Share-Link deleted.');
      };
      actions.appendChild(btnCopy);
      actions.appendChild(btnDel);
      row.appendChild(info);
      row.appendChild(actions);
      shareListBox.appendChild(row);
    }
    sharePanel.style.display = 'grid';
  } catch(err) {
    showToast('Shares: ' + err.message, 4000);
  }
});

// ---------------- Users Panel ----------------
const userPanel = document.getElementById('userPanel');
const userListBox = document.getElementById('userListBox');
const btnUsers = document.getElementById('btnUsers');
const btnCloseUsers = document.getElementById('btnCloseUsers');
const btnUsersRefresh = document.getElementById('btnUsersRefresh');
// --- ACL cleanup state ---
let userPanelDirty = false;
const uNewUser = document.getElementById('uNewUser');
const uNewPass = document.getElementById('uNewPass');
const uNewPass2 = document.getElementById('uNewPass2');
const btnUserAdd = document.getElementById('btnUserAdd');
const aclExpandedNodes = new Set();
const userFolderOpen = new Map();

function usersOpen(){
  userPanelDirty = false;
  userPanel.style.display = 'grid';
}

async function usersClose(){
  userPanel.style.display = 'none';
  userListBox.innerHTML = '';
  if (window.IS_ADMIN && userPanelDirty) {
    userPanelDirty = false;
    try {
      const j = await apiJson('aclCleanup');
      if (j.usersAffected > 0) {
        showToast(`ACL cleanup: ${j.usersAffected} user(s) updated`, 3500);
      }
    } catch (e) {
      console.warn('ACL cleanup failed', e);
    }
  }
}

btnCloseUsers?.addEventListener('click', usersClose);
userPanel?.addEventListener('click', (e)=>{ if (e.target === userPanel) usersClose(); });

async function renderAclNode(container, user, node, acl, level = 0) {
  if (acl[node.path]) {
    let p = node.path;
    aclExpandedNodes.add(p);
    while (p.includes('/')) {
      p = p.substring(0, p.lastIndexOf('/'));
      aclExpandedNodes.add(p);
    }
  }
  const wrap = document.createElement('div');
  const row = document.createElement('div');
  row.className = 'row';
  row.style.alignItems = 'center';
  row.style.gap = '6px';
  row.style.marginLeft = (level * 16) + 'px';
  row.style.marginTop = '10px';
  row.style.fontSize = '14px';

  // Toggle
  const toggle = document.createElement('span');
  toggle.textContent = node.hasChildren ? (aclExpandedNodes.has(node.path) ? '▾' : '▸') : ' ';
  toggle.style.cursor = 'pointer';
  toggle.style.width = '16px';

  // Checkbox
  const cb = document.createElement('input');
  cb.type = 'checkbox';
  cb.checked = !!acl[node.path];

  // Label
  const label = document.createElement('span');
  label.textContent = node.name;
  label.style.flex = '1';

  // Read / Write
  const sel = document.createElement('select');
  sel.innerHTML = `
    <option value="read">⇅ Read</option>
    <option value="write">⇅ Write</option>
  `;
  sel.value = acl[node.path] || 'read';
  sel.disabled = !cb.checked;

  // ----- ACL logic -----
  cb.addEventListener('change', async () => {
    userPanelDirty = true;
    if (cb.checked) {
      sel.disabled = false;
      await apiJson('userFolderSet', {
        user: user.user,
        path: node.path,
        mode: sel.value
      });
    } else {
      sel.disabled = true;
      await apiJson('userFolderRemove', {
        user: user.user,
        path: node.path
      });
    }
  });

  sel.addEventListener('change', async () => {
    userPanelDirty = true;
    await apiJson('userFolderSet', {
      user: user.user,
      path: node.path,
      mode: sel.value
    });
  });

// ----- Expand / Collapse -----
toggle.addEventListener('click', async () => {
  if (!node.hasChildren) return;
  const isOpen = aclExpandedNodes.has(node.path);
  if (isOpen) {
    aclExpandedNodes.delete(node.path);
    wrap.querySelector('.acl-children')?.remove();
    toggle.textContent = '▸';
    return;
  }
  aclExpandedNodes.add(node.path);
  toggle.textContent = '▾';
  if (!wrap.querySelector('.acl-children')) {
    const j = await apiJson('treeChildren', { path: node.path });
    const children = document.createElement('div');
    children.className = 'acl-children';
    for (const ch of j.nodes || []) {
      await renderAclNode(children, user, ch, acl, level + 1);
    }
    wrap.appendChild(children);
  }
});
  row.append(toggle, cb, label, sel);
  wrap.appendChild(row);
  container.appendChild(wrap);

// Restore open state
  if (node.hasChildren && aclExpandedNodes.has(node.path)) {
    toggle.textContent = '▾';
    if (!wrap.querySelector('.acl-children')) {
      const j = await apiJson('treeChildren', { path: node.path });
      const children = document.createElement('div');
      children.className = 'acl-children';
      for (const ch of j.nodes || []) {
        await renderAclNode(children, user, ch, acl, level + 1);
      }
      wrap.appendChild(children);
    }
  }
}

async function renderUserFolders(container, user) {
  container.innerHTML = '<div class="small muted">Loading folders…</div>';
  const acl = user.folders || {};
  aclExpandedNodes.clear();
  for (const path of Object.keys(acl)) {
    let p = path;
    aclExpandedNodes.add(p);
    while (p.includes('/')) {
      p = p.substring(0, p.lastIndexOf('/'));
      aclExpandedNodes.add(p);
    }
  }
  const root = await apiJson('treeRoot');
  container.innerHTML = '';
  for (const n of root.nodes || []) {
    await renderAclNode(container, user, n, acl);
  }
}

async function renderUsers(){
  userListBox.innerHTML = 'Loading…';
  const j = await apiJson('userList');
  const users = j.users || [];
  const me = j.me || '';
  userListBox.innerHTML = '';

  if (!users.length) {
    userListBox.innerHTML = '<div class="small muted">No users available.</div>';
    return;
  }

  for (const u of users) {
    const row = document.createElement('div');
    row.className = 'row';
    row.style.justifyContent = 'space-between';
    row.style.alignItems = 'center';
    row.style.border = '1px solid rgba(34,48,71,.7)';
    row.style.borderRadius = '12px';
    row.style.padding = '10px';

    const left = document.createElement('div');
    left.innerHTML = `
      <div style="font-weight:650">
        👤 ${escapeHtml(u.user)}
        ${u.user === me ? '<span class="tag">You</span>' : ''}
      </div>
      <div class="small muted" style="margin-top: 10px;">Created: ${u.created ? fmtTime(u.created) : '—'}</div>
    `;

    // --- Folder toggle (non-admin only)
    if (u.role !== 'admin') {
      const btnToggleFolders = document.createElement('button');
      btnToggleFolders.className = 'btn small';
      btnToggleFolders.textContent = '📁 Show folder permissions';
      btnToggleFolders.style.marginTop = '10px';
      const foldersBox = document.createElement('div');
      foldersBox.style.padding = '10px';
      foldersBox.style.display = 'none';
      foldersBox.innerHTML = '<div class="small muted">Folders</div>';
      btnToggleFolders.onclick = async () => {
        const isOpen = userFolderOpen.get(u.user) === true;
        if (isOpen) {
          foldersBox.style.display = 'none';
          btnToggleFolders.textContent = '📁 Show folder permissions';
          userFolderOpen.set(u.user, false);
          return;
        }
        foldersBox.style.display = 'block';
        btnToggleFolders.textContent = '📂 Hide folder permissions';
        if (!foldersBox.dataset.loaded) {
          foldersBox.innerHTML = '<div class="small muted">Loading…</div>';
          await renderUserFolders(foldersBox, u);
          foldersBox.dataset.loaded = '1';
        }
        userFolderOpen.set(u.user, true);
      };
      left.appendChild(btnToggleFolders);
      left.appendChild(foldersBox);
    }
    const actions = document.createElement('div');
    actions.className = 'row';
    const btnPw = document.createElement('button');
    btnPw.className = 'btn';
    btnPw.textContent = '🔑 Password';
    btnPw.onclick = async () => {
      const p1 = prompt(`New password for "${u.user}" (minimum 8 characters):`, '');
      if (!p1 || p1.length < 8) return showToast('Password too short.', 3000);
      const p2 = prompt('Repeat Password:', '');
      if (p1 !== p2) return showToast('Passwords do not match.', 3000);
      await apiJson('userPw', { user: u.user, pass: p1, pass2: p2 });
      showToast('Password changed.');
    };
    const btnDel = document.createElement('button');
    btnDel.className = 'btn danger';
    btnDel.textContent = '🗑 Delete';
    btnDel.onclick = async () => {
      if (!confirm(`Really delete user?\n\n${u.user}`)) return;
      try {
        await apiJson('userDelete', { user: u.user });
        await renderUsers();
        showToast('User deleted.');
      } catch (err) {
        showToast(err.message || 'Delete failed.', 4000);
      }
    };
    if (u.role === 'admin') {
      btnDel.disabled = true;
      btnDel.title = 'Admin cannot be deleted';
    }
    actions.append(btnPw, btnDel);
    row.append(left, actions);
    userListBox.appendChild(row);
  }
}
btnUsers?.addEventListener('click', async ()=>{
  try{
    usersOpen();
    await renderUsers();
  } catch(err){
    showToast('Users: ' + err.message, 4000);
  }
});

btnUsersRefresh?.addEventListener('click', ()=>renderUsers().catch(()=>{}));

btnUserAdd?.addEventListener('click', async ()=>{
  const user = (uNewUser.value || '').trim();
  const pass = String(uNewPass.value || '');
  const pass2 = String(uNewPass2.value || '');
  try{
    await apiJson('userAdd', { user, pass, pass2 });
    uNewUser.value = '';
    uNewPass.value = '';
    uNewPass2.value = '';
    showToast('User created.');
    await renderUsers();
  } catch(err){
    showToast('UserAdd: ' + err.message, 4000);
  }
});

// ---------------- Actions ----------------
document.getElementById('btnDownload').addEventListener('click', async () => {
  if (!selected.size) return showToast('Nothing selected.');
  try {
    const paths = Array.from(selected);
    const it = paths.length === 1
      ? items.find(x => x.path === paths[0])
      : null;
    if (it && it.type === 'file') {
      showSpinner();
      startDownload(
        API('preview', 'path=' + encodeURIComponent(it.path) + '&inline=0')
      );
      return;
    }
    await createZipAndDownload(paths);
  } catch (err) {
    hideSpinner();
    elStatus.textContent = 'Ready.';
    showToast('Download: ' + (err?.message || err), 4000);
  }
});

async function createZipAndDownload(paths) {
  showSpinner();
  elStatus.textContent = 'Creating ZIP…';
  const j = await apiJson('download', {
    cwd,
    paths
  });
  startDownload(`?action=download&dl=${encodeURIComponent(j.token)}`);
}

function startDownload(url) {
  elStatus.textContent = 'Download starting…';
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener';
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  setTimeout(() => {
    hideSpinner();
    elStatus.textContent = 'Ready.';
    a.remove();
  }, 1200);
}

document.getElementById('btnSave').addEventListener('click', async ()=>{
  if (window.IS_SHARE_VIEW) {
    showToast('Read-only preview.');
    return;
  }
  if (!currentPreview || currentPreview.type !== 'file') return showToast('No file selected.');
  if (!isTextExt(currentPreview.name)) return showToast('Only text files can be saved.');
  try{
    await apiJson('saveText', {path: currentPreview.path, text: editor.value});
    await refreshAll();
    showToast('Saved.');
  } catch(err){
    showToast('Save: ' + err.message, 3500);
  }
});

document.getElementById('btnRefreshTree').addEventListener('click', reloadExplorerFull);
elSearch.addEventListener('input', renderList);
document.addEventListener('keydown', async (e)=>{
  if (e.key === 'F2' && selected.size === 1) {
    const p = Array.from(selected)[0];
    const it = items.find(x=>x.path===p);
    if (!it) return;
    const nn = prompt('Rename:', it.name);
    if (!nn) return;
    try{ await apiJson('rename', {path: it.path, newName: nn}); await refreshAll(); }
    catch(err){ showToast('Rename: '+err.message, 3500); }
  }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c' && selected.size) {
    showToast('Copy: Select the destination folder in the tree and drop it there.', 3500);
  }
});

const ctx = document.getElementById('ctxMenu');
let ctxItem = null;

function hideCtx(){
  ctx.style.display = 'none';
  ctxItem = null;
}

function resetCtxActions() {
  ctx.querySelectorAll('button[data-act]').forEach(btn => {
    btn.style.display = 'none';
  });
}

function openContextForItem(it, x, y) {
  ctxItem = it;
  ensureContextSelection(it);
  resetCtxActions();
  if (selected.size === 1) {
    ctx.querySelector('[data-act="share"]').style.display  = 'block';
    ctx.querySelector('[data-act="rename"]').style.display = 'block';
  }
  if (it.type === 'dir' && selected.size === 1) {
    ctx.querySelector('[data-act="open"]').style.display = 'block';
  }
  if (selected.size === 1 && it.type === 'file' && /\.zip$/i.test(it.name)) {
    ctx.querySelector('[data-act="unzip"]').style.display = 'block';
  }
  if (selected.size >= 1) {
    ctx.querySelector('[data-act="download"]').style.display = 'block';
    ctx.querySelector('[data-act="copy"]').style.display = 'block';
    ctx.querySelector('[data-act="move"]').style.display = 'block';
    ctx.querySelector('[data-act="delete"]').style.display = 'block';
    ctx.querySelector('[data-act="zip"]').style.display = 'block';
}
  placeCtx(x, y);
}

function placeCtx(x,y){
  ctx.style.display = 'block';
  const pad = 8;
  const rect = ctx.getBoundingClientRect();
  const vw = window.innerWidth, vh = window.innerHeight;
  let nx = x, ny = y;
  if (nx + rect.width + pad > vw) nx = vw - rect.width - pad;
  if (ny + rect.height + pad > vh) ny = vh - rect.height - pad;
  if (nx < pad) nx = pad;
  if (ny < pad) ny = pad;
  ctx.style.left = nx + 'px';
  ctx.style.top  = ny + 'px';
}

function ensureContextSelection(item){
  if (selected.has(item.path)) return;
  selected.clear();
  selected.add(item.path);
  lastClicked = item.path;
  renderList();
  setSelectedTag();
  updateHeaderButtons();
}

document.addEventListener('click', (e)=>{
  if (ctx.style.display === 'block' && !ctx.contains(e.target)) hideCtx();
});

document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape') hideCtx();
});

window.addEventListener('resize', hideCtx);
window.addEventListener('scroll', hideCtx, true);

document.getElementById('list').addEventListener('contextmenu', (e)=>{
  const tr = e.target.closest('tr');
  if (!tr) return;
  e.preventDefault();

  const it = items.find(x => x.path === tr.dataset.path);
  if (!it) return;

  openContextForItem(it, e.clientX, e.clientY);
});

let longPressTimer = null;
let lpTargetTr = null;
const LONG_PRESS_MS = 500;

document.getElementById('list').addEventListener('pointerdown', (e) => {
  if (e.pointerType !== 'touch') return;

  const tr = e.target.closest('tr');
  if (!tr) return;

  const it = items.find(x => x.path === tr.dataset.path);
  if (!it) return;

  lpTargetTr = tr;
  tr.draggable = false;
  e.preventDefault();

  longPressTimer = setTimeout(() => {
    if (navigator.vibrate) navigator.vibrate(30);
    openContextForItem(it, e.clientX, e.clientY);
  }, LONG_PRESS_MS);
});

document.getElementById('list').addEventListener('pointerup', () => {
  clearTimeout(longPressTimer);
  longPressTimer = null;
  if (lpTargetTr) {
    lpTargetTr.draggable = true;
    lpTargetTr = null;
  }
});

document.getElementById('list').addEventListener('pointermove', () => {
  if (!longPressTimer) return;

  clearTimeout(longPressTimer);
  longPressTimer = null;

  if (lpTargetTr) {
    lpTargetTr.draggable = true;
    lpTargetTr = null;
  }
});

ctx.addEventListener('click', async (e)=>{
  const btn = e.target.closest('button[data-act]');
  if (!btn) return;
  const act = btn.dataset.act;
  const fn = ACTIONS[act];
  if (fn) {
    hideCtx();
    fn();
    return;
  }
  const item = ctxItem;
  if (!item) return;
  try {
    if (act === 'open') {
      hideCtx();
      if (item.type === 'dir') await openDir(item.path);
      else await openPreview(item);
      return;
    }

    if (act === 'download') {
      hideCtx();
      if (!selected.size) return;
      try {
        await createZipAndDownload(Array.from(selected));
      } catch (err) {
        hideSpinner();
        elStatus.textContent = 'Ready.';
        showToast('Download: ' + err.message, 4000);
      }
      return;
    }

    if (act === 'rename') {
      hideCtx();
      if (selected.size !== 1) {
        showToast('Please select exactly 1 item to rename.');
        return;
      }
      const [onlyPath] = Array.from(selected);
      const item = items.find(x => x.path === onlyPath);
      if (!item) return;
      const oldName = item.name;
      const oldPath = item.path;
      const nn = (prompt('New name:', oldName) || '').trim();
      if (!nn) return;
      try {
        await apiJson('rename', { path: oldPath, newName: nn });
        await refreshAll();
        showToast('Renamed.');
      } catch (err) {
        showToast('Rename: ' + (err?.message || err), 4000);
      }
      return;
    }

    if (act === 'move') {
      hideCtx();
      if (!selected.size) return showToast('Nothing selected.');
      const dest = await openFolderPicker({ title:'📦 Move to…', initial: cwd || '' });
      if (dest === null) return;
      const paths = Array.from(selected);
      const invalid = paths.some(p => {
        const it = items.find(x => x.path === p);
        if (!it) return false;
        if (it.type === 'file') {
          return parentDir(p) === dest;
        }
        if (it.type === 'dir') {
          return isSameOrChild(dest, p);
        }
        return false;
      });
      if (invalid) {
        showToast('Source and destination are the same.');
        return;
      }
      await apiJson('move', { paths, dest });
      fixCwdAfterMove(paths, dest);
      rebuildTreeStateAfterMove(dest);
      await new Promise(r => setTimeout(r, 100));
      await refreshAll();
      showToast('Moved.');
      return;
    }

    if (act === 'copy') {
      hideCtx();
      if (!selected.size) return showToast('Nothing selected.');
      const dest = await openFolderPicker({ title:'📄 Copy to…', initial: cwd || '' });
      if (dest === null) return;
      const paths = Array.from(selected);
      for (const p of paths) {
        const it = items.find(x => x.path === p);
        if (it?.type === 'dir' && isSameOrChild(dest, p)) {
          showToast('Cannot copy a folder into itself or its subfolder.');
          return;
        }
      }
      await apiJson('copy', { paths, dest });
      fixCwdAfterMove(paths, dest);
      rebuildTreeStateAfterMove(dest);
      await new Promise(r => setTimeout(r, 100));
      await refreshAll();
      showToast('Copied.');
      return;
    }

    if (act === 'delete') {
      hideCtx();
      if (!confirm(`Really delete? (${selected.size})`)) return;
      const paths = Array.from(selected);
      await apiJson('delete', { paths });
      selected.clear();
      setSelectedTag();
      updateHeaderButtons();
      fixCwdAfterMove(paths, parentDir(cwd));
      rebuildTreeStateAfterMove(parentDir(cwd));
      await new Promise(r => setTimeout(r, 30))
      await refreshAll();
      showToast('Deleted.');
      return;
    }

    if (act === 'zip') {
      hideCtx();
      if (!selected.size) return;
      try {
        showSpinner();
        elStatus.textContent = 'Creating ZIP…';
        await apiJson('zipCreate', {
          cwd,
          paths: Array.from(selected)
        });
        hideSpinner();
        elStatus.textContent = 'Ready.';
        await refreshAll();
        showToast('ZIP created.');
      } catch (err) {
        hideSpinner();
        elStatus.textContent = 'Ready.';
        showToast('ZIP: ' + err.message, 4000);
      }
      return;
    }

    if (act === 'unzip') {
      hideCtx();
      if (!/\.zip$/i.test(item.name) || item.type !== 'file') return;
    try {
      showSpinner();
      elStatus.textContent = 'Extracting ZIP…';
      await apiJson('unzip', {
        path: item.path,
        dest: cwd || '',
        policy: 'ask'
      });
    } catch (err) {
      if (err.status === 409 && err.data?.needsChoice) {
        const policy = await chooseOverwriteRename({
          title: 'File already exists',
          message: `Already exists during extraction:\n\n${err.data.path}`
        });
        if (!policy) {
          showToast('Extraction canceled.');
          hideSpinner();
          elStatus.textContent = 'Ready.';
          return;
        }
        showSpinner();
        elStatus.textContent = 'Extracting ZIP…';
        await apiJson('unzip', {
          path: item.path,
          dest: cwd || '',
          policy
        });
      } else {
        throw err;
      }
    }
    rebuildTreeStateAfterMove(cwd || '');
    await new Promise(r => setTimeout(r, 100));
    await refreshAll();
    hideSpinner();
    showToast('ZIP extracted.');
    return;
    }

    if (act === 'share') {
      hideCtx();
      if (selected.size !== 1) {
        showToast('Please select exactly 1 item to share.');
        return;
      }
      let token = null;
      try {
        const j = await apiJson('shareCreate', { paths: Array.from(selected) });
        const url = j.url;
        token = j.token;
        let copied = false;
        if (navigator.clipboard && window.isSecureContext) {
          try {
            await navigator.clipboard.writeText(url);
            copied = true;
          } catch {}
        }
        if (!copied) {
          const res = prompt('Copy Share-Link:', url);
          if (res === null && token) {
            await apiJson('shareRevoke', { token });
            showToast('Share-Link creation canceled.');
            return;
          }
        }
        showToast(copied ? '🔗 Share-Link copied.' : '🔗 Share-Link created.', 4000);
        return;
      } catch (err) {
        if (token) {
          try { await apiJson('shareRevoke', { token }); } catch {}
        }
        showToast('Share: ' + (err?.message || err), 4000);
        return;
      }
    }
  } catch (err) {
    showToast('Action failed: ' + (err?.message || err), 4000);
  }
});

const ACTIONS = {
  download: () => document.getElementById('btnDownload').click(),
  rename: () => document.getElementById('btnRename')?.click(),
  copy:   () => document.getElementById('btnCopy')?.click(),
  move:   () => document.getElementById('btnMove')?.click(),
  delete: () => document.getElementById('btnDelete').click(),
  zip:    () => document.getElementById('btnZip').click(),
  unzip:  () => document.getElementById('btnUnzip')?.click(),
  share:  () => document.getElementById('btnShare').click()
};

// Init
document.addEventListener('DOMContentLoaded', () => {
  (async () => {
    try {
      if (window.IS_ADMIN) {
        await openDir('');
      } else {
        const j = await apiJson('treeRoot');
        if (j.nodes?.[0]) {
          await openDir(j.nodes[0].path);
        } else {
          showToast('No folders assigned.');
        }
      }
      await refreshRootSize();
    } catch (e) {
      showToast('Init failed: ' + e.message, 5000);
    }
  })();
});
