import { create } from 'zustand'

interface Typist {
  name: string
  until: number
}

/** Ephemeral real-time UI state (not persisted): presence, typing, connection. */
interface ChatState {
  online: Set<number>
  typing: Record<number, Record<number, Typist>>
  connection: 'connecting' | 'connected' | 'unavailable' | 'disconnected'
  activeConversationId: number | null
  setOnline: (ids: number[]) => void
  addOnline: (id: number) => void
  removeOnline: (id: number) => void
  setTyping: (conversationId: number, userId: number, name: string) => void
  clearTyping: (conversationId: number, userId: number) => void
  setConnection: (c: ChatState['connection']) => void
  setActive: (id: number | null) => void
}

export const useChatStore = create<ChatState>((set) => ({
  online: new Set(),
  typing: {},
  connection: 'disconnected',
  activeConversationId: null,
  setOnline: (ids) => set({ online: new Set(ids) }),
  addOnline: (id) => set((s) => ({ online: new Set(s.online).add(id) })),
  removeOnline: (id) =>
    set((s) => {
      const online = new Set(s.online)
      online.delete(id)
      return { online }
    }),
  setTyping: (cid, uid, name) =>
    set((s) => ({ typing: { ...s.typing, [cid]: { ...s.typing[cid], [uid]: { name, until: Date.now() + 4000 } } } })),
  clearTyping: (cid, uid) =>
    set((s) => {
      const conv = { ...s.typing[cid] }
      delete conv[uid]
      return { typing: { ...s.typing, [cid]: conv } }
    }),
  setConnection: (connection) => set({ connection }),
  setActive: (activeConversationId) => set({ activeConversationId }),
}))

/** "Anna is typing…" / "Anna and Ben are typing…" (MSG-8) */
export function typingLabel(typists: Record<number, Typist> | undefined, now = Date.now()): string | null {
  const names = Object.values(typists ?? {})
    .filter((t) => t.until > now)
    .map((t) => t.name.split(' ')[0])
  if (!names.length) return null
  if (names.length === 1) return `${names[0]} is typing…`
  if (names.length === 2) return `${names[0]} and ${names[1]} are typing…`
  return 'Several people are typing…'
}
