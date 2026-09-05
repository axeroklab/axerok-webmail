import { useEffect, useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { api } from './api';
import type { Folder, MailFilter, FilterCondition } from './types';

const FIELDS: Record<FilterCondition['field'], string> = {
  from: 'De', to: 'Para', cc: 'Cc', subject: 'Asunto', body: 'Contiene (cuerpo)',
};

function emptyFilter(): MailFilter {
  return {
    id: '', name: '', match: 'all',
    conditions: [{ field: 'from', op: 'contains', value: '' }],
    actions: { folder: '', read: false, star: false, forward: '', delete: false, stop: false },
  };
}

function summary(f: MailFilter): string {
  const join = f.match === 'any' ? ' o ' : ' y ';
  const conds = f.conditions.map(c => `${FIELDS[c.field]} ${c.op === 'is' ? 'es' : 'contiene'} "${c.value}"`).join(join);
  const acts: string[] = [];
  if (f.actions.folder) acts.push(`mover a ${f.actions.folder}`);
  if (f.actions.read) acts.push('marcar leído');
  if (f.actions.star) acts.push('destacar');
  if (f.actions.forward) acts.push(`reenviar a ${f.actions.forward}`);
  if (f.actions.delete) acts.push('eliminar');
  if (f.actions.stop) acts.push('detener');
  return `Si ${conds} → ${acts.join(', ') || 'sin acción'}`;
}

export function Filters({ csrf, onNotice }: { csrf: string; onNotice: (message: string) => void }) {
  const [items, setItems] = useState<MailFilter[]>([]);
  const [folders, setFolders] = useState<Folder[]>([]);
  const [value, setValue] = useState<MailFilter>(emptyFilter());
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    api.filters().then(r => setItems(r.filters)).catch(e => setError(e.message));
    api.folders().then(r => setFolders(r.folders)).catch(() => {});
  }, []);

  function reset() { setValue(emptyFilter()); setEditing(false); }

  async function save() {
    setBusy(true); setError('');
    try {
      const r = await api.saveFilter(value, csrf);
      setItems(r.filters); reset();
      onNotice('Filtro guardado. Se aplica al correo nuevo que llegue.');
    } catch (e) { setError(e instanceof Error ? e.message : 'No se pudo guardar el filtro.'); }
    finally { setBusy(false); }
  }

  async function remove(id: string) {
    if (!confirm('¿Eliminar este filtro?')) return;
    setBusy(true);
    try { setItems((await api.deleteFilter(id, csrf)).filters); if (value.id === id) reset(); }
    catch (e) { setError(e instanceof Error ? e.message : 'No se pudo eliminar el filtro.'); }
    finally { setBusy(false); }
  }

  function setCond(i: number, patch: Partial<FilterCondition>) {
    setValue(v => ({ ...v, conditions: v.conditions.map((c, idx) => idx === i ? { ...c, ...patch } : c) }));
  }
  function addCond() { setValue(v => ({ ...v, conditions: [...v.conditions, { field: 'subject', op: 'contains', value: '' }] })); }
  function removeCond(i: number) { setValue(v => ({ ...v, conditions: v.conditions.filter((_, idx) => idx !== i) })); }
  function setAction(patch: Partial<MailFilter['actions']>) { setValue(v => ({ ...v, actions: { ...v.actions, ...patch } })); }

  const canSave = value.name.trim() !== '' && value.conditions.some(c => c.value.trim() !== '');

  return (
    <div className="page managerPage">
      <div className="pageTitle">
        <div>
          <h1>Filtros</h1>
          <p>Reglas que se aplican en el servidor al llegar el correo (también en el celular u otros clientes).</p>
        </div>
      </div>
      {error && <div className="error">{error}</div>}

      <div className="filterForm" style={{ background: 'var(--surface,#fff)', border: '1px solid var(--border,#e0e0e0)', borderRadius: 12, padding: 16, marginBottom: 18 }}>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}>
          <input style={{ flex: 1 }} placeholder="Nombre del filtro" value={value.name} onChange={e => setValue({ ...value, name: e.target.value })} />
          <label style={{ fontSize: 13, color: '#888' }}>Coincide</label>
          <select value={value.match} onChange={e => setValue({ ...value, match: e.target.value as 'all' | 'any' })}>
            <option value="all">con todas</option>
            <option value="any">con alguna</option>
          </select>
        </div>

        <div style={{ fontSize: 13, fontWeight: 600, margin: '4px 0' }}>Condiciones</div>
        {value.conditions.map((c, i) => (
          <div key={i} style={{ display: 'flex', gap: 6, marginBottom: 6, alignItems: 'center' }}>
            <select value={c.field} onChange={e => setCond(i, { field: e.target.value as FilterCondition['field'] })}>
              {Object.entries(FIELDS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
            </select>
            <select value={c.op} onChange={e => setCond(i, { op: e.target.value as 'contains' | 'is' })}>
              <option value="contains">contiene</option>
              <option value="is">es exactamente</option>
            </select>
            <input style={{ flex: 1 }} placeholder="valor" value={c.value} onChange={e => setCond(i, { value: e.target.value })} />
            {value.conditions.length > 1 && <button className="round" title="Quitar" onClick={() => removeCond(i)}><X size={16} /></button>}
          </div>
        ))}
        <button className="linkButton" style={{ marginBottom: 12 }} onClick={addCond}><Plus size={14} /> Agregar condición</button>

        <div style={{ fontSize: 13, fontWeight: 600, margin: '4px 0' }}>Acciones</div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', marginBottom: 8 }}>
          <label>Mover a{' '}
            <select value={value.actions.folder} onChange={e => setAction({ folder: e.target.value })}>
              <option value="">(no mover)</option>
              {folders.filter(f => !(f.flags || []).includes('\\Noselect')).map(f => <option key={f.name} value={f.name}>{f.name}</option>)}
            </select>
          </label>
          <label><input type="checkbox" checked={value.actions.read} onChange={e => setAction({ read: e.target.checked })} /> Marcar leído</label>
          <label><input type="checkbox" checked={value.actions.star} onChange={e => setAction({ star: e.target.checked })} /> Destacar</label>
          <label><input type="checkbox" checked={value.actions.delete} onChange={e => setAction({ delete: e.target.checked })} /> Eliminar</label>
          <label><input type="checkbox" checked={value.actions.stop} onChange={e => setAction({ stop: e.target.checked })} /> Detener otros filtros</label>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}>
          <label style={{ flex: 1 }}>Reenviar a{' '}
            <input type="email" placeholder="opcional@dominio.com" value={value.actions.forward} onChange={e => setAction({ forward: e.target.value })} />
          </label>
        </div>

        <div style={{ display: 'flex', gap: 8 }}>
          <button className="primaryButton" disabled={busy || !canSave} onClick={() => void save()}>{editing ? 'Guardar cambios' : 'Crear filtro'}</button>
          {editing && <button className="round" onClick={reset}>Cancelar</button>}
        </div>
      </div>

      <div className="managerList">
        {items.length === 0 && <p style={{ color: '#888' }}>Todavía no tenés filtros.</p>}
        {items.map(f => (
          <div className="managerRow" key={f.id}>
            <div style={{ cursor: 'pointer' }} onClick={() => { setValue(JSON.parse(JSON.stringify(f))); setEditing(true); }}>
              <span><b>{f.name}</b><small>{summary(f)}</small></span>
            </div>
            <button className="dangerButton" disabled={busy} onClick={() => void remove(f.id)}><Trash2 /> Eliminar</button>
          </div>
        ))}
      </div>
    </div>
  );
}
