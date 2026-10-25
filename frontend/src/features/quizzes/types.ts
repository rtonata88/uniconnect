export type QuestionType = 'mcq' | 'true_false' | 'short_answer'

export interface QuizOption {
  id?: number
  text: string
  position?: number
  is_correct?: boolean
}

export interface Question {
  id?: number
  type: QuestionType
  prompt: string
  points: number
  position?: number
  correct_text?: string | null
  options: QuizOption[]
}

export interface Answer {
  question_id: number
  option_id?: number | null
  text_answer?: string | null
  is_correct?: boolean | null
  points_awarded?: number | null
}

export interface Attempt {
  id: number
  quiz_id: number
  student?: { id: number; name: string; student_number?: string | null }
  started_at: string
  deadline_at: string | null
  submitted_at: string | null
  auto_submitted: boolean
  needs_manual_grading: boolean
  score: number | null
  max_score: number | null
  grade_released: boolean
  server_now: string
  questions?: Question[]
  answers?: Answer[]
}

export interface Quiz {
  id: number
  course_id: number
  title: string
  instructions: string | null
  opens_at: string | null
  closes_at: string | null
  time_limit_minutes: number | null
  max_attempts: number
  shuffle_questions: boolean
  published_at: string | null
  is_open: boolean
  question_count?: number
  max_score: number | null
  questions?: Question[]
  attempts_count?: number
  my_attempts?: Attempt[] | null
  grade_released: boolean
  updated_at: string
}
