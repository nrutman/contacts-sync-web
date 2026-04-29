import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['panel', 'trigger'];
    static values = { open: { type: Boolean, default: false } };

    connect() {
        this.render();
    }

    toggle(event) {
        event?.preventDefault();
        this.openValue = !this.openValue;
    }

    close() {
        this.openValue = false;
    }

    closeOnOutsideClick(event) {
        if (!this.openValue) return;
        if (this.element.contains(event.target)) return;
        this.openValue = false;
    }

    closeOnEscape(event) {
        if (event.key === 'Escape' && this.openValue) {
            this.openValue = false;
            if (this.hasTriggerTarget) {
                this.triggerTarget.focus();
            }
        }
    }

    openValueChanged() {
        this.render();
    }

    render() {
        if (this.hasPanelTarget) {
            this.panelTarget.classList.toggle('hidden', !this.openValue);
        }
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute(
                'aria-expanded',
                this.openValue ? 'true' : 'false',
            );
        }
    }
}
