import { ErrorState, ListSkeleton, UserAvatar } from '@/components/common'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { User } from '@/features/auth/types'
import type { CourseTabProps } from '../CoursePage'
import { usePeople } from '../api'
import { peopleActions } from './peopleActions'

function PersonRow({ user }: { user: User }) {
  return (
    <li className="flex min-h-12 items-center gap-3 py-1.5">
      <UserAvatar user={user} className="size-9" />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">{user.name}</p>
        {user.email && <p className="truncate text-xs text-muted-foreground">{user.email}</p>}
      </div>
      {peopleActions.map((Action, i) => (
        <Action key={i} user={user} />
      ))}
    </li>
  )
}

export function PeopleTab({ course }: CourseTabProps) {
  const people = usePeople(course.id)
  if (people.isPending) return <ListSkeleton />
  if (people.isError) return <ErrorState error={people.error} onRetry={() => people.refetch()} />
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle>Lecturers</CardTitle>
        </CardHeader>
        <CardContent>
          <ul className="divide-y">
            {people.data.lecturers.map((u) => (
              <PersonRow key={u.id} user={u} />
            ))}
          </ul>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Students ({people.data.students.length})</CardTitle>
        </CardHeader>
        <CardContent>
          <ul className="divide-y">
            {people.data.students.map((u) => (
              <PersonRow key={u.id} user={u} />
            ))}
          </ul>
        </CardContent>
      </Card>
    </div>
  )
}
