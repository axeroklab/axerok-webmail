import { Paperclip } from 'lucide-react';
import type { Attachment } from './types';

function previewType(type: string): 'image' | 'pdf' | null {
  if (/^image\/(png|jpeg|gif|webp)$/i.test(type)) return 'image';
  if (type.toLowerCase() === 'application/pdf') return 'pdf';
  return null;
}

export function AttachmentPreview({ file }: { file: Attachment }) {
  const kind = previewType(file.type);
  return <div className="attachmentItem">{kind === 'image' && <img className="attachmentImagePreview" src={file.url} alt={file.name} loading="lazy" referrerPolicy="no-referrer" />}{kind === 'pdf' && <iframe className="attachmentPdfPreview" title={`Vista previa de ${file.name}`} src={file.url} sandbox="allow-same-origin" referrerPolicy="no-referrer" />}{kind===null&&<Paperclip/>}<a href={file.url} download={file.name}><span><b>{file.name}</b><small>{formatBytes(file.size)}{kind?' · Vista previa disponible':''}</small></span></a></div>;
}

function formatBytes(value: number): string { if (value < 1024) return `${value} B`; if (value < 1048576) return `${Math.round(value / 1024)} KB`; return `${(value / 1048576).toFixed(1)} MB`; }
