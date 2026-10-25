import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { SecureImage } from '@/components/SecureImage'

/** Full-size image viewer for chat photos (PRD 10.2). */
export function Lightbox({ image, onClose }: { image: { id?: number; src?: string; name: string } | null; onClose: () => void }) {
  return (
    <Dialog open={!!image} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-[95vw] border-0 bg-black/90 p-2 sm:max-w-4xl">
        <DialogTitle className="sr-only">{image?.name}</DialogTitle>
        {image?.src ? (
          <img src={image.src} alt={image.name} className="max-h-[85svh] w-full object-contain" />
        ) : image?.id ? (
          <SecureImage attachmentId={image.id} alt={image.name} className="max-h-[85svh] w-full cursor-default object-contain" />
        ) : null}
      </DialogContent>
    </Dialog>
  )
}
