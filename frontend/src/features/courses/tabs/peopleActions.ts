import type { ComponentType } from 'react'
import type { User } from '@/features/auth/types'

/** Per-person actions (e.g. "Message") contributed by the chat feature. */
export const peopleActions: ComponentType<{ user: User }>[] = []
