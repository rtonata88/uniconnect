export function Placeholder({ title }: { title: string }) {
  return (
    <div className="mx-auto w-full max-w-5xl p-4 md:p-6">
      <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
      <p className="mt-2 text-muted-foreground">Coming soon.</p>
    </div>
  )
}
