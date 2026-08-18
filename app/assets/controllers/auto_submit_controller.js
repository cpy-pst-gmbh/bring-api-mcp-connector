import { Controller } from '@hotwired/stimulus';

/*
 * Submits the surrounding form as soon as a control changes.
 *
 * The form keeps a submit button in the markup so it still works without
 * JavaScript; this hides it, because with the controller running it would
 * never be needed and only invite a second click.
 */
export default class extends Controller {
    static targets = ['fallback'];

    connect() {
        this.fallbackTargets.forEach((element) => element.classList.add('hidden'));
    }

    submit() {
        // requestSubmit rather than submit: it fires the submit event, which is
        // what Turbo and the CSRF controller listen for.
        this.element.requestSubmit();
    }
}
