import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['overlay'];

    open() {
        this.overlayTarget.classList.remove('hidden');
    }

    close() {
        this.overlayTarget.classList.add('hidden');
    }
}
