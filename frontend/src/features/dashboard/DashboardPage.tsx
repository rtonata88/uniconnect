import { useQuery } from '@tanstack/react-query'
import { RefreshCw } from 'lucide-react'
import { api } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'

interface Health {
  status: string
  hostname: string
  instance: string
  db: boolean
  redis: boolean
}

export function DashboardPage() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: async () => {
      const res = await api.get<Health>('/health')
      return { ...res.data, servedBy: res.headers['x-served-by'] as string }
    },
    refetchInterval: 5000,
  })

  return (
    <div className="mx-auto w-full max-w-5xl space-y-4 p-4 md:p-6">
      <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
      <Card>
        <CardHeader className="flex flex-row items-start justify-between">
          <div>
            <CardTitle>Platform health</CardTitle>
            <CardDescription>Served through the Traefik gateway; refreshes every 5 s.</CardDescription>
          </div>
          <Button variant="outline" size="sm" onClick={() => health.refetch()}>
            <RefreshCw /> Refresh
          </Button>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2">
          {health.isPending ? (
            <Skeleton className="h-6 w-64" />
          ) : health.isError ? (
            <Badge variant="destructive">API unreachable</Badge>
          ) : (
            <>
              <Badge variant={health.data.status === 'ok' ? 'default' : 'destructive'}>{health.data.status}</Badge>
              <Badge variant="outline">X-Served-By {health.data.servedBy}</Badge>
              <Badge variant="outline">db {health.data.db ? 'ok' : 'down'}</Badge>
              <Badge variant="outline">redis {health.data.redis ? 'ok' : 'down'}</Badge>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
