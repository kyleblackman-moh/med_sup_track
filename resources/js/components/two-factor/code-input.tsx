import { cn } from '@/lib/utils';
import { forwardRef } from 'react';

interface CodeInputProps {
    id: string;
    value: string;
    onChange: (value: string) => void;
    invalid?: boolean;
    describedBy?: string;
    autoFocus?: boolean;
}

/**
 * MFA: the 6-digit authenticator code (Blueprint components/otp). One native
 * text input under the daisyUI otp boxes, so paste, password managers and
 * one-time-code autofill all work; anything but digits is dropped as typed.
 * Name it with a visible <label htmlFor={id}> beside it.
 */
export const CodeInput = forwardRef<HTMLInputElement, CodeInputProps>(({ id, value, onChange, invalid = false, describedBy, autoFocus }, ref) => (
    <label className={cn('otp', invalid && 'otp-error')}>
        {Array.from({ length: 6 }, (_, i) => (
            <span key={i} aria-hidden="true" />
        ))}
        <input
            ref={ref}
            id={id}
            name="code"
            type="text"
            autoComplete="one-time-code"
            inputMode="numeric"
            maxLength={6}
            pattern="[0-9]{6}"
            required
            autoFocus={autoFocus}
            value={value}
            onChange={(e) => onChange(e.target.value.replace(/\D/g, '').slice(0, 6))}
            aria-invalid={invalid || undefined}
            aria-describedby={describedBy}
        />
    </label>
));
CodeInput.displayName = 'CodeInput';
