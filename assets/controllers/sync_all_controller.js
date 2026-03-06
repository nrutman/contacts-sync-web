import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        lists: { type: Array, default: [] },
        csrfToken: String,
    };

    static targets = ['modal', 'listStatus', 'closeButton', 'cancelButton'];

    submit(event) {
        event.preventDefault();

        if (this.listsValue.length === 0) {
            // No JS data — fall back to form submission
            this.element.querySelector('form').submit();
            return;
        }

        this.cancelled = false;
        this.abortController = null;
        this.showModal();
        this.runSyncs();
    }

    showModal() {
        if (this.hasModalTarget) {
            this.modalTarget.showModal();
        }
    }

    close() {
        if (this.hasModalTarget) {
            this.modalTarget.close();
        }
        window.location.reload();
    }

    cancel() {
        this.cancelled = true;
        if (this.abortController) {
            this.abortController.abort();
        }
    }

    preventCloseWhileRunning(event) {
        if (this.hasCloseButtonTarget && this.closeButtonTarget.disabled) {
            event.preventDefault();
        }
    }

    async runSyncs() {
        const lists = this.listsValue;

        if (this.hasCloseButtonTarget) {
            this.closeButtonTarget.disabled = true;
        }
        if (this.hasCancelButtonTarget && lists.length > 1) {
            this.cancelButtonTarget.classList.remove('hidden');
        }

        for (const list of lists) {
            if (this.cancelled) {
                this.updateStatus(list.id, 'skipped');
                continue;
            }

            this.updateStatus(list.id, 'syncing');
            this.abortController = new AbortController();

            try {
                const response = await fetch(
                    `/api/sync-lists/${list.id}/sync`,
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': this.csrfTokenValue,
                            'Content-Type': 'application/json',
                        },
                        signal: this.abortController.signal,
                    },
                );

                const data = await response.json();

                if (data.success) {
                    this.updateStatus(
                        list.id,
                        'done',
                        `+${data.addedCount} / -${data.removedCount}`,
                    );
                } else {
                    this.updateStatus(
                        list.id,
                        'failed',
                        data.errorMessage || 'Unknown error',
                    );
                }
            } catch (error) {
                if (this.cancelled) {
                    this.updateStatus(list.id, 'skipped', 'Cancelled');
                } else {
                    this.updateStatus(
                        list.id,
                        'failed',
                        error.message || 'Network error',
                    );
                }
            }
        }

        if (this.hasCancelButtonTarget) {
            this.cancelButtonTarget.classList.add('hidden');
        }
        if (this.hasCloseButtonTarget) {
            this.closeButtonTarget.disabled = false;
        }
    }

    updateStatus(listId, status, detail = '') {
        const row = this.listStatusTargets.find(
            (el) => el.dataset.listId === String(listId),
        );

        if (!row) return;

        const statusEl = row.querySelector("[data-role='status']");
        const detailEl = row.querySelector("[data-role='detail']");

        // Toggle status icons
        const icons = ['waiting', 'syncing', 'done', 'failed', 'skipped'];
        for (const icon of icons) {
            const el = row.querySelector(`[data-role='icon-${icon}']`);
            if (el) {
                el.classList.toggle('hidden', icon !== status);
            }
        }

        if (statusEl) {
            const labels = {
                waiting: 'Waiting',
                syncing: 'Syncing\u2026',
                done: 'Done',
                failed: 'Failed',
                skipped: 'Skipped',
            };

            const colors = {
                waiting: 'text-muted-foreground',
                syncing: 'text-primary',
                done: 'text-green-600',
                failed: 'text-destructive',
                skipped: 'text-muted-foreground',
            };

            statusEl.textContent = labels[status] || status;
            statusEl.className = `text-sm font-medium ${colors[status] || 'text-gray-400'}`;
        }

        if (detailEl) {
            detailEl.textContent = detail;
        }
    }
}
