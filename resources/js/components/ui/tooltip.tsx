import * as React from 'react';

import { cn } from '@/lib/utils';

/** daisyUI tooltip (CSS only, shows on hover and focus). */
interface TooltipProps extends React.HTMLAttributes<HTMLDivElement> {
    tip: string;
    side?: 'top' | 'right' | 'bottom' | 'left';
}

const SIDE = { top: 'tooltip-top', right: 'tooltip-right', bottom: 'tooltip-bottom', left: 'tooltip-left' } as const;

function Tooltip({ tip, side = 'top', className, ...props }: TooltipProps) {
    return <div data-tip={tip} className={cn('tooltip', SIDE[side], className)} {...props} />;
}

export { Tooltip };
