import { useState, type FormEvent } from 'react';
import { api } from './api';

/**
 * Cambio de contraseña de la casilla por el propio usuario. Llama al endpoint
 * `account-password`, que verifica la contraseña actual y aplica la política
 * (8+, mayúscula, minúscula, número y símbolo) en el servidor.
 */
export function ChangePassword({ csrf, onNotice }: { csrf: string; onNotice: (s: string) => void }) {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    if (next !== confirm) {
      setError('La nueva contraseña y su confirmación no coinciden.');
      return;
    }
    setBusy(true);
    try {
      await api.changePassword(current, next, csrf);
      setCurrent('');
      setNext('');
      setConfirm('');
      onNotice('Contraseña actualizada correctamente.');
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="passwordSection" onSubmit={submit}>
      <h2>Contraseña</h2>
      <p>Cambiá la contraseña de tu casilla. Debe tener al menos 8 caracteres e incluir may&uacute;scula, min&uacute;scula, n&uacute;mero y un s&iacute;mbolo (ej: $ &amp; /).</p>
      {error && <div className="error">{error}</div>}
      <div className="identityGrid">
        <label>Contrase&ntilde;a actual<input type="password" autoComplete="current-password" value={current} onChange={e => setCurrent(e.target.value)} required /></label>
        <label>Nueva contrase&ntilde;a<input type="password" autoComplete="new-password" value={next} onChange={e => setNext(e.target.value)} required /></label>
        <label>Repetir nueva contrase&ntilde;a<input type="password" autoComplete="new-password" value={confirm} onChange={e => setConfirm(e.target.value)} required /></label>
      </div>
      <button className="saveButton" type="submit" disabled={busy}>{busy ? 'Cambiando…' : 'Cambiar contraseña'}</button>
    </form>
  );
}
