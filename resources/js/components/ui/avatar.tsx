import * as React from 'react';

import { cn } from '@/lib/utils';

/** daisyUI avatar. The fallback shows until the image loads, and stays if it fails. */
const AvatarContext = React.createContext<{ loaded: boolean; setLoaded: (v: boolean) => void }>({ loaded: false, setLoaded: () => {} });

const Avatar = React.forwardRef<HTMLSpanElement, React.HTMLAttributes<HTMLSpanElement>>(({ className, ...props }, ref) => {
    const [loaded, setLoaded] = React.useState(false);
    return (
        <AvatarContext.Provider value={{ loaded, setLoaded }}>
            <span ref={ref} className={cn('avatar relative flex size-10 shrink-0 overflow-hidden rounded-full', className)} {...props} />
        </AvatarContext.Provider>
    );
});
Avatar.displayName = 'Avatar';

const AvatarImage = React.forwardRef<HTMLImageElement, React.ImgHTMLAttributes<HTMLImageElement>>(({ className, src, alt = '', ...props }, ref) => {
    const { loaded, setLoaded } = React.useContext(AvatarContext);
    if (!src) return null;
    return (
        <img
            ref={ref}
            src={src}
            alt={alt}
            onLoad={() => setLoaded(true)}
            onError={() => setLoaded(false)}
            className={cn('aspect-square size-full', !loaded && 'hidden', className)}
            {...props}
        />
    );
});
AvatarImage.displayName = 'AvatarImage';

const AvatarFallback = React.forwardRef<HTMLSpanElement, React.HTMLAttributes<HTMLSpanElement>>(({ className, ...props }, ref) => {
    const { loaded } = React.useContext(AvatarContext);
    if (loaded) return null;
    return <span ref={ref} className={cn('bg-primary text-primary-content flex size-full items-center justify-center rounded-full', className)} {...props} />;
});
AvatarFallback.displayName = 'AvatarFallback';

export { Avatar, AvatarFallback, AvatarImage };
