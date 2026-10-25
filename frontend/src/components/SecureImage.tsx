import { useQuery } from '@tanstack/react-query'
import { ImageOff } from 'lucide-react'
import { signedUrl } from '@/lib/files'
import { cn } from '@/lib/utils'
import { Skeleton } from '@/components/ui/skeleton'

/** <img> for a private attachment: fetch a short-lived signed URL with the token first (SEC-4). */
export function SecureImage({ attachmentId, alt, className, onClick }: { attachmentId: number; alt: string; className?: string; onClick?: () => void }) {
  const url = useQuery({ queryKey: ['signed-url', attachmentId], queryFn: () => signedUrl(attachmentId), staleTime: 4 * 60_000, gcTime: 4 * 60_000 })
  if (url.isPending) return <Skeleton className={cn('aspect-video w-64 max-w-full', className)} />
  if (url.isError)
    return (
      <div className={cn('flex aspect-video w-64 max-w-full items-center justify-center rounded-md bg-muted', className)}>
        <ImageOff className="size-6 text-muted-foreground" />
      </div>
    )
  return <img src={url.data} alt={alt} loading="lazy" className={cn('cursor-zoom-in', className)} onClick={onClick} />
}
