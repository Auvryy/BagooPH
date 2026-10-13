import { authRequest, AuthRequestError, AuthRequestScope, normalizeRegistrationEmail, validEmailFormat } from './authRequests';

export type EmailAvailability = {
    email: string;
    status: 'idle' | 'waiting' | 'checking' | 'available' | 'taken' | 'invalid' | 'error' | 'redirecting';
    message?: string;
};

type Options = {
    check?: (email: string, signal: AbortSignal) => Promise<Record<string, unknown>>;
    debounceMs?: number;
    timeoutMs?: number;
    now?: () => number;
};

export class RegistrationEmailAvailability {
    private email = '';
    private enabled = false;
    private revision = 0;
    private scope = new AuthRequestScope();
    private timer?: ReturnType<typeof setTimeout>;
    private timeout?: ReturnType<typeof setTimeout>;
    private retryAfter = 0;
    private state: EmailAvailability = { email: '', status: 'idle' };
    private check: NonNullable<Options['check']>;
    private now: () => number;

    constructor(private publish: (state: EmailAvailability) => void, private options: Options = {}) {
        this.check = options.check ?? ((email, signal) => authRequest('/register/email/validate', { email }, signal));
        this.now = options.now ?? Date.now;
    }

    private emit(status: EmailAvailability['status'], message?: string) {
        this.state = { email: this.email, status, message };
        this.publish(this.state);
    }

    update(rawEmail: string, enabled = true) {
        const email = normalizeRegistrationEmail(rawEmail);
        if (email === this.email && enabled === this.enabled) return;
        this.cancel();
        this.email = email;
        this.enabled = enabled;
        if (!enabled || !email || !validEmailFormat(email)) {
            this.emit('idle');
            return;
        }
        this.emit('waiting');
        this.timer = setTimeout(() => { void this.checkNow(); }, this.options.debounceMs ?? 500);
    }

    async checkNow(): Promise<void> {
        clearTimeout(this.timer);
        this.timer = undefined;
        if (!this.enabled || !validEmailFormat(this.email) || ['checking', 'available', 'taken', 'invalid', 'redirecting'].includes(this.state.status)) return;
        const cooldown = Math.ceil((this.retryAfter - this.now()) / 1000);
        if (cooldown > 0) {
            this.emit('error', `Please wait ${cooldown} seconds before checking again.`);
            return;
        }
        const request = this.scope.begin();
        if (!request) return;
        const email = this.email;
        const revision = this.revision;
        const current = () => this.revision === revision && this.scope.current(request);
        this.emit('checking');
        const timeout = setTimeout(() => {
            if (!current()) return;
            this.scope.cancel();
            this.emit('error', 'The email check took too long. Please try again.');
        }, this.options.timeoutMs ?? 10000);
        this.timeout = timeout;
        try {
            const reply = await this.check(email, request.signal);
            if (!current()) return;
            if (reply.email !== email || typeof reply.available !== 'boolean') {
                throw new AuthRequestError('We could not confirm this email. Please try again.');
            }
            this.emit(reply.available ? 'available' : 'taken');
        } catch (error) {
            if (!current()) return;
            if (error instanceof AuthRequestError && error.redirect) {
                this.emit('redirecting', 'Returning to your account.');
            } else if (error instanceof AuthRequestError && error.status === 429) {
                this.retryAfter = this.now() + Math.max(1, error.cooldown || 60) * 1000;
                this.emit('error', `Please wait ${Math.max(1, error.cooldown || 60)} seconds before checking again.`);
            } else if (error instanceof AuthRequestError && error.fields.email) {
                this.emit('invalid', error.fields.email);
            } else {
                this.emit('error', error instanceof AuthRequestError ? error.message : 'We could not check this email. Please try again.');
            }
        } finally {
            clearTimeout(timeout);
            if (this.timeout === timeout) this.timeout = undefined;
            this.scope.finish(request);
        }
    }

    cancel() {
        this.revision++;
        this.enabled = false;
        clearTimeout(this.timer);
        clearTimeout(this.timeout);
        this.timer = undefined;
        this.timeout = undefined;
        this.scope.cancel();
    }
}
