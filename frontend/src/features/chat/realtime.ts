import type Echo from 'laravel-echo'
import { connectEcho, disconnectEcho, getEcho } from '@/lib/echo'
import { db } from '@/lib/db'
import { processOutbox } from '@/lib/sync/outbox'
import { applyReceipts, markDeleted, receiveMessage, refreshConversation, refreshConversations, removeConversationLocally } from './data'
import { useChatStore } from './store'
import type { Message } from './types'

type Unsub = () => void
const joined = new Map<number, Unsub>()
let userId: number | null = null
const reconnectListeners = new Set<() => void>()
const userEventListeners = new Map<string, Set<(payload: unknown) => void>>()

const boundEvents = new Set<string>()

function bindUserEvent(echo: Echo<'reverb'>, event: string) {
  if (!userId || boundEvents.has(event)) return
  boundEvents.add(event)
  echo.private(`user.${userId}`).listen(`.${event}`, (p: unknown) => userEventListeners.get(event)?.forEach((l) => l(p)))
}

/** Other features (notifications) listen to private-user.{id} events through this. */
export function onUserEvent(event: string, fn: (payload: unknown) => void) {
  if (!userEventListeners.has(event)) userEventListeners.set(event, new Set())
  userEventListeners.get(event)!.add(fn)
  const echo = getEcho()
  if (echo) bindUserEvent(echo, event)
  return () => userEventListeners.get(event)?.delete(fn)
}

/** Called after the socket reconnects (sync catches up on missed messages, PRD 9.3). */
export function onReconnect(fn: () => void) {
  reconnectListeners.add(fn)
  return () => reconnectListeners.delete(fn)
}

function isVisible(conversationId: number) {
  return useChatStore.getState().activeConversationId === conversationId && document.visibilityState === 'visible'
}

/** presence-conversation.{id}: MessageSent, MessageDeleted, typing whispers (MSG-5, MSG-8). */
function joinConversation(echo: Echo<'reverb'>, id: number) {
  if (joined.has(id)) return
  const channel = echo.join(`conversation.${id}`)
  channel
    .listen('.MessageSent', (e: { message: Message }) => void receiveMessage(e.message, { visible: isVisible(id) }))
    .listen('.MessageDeleted', (e: { id: number }) => void markDeleted(e.id))
    .listenForWhisper('typing', (e: { id: number; name: string; typing: boolean }) => {
      if (e.typing) useChatStore.getState().setTyping(id, e.id, e.name)
      else useChatStore.getState().clearTyping(id, e.id)
    })
  joined.set(id, () => echo.leave(`conversation.${id}`))
}

export function whisperTyping(conversationId: number, me: { id: number; name: string }, typing: boolean) {
  getEcho()?.join(`conversation.${conversationId}`).whisper('typing', { id: me.id, name: me.name, typing })
}

/** Keep one presence subscription per conversation in the local database. */
async function syncSubscriptions(echo: Echo<'reverb'>) {
  const ids = new Set((await db.conversations.toCollection().primaryKeys()) as number[])
  ids.forEach((id) => joinConversation(echo, id))
  for (const [id, leave] of joined) {
    if (!ids.has(id)) {
      leave()
      joined.delete(id)
    }
  }
}

export async function startRealtime(uid: number) {
  if (userId === uid && getEcho()) return
  userId = uid
  const echo = await connectEcho()
  const store = useChatStore.getState()

  // private-user.{id}: conversation changes and tick updates for my messages (PRD 8.1)
  const user = echo.private(`user.${uid}`)
  user
    .listen('.ConversationCreated', async (e: { conversation_id: number }) => {
      await refreshConversation(e.conversation_id)
      await syncSubscriptions(echo)
    })
    .listen('.ConversationUpdated', async (e: { conversation_id: number; reason: string }) => {
      if (e.reason === 'removed') await removeConversationLocally(e.conversation_id)
      else await refreshConversation(e.conversation_id)
      await syncSubscriptions(echo)
    })
    .listen('.ReceiptsUpdated', (e: { receipts: Parameters<typeof applyReceipts>[0] }) => void applyReceipts(e.receipts))
  for (const event of userEventListeners.keys()) bindUserEvent(echo, event)

  // presence-online: online dots (MSG-9)
  echo
    .join('online')
    .here((members: { id: number }[]) => store.setOnline(members.map((m) => m.id)))
    .joining((m: { id: number }) => useChatStore.getState().addOnline(m.id))
    .leaving((m: { id: number }) => useChatStore.getState().removeOnline(m.id))

  // connection state + catch-up after a reconnect
  const pusher = (echo.connector as unknown as { pusher: { connection: { bind: (e: string, f: (s: { current: string; previous: string }) => void) => void } } }).pusher
  pusher.connection.bind('state_change', (s) => {
    useChatStore.getState().setConnection(s.current as never)
    if (s.current === 'connected' && (s.previous === 'unavailable' || s.previous === 'connecting')) {
      void processOutbox()
      reconnectListeners.forEach((l) => l())
    }
  })

  await refreshConversations().catch(() => undefined)
  await syncSubscriptions(echo)
}

/** Re-check presence subscriptions after the local conversation list changed (e.g. after sync). */
export async function resubscribe() {
  const echo = getEcho()
  if (echo) await syncSubscriptions(echo)
}

export function stopRealtime() {
  joined.clear()
  boundEvents.clear()
  userId = null
  disconnectEcho()
  useChatStore.getState().setOnline([])
}
