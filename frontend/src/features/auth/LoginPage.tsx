import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useLocation, useNavigate } from 'react-router'
import { z } from 'zod'
import { applyServerErrors, TextField } from '@/components/form'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { errorMessage } from '@/lib/api'
import { AuthShell } from './AuthShell'
import { useAuth } from './store'

const schema = z.object({
  email: z.email('Enter a valid email address'),
  password: z.string().min(1, 'Enter your password'),
})
type Values = z.infer<typeof schema>

export function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState<string | null>(null)
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '' } })
  const from = (location.state as { from?: string } | null)?.from ?? '/'

  if (user) return <Navigate to={from} replace />

  const onSubmit = form.handleSubmit(async (values) => {
    setError(null)
    try {
      await login(values.email, values.password)
      navigate(from, { replace: true })
    } catch (e) {
      if (!applyServerErrors(e, form.setError)) setError(errorMessage(e))
    }
  })

  return (
    <AuthShell title="Sign in" description="Use your university email address.">
      <form onSubmit={onSubmit} noValidate>
        <FieldGroup>
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <TextField control={form.control} name="email" label="Email" type="email" autoComplete="username" inputMode="email" autoFocus />
          <TextField control={form.control} name="password" label="Password" type="password" autoComplete="current-password" />
          <Button type="submit" size="lg" className="w-full" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />} Sign in
          </Button>
          <Link to="/forgot-password" className="text-center text-sm text-muted-foreground underline-offset-4 hover:underline">
            Forgot your password?
          </Link>
        </FieldGroup>
      </form>
    </AuthShell>
  )
}
