import { BookOpen, Users } from 'lucide-react'
import { Link } from 'react-router'
import { EmptyState, ErrorState, ListSkeleton, Page, PageHeader } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useCourses } from './api'

export function CoursesPage() {
  const courses = useCourses()
  return (
    <Page>
      <PageHeader title="Courses" description="Your enrolled courses this semester." />
      {courses.isPending ? (
        <ListSkeleton rows={3} />
      ) : courses.isError ? (
        <ErrorState error={courses.error} onRetry={() => courses.refetch()} />
      ) : courses.data.length === 0 ? (
        <EmptyState icon={BookOpen} title="No courses yet" hint="Once an administrator enrols you, your courses appear here." />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {courses.data.map((c) => (
            <Link key={c.id} to={`/courses/${c.id}`} className="rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
              <Card className="h-full transition-colors hover:bg-muted/40">
                <CardHeader>
                  <div className="flex items-center justify-between gap-2">
                    <Badge variant="secondary">{c.code}</Badge>
                    {c.my_role && (
                      <Badge variant="outline" className="capitalize">
                        {c.my_role}
                      </Badge>
                    )}
                  </div>
                  <CardTitle className="mt-2 leading-snug">{c.title}</CardTitle>
                  <CardDescription>{c.semester}</CardDescription>
                </CardHeader>
                <CardContent className="flex items-center gap-4 text-sm text-muted-foreground">
                  <span className="truncate">{c.lecturers?.map((l) => l.name).join(', ')}</span>
                  {c.students_count !== undefined && (
                    <span className="ml-auto flex shrink-0 items-center gap-1">
                      <Users className="size-4" /> {c.students_count}
                    </span>
                  )}
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </Page>
  )
}
