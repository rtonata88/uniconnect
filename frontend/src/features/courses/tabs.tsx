import type { CourseTab } from './CoursePage'
import { AnnouncementsTab } from './tabs/AnnouncementsTab'
import { AssignmentsTab } from './tabs/AssignmentsTab'
import { MaterialsTab } from './tabs/MaterialsTab'
import { OverviewTab } from './tabs/OverviewTab'
import { PeopleTab } from './tabs/PeopleTab'
import { ComingSoonTab } from './tabs/ComingSoonTab'
import { GradesTab } from '@/features/grades/GradesTab'
import { QuizzesTab } from '@/features/quizzes/QuizzesTab'

export const courseTabs: CourseTab[] = [
  { key: 'overview', label: 'Overview', component: OverviewTab },
  { key: 'materials', label: 'Materials', component: MaterialsTab },
  { key: 'assignments', label: 'Assignments', component: AssignmentsTab },
  { key: 'quizzes', label: 'Quizzes', component: QuizzesTab },
  { key: 'grades', label: 'Grades', component: GradesTab },
  { key: 'announcements', label: 'Announcements', component: AnnouncementsTab },
  { key: 'chat', label: 'Chat', component: ComingSoonTab },
  { key: 'people', label: 'People', component: PeopleTab },
]
