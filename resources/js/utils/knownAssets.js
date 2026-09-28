// Helpers for the known-asset import flow (Feature 1c). Kept as pure functions
// so the autofill/label logic is unit-testable without driving Vuetify dialogs.

/**
 * Human-readable label for a known asset in the autocomplete.
 */
export function knownAssetLabel(asset) {
    if (!asset) {
        return '';
    }
    return asset.ticker ? `${asset.ticker} — ${asset.name ?? ''}`.trim() : (asset.name ?? asset.subject ?? '');
}

/**
 * Convert a human-entered amount into the on-chain base-unit integer for a token with
 * `decimals` (e.g. 10 USDM at 6 decimals → 10_000_000). Decimals ≤ 0 pass through as a
 * whole number. This is what actually gets distributed, so it must be an integer.
 */
export function toBaseUnits(amount, decimals) {
    const n = Number(amount);
    if (!Number.isFinite(n)) {
        return 0;
    }
    return Math.round(n * 10 ** (decimals > 0 ? decimals : 0));
}

/**
 * Map a selected/looked-up asset into the reward-token fields the code form
 * uses, plus the display metadata cached for the reward-detail view.
 */
export function assetToToken(asset) {
    return {
        policy_id: asset.policy_id ?? null,
        token_id: asset.asset_name ?? null,
        meta: {
            name: asset.name ?? null,
            ticker: asset.ticker ?? null,
            decimals: asset.decimals || 0,
            logo: asset.logo || null,
        },
    };
}

/**
 * Format an on-chain base-unit quantity for a token with `decimals` (e.g. 251000000 USDM at
 * 6 decimals → "251"). Worked on the digit string rather than a Number, because a summed
 * quantity can pass 2^53 and a float would silently round it. `locale` defaults to the
 * reader's own.
 */
export function fromBaseUnits(raw, decimals, locale = undefined) {
    const digits = String(raw ?? '0').replace(/^0+(?=\d)/, '');
    if (!/^\d+$/.test(digits)) {
        return String(raw ?? '');
    }
    const places = decimals > 0 ? decimals : 0;
    const padded = digits.padStart(places + 1, '0');
    const whole = padded.slice(0, padded.length - places);
    const fraction = padded.slice(padded.length - places).replace(/0+$/, '');
    const grouped = BigInt(whole).toLocaleString(locale);
    // The whole part is grouped for the reader's locale, so the decimal point has to be
    // theirs too, or 1.234.5 in German leaves no way to tell which dot is the decimal.
    const point = new Intl.NumberFormat(locale)
        .formatToParts(1.1)
        .find((part) => part.type === 'decimal').value;

    return fraction ? `${grouped}${point}${fraction}` : grouped;
}
