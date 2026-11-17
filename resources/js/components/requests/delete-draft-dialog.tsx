import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';

interface DeleteDraftDialogProps {
    /** The draft to delete; null keeps the dialog closed. */
    caseId: string | null;
    onClose: () => void;
    /** Awaited just before the request goes, for example to stop autosave and let a save in flight finish. */
    onBeforeDelete?: () => void | Promise<unknown>;
}

/**
 * LIST-1: the one confirmation for deleting a draft (Requests list, Ad Hoc
 * form, old edit page). Cancel has the focus when it opens. The server
 * decides everything and redirects to the Requests list with a message.
 */
export function DeleteDraftDialog({ caseId, onClose, onBeforeDelete }: DeleteDraftDialogProps) {
    const cancelRef = useRef<HTMLButtonElement>(null);
    const [deleting, setDeleting] = useState(false);

    const confirm = async () => {
        if (caseId === null || deleting) {
            return;
        }
        setDeleting(true);
        await onBeforeDelete?.();
        router.delete(route('requests.destroy', caseId), {
            onFinish: () => {
                setDeleting(false);
                onClose();
            },
        });
    };

    return (
        <Dialog open={caseId !== null} onOpenChange={(open) => !open && !deleting && onClose()}>
            <DialogContent
                onOpenAutoFocus={(e) => {
                    e.preventDefault();
                    cancelRef.current?.focus();
                }}
            >
                <DialogHeader>
                    <DialogTitle>Delete this draft?</DialogTitle>
                    <DialogDescription>
                        It will be permanently removed together with any files you uploaded. This can&apos;t be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button ref={cancelRef} type="button" variant="outline" className="h-11 md:h-9" onClick={onClose} disabled={deleting}>
                        Cancel
                    </Button>
                    <Button type="button" variant="destructive" className="h-11 md:h-9" onClick={() => void confirm()} disabled={deleting}>
                        {deleting ? 'Deleting…' : 'Delete draft'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
