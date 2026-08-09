import { useEffect, useState } from 'react';
import { Bell, BellOff } from 'lucide-react';

export function NotificationSettings({ onNotice }: { onNotice: (message: string) => void }) {
  const [permission, setPermission] = useState<NotificationPermission>(() => ('Notification' in window ? Notification.permission : 'denied'));
  const supported = 'Notification' in window;
  useEffect(() => { if (supported) setPermission(Notification.permission); }, [supported]);
  async function requestPermission() {
    if (!supported) { onNotice('Este navegador no admite notificaciones.'); return; }
    const result = await Notification.requestPermission();
    setPermission(result);
    onNotice(result === 'granted' ? 'Notificaciones activadas.' : 'El permiso de notificaciones no fue concedido.');
  }
  return <section className="notificationSettings"><h2>Notificaciones</h2><p>Solo se notifican mensajes nuevos mientras AxerOK está abierto y la pestaña está visible. No se ejecuta ningún proceso en segundo plano.</p><button className="saveButton" disabled={!supported||permission==='granted'} onClick={()=>void requestPermission()}>{permission==='granted'?<Bell/>:<BellOff/>}{permission==='granted'?'Notificaciones activadas':'Activar notificaciones'}</button></section>;
}
