import type { Attachment, Contact, Draft, Folder, Identity, Label, MailRow, MailTemplate, Message, Preferences, SearchFilters, Session } from './types';

const endpoint = new URL('api.php', window.location.href).pathname;

async function request<T>(action: string, params: Record<string, string> = {}, init?: RequestInit): Promise<T> {
  const url = new URL(endpoint, window.location.origin);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
  const headers=new Headers(init?.headers);const account=sessionStorage.getItem('axerok-account');if(account)headers.set('X-AxerOK-Account',account);
  const response = await fetch(url, { credentials: 'same-origin', ...init,headers });
  const text = await response.text();
  let data: Record<string, unknown>;
  try { data = JSON.parse(text) as Record<string, unknown>; }
  catch { data = { error: response.ok ? 'El servidor devolvió una respuesta inválida.' : 'La sesión expiró o el servidor rechazó la operación.' }; }
  if (!response.ok) {
    if (action !== 'login' && (response.status === 401 || response.status === 419)) {
      window.dispatchEvent(new Event('axerok:session-expired'));
    }
    throw new Error(typeof data.error === 'string' ? data.error : 'No se pudo completar la operación.');
  }
  return data as T;
}

export const api = {
  session: () => request<Session>('session'),
  login: (email: string, password: string, csrf: string) => {
    const body = new FormData(); body.set('email', email); body.set('password', password); body.set('csrf', csrf);
    return request<Session>('login', {}, { method: 'POST', body });
  },
  switchAccount:(email:string,csrf:string)=>{const body=new FormData();body.set('email',email);body.set('csrf',csrf);return request<Session>('account-switch',{}, {method:'POST',body});},
  removeAccount:(email:string,csrf:string)=>{const body=new FormData();body.set('email',email);body.set('csrf',csrf);return request<Session>('account-remove',{}, {method:'POST',body});},
  folders: () => request<{ folders: Folder[] }>('folders'),
  messages: (folder: string, query = '', searchBody=false, fresh=false, filters?:SearchFilters,page=1) => {const size=Number(filters?.size_value||0);const multiplier=filters?.size_unit==='MB'?1048576:1024;return request<{ messages: MailRow[];total:number }>('messages', { folder, page:String(page),q: query, ...(searchBody?{body:'1'}:{}), ...(fresh?{fresh:'1'}:{}),...(filters?{from:filters.from,to:filters.to,subject:filters.subject,contains:filters.contains,exclude:filters.exclude,status:filters.status,size_op:filters.size_op,size_bytes:String(Number.isFinite(size)?Math.round(size*multiplier):0),since:filters.since,before:filters.before,...(filters.has_attachment?{has_attachment:'1'}:{})}:{}) });},
  message: (folder: string, uid: number,peek=false) => request<{ message: Message;attachments:Attachment[];safeHtml: string;hasRemoteImages:boolean;remoteUrl:string }>('message', { folder, uid: String(uid), ...(peek?{peek:'1'}:{}) }),
  thread:(folder:string,threadId:string,excludeUid:number)=>request<{messages:Array<{message:Message;attachments:Attachment[];safeHtml:string;hasRemoteImages:boolean;remoteUrl:string}>}>('thread',{folder,thread_id:threadId,exclude_uid:String(excludeUid)}),
  setSeen: (folder:string,uid:number,csrf:string) => {const body=new FormData();body.set('folder',folder);body.set('uid',String(uid));body.set('csrf',csrf);return request<{ok:boolean}>('set-seen',{}, {method:'POST',body});},
  updateFlags: (folder:string,uids:number[],flag:'seen'|'flagged',enabled:boolean,csrf:string) => {const body=new FormData();body.set('folder',folder);body.set('uids',uids.join(','));body.set('flag',flag);body.set('enabled',enabled?'1':'0');body.set('csrf',csrf);return request<{ok:boolean}>('update-flags',{}, {method:'POST',body});},
  deleteMessage: (folder:string,uid:number,csrf:string) => {const body=new FormData();body.set('folder',folder);body.set('uid',String(uid));body.set('csrf',csrf);return request<{ok:boolean}>('delete-message',{}, {method:'POST',body});},
  moveMessages: (folder:string,uids:number[],destination:string,csrf:string) => {const body=new FormData();body.set('folder',folder);body.set('uids',uids.join(','));body.set('destination',destination);body.set('csrf',csrf);return request<{ok:boolean}>('move-messages',{}, {method:'POST',body});},
  deleteMessages: (folder:string,uids:number[],csrf:string) => {const body=new FormData();body.set('folder',folder);body.set('uids',uids.join(','));body.set('csrf',csrf);return request<{ok:boolean;permanent:boolean}>('delete-messages',{}, {method:'POST',body});},
  emptyFolder: (folder:string,csrf:string) => {const body=new FormData();body.set('folder',folder);body.set('confirm','ELIMINAR TODO');body.set('csrf',csrf);return request<{ok:boolean;deleted:number}>('empty-folder',{}, {method:'POST',body});},
  createFolder:(name:string,csrf:string)=>{const body=new FormData();body.set('name',name);body.set('csrf',csrf);return request<{ok:boolean;folders:Folder[]}>('folder-create',{}, {method:'POST',body});},
  renameFolder:(current:string,name:string,csrf:string)=>{const body=new FormData();body.set('current',current);body.set('name',name);body.set('csrf',csrf);return request<{ok:boolean;folders:Folder[]}>('folder-rename',{}, {method:'POST',body});},
  deleteFolder:(name:string,csrf:string)=>{const body=new FormData();body.set('name',name);body.set('confirm',name);body.set('csrf',csrf);return request<{ok:boolean;folders:Folder[]}>('folder-delete',{}, {method:'POST',body});},
  labels:()=>request<{labels:Label[]}>('labels'),
  createLabel:(name:string,color:string,csrf:string)=>{const body=new FormData();body.set('name',name);body.set('color',color);body.set('csrf',csrf);return request<{ok:boolean;labels:Label[]}>('label-create',{}, {method:'POST',body});},
  updateLabel:(id:number,name:string,color:string,csrf:string)=>{const body=new FormData();body.set('id',String(id));body.set('name',name);body.set('color',color);body.set('csrf',csrf);return request<{ok:boolean;labels:Label[]}>('label-update',{}, {method:'POST',body});},
  deleteLabel:(id:number,csrf:string)=>{const body=new FormData();body.set('id',String(id));body.set('csrf',csrf);return request<{ok:boolean;labels:Label[]}>('label-delete',{}, {method:'POST',body});},
  applyLabel:(folder:string,uids:number[],id:number,enabled:boolean,csrf:string)=>{const body=new FormData();body.set('folder',folder);body.set('uids',uids.join(','));body.set('id',String(id));body.set('enabled',enabled?'1':'0');body.set('csrf',csrf);return request<{ok:boolean}>('label-apply',{}, {method:'POST',body});},
  contacts: () => request<{contacts:Contact[]}>('contacts'),
  importContacts: (file:File,csrf:string) => {const body=new FormData();body.set('vcard',file,file.name);body.set('csrf',csrf);return request<{ok:boolean;stats:{created:number;updated:number;invalid:number}}>('contacts-import',{}, {method:'POST',body});},
  roundcubeStatus:()=>request<{available:boolean;reason?:string;status?:{personal:number;collected:number;carddav:number;identity:boolean}}>('roundcube-status'),
  importRoundcube:(collected:boolean,identity:boolean,csrf:string)=>{const body=new FormData();body.set('collected',collected?'1':'0');body.set('identity',identity?'1':'0');body.set('csrf',csrf);return request<{ok:boolean;stats:{created:number;updated:number;invalid:number};identity_imported:boolean}>('roundcube-import',{}, {method:'POST',body});},
  exportContacts: () => request<{filename:string;contents:string}>('contacts-export'),
  preferences: () => request<{preferences:Preferences}>('preferences'),
  savePreferences: (preferences:Preferences,csrf:string) => { const body=new FormData();Object.entries(preferences).forEach(([key,value])=>body.set(key,value===true?'1':value===false?'0':String(value)));body.set('csrf',csrf);return request<{ok:boolean}>('preferences',{}, {method:'POST',body}); },
  drafts: () => request<{drafts:Draft[]}>('draft'),
  saveDraft: (draft:Draft,csrf:string) => {const body=new FormData();Object.entries(draft).forEach(([key,value])=>body.set(key,value===true?'1':value===false?'0':String(value)));body.set('csrf',csrf);return request<{ok:boolean;draft:Draft}>('draft',{}, {method:'POST',body});},
  deleteDraft: (id:string,csrf:string) => request<{ok:boolean}>('draft',{}, {method:'DELETE',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({id,csrf})}),
  templates:()=>request<{templates:MailTemplate[]}>('templates'),
  saveTemplate:(template:Pick<MailTemplate,'name'|'subject'|'body_html'>,csrf:string)=>{const body=new FormData();Object.entries(template).forEach(([key,value])=>body.set(key,value));body.set('csrf',csrf);return request<{ok:boolean;template:MailTemplate;templates:MailTemplate[]}>('templates',{}, {method:'POST',body});},
  deleteTemplate:(id:string,csrf:string)=>request<{ok:boolean;templates:MailTemplate[]}>('templates',{}, {method:'DELETE',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({id,csrf})}),
  blockSender:(folder:string,uid:number,csrf:string)=>{const body=new FormData();body.set('folder',folder);body.set('uid',String(uid));body.set('csrf',csrf);return request<{ok:boolean;sender:string}>('block-sender',{}, {method:'POST',body});},
  blockedSenders:()=>request<{senders:string[]}>('blocked-senders'),
  identities:()=>request<{identities:Identity[]}>('identities'),
  saveIdentity:(identity:Identity,csrf:string)=>{const body=new FormData();Object.entries(identity).forEach(([key,value])=>body.set(key,String(value??'')));body.set('csrf',csrf);return request<{ok:boolean;identity:Identity;identities:Identity[]}>('identities',{}, {method:'POST',body});},
  deleteIdentity:(id:string,csrf:string)=>request<{ok:boolean;identities:Identity[]}>('identities',{}, {method:'DELETE',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({id,csrf})}),
  unblockSender:(sender:string,csrf:string)=>request<{ok:boolean;senders:string[]}>('blocked-senders',{}, {method:'DELETE',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({sender,csrf})}),
  send: (body:FormData) => request<{ok:boolean;warning:string}>('send',{}, {method:'POST',body}),
  scheduleSend: (body:FormData) => request<{ok:boolean;job_id:string;send_at:string}>('schedule-send',{}, {method:'POST',body}),
  logout: (csrf:string) => {const body=new FormData();body.set('csrf',csrf);return request<{ok:boolean}>('logout',{}, {method:'POST',body});},
};
