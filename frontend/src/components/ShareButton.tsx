import { Link2, Share2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'

/** MOB-3: native share sheet where available (phones), otherwise copy the link. */
export function ShareButton({ title, text, path }: { title: string; text?: string; path: string }) {
  const url = new URL(path, window.location.origin).href
  const canShare = typeof navigator.share === 'function'
  return (
    <Button
      variant="outline"
      size="sm"
      onClick={async () => {
        try {
          if (canShare) await navigator.share({ title, text, url })
          else {
            await navigator.clipboard.writeText(url)
            toast.success('Link copied')
          }
        } catch {
          // share sheet dismissed
        }
      }}
    >
      {canShare ? <Share2 /> : <Link2 />} {canShare ? 'Share' : 'Copy link'}
    </Button>
  )
}
