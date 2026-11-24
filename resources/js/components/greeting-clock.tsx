import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Moon, Sun, Sunrise, type LucideIcon } from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

/** The server's display time zone (UTC stored, Port of Spain shown). */
const ZONE = 'America/Port_of_Spain';

interface ClockParts {
    hour: number; // 1 to 12
    minute: number;
    second: number;
    period: string; // "am" or "pm"
    hour24: number;
    day: string; // "Tue 24 Nov"
    long: string; // "Tuesday 24 November 2026"
}

function partsOf(now: Date): ClockParts {
    const pick = (options: Intl.DateTimeFormatOptions) =>
        Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: ZONE, ...options }).formatToParts(now).map((p) => [p.type, p.value]));
    const time = pick({ hour: 'numeric', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
    const hour24 = Number(time.hour) % 24;

    return {
        hour: hour24 % 12 === 0 ? 12 : hour24 % 12,
        minute: Number(time.minute),
        second: Number(time.second),
        period: hour24 < 12 ? 'am' : 'pm',
        hour24,
        day: new Intl.DateTimeFormat('en-GB', { timeZone: ZONE, weekday: 'short', day: 'numeric', month: 'short' }).format(now),
        long: new Intl.DateTimeFormat('en-GB', { timeZone: ZONE, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(now),
    };
}

/** Morning before 12, afternoon before 5 pm, else evening (as the dashboard's greeting was). */
function greetingFor(hour24: number): { text: string; icon: LucideIcon } {
    if (hour24 < 12) return { text: 'Good morning', icon: Sunrise };
    if (hour24 < 17) return { text: 'Good afternoon', icon: Sun };
    return { text: 'Good evening', icon: Moon };
}

/** One rolling daisyUI countdown digit group (0 to 99). */
function Digits({ value, pad = false }: { value: number; pad?: boolean }) {
    return (
        <span className="countdown">
            <span style={{ '--value': value, ...(pad ? { '--digits': 2 } : {}) } as CSSProperties}>
                {pad ? String(value).padStart(2, '0') : value}
            </span>
        </span>
    );
}

/**
 * Kyle, 2026-11-24: the greeting moved off the dashboard into the top bar,
 * beside the bell, with a live clock in Port of Spain time. A quiet pill: the
 * time-of-day icon, "Good afternoon, Kyle" over the day and a ticking time
 * (daisyUI countdown, so each digit rolls). On phones only the time shows.
 * Screen readers get one plain sentence (not announced on every tick).
 */
export function GreetingClock() {
    const { auth } = usePage<SharedData>().props;
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        // Tick on the second boundary, then every second.
        let timer: number | undefined;
        const first = window.setTimeout(
            () => {
                setNow(new Date());
                timer = window.setInterval(() => setNow(new Date()), 1000);
            },
            1000 - (Date.now() % 1000),
        );

        return () => {
            window.clearTimeout(first);
            window.clearInterval(timer);
        };
    }, []);

    const t = partsOf(now);
    const greeting = greetingFor(t.hour24);
    const firstName = (auth?.user?.name ?? '').trim().split(/\s+/)[0] ?? '';
    const spoken = `${greeting.text}${firstName ? `, ${firstName}` : ''}. ${t.hour}:${String(t.minute).padStart(2, '0')} ${t.period}, ${t.long}.`;

    return (
        <div className="border-base-300 bg-base-200/60 rounded-box flex items-center gap-2.5 border py-1 ps-2 pe-3">
            <span className="bg-base-100 text-primary rounded-field hidden size-8 shrink-0 items-center justify-center sm:flex" aria-hidden="true">
                <greeting.icon className="size-4" />
            </span>
            <span className="sr-only">{spoken}</span>
            <span className="grid min-w-0 leading-tight" aria-hidden="true">
                <span className="hidden truncate text-sm font-medium sm:block">
                    {greeting.text}
                    {firstName && `, ${firstName}`}
                </span>
                <span className="text-base-content/70 flex items-center gap-1.5 text-xs">
                    <span className="hidden sm:inline">{t.day}</span>
                    <span className="hidden sm:inline" aria-hidden="true">
                        ·
                    </span>
                    <time dateTime={now.toISOString()} className="text-base-content flex items-baseline font-mono font-medium tabular-nums">
                        <Digits value={t.hour} />:
                        <Digits value={t.minute} pad />
                        <span className="text-base-content/60">:</span>
                        <span className="text-base-content/60">
                            <Digits value={t.second} pad />
                        </span>
                        <span className="ms-1 font-sans">{t.period}</span>
                    </time>
                </span>
            </span>
        </div>
    );
}
