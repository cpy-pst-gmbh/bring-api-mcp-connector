import { Controller } from '@hotwired/stimulus';

/*
 * Turns the loading overlay into a real lock.
 *
 * The overlay stops the mouse, but pointer-events say nothing about the
 * keyboard: Tab and Enter still reached the form behind it. `inert` covers
 * both, and there is no way to set it from a stylesheet.
 *
 * What counts as busy is not decided here. The controller mirrors the
 * aria-busy attributes Turbo sets — the same ones app.css hangs the overlay
 * off — so the lock and the dim cannot disagree, and a request that ends in a
 * way nobody thought of still lifts the lock.
 */
export default class extends Controller {
    connect() {
        this.observer = new MutationObserver(() => this.refresh());
        this.observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['aria-busy'],
            subtree: true,
        });

        // Turbo replaces the body in the middle of a visit, so a fresh
        // controller can arrive while the document is already busy.
        this.refresh();
    }

    disconnect() {
        this.observer.disconnect();
        this.element.inert = false;
        this.releaseForm();
    }

    refresh() {
        // The same two states the overlay is drawn for: Turbo marks the
        // document for a visit, but a form submission only ever marks the form.
        const submitting = document.querySelector('form[aria-busy="true"]');
        const busy = document.documentElement.getAttribute('aria-busy') === 'true' || submitting !== null;

        this.element.inert = busy;

        if (this.submitting !== submitting) {
            this.releaseForm();
        }

        // An open modal <dialog> is exempt from the inertness of its
        // ancestors — the browser treats it as the one live part of the page —
        // so an inert body does not reach the form inside it. That form gets
        // its own.
        if (submitting) {
            submitting.inert = true;
            this.submitting = submitting;
        }
    }

    releaseForm() {
        if (this.submitting) {
            this.submitting.inert = false;
            this.submitting = null;
        }
    }
}
