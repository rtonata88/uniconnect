import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AppLayout } from '@/app/layouts/AppLayout'
import { TooltipProvider } from '@/components/ui/tooltip'

function renderAt(path: string, role?: 'admin' | 'student') {
  const router = createMemoryRouter(
    [{ element: <AppLayout role={role} badges={{ chats: 3 }} />, children: [{ path: '*', element: <p>page</p> }] }],
    { initialEntries: [path] },
  )
  return render(
    <TooltipProvider>
      <RouterProvider router={router} />
    </TooltipProvider>,
  )
}

describe('AppLayout', () => {
  it('MOB-1 renders the mobile bottom tab bar with four tabs', () => {
    renderAt('/chats')
    const nav = screen.getByRole('navigation', { name: 'Main' })
    expect(nav.querySelectorAll('a')).toHaveLength(4)
    expect(screen.getAllByText('3').length).toBeGreaterThan(0)
  })

  it('only shows administration links to admins', () => {
    renderAt('/', 'student')
    expect(screen.queryByText('System status')).not.toBeInTheDocument()
  })
})
