import type { MailRow } from './types';

export type MailSelection = { folder: string; uid: number };

export function selectionKey(folder: string, uid: number): string {
  return `${folder}\u0000${uid}`;
}

export function selectionFromRow(row: Pick<MailRow, 'folder' | 'uid'>): string {
  return selectionKey(row.folder, row.uid);
}

export function selectionFromRows(rows: Array<Pick<MailRow, 'folder' | 'uid'>>): Set<string> {
  return new Set(rows.map(selectionFromRow));
}

export function selectedRows(rows: MailRow[], selected: Set<string>): MailRow[] {
  return rows.filter(row => selected.has(selectionFromRow(row)));
}

export function groupSelections(selected: MailSelection[]): Map<string, number[]> {
  const groups = new Map<string, number[]>();
  for (const item of selected) {
    const uids = groups.get(item.folder) ?? [];
    if (!uids.includes(item.uid)) uids.push(item.uid);
    groups.set(item.folder, uids);
  }
  return groups;
}

export function parseSelectionKey(key: string): MailSelection | null {
  const separator = key.lastIndexOf('\u0000');
  if (separator < 1) return null;
  const folder = key.slice(0, separator);
  const uid = Number(key.slice(separator + 1));
  return folder !== '' && Number.isSafeInteger(uid) && uid > 0 ? { folder, uid } : null;
}

