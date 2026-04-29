import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['panel', 'trigger'];
    static values = { open: { type: Boolean, default: false } };

    connect() {
        console.log('[disclosure] connect', {
            element: this.element,
            hasPanelTarget: this.hasPanelTarget,
            hasTriggerTarget: this.hasTriggerTarget,
            openValue: this.openValue,
        });
        this.render();
    }

    toggle(event) {
        console.log('[disclosure] toggle fired', {
            target: event?.target,
            currentTarget: event?.currentTarget,
            openValueBefore: this.openValue,
        });
        event?.preventDefault();
        this.openValue = !this.openValue;
        console.log('[disclosure] toggle openValue after', this.openValue);
    }

    close() {
        console.log('[disclosure] close');
        this.openValue = false;
    }

    closeOnOutsideClick(event) {
        if (!this.openValue) return;
        if (this.element.contains(event.target)) return;
        console.log('[disclosure] closeOnOutsideClick — closing');
        this.openValue = false;
    }

    closeOnEscape(event) {
        if (event.key === 'Escape' && this.openValue) {
            console.log('[disclosure] closeOnEscape — closing');
            this.openValue = false;
            if (this.hasTriggerTarget) {
                this.triggerTarget.focus();
            }
        }
    }

    openValueChanged(value, previous) {
        console.log('[disclosure] openValueChanged', { value, previous });
        this.render();
    }

    render() {
        console.log('[disclosure] render', {
            openValue: this.openValue,
            hasPanelTarget: this.hasPanelTarget,
            hasTriggerTarget: this.hasTriggerTarget,
        });
        if (this.hasPanelTarget) {
            this.panelTarget.classList.toggle('hidden', !this.openValue);
            console.log('[disclosure] panel classList', this.panelTarget.className);
        } else {
            console.warn('[disclosure] no panel target found!');
        }
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute(
                'aria-expanded',
                this.openValue ? 'true' : 'false',
            );
        }
    }
}
