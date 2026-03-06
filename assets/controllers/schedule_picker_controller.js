import { Controller } from '@hotwired/stimulus';

const DAYS_OF_WEEK = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

export default class extends Controller {
    static targets = [
        'frequency',
        'timeFields',
        'dayField',
        'hourSelect',
        'minuteSelect',
        'daySelect',
        'customField',
        'customInput',
        'cronInput',
        'preview',
    ];
    static values = { cron: String };

    connect() {
        const parsed = this.parseCron(this.cronValue);
        this.frequencyTarget.value = parsed.frequency;
        if (parsed.hour !== null) this.hourSelectTarget.value = parsed.hour;
        if (parsed.minute !== null)
            this.minuteSelectTarget.value = parsed.minute;
        if (parsed.day !== null) this.daySelectTarget.value = parsed.day;
        if (parsed.raw !== null) this.customInputTarget.value = parsed.raw;
        this.updateVisibility();
        this.updatePreview();
    }

    frequencyChanged() {
        this.updateVisibility();
        this.updateCron();
    }

    updateCron() {
        const frequency = this.frequencyTarget.value;
        let cron = '';

        switch (frequency) {
            case 'manual':
                cron = '';
                break;
            case 'every30':
                cron = '*/30 * * * *';
                break;
            case 'hourly':
                cron = '0 * * * *';
                break;
            case 'daily':
                cron = `${parseInt(this.minuteSelectTarget.value)} ${parseInt(this.hourSelectTarget.value)} * * *`;
                break;
            case 'weekly':
                cron = `${parseInt(this.minuteSelectTarget.value)} ${parseInt(this.hourSelectTarget.value)} * * ${this.daySelectTarget.value}`;
                break;
            case 'custom':
                cron = this.customInputTarget.value;
                break;
        }

        this.cronInputTarget.value = cron;
        this.updatePreview();
    }

    updateVisibility() {
        const frequency = this.frequencyTarget.value;
        const showTime = frequency === 'daily' || frequency === 'weekly';
        const showDay = frequency === 'weekly';
        const showCustom = frequency === 'custom';

        this.timeFieldsTarget.classList.toggle('hidden', !showTime);
        this.dayFieldTarget.classList.toggle('hidden', !showDay);
        this.customFieldTarget.classList.toggle('hidden', !showCustom);
    }

    updatePreview() {
        this.previewTarget.textContent = this.describeSchedule();
    }

    describeSchedule() {
        const frequency = this.frequencyTarget.value;

        switch (frequency) {
            case 'manual':
                return 'Manual only — no automatic schedule';
            case 'every30':
                return 'Every 30 minutes';
            case 'hourly':
                return 'Every hour at :00';
            case 'daily': {
                const time = this.formatTime(
                    this.hourSelectTarget.value,
                    this.minuteSelectTarget.value,
                );
                return `Every day at ${time}`;
            }
            case 'weekly': {
                const time = this.formatTime(
                    this.hourSelectTarget.value,
                    this.minuteSelectTarget.value,
                );
                const day =
                    DAYS_OF_WEEK[this.daySelectTarget.value] || 'Sunday';
                return `Every ${day} at ${time}`;
            }
            case 'custom':
                return this.customInputTarget.value
                    ? `Custom: ${this.customInputTarget.value}`
                    : 'Enter a cron expression';
            default:
                return '';
        }
    }

    formatTime(hour, minute) {
        const h = parseInt(hour);
        const m = parseInt(minute);
        const period = h >= 12 ? 'PM' : 'AM';
        const displayHour = h === 0 ? 12 : h > 12 ? h - 12 : h;
        return `${displayHour}:${String(m).padStart(2, '0')} ${period}`;
    }

    parseCron(expr) {
        const result = {
            frequency: 'manual',
            hour: null,
            minute: null,
            day: null,
            raw: null,
        };

        if (!expr || expr.trim() === '') {
            return result;
        }

        const trimmed = expr.trim();

        if (trimmed === '*/30 * * * *') {
            result.frequency = 'every30';
            return result;
        }

        if (trimmed === '0 * * * *') {
            result.frequency = 'hourly';
            return result;
        }

        // Match daily: min hour * * *
        const dailyMatch = trimmed.match(
            /^(\d{1,2})\s+(\d{1,2})\s+\*\s+\*\s+\*$/,
        );
        if (dailyMatch) {
            result.frequency = 'daily';
            result.minute = parseInt(dailyMatch[1]);
            result.hour = parseInt(dailyMatch[2]);
            return result;
        }

        // Match weekly: min hour * * day
        const weeklyMatch = trimmed.match(
            /^(\d{1,2})\s+(\d{1,2})\s+\*\s+\*\s+(\d)$/,
        );
        if (weeklyMatch) {
            result.frequency = 'weekly';
            result.minute = parseInt(weeklyMatch[1]);
            result.hour = parseInt(weeklyMatch[2]);
            result.day = parseInt(weeklyMatch[3]);
            return result;
        }

        // Unrecognized — fall back to custom
        result.frequency = 'custom';
        result.raw = trimmed;
        return result;
    }
}
