import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { tickOf, type Message } from '../types'
import { Ticks } from './Ticks'

const base: Message = {
  conversation_id: 1,
  client_uuid: 'u',
  type: 'text',
  sender: { id: 1, name: 'A' },
  body: 'hi',
  attachment: null,
  reply_to: null,
  deleted: false,
  status: null,
  created_at: '2026-10-25T10:00:00Z',
}

describe('Ticks (MSG-7)', () => {
  it.each([
    ['pending', 'Sending'],
    ['sent', 'Sent'],
    ['delivered', 'Delivered'],
    ['read', 'Read'],
    ['failed', 'Not sent'],
  ] as const)('renders %s as "%s"', (status, label) => {
    render(<Ticks status={status} />)
    expect(screen.getByRole('img', { name: label })).toHaveAttribute('data-status', status)
  })

  it('shows blue ticks only when read', () => {
    const { container, rerender } = render(<Ticks status="delivered" />)
    expect(container.querySelector('svg')?.getAttribute('class')).not.toContain('text-sky-500')
    rerender(<Ticks status="read" />)
    expect(container.querySelector('svg')?.getAttribute('class')).toContain('text-sky-500')
  })

  it('derives the status: local outbox state wins over the server status', () => {
    expect(tickOf({ ...base, local_status: 'pending' })).toBe('pending')
    expect(tickOf({ ...base, local_status: 'failed', status: 'sent' })).toBe('failed')
    expect(tickOf({ ...base, status: null })).toBe('sent')
    expect(tickOf({ ...base, status: 'delivered' })).toBe('delivered')
    expect(tickOf({ ...base, status: 'read' })).toBe('read')
  })
})
