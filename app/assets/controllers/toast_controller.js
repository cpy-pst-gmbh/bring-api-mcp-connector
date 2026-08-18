import { Controller } from '@hotwired/stimulus';

/*
 * Dismisses a toast on its own after a while, and on click.
 *
 * Without JavaScript the message still renders — it simply stays, which is a
 * better failure than a notice nobody ever sees.
 */
export default class extends Controller {
    static values = {
        // Errors are worth reading twice; a confirmation is not.
        delay: { type: Number, default: 5000 },
    };

    connect() {
        this.timeout = window.setTimeout(() => this.dismiss(), this.delayValue);
    }

    disconnect() {
        window.clearTimeout(this.timeout);
    }

    dismiss() {
        window.clearTimeout(this.timeout);

        // Let the transition finish before the element leaves the document.
        this.element.classList.add('opacity-0', 'translate-y-1');
        this.element.addEventListener('transitionend', () => this.element.remove(), { once: true });
    }
}
