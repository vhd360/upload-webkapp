function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function errorMessage(response) {
    if (response.status === 413) {
        return 'Dieser Dateiteil ist für den Server zu groß. Bitte die PHP-Upload-Limits erhöhen.';
    }

    const data = await response.json().catch(() => ({}));

    if (data.errors) {
        return Object.values(data.errors).flat().join(' ');
    }

    return data.message || 'Die Übertragung ist fehlgeschlagen.';
}

async function postJson(url, body, signal) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(await errorMessage(response));
    }

    return response.json();
}

function postForm(url, form, signal, onProgress, onPhase) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        let settled = false;
        let phase = '';

        const reportPhase = (nextPhase) => {
            if (phase === nextPhase) {
                return;
            }

            phase = nextPhase;
            onPhase?.(nextPhase);
        };

        const abort = () => xhr.abort();

        const cleanup = () => {
            signal?.removeEventListener('abort', abort);
            xhr.upload.onprogress = null;
            xhr.upload.onload = null;
            xhr.onload = null;
            xhr.onerror = null;
            xhr.onabort = null;
        };

        const succeed = (value) => {
            if (settled) {
                return;
            }

            settled = true;
            cleanup();
            resolve(value);
        };

        const fail = (error) => {
            if (settled) {
                return;
            }

            settled = true;
            cleanup();
            reject(error);
        };

        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.responseType = 'json';

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded, event.total);

                if (event.loaded >= event.total) {
                    reportPhase('saving');
                }
            }
        };

        xhr.upload.onload = () => reportPhase('saving');

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                succeed(xhr.response);
                return;
            }

            const data = xhr.response || {};
            const message = data.errors
                ? Object.values(data.errors).flat().join(' ')
                : data.message || 'Die Übertragung ist fehlgeschlagen.';
            const error = new Error(message);
            error.retryable = xhr.status === 408 || xhr.status === 429 || xhr.status >= 500;

            fail(error);
        };

        xhr.onerror = () => {
            const error = new Error('Die Verbindung wurde unterbrochen.');
            error.retryable = true;
            fail(error);
        };

        xhr.onabort = () => {
            const error = new Error('Die Übertragung wurde abgebrochen.');
            error.name = 'AbortError';
            fail(error);
        };

        signal?.addEventListener('abort', abort, { once: true });

        if (signal?.aborted) {
            const error = new Error('Die Übertragung wurde abgebrochen.');
            error.name = 'AbortError';
            fail(error);
            return;
        }

        xhr.send(form);
    });
}

async function postFormWithRetry(url, createForm, signal, onProgress, onPhase) {
    const maximumAttempts = 3;

    for (let attempt = 1; attempt <= maximumAttempts; attempt += 1) {
        try {
            onPhase?.('sending');

            return await postForm(url, createForm(), signal, onProgress, onPhase);
        } catch (error) {
            if (error.name === 'AbortError' || !error.retryable || attempt === maximumAttempts) {
                throw error;
            }

            onPhase?.('retrying');
            await delay(attempt * 1000, signal);
        }
    }
}

function delay(milliseconds, signal) {
    return new Promise((resolve, reject) => {
        if (signal?.aborted) {
            const error = new Error('Die Übertragung wurde abgebrochen.');
            error.name = 'AbortError';
            reject(error);
            return;
        }

        const timeout = window.setTimeout(() => {
            signal?.removeEventListener('abort', abort);
            resolve();
        }, milliseconds);

        const abort = () => {
            window.clearTimeout(timeout);
            const error = new Error('Die Übertragung wurde abgebrochen.');
            error.name = 'AbortError';
            reject(error);
        };

        signal?.addEventListener('abort', abort, { once: true });
    });
}

export async function fetchAccess() {
    const response = await fetch('/api/access', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(await errorMessage(response));
    }

    return response.json();
}

export async function submitAccessCode(code) {
    const data = await postJson('/api/access', { code });

    if (data.csrf_token) {
        document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', data.csrf_token);
    }

    return data;
}

export async function uploadFile({ file, sender, signal, onProgress, onActivity }) {
    const session = await postJson('/api/uploads', {
        original_name: file.name,
        total_size: file.size,
        mime_type: file.type || null,
        sender_name: sender.name,
        sender_email: sender.email,
        message: sender.message || null,
    }, signal);

    let confirmed = 0;

    for (let index = 0; index < session.total_chunks; index += 1) {
        const start = index * session.chunk_size;
        const end = Math.min(file.size, start + session.chunk_size);

        const reportActivity = (activity) => onActivity?.({
            activity,
            chunk: index + 1,
            totalChunks: session.total_chunks,
        });

        await postFormWithRetry(`/api/uploads/${session.id}/chunks`, () => {
            const form = new FormData();
            form.append('index', String(index));
            form.append('chunk', file.slice(start, end), `teil-${index}`);

            return form;
        }, signal, (loaded, total) => {
            const fraction = total > 0 ? loaded / total : 0;
            onProgress(Math.min(file.size, confirmed + (end - start) * fraction));
        }, reportActivity);

        confirmed = end;
        onProgress(confirmed);
    }

    onActivity?.({
        activity: 'assembling',
        chunk: session.total_chunks,
        totalChunks: session.total_chunks,
    });

    return postJson(`/api/uploads/${session.id}/complete`, {}, signal);
}
