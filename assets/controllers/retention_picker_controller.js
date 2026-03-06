import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['preset', 'customField'];

    connect() {
        this.formInput = this.customFieldTarget.querySelector('input');
        const days = this.formInput.value;

        if (days === '30') {
            this.presetTarget.value = '30';
        } else if (days === '365') {
            this.presetTarget.value = '365';
        } else if (days !== '') {
            this.presetTarget.value = 'custom';
        } else {
            this.presetTarget.value = '';
        }

        this.updateVisibility();
    }

    presetChanged() {
        const value = this.presetTarget.value;

        if (value === 'custom') {
            this.formInput.value = '';
            this.formInput.focus();
        } else {
            this.formInput.value = value;
        }

        this.updateVisibility();
    }

    updateVisibility() {
        const isCustom = this.presetTarget.value === 'custom';
        this.customFieldTarget.classList.toggle('hidden', !isCustom);
    }
}
