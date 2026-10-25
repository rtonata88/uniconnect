import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { applyServerErrors, TextField } from '@/components/form'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { AuthShell } from './AuthShell'

export const passwordRule = z
  .string()
  .min(8, 'At least 8 characters')
  .regex(/[A-Za-z]/, 'Include a letter')
  .regex(/[0-9]/, 'Include a number')

export function ForgotPasswordPage() {
  const [sent, setSent] = useState<string | null>(null)
  const form = useForm<{ email: string }>({
    resolver: zodResolver(z.object({ email: z.email('Enter a valid email address') })),
    defaultValues: { email: '' },
  })
  const onSubmit = form.handleSubmit(async (values) => {
    try {
      const { data } = await api.post('/auth/forgot-password', values)
      setSent(data.message)
    } catch (e) {
      if (!applyServerErrors(e, form.setError)) toast.error(errorMessage(e))
    }
  })

  return (
    <AuthShell title="Reset password" description="We'll email you a link to choose a new password.">
      {sent ? (
        <div className="space-y-4">
          <Alert>
            <AlertDescription>{sent}</AlertDescription>
          </Alert>
          <Button asChild variant="outline" className="w-full">
            <Link to="/login">Back to sign in</Link>
          </Button>
        </div>
      ) : (
        <form onSubmit={onSubmit} noValidate>
          <FieldGroup>
            <TextField control={form.control} name="email" label="Email" type="email" autoComplete="username" autoFocus />
            <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>
              {form.formState.isSubmitting && <Loader2 className="animate-spin" />} Send reset link
            </Button>
            <Link to="/login" className="text-center text-sm text-muted-foreground hover:underline">
              Back to sign in
            </Link>
          </FieldGroup>
        </form>
      )}
    </AuthShell>
  )
}

const resetSchema = z
  .object({ password: passwordRule, password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Passwords do not match' })

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)
  const form = useForm<z.infer<typeof resetSchema>>({
    resolver: zodResolver(resetSchema),
    defaultValues: { password: '', password_confirmation: '' },
  })
  const onSubmit = form.handleSubmit(async (values) => {
    setError(null)
    try {
      await api.post('/auth/reset-password', { ...values, token: params.get('token'), email: params.get('email') })
      toast.success('Password changed. Please sign in.')
      navigate('/login', { replace: true })
    } catch (e) {
      // an invalid/expired token is reported on `email`, which has no input here
      const { email, ...rest } = fieldErrors(e)
      Object.entries(rest).forEach(([k, m]) => form.setError(k as 'password', { message: m }))
      if (email || !Object.keys(rest).length) setError(email ?? errorMessage(e))
    }
  })

  return (
    <AuthShell title="Choose a new password" description={params.get('email') ?? undefined}>
      <form onSubmit={onSubmit} noValidate>
        <FieldGroup>
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <TextField control={form.control} name="password" label="New password" type="password" autoComplete="new-password" />
          <TextField control={form.control} name="password_confirmation" label="Confirm password" type="password" autoComplete="new-password" />
          <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />} Save password
          </Button>
        </FieldGroup>
      </form>
    </AuthShell>
  )
}
