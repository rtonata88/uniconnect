import { useQuery } from '@tanstack/react-query'
import { Activity, Boxes, Database, HardDrive, Network, RadioTower, Server, Timer, Workflow } from 'lucide-react'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { ErrorState, ListSkeleton, Page, PageHeader } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { api } from '@/lib/api'
import { cn } from '@/lib/utils'

interface Instance {
  service: string
  host: string
  port: number | null
  url: string | null
  hostname: string
  pid: number
  version: string
  started_at: string
  last_seen: string
  load: number | null
  connections?: number
  status: 'healthy' | 'stale' | 'down'
  heartbeat_age_seconds: number
  uptime_seconds: number
}
interface Check {
  ok: boolean
  latency_ms: number
  detail?: string
  [k: string]: unknown
}
interface System {
  generated_at: string
  served_by: string
  instances: Record<string, Instance[]>
  gateway: { routed: Record<string, string[]>; traefik: Record<string, Record<string, string>> | null }
  checks: Record<'mysql_primary' | 'mysql_replica' | 'redis' | 'minio', Check>
  queues: Check & { pending?: Record<string, number>; failed?: number }
  websockets: { ok: boolean; connections: number; per_instance: Record<string, number> }
  counts: Record<string, number>
}

const SERVICES: { key: string; label: string; icon: typeof Server; hint: string }[] = [
  { key: 'api', label: 'API (Octane)', icon: Server, hint: 'make scale API=3 · make kill-api' },
  { key: 'reverb', label: 'Reverb WebSockets', icon: RadioTower, hint: 'make scale REVERB=3' },
  { key: 'worker', label: 'Horizon workers', icon: Workflow, hint: 'queues: broadcasts, default, push' },
  { key: 'scheduler', label: 'Scheduler', icon: Timer, hint: 'reminders, quiz auto-submit' },
]

function uptime(s: number) {
  if (s < 60) return `${s}s`
  if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`
  return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`
}

function Dot({ ok, className }: { ok: boolean | 'warn'; className?: string }) {
  return <span className={cn('inline-block size-2.5 shrink-0 rounded-full', ok === 'warn' ? 'bg-amber-500' : ok ? 'bg-emerald-500' : 'bg-destructive', className)} />
}

function Tile({ label, value, sub, ok }: { label: string; value: ReactNode; sub?: ReactNode; ok?: boolean | 'warn' }) {
  return (
    <Card className="gap-1 py-4">
      <CardContent className="space-y-1">
        <p className="flex items-center gap-2 text-xs font-medium text-muted-foreground uppercase">
          {ok !== undefined && <Dot ok={ok} />} {label}
        </p>
        <p className="text-2xl font-semibold tabular-nums">{value}</p>
        {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
      </CardContent>
    </Card>
  )
}

function InstanceRow({ i, routed, traefikState }: { i: Instance; routed?: boolean; traefikState?: string }) {
  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border px-3 py-2 text-sm">
      <span className="flex min-w-48 items-center gap-2 font-mono font-medium">
        <Dot ok={i.status === 'healthy' ? true : i.status === 'stale' ? 'warn' : false} />
        {i.port ? `${i.host}:${i.port}` : `${i.host} · pid ${i.pid}`}
      </span>
      <span className="text-muted-foreground">{i.hostname}</span>
      <Badge variant={i.status === 'healthy' ? 'secondary' : 'destructive'} className="capitalize">
        {i.status}
      </Badge>
      <span className="text-xs text-muted-foreground tabular-nums">heartbeat {i.heartbeat_age_seconds.toFixed(1)}s ago</span>
      <span className="text-xs text-muted-foreground tabular-nums">up {uptime(i.uptime_seconds)}</span>
      {i.connections !== undefined && <span className="text-xs tabular-nums">{i.connections} sockets</span>}
      {i.load !== null && <span className="text-xs text-muted-foreground tabular-nums">load {i.load}</span>}
      {routed !== undefined && (
        <span className={cn('ml-auto text-xs font-medium', routed ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground')}>
          {routed ? `in gateway${traefikState ? ` · ${traefikState}` : ''}` : 'not routed yet'}
        </span>
      )}
    </li>
  )
}

/** DIST-6: services, instances and health, refreshed every 5 s. */
export function SystemStatusPage() {
  const served = useRef<Record<string, number>>({})
  const [servedCounts, setServedCounts] = useState<Record<string, number>>({})
  const status = useQuery({
    queryKey: ['admin', 'system'],
    queryFn: async () => {
      const res = await api.get<System>('/admin/system')
      const by = String(res.headers['x-served-by'] ?? res.data.served_by)
      served.current = { ...served.current, [by]: (served.current[by] ?? 0) + 1 }
      return res.data
    },
    refetchInterval: 5000,
    refetchIntervalInBackground: false,
  })
  useEffect(() => setServedCounts(served.current), [status.dataUpdatedAt])

  if (status.isPending) return <Page wide><ListSkeleton rows={6} /></Page>
  if (status.isError) return <Page wide><ErrorState error={status.error} onRetry={() => status.refetch()} /></Page>
  const s = status.data
  const all = Object.values(s.instances).flat()
  const healthy = all.filter((i) => i.status === 'healthy').length
  const replica = s.checks.mysql_replica
  const lag = replica.measured_lag_ms as number | null
  const pending = Object.values(s.queues.pending ?? {}).reduce((a, b) => a + b, 0)

  return (
    <Page wide>
      <PageHeader
        title="System status"
        description={
          <span className="flex flex-wrap items-center gap-2">
            Auto-refresh every 5 s · last {new Date(s.generated_at).toLocaleTimeString()} ·
            <Badge variant="outline" className="font-mono">X-Served-By {s.served_by}</Badge>
          </span>
        }
      />
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Tile label="Instances" value={`${healthy}/${all.length}`} sub="healthy / registered" ok={healthy === all.length && all.length > 0} />
        <Tile label="WebSockets" value={s.websockets.connections} sub={`${Object.keys(s.websockets.per_instance).length} Reverb instances`} ok={s.websockets.ok} />
        <Tile
          label="Replication lag"
          value={lag !== null && lag !== undefined ? `${lag} ms` : '—'}
          sub={replica.seconds_behind_source !== undefined ? `Seconds_Behind_Source: ${replica.seconds_behind_source ?? 'n/a'}` : String(replica.detail ?? '')}
          ok={replica.ok ? (lag !== null && lag > 1000 ? 'warn' : true) : false}
        />
        <Tile label="Queues" value={pending} sub={`pending · ${s.queues.failed ?? 0} failed`} ok={s.queues.ok ? (pending > 100 ? 'warn' : true) : false} />
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        {SERVICES.map(({ key, label, icon: Icon, hint }) => {
          const list = s.instances[key] ?? []
          const routedUrls = s.gateway.routed[key]
          const traefik = s.gateway.traefik?.[`${key}@redis`]
          return (
            <Card key={key}>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon className="size-4" /> {label}
                  <Badge variant="secondary">{list.length}</Badge>
                </CardTitle>
                <CardDescription className="font-mono text-xs">{hint}</CardDescription>
              </CardHeader>
              <CardContent>
                {list.length === 0 ? (
                  <p className="text-sm text-destructive">No instance registered.</p>
                ) : (
                  <ul className="space-y-2">
                    {list.map((i) => (
                      <InstanceRow
                        key={`${i.host}:${i.port ?? i.pid}`}
                        i={i}
                        routed={routedUrls ? routedUrls.includes(i.url ?? '') : undefined}
                        traefikState={traefik?.[i.url ?? '']}
                      />
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          )
        })}
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Database className="size-4" /> MySQL primary <Dot ok={s.checks.mysql_primary.ok} />
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-sm">
            <p className="font-mono">{String(s.checks.mysql_primary.host ?? '')}</p>
            <p className="text-muted-foreground">{String(s.checks.mysql_primary.version ?? s.checks.mysql_primary.detail ?? '')}</p>
            <p className="truncate text-xs text-muted-foreground" title={String(s.checks.mysql_primary.gtid_executed ?? '')}>
              GTID {String(s.checks.mysql_primary.gtid_executed ?? '—')}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Database className="size-4" /> MySQL replica <Dot ok={replica.ok} />
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-sm">
            <p className="font-mono">{String(replica.host ?? '')}</p>
            <p>
              IO {replica.io_running ? 'running' : 'stopped'} · SQL {replica.sql_running ? 'running' : 'stopped'}
            </p>
            <p className="text-muted-foreground">measured lag {lag ?? '—'} ms</p>
            {replica.last_error ? <p className="text-xs text-destructive">{String(replica.last_error)}</p> : null}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Boxes className="size-4" /> Redis <Dot ok={s.checks.redis.ok} />
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-sm">
            <p>v{String(s.checks.redis.version ?? '?')} · {String(s.checks.redis.used_memory ?? '')}</p>
            <p className="text-muted-foreground">cache, queues, Reverb pub/sub, registry</p>
            <p className="text-xs text-muted-foreground">keyspace events: {String(s.checks.redis.keyspace_notifications ?? '')}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <HardDrive className="size-4" /> MinIO <Dot ok={s.checks.minio.ok} />
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 text-sm">
            <p className="font-mono">{String(s.checks.minio.endpoint ?? '')}</p>
            <p className="text-muted-foreground">private bucket {String(s.checks.minio.bucket ?? '')}</p>
            {s.checks.minio.detail ? <p className="text-xs text-destructive">{String(s.checks.minio.detail)}</p> : null}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Network className="size-4" /> Gateway (Traefik, Redis provider)
            </CardTitle>
            <CardDescription>Servers written by registry:sync and Traefik's health-checked view.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            {Object.entries(s.gateway.routed).map(([svc, urls]) => (
              <div key={svc}>
                <p className="font-medium">{svc}@redis</p>
                <ul className="font-mono text-xs">
                  {urls.map((u) => (
                    <li key={u} className="flex items-center gap-2">
                      <Dot ok={(s.gateway.traefik?.[`${svc}@redis`]?.[u] ?? 'UP') === 'UP'} /> {u} {s.gateway.traefik?.[`${svc}@redis`]?.[u] ?? ''}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Activity className="size-4" /> Load balancing (this page's polls)
            </CardTitle>
            <CardDescription>Which API instance answered each refresh (X-Served-By).</CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="space-y-1 font-mono text-sm">
              {Object.entries(servedCounts)
                .sort()
                .map(([by, n]) => (
                  <li key={by} className="flex items-center gap-3">
                    <span className="w-40">{by}</span>
                    <span className="h-2 rounded bg-primary" style={{ width: `${Math.min(100, n * 6)}%` }} />
                    <span className="tabular-nums">{n}</span>
                  </li>
                ))}
            </ul>
            <p className="mt-3 text-xs text-muted-foreground">
              Queues: {Object.entries(s.queues.pending ?? {}).map(([q, n]) => `${q} ${n}`).join(' · ')} · Users {s.counts.users} · Courses {s.counts.courses} · Messages {s.counts.messages}
            </p>
          </CardContent>
        </Card>
      </div>
    </Page>
  )
}
