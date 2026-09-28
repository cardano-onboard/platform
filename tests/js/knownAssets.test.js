import { describe, it, expect } from 'vitest';
import { knownAssetLabel, assetToToken, toBaseUnits, fromBaseUnits } from '../../resources/js/utils/knownAssets.js';

describe('knownAssets utils', () => {
    const hosky = {
        policy_id: 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
        asset_name: '484f534b59',
        ticker: 'HOSKY',
        name: 'HOSKY Token',
        decimals: 0,
        subject: 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235484f534b59',
    };

    it('labels an asset by ticker and name', () => {
        expect(knownAssetLabel(hosky)).toBe('HOSKY — HOSKY Token');
    });

    it('falls back to name then subject when ticker is missing', () => {
        expect(knownAssetLabel({ name: 'Some Token' })).toBe('Some Token');
        expect(knownAssetLabel({ subject: 'abc123' })).toBe('abc123');
        expect(knownAssetLabel(null)).toBe('');
    });

    it('maps a selected asset into reward-token fields and metadata', () => {
        const mapped = assetToToken(hosky);
        expect(mapped.policy_id).toBe(hosky.policy_id);
        expect(mapped.token_id).toBe('484f534b59');
        expect(mapped.meta).toEqual({
            name: 'HOSKY Token',
            ticker: 'HOSKY',
            decimals: 0,
            logo: null,
        });
    });

    it('defaults decimals to 0 and logo to null when absent', () => {
        const mapped = assetToToken({ policy_id: 'p', asset_name: 'a', name: 'X' });
        expect(mapped.meta.decimals).toBe(0);
        expect(mapped.meta.logo).toBeNull();
    });

    it('converts human amounts to on-chain base units by decimals', () => {
        expect(toBaseUnits(10, 6)).toBe(10000000); // 10 USDM -> base units
        expect(toBaseUnits('10.5', 6)).toBe(10500000);
        expect(toBaseUnits(44000, 0)).toBe(44000); // 0-decimal token passes through
        expect(toBaseUnits(1, 0)).toBe(1);
        expect(toBaseUnits('', 6)).toBe(0); // non-numeric guard
    });

    describe('fromBaseUnits', () => {
        it('shifts a quantity by the token decimals', () => {
            expect(fromBaseUnits('251000000', 6)).toBe('251');
            expect(fromBaseUnits('1500000', 6)).toBe('1.5');
            expect(fromBaseUnits('5', 6)).toBe('0.000005');
        });

        it('uses the locale decimal point alongside the locale grouping', () => {
            expect(fromBaseUnits('1234500000', 6, 'en-US')).toBe('1,234.5');
            expect(fromBaseUnits('1234500000', 6, 'de-DE')).toBe('1.234,5');
        });

        it('leaves a token without decimals whole', () => {
            expect(fromBaseUnits('1000', 0)).toBe((1000).toLocaleString());
            expect(fromBaseUnits('7', undefined)).toBe('7');
        });

        // A summed quantity can pass 2^53, where a float would round the last digits.
        it('keeps every digit of a quantity too large for a float', () => {
            expect(fromBaseUnits('9007199254740993123', 3)).toBe(
                `${BigInt('9007199254740993').toLocaleString()}.123`,
            );
        });
    });
});

