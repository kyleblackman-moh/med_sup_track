/**
 * Kyle, 2026-11-24: a case is named by its drug and DIN ("Degarelix · DIN
 * 02345678"); the reference number moves under it. Falls back to the
 * reference when no item name was entered.
 */
export function caseTitle(item: string | null | undefined, din: string | null | undefined, reference?: string | null): string {
    const name = (item ?? '').trim();
    const code = (din ?? '').trim();
    if (name === '') {
        return reference ?? 'Case';
    }

    return code === '' ? name : `${name} · DIN ${code}`;
}
