import { useMemo, useState } from 'react';
import { X } from 'lucide-react';
import type { Contact } from './types';

function splitRecipients(value: string): string[] { return value.split(/[;,]/).map(item => item.trim()).filter(Boolean); }
function validRecipient(value: string): boolean { const match = value.match(/^(?:[^<>]+\s*)?<([^<>]+)>$/) || [value, value]; return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(match[1].trim()); }

export function RecipientInput({ value, onChange, contacts, placeholder }: { value: string; onChange: (value: string) => void; contacts: Contact[]; placeholder?: string }) {
  const [draft, setDraft] = useState('');
  const chips = useMemo(() => splitRecipients(value), [value]);
  const suggestions = contacts.filter(contact => `${contact.name} ${contact.email}`.toLowerCase().includes(draft.trim().toLowerCase())).slice(0, 8);
  function commit(raw: string) { const item = raw.trim(); if (!item || !validRecipient(item)) return; onChange([...chips, item].join(', ')); setDraft(''); }
  function remove(index: number) { onChange(chips.filter((_, itemIndex) => itemIndex !== index).join(', ')); }
  return <div className="recipientInput"><div className="recipientChips">{chips.map((chip, index) => <span className="recipientChip" key={`${chip}-${index}`}>{chip}<button type="button" aria-label={`Quitar ${chip}`} onClick={()=>remove(index)}><X/></button></span>)}<input value={draft} placeholder={chips.length===0?placeholder:undefined} onChange={event=>{const next=event.target.value;if(/[;,]$/.test(next)){commit(next.slice(0,-1));}else setDraft(next)}} onKeyDown={event=>{if(event.key==='Enter'||event.key==='Tab'){event.preventDefault();commit(draft);}if(event.key==='Backspace'&&!draft&&chips.length)remove(chips.length-1)}} onBlur={()=>commit(draft)} /></div>{draft&&suggestions.length>0&&<div className="recipientSuggestions" role="listbox">{suggestions.map(contact=><button type="button" role="option" key={contact.id} onMouseDown={event=>event.preventDefault()} onClick={()=>commit(contact.name?`${contact.name} <${contact.email}>`:contact.email)}><span>{contact.name||contact.email}</span><small>{contact.email}</small></button>)}</div>}</div>;
}
