import { Controller } from '@hotwired/stimulus';

const SELECT_CLASSES = 'flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background data-[placeholder]:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 [&>span]:line-clamp-1';

const SPINNER_HTML = '<svg class="animate-spin h-4 w-4 text-muted-foreground inline-block ml-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';

export default class extends Controller {
    static values = { apiBase: String };
    static targets = ['sourceCredentialWrapper', 'sourceListWrapper', 'destinationCredentialWrapper', 'destinationListWrapper'];

    connect() {
        // If credentials are already selected (edit form), fetch lists for both sides
        this.fetchListsForSide('source');
        this.fetchListsForSide('destination');
    }

    sourceCredentialChanged() {
        this.fetchListsForSide('source');
    }

    destinationCredentialChanged() {
        this.fetchListsForSide('destination');
    }

    async fetchListsForSide(side) {
        const credentialWrapper = this[`${side}CredentialWrapperTarget`];
        const listWrapper = this[`${side}ListWrapperTarget`];

        const credentialSelect = credentialWrapper.querySelector('select');
        if (!credentialSelect) return;

        const credentialId = credentialSelect.value;
        const textInput = listWrapper.querySelector('input[type="text"], input[type="hidden"][data-list-picker-original]');

        if (!credentialId) {
            this.restoreTextInput(listWrapper);
            return;
        }

        // Show loading spinner
        const spinner = this.showSpinner(listWrapper);

        try {
            const response = await fetch(`${this.apiBaseValue}/${credentialId}/lists`);
            const data = await response.json();

            this.removeSpinner(spinner);

            if (!data.discoverable || data.error || !data.lists) {
                this.restoreTextInput(listWrapper);
                return;
            }

            const lists = data.lists;
            if (Object.keys(lists).length === 0) {
                this.restoreTextInput(listWrapper);
                return;
            }

            this.replaceWithSelect(listWrapper, lists);
        } catch {
            this.removeSpinner(spinner);
            this.restoreTextInput(listWrapper);
        }
    }

    replaceWithSelect(listWrapper, lists) {
        // Find the original text input
        const input = listWrapper.querySelector('input[type="text"], input[type="hidden"][data-list-picker-original]');
        if (!input) return;

        // Remove any existing generated select
        const existingSelect = listWrapper.querySelector('select[data-list-picker-select]');
        if (existingSelect) existingSelect.remove();

        const currentValue = input.value;

        // Hide the text input and mark it
        input.type = 'hidden';
        input.setAttribute('data-list-picker-original', 'true');

        // Create a select element
        const select = document.createElement('select');
        select.className = SELECT_CLASSES;
        select.setAttribute('data-list-picker-select', 'true');

        // Add placeholder option
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select a list --';
        select.appendChild(placeholder);

        // Track whether current value was found
        let valueFound = false;

        // Add list options
        for (const [id, name] of Object.entries(lists)) {
            const option = document.createElement('option');
            option.value = id;
            option.textContent = id === name ? name : `${name} (${id})`;
            if (id === currentValue) {
                option.selected = true;
                valueFound = true;
            }
            select.appendChild(option);
        }

        // If current value exists but wasn't in the returned list, add it with "(not found)" label
        if (currentValue && !valueFound) {
            const option = document.createElement('option');
            option.value = currentValue;
            option.textContent = `${currentValue} (not found)`;
            option.selected = true;
            select.appendChild(option);
        }

        // Sync value back to hidden input on change
        select.addEventListener('change', () => {
            input.value = select.value;
        });

        // Insert select after the hidden input within the field-content div
        const fieldContent = input.closest('[data-slot="field-content"]') || input.parentNode;
        fieldContent.insertBefore(select, fieldContent.firstChild);
    }

    restoreTextInput(listWrapper) {
        const input = listWrapper.querySelector('input[data-list-picker-original]');
        if (input) {
            input.type = 'text';
            input.removeAttribute('data-list-picker-original');
        }

        const existingSelect = listWrapper.querySelector('select[data-list-picker-select]');
        if (existingSelect) existingSelect.remove();
    }

    showSpinner(listWrapper) {
        const spinner = document.createElement('span');
        spinner.setAttribute('data-list-picker-spinner', 'true');
        spinner.innerHTML = SPINNER_HTML;

        // Place spinner next to the label
        const label = listWrapper.querySelector('label');
        if (label) {
            label.appendChild(spinner);
        }

        return spinner;
    }

    removeSpinner(spinner) {
        if (spinner && spinner.parentNode) {
            spinner.remove();
        }
    }
}
