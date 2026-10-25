import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { formatClock, useCountdown } from './useCountdown'

describe('useCountdown (LMS-9 quiz timer)', () => {
  beforeEach(() => vi.useFakeTimers({ now: new Date('2026-10-25T10:00:00Z') }))
  afterEach(() => vi.useRealTimers())

  it('counts down and auto-submits exactly once when time runs out', () => {
    const onExpire = vi.fn()
    const { result } = renderHook(() => useCountdown('2026-10-25T10:01:00Z', '2026-10-25T10:00:00Z', onExpire))
    expect(result.current).toBe(60_000)

    act(() => vi.advanceTimersByTime(30_000))
    expect(result.current).toBe(30_000)
    expect(onExpire).not.toHaveBeenCalled()

    act(() => vi.advanceTimersByTime(31_000))
    expect(result.current).toBe(0)
    expect(onExpire).toHaveBeenCalledTimes(1)

    act(() => vi.advanceTimersByTime(5_000))
    expect(onExpire).toHaveBeenCalledTimes(1)
  })

  it('uses server time, not the device clock', () => {
    // device clock is 5 minutes fast: server says it is 09:55
    const onExpire = vi.fn()
    const { result } = renderHook(() => useCountdown('2026-10-25T10:00:00Z', '2026-10-25T09:55:00Z', onExpire))
    expect(result.current).toBe(5 * 60_000)
    expect(onExpire).not.toHaveBeenCalled()
  })

  it('returns null without a deadline', () => {
    const { result } = renderHook(() => useCountdown(null, undefined, vi.fn()))
    expect(result.current).toBeNull()
  })

  it('formats the clock', () => {
    expect(formatClock(65_000)).toBe('1:05')
    expect(formatClock(3_725_000)).toBe('1:02:05')
    expect(formatClock(400)).toBe('0:01')
  })
})
