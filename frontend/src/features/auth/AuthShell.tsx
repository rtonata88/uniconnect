import { GraduationCap } from 'lucide-react'
import type { ReactNode } from 'react'
import { ThemeToggle } from '@/components/ThemeToggle'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

export function AuthShell({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted/40 p-4 pt-[max(1rem,env(safe-area-inset-top))]">
      <div className="absolute top-[max(0.5rem,env(safe-area-inset-top))] right-2">
        <ThemeToggle />
      </div>
      <div className="flex items-center gap-2 text-xl font-semibold">
        <GraduationCap className="size-8 text-primary" /> UniConnect
      </div>
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle className="text-xl">{title}</CardTitle>
          {description && <CardDescription>{description}</CardDescription>}
        </CardHeader>
        <CardContent>{children}</CardContent>
      </Card>
    </div>
  )
}
