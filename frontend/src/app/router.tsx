import { createBrowserRouter } from 'react-router'
import { AppLayout } from '@/app/layouts/AppLayout'
import { Placeholder } from '@/components/Placeholder'
import { DashboardPage } from '@/features/dashboard/DashboardPage'

export const router = createBrowserRouter([
  {
    element: <AppLayout />,
    children: [
      { index: true, element: <DashboardPage /> },
      { path: 'courses', element: <Placeholder title="Courses" /> },
      { path: 'chats', element: <Placeholder title="Chats" /> },
      { path: 'notifications', element: <Placeholder title="Notifications" /> },
      { path: 'profile', element: <Placeholder title="Profile" /> },
    ],
  },
])
