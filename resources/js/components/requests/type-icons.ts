import { ArrowLeftRight, ClipboardList, FileX, type LucideIcon, PackageX, Siren, TrendingUp } from 'lucide-react';

/**
 * V2-2c: the server names each type's icon (RequestDetailResource::TYPE_ICONS,
 * CaseHero::DEFAULT_TYPE_ICON); an unknown name gets none. Shared by the type
 * cards and the case hero (V2-4).
 */
export const TYPE_ICONS: Record<string, LucideIcon> = {
    siren: Siren,
    'package-x': PackageX,
    'file-x': FileX,
    'trending-up': TrendingUp,
    'arrow-left-right': ArrowLeftRight,
    'clipboard-list': ClipboardList,
};
