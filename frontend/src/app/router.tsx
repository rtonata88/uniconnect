import { createBrowserRouter } from 'react-router'
import { Shell } from '@/app/Shell'
import { Placeholder } from '@/components/Placeholder'
import { CoursesAdminPage } from '@/features/admin/CoursesAdminPage'
import { UsersPage } from '@/features/admin/UsersPage'
import { AssignmentPage } from '@/features/assignments/AssignmentPage'
import { CoursePage } from '@/features/courses/CoursePage'
import { CoursesPage } from '@/features/courses/CoursesPage'
import { RequireAuth, RequireRole } from '@/features/auth/guards'
import { LoginPage } from '@/features/auth/LoginPage'
import { ForgotPasswordPage, ResetPasswordPage } from '@/features/auth/PasswordPages'
import { ChatsPage } from '@/features/chat/ChatsPage'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { QuizBuilderPage } from '@/features/quizzes/QuizBuilderPage'
import { QuizPage } from '@/features/quizzes/QuizPage'
import { QuizTakePage } from '@/features/quizzes/QuizTakePage'
import { ProfilePage } from '@/features/profile/ProfilePage'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/forgot-password', element: <ForgotPasswordPage /> },
  { path: '/reset-password', element: <ResetPasswordPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <Shell />,
        children: [
          { index: true, element: <DashboardPage /> },
          { path: 'courses', element: <CoursesPage /> },
          { path: 'courses/:courseId/:tab?', element: <CoursePage /> },
          { path: 'assignments/:assignmentId', element: <AssignmentPage /> },
          { path: 'courses/:courseId/quizzes/new', element: <QuizBuilderPage /> },
          { path: 'quizzes/:quizId', element: <QuizPage /> },
          { path: 'quizzes/:quizId/edit', element: <QuizBuilderPage /> },
          { path: 'attempts/:attemptId', element: <QuizTakePage /> },
          { path: 'chats', element: <ChatsPage /> },
          { path: 'chats/:conversationId', element: <ChatsPage /> },
          { path: 'notifications', element: <Placeholder title="Notifications" /> },
          { path: 'profile', element: <ProfilePage /> },
          {
            path: 'admin',
            element: <RequireRole roles={['admin']} />,
            children: [
              { path: 'users', element: <UsersPage /> },
              { path: 'courses', element: <CoursesAdminPage /> },
              { path: 'system', element: <Placeholder title="System status" /> },
              { path: 'audit-logs', element: <Placeholder title="Audit log" /> },
            ],
          },
          { path: '*', element: <Placeholder title="Page not found" /> },
        ],
      },
    ],
  },
])
