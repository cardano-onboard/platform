import { describe, it, expect, vi, afterEach } from 'vitest';
import { storeFile } from '../../resources/js/utils/upload.js';

const SIGNING_URL = '/uploads/signed-url';

function signedResponse(overrides = {}) {
    return {
        data: {
            uuid: '0f1c9b3e-6b0e-4a2f-9f25-6f6a8a1f7c11',
            bucket: 'onbd-private',
            key: 'tmp/0f1c9b3e-6b0e-4a2f-9f25-6f6a8a1f7c11',
            url: 'https://object-store.example.test/onbd-private/tmp/0f1c9b3e?X-Amz-Signature=abc',
            headers: {
                Host: 'object-store.example.test',
                'Content-Type': 'text/csv',
            },
            ...overrides,
        },
    };
}

function codesFile(name = 'codes.csv', size = 400) {
    const file = new File(['a,b,c'], name, { type: 'text/csv' });

    // jsdom sizes the File from its parts; the upload reports progress against the real
    // byte count, so pin it rather than depending on how the blob was built.
    Object.defineProperty(file, 'size', { value: size });

    return file;
}

afterEach(() => {
    delete window.axios;
});

describe('storeFile', () => {
    it('signs, uploads, and returns the key the import endpoint reads', async () => {
        const put = vi.fn().mockResolvedValue({});
        window.axios = { post: vi.fn().mockResolvedValue(signedResponse()), put };

        const result = await storeFile(codesFile(), { signingUrl: SIGNING_URL });

        expect(window.axios.post).toHaveBeenCalledWith(SIGNING_URL, { content_type: 'text/csv' });

        const [url, body] = put.mock.calls[0];
        expect(url).toBe(signedResponse().data.url);
        expect(body).toBeInstanceOf(File);

        // file_key on the import request is this key, so a changed shape breaks the import
        // rather than the upload.
        expect(result.key).toBe('tmp/0f1c9b3e-6b0e-4a2f-9f25-6f6a8a1f7c11');
        expect(result.extension).toBe('csv');
    });

    it('sends the signed headers without Host, which a browser refuses to set', async () => {
        const put = vi.fn().mockResolvedValue({});
        window.axios = { post: vi.fn().mockResolvedValue(signedResponse()), put };

        await storeFile(codesFile(), { signingUrl: SIGNING_URL });

        const { headers } = put.mock.calls[0][2];

        expect(headers).not.toHaveProperty('Host');
        expect(headers['Content-Type']).toBe('text/csv');
    });

    it('does not edit the headers the signing response handed back', async () => {
        const signed = signedResponse();
        const put = vi.fn().mockResolvedValue({});
        window.axios = { post: vi.fn().mockResolvedValue(signed), put };

        await storeFile(codesFile(), { signingUrl: SIGNING_URL });

        // A retry would otherwise be signing against a set of headers the first attempt
        // had already taken Host out of.
        expect(signed.data.headers.Host).toBe('object-store.example.test');
    });

    it('reports progress as a fraction while the bytes go out', async () => {
        const progress = vi.fn();
        const put = vi.fn().mockImplementation((url, file, config) => {
            config.onUploadProgress({ loaded: 100, total: 400 });
            config.onUploadProgress({ loaded: 400, total: 400 });

            return Promise.resolve({});
        });
        window.axios = { post: vi.fn().mockResolvedValue(signedResponse()), put };

        await storeFile(codesFile(), { signingUrl: SIGNING_URL, progress });

        // The import dialog multiplies by 100 and rounds, so 0.25 is the 25% it shows.
        expect(progress.mock.calls.map(([fraction]) => fraction)).toEqual([0.25, 1]);
    });

    it('falls back to the file size when the progress event carries no total', async () => {
        const progress = vi.fn();
        const put = vi.fn().mockImplementation((url, file, config) => {
            // No total: the dialog would otherwise be handed NaN, which renders as a bar
            // that never moves rather than as anything anybody would report.
            config.onUploadProgress({ loaded: 200, total: undefined });

            return Promise.resolve({});
        });
        window.axios = { post: vi.fn().mockResolvedValue(signedResponse()), put };

        await storeFile(codesFile('codes.csv', 400), { signingUrl: SIGNING_URL, progress });

        expect(progress).toHaveBeenCalledWith(0.5);
    });

    it('uploads without a progress callback', async () => {
        const put = vi.fn().mockImplementation((url, file, config) => {
            config.onUploadProgress({ loaded: 1, total: 2 });

            return Promise.resolve({});
        });
        window.axios = { post: vi.fn().mockResolvedValue(signedResponse()), put };

        await expect(storeFile(codesFile(), { signingUrl: SIGNING_URL })).resolves.toBeTruthy();
    });

    it('rejects with the 503 the signing endpoint answers when there is no object storage', async () => {
        // The deployment has no bucket attached. The dialog reads the message out of the
        // rejection and shows it; without the rejection reaching the caller intact the
        // upload stalls at its last progress reading and explains nothing.
        const refusal = {
            response: {
                status: 503,
                data: { message: 'File uploads are not available on this server: ...' },
            },
        };
        const put = vi.fn();
        window.axios = { post: vi.fn().mockRejectedValue(refusal), put };

        await expect(storeFile(codesFile(), { signingUrl: SIGNING_URL })).rejects.toBe(refusal);

        expect(put).not.toHaveBeenCalled();
    });

    it('rejects when the bucket refuses the upload itself', async () => {
        const refusal = { response: { status: 403, data: '' } };
        window.axios = {
            post: vi.fn().mockResolvedValue(signedResponse()),
            put: vi.fn().mockRejectedValue(refusal),
        };

        await expect(storeFile(codesFile(), { signingUrl: SIGNING_URL })).rejects.toBe(refusal);
    });
});
