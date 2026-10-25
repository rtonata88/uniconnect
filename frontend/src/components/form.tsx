import type { ComponentProps, ReactNode } from 'react'
import { Controller, type Control, type FieldPath, type FieldValues, type UseFormSetError } from 'react-hook-form'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { fieldErrors } from '@/lib/api'

type BaseProps<T extends FieldValues> = {
  control: Control<T>
  name: FieldPath<T>
  label: ReactNode
  description?: ReactNode
}

/** Labelled input bound to react-hook-form with inline validation errors (SEC-8, a11y labels). */
export function TextField<T extends FieldValues>({
  control,
  name,
  label,
  description,
  multiline,
  ...input
}: BaseProps<T> & { multiline?: boolean } & Omit<ComponentProps<'input'>, 'name'>) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <Field data-invalid={fieldState.invalid || undefined}>
          <FieldLabel htmlFor={name}>{label}</FieldLabel>
          {multiline ? (
            <Textarea
              id={name}
              aria-invalid={fieldState.invalid}
              {...field}
              value={field.value ?? ''}
              placeholder={input.placeholder}
              rows={5}
            />
          ) : (
            <Input id={name} aria-invalid={fieldState.invalid} {...input} {...field} value={field.value ?? ''} />
          )}
          {description && <FieldDescription>{description}</FieldDescription>}
          <FieldError errors={[fieldState.error]} />
        </Field>
      )}
    />
  )
}

/** Copy Laravel validation errors (`errors.{field}[0]`) onto the form. Returns true if any were applied. */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>): boolean {
  const errs = fieldErrors(error)
  Object.entries(errs).forEach(([key, message]) => setError(key as FieldPath<T>, { type: 'server', message }))
  return Object.keys(errs).length > 0
}
