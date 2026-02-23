import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['container'];

    dismiss() {
        this.containerTarget.style.transition = 'opacity 150ms ease-out';
        this.containerTarget.style.opacity = '0';

        setTimeout(() => {
            this.containerTarget.remove();
        }, 150);
    }
}
