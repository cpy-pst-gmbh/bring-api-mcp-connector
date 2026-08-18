import { Controller } from '@hotwired/stimulus';

/*
 * Opens a <dialog> as a modal.
 *
 * Escape and the backdrop close it, both handled here — Escape is native to
 * <dialog>, the backdrop needs the click check below because a click anywhere
 * on the backdrop reports the dialog itself as the target.
 *
 * Without JavaScript the dialog never opens, so the template pairs this with a
 * <noscript> rule that renders it as a plain block instead.
 */
export default class extends Controller {
    static targets = ['dialog'];

    connect() {
        // A dialog rendered with `open` carries something the visitor has to
        // see — a form that came back with errors. Reopening it modally gets
        // the backdrop and the focus trap that markup alone cannot.
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
            this.dialogTarget.showModal();
        }
    }

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    closeOnBackdrop(event) {
        if (event.target === this.dialogTarget) {
            this.dialogTarget.close();
        }
    }
}
