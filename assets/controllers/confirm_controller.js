import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        message: { type: String, default: 'Are you sure?' },
    };

    connect() {
        this.element.addEventListener('submit', this.confirm.bind(this));
    }

    disconnect() {
        this.element.removeEventListener('submit', this.confirm.bind(this));
    }

    confirm(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }
}
