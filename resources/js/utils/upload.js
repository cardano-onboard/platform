/**
 * Upload a file straight from the browser to object storage.
 *
 * The file never passes through the application: it asks the server to sign a PUT, sends
 * the bytes to the bucket itself, and hands back the object key, which is what the import
 * endpoint is given as `file_key`. A codes file can be tens of megabytes, and a request
 * that large through a PHP process is a request that times out.
 *
 * This replaces a helper from a third-party package that did the same three steps against
 * a route that package's server-side half registered. That package described infrastructure
 * this application no longer runs on; the signing endpoint is now ours.
 *
 * Two behaviours the import dialog depends on:
 *
 *  - `progress` is called with a fraction between 0 and 1 as the bytes go out, which is
 *    what moves the percentage in the dialog.
 *  - A rejection carries the axios error untouched, so a caller can read the message out
 *    of `error.response.data`. The signing endpoint answers 503 with an explanation when
 *    the deployment has no object storage attached, and that explanation is the difference
 *    between telling the operator why the upload cannot happen and a dialog that sits at
 *    its last reading.
 *
 * @param {File} file The file to upload.
 * @param {{signingUrl: string, progress?: (fraction: number) => void}} options
 * @returns {Promise<{uuid: string, key: string, bucket: string, url: string, extension: string}>}
 */
export async function storeFile(file, { signingUrl, progress } = {}) {
    const signed = await window.axios.post(signingUrl, {
        content_type: file.type,
    });

    const headers = { ...signed.data.headers };

    // The signature covers a Host header, but the browser sets Host itself and forbids a
    // script from doing it. Sending it back is refused by the XHR layer before the request
    // leaves, so it comes out of the set the signature handed us.
    delete headers.Host;

    await window.axios.put(signed.data.url, file, {
        headers,
        onUploadProgress: (event) => {
            if (!progress) {
                return;
            }

            // A response without a length leaves `total` undefined, and dividing by it puts
            // NaN in the progress bar, which renders as no progress at all rather than as
            // an error anybody would notice.
            const total = event.total || file.size || 0;

            progress(total > 0 ? event.loaded / total : 0);
        },
    });

    return {
        ...signed.data,
        extension: file.name.split('.').pop(),
    };
}
