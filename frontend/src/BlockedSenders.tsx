import { useEffect, useState } from 'react';
import { ShieldOff } from 'lucide-react';
import { api } from './api';

export function BlockedSenders({ csrf, onNotice }: { csrf: string; onNotice: (message: string) => void }) {
  const [senders, setSenders] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => { api.blockedSenders().then(result => setSenders(result.senders)).catch(error => setError(error.message)); }, []);
  async function unblock(sender: string) {
    setBusy(true); setError('');
    try { const result = await api.unblockSender(sender, csrf); setSenders(result.senders); onNotice(`Remitente desbloqueado: ${sender}`); }
    catch (error) { setError(error instanceof Error ? error.message : 'No se pudo desbloquear el remitente.'); }
    finally { setBusy(false); }
  }
  return <div className="page managerPage"><div className="pageTitle"><div><h1>Remitentes bloqueados</h1><p>AxerOK oculta sus mensajes y mueve el mensaje bloqueado actual a Spam. El movimiento automático con el navegador cerrado requiere Sieve o un worker de cPanel.</p></div></div>{error&&<div className="error">{error}</div>}{senders.length===0?<div className="empty"><ShieldOff/><b>No hay remitentes bloqueados</b></div>:<div className="managerList">{senders.map(sender=><div className="managerRow" key={sender}><div><ShieldOff/><span><b>{sender}</b><small>Bloqueo local de AxerOK</small></span></div><button className="dangerButton" disabled={busy} onClick={()=>void unblock(sender)}>Desbloquear</button></div>)}</div>}</div>;
}
