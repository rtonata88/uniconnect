import { ChevronLeft } from 'lucide-react'
import type { ComponentType } from 'react'
import { Link, Navigate, useNavigate, useParams } from 'react-router'
import { ErrorState, Page } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Forbidden } from '@/features/auth/guards'
import { useCourse } from './api'
import { courseTabs } from './tabs'
import type { Course } from './types'

export type CourseTabProps = { course: Course; isLecturer: boolean }

export interface CourseTab {
  key: string
  label: string
  component: ComponentType<CourseTabProps>
}

/** LMS-2: Overview, Materials, Assignments, Quizzes, Grades, Announcements, Chat, People. */
export function CoursePage() {
  const { courseId, tab = 'overview' } = useParams()
  const id = Number(courseId)
  const navigate = useNavigate()
  const course = useCourse(id)

  if (!courseTabs.some((t) => t.key === tab)) return <Navigate to={`/courses/${id}`} replace />
  if (course.isError) {
    const status = (course.error as { response?: { status?: number } }).response?.status
    if (status === 403 || status === 404) return <Forbidden message="You are not enrolled in this course." />
    return (
      <Page>
        <ErrorState error={course.error} onRetry={() => course.refetch()} />
      </Page>
    )
  }

  return (
    <Page wide>
      <div className="space-y-1">
        <Link to="/courses" className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
          <ChevronLeft className="size-4" /> Courses
        </Link>
        {course.isPending ? (
          <Skeleton className="h-8 w-72" />
        ) : (
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="secondary">{course.data.code}</Badge>
            <h1 className="text-2xl font-semibold tracking-tight">{course.data.title}</h1>
          </div>
        )}
      </div>
      <Tabs value={tab} onValueChange={(v) => navigate(v === 'overview' ? `/courses/${id}` : `/courses/${id}/${v}`)}>
        <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
          <TabsList className="w-max">
            {courseTabs.map((t) => (
              <TabsTrigger key={t.key} value={t.key} className="min-h-9">
                {t.label}
              </TabsTrigger>
            ))}
          </TabsList>
        </div>
        {course.data &&
          courseTabs.map((t) => (
            <TabsContent key={t.key} value={t.key} className="pt-4">
              {t.key === tab && <t.component course={course.data} isLecturer={course.data.my_role === 'lecturer'} />}
            </TabsContent>
          ))}
      </Tabs>
    </Page>
  )
}
