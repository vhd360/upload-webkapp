import { useEffect, useMemo, useRef, useState } from 'react';
import { formatBytes, formatDuration } from './format';
import { fetchAccess, submitAccessCode, uploadFile } from './upload';

function Logo({ light = false }) {
    return (
        <span className={`inline-flex items-center text-[1.7rem] leading-none font-bold tracking-tight ${light ? 'text-white' : 'text-brand-black'}`}>
            webkapp
            <span className="ml-0.5 inline-block size-2.5 -translate-y-2 rounded-full bg-brand-yellow" />
        </span>
    );
}

function Field({ id, label, children }) {
    return (
        <label htmlFor={id} className="block">
            <span className="mb-2 block text-sm font-medium">{label}</span>
            {children}
        </label>
    );
}

const inputClass = 'w-full rounded-[4px] border border-line bg-white px-4 py-3 text-base outline-none transition focus:border-brand-yellow';

export default function App({ maxBytes }) {
    const [access, setAccess] = useState('loading');
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [message, setMessage] = useState('');
    const [items, setItems] = useState([]);
    const [phase, setPhase] = useState('form');
    const [notice, setNotice] = useState('');
    const [dragOver, setDragOver] = useState(false);
    const [solidHeader, setSolidHeader] = useState(false);
    const [startedAt, setStartedAt] = useState(0);
    const [now, setNow] = useState(() => Date.now());
    const fileInput = useRef(null);
    const abortRef = useRef(null);

    useEffect(() => {
        fetchAccess()
            .then((data) => setAccess(data.unlocked ? 'open' : 'locked'))
            .catch(() => setAccess('locked'));
    }, []);

    useEffect(() => {
        const onScroll = () => setSolidHeader(window.scrollY > 24);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => {
        if (phase !== 'uploading') {
            return undefined;
        }

        const onLeave = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };

        const tick = window.setInterval(() => setNow(Date.now()), 1000);
        window.addEventListener('beforeunload', onLeave);

        return () => {
            window.clearInterval(tick);
            window.removeEventListener('beforeunload', onLeave);
        };
    }, [phase]);

    const totals = useMemo(() => {
        const total = items.reduce((sum, item) => sum + item.file.size, 0);
        const loaded = items.reduce((sum, item) => sum + item.loaded, 0);

        return { total, loaded };
    }, [items]);

    const lightHeader = !solidHeader;
    const uploading = phase === 'uploading';
    const activeItem = items.find((item) => item.status === 'uploading');
    const activeStatus = activeItem ? activityText(activeItem, now) : '';
    const elapsed = startedAt > 0 ? (Date.now() - startedAt) / 1000 : 0;
    const speed = elapsed > 0.4 ? totals.loaded / elapsed : 0;
    const remaining = speed > 0 ? (totals.total - totals.loaded) / speed : 0;
    const percent = totals.total > 0 ? Math.min(100, Math.round((totals.loaded / totals.total) * 100)) : 0;

    function addFiles(fileList) {
        const incoming = [...fileList];

        if (incoming.length === 0) {
            return;
        }

        setNotice('');
        setItems((current) => {
            const next = [...current];

            incoming.forEach((file) => {
                const exists = next.some((item) => item.file.name === file.name && item.file.size === file.size && item.file.lastModified === file.lastModified);

                if (exists) {
                    return;
                }

                let error = '';

                if (file.size < 1) {
                    error = 'Die Datei ist leer.';
                } else if (maxBytes > 0 && file.size > maxBytes) {
                    error = `Größer als ${formatBytes(maxBytes)}.`;
                }

                next.push({
                    id: crypto.randomUUID(),
                    file,
                    loaded: 0,
                    status: error ? 'error' : 'ready',
                    error,
                    activity: '',
                    chunk: 0,
                    totalChunks: 0,
                    activityAt: 0,
                    reference: '',
                });
            });

            return next.slice(0, 20);
        });
    }

    function removeItem(id) {
        setItems((current) => current.filter((item) => item.id !== id));
    }

    function resetForm() {
        setItems([]);
        setNotice('');
        setPhase('form');
        setStartedAt(0);

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    }

    function patchItem(id, patch) {
        setItems((current) => current.map((item) => (item.id === id ? { ...item, ...patch } : item)));
    }

    async function onSubmit(event) {
        event.preventDefault();
        setNotice('');

        const pending = items.filter((item) => item.status === 'ready' || item.status === 'error');
        const blocked = items.filter((item) => item.file.size < 1 || (maxBytes > 0 && item.file.size > maxBytes));

        if (name.trim() === '' || email.trim() === '') {
            setNotice('Bitte Name und E-Mail ausfüllen.');
            return;
        }

        if (pending.length === 0) {
            setNotice('Bitte mindestens eine Datei auswählen.');
            return;
        }

        if (blocked.length > 0) {
            setNotice('Bitte die markierten Dateien entfernen.');
            return;
        }

        const sender = { name: name.trim(), email: email.trim(), message: message.trim() };
        const byId = new Map(pending.map((item) => [item.id, item]));
        const controller = new AbortController();
        abortRef.current = controller;
        setPhase('uploading');
        setStartedAt(Date.now());
        setItems((current) => current.map((item) => (
            item.status === 'done'
                ? item
                : { ...item, status: 'ready', loaded: 0, error: '', activity: '', chunk: 0, totalChunks: 0, activityAt: 0, reference: '' }
        )));

        let failed = false;

        try {
            await runPool([...byId.keys()], 2, async (id) => {
                if (controller.signal.aborted) {
                    return;
                }

                const item = byId.get(id);
                patchItem(id, { status: 'uploading', loaded: 0, error: '', activity: 'sending', activityAt: Date.now() });

                try {
                    const result = await uploadFile({
                        file: item.file,
                        sender,
                        signal: controller.signal,
                        onProgress: (loaded) => patchItem(id, { loaded }),
                        onActivity: ({ activity, chunk, totalChunks }) => patchItem(id, {
                            activity,
                            chunk,
                            totalChunks,
                            activityAt: Date.now(),
                        }),
                    });

                    patchItem(id, {
                        status: 'done',
                        loaded: item.file.size,
                        reference: result.reference,
                        error: '',
                    });
                } catch (error) {
                    if (error.name === 'AbortError') {
                        throw error;
                    }

                    failed = true;
                    patchItem(id, { status: 'error', error: error.message || 'Die Übertragung ist fehlgeschlagen.' });
                }
            });

            if (controller.signal.aborted) {
                return;
            }

            setPhase(failed ? 'form' : 'done');

            if (failed) {
                setNotice('Ein Teil der Dateien konnte nicht übertragen werden. Sie können es erneut versuchen.');
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                setPhase('form');
                setNotice('Die Übertragung wurde abgebrochen.');
                setItems((current) => current.map((item) => (
                    item.status === 'uploading' || item.status === 'ready'
                        ? { ...item, status: 'ready', loaded: 0 }
                        : item
                )));
                return;
            }

            setPhase('form');
            setNotice(error.message || 'Die Übertragung ist fehlgeschlagen.');
        }
    }

    function cancelUpload() {
        abortRef.current?.abort();
    }

    return (
        <div className="min-h-screen bg-white text-brand-black">
            <header className={`fixed inset-x-0 top-0 z-50 transition-all duration-300 ${lightHeader ? 'bg-transparent py-5' : 'bg-white/90 py-4 shadow-sm backdrop-blur'}`}>
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-6 px-6">
                    <a href="https://www.webkapp.de" className="shrink-0">
                        <Logo light={lightHeader} />
                    </a>
                    <nav className="flex items-center gap-6 text-base font-medium">
                        <a href="https://www.webkapp.de" className={`hidden sm:inline ${lightHeader ? 'text-white' : 'text-brand-black'}`}>
                            webkapp.de
                        </a>
                        <a href="mailto:info@webkapp.de" className={`rounded-[4px] px-6 py-3 ${lightHeader ? 'bg-brand-yellow text-brand-black' : 'bg-brand-yellow text-brand-black'}`}>
                            Kontakt
                        </a>
                    </nav>
                </div>
            </header>

            <main>
                <section className="relative flex min-h-[78vh] items-center overflow-hidden bg-brand-black text-white">
                    <div className="bg-grid absolute inset-0 opacity-80" />
                    <div className="absolute top-24 -left-24 size-72 rounded-full bg-brand-yellow/10 blur-3xl" />
                    <div className="relative mx-auto w-full max-w-6xl px-6 pt-32 pb-28">
                        <div className="max-w-3xl">
                            <div className="mb-7 h-1 w-12 bg-brand-yellow" />
                            <h1 className="font-display text-4xl leading-none font-bold tracking-[-1.5px] uppercase sm:text-6xl">
                                Große Dateien. Einfach senden.
                            </h1>
                            <p className="mt-6 max-w-2xl text-lg leading-relaxed text-white/80">
                                Videos, Druckdaten und Projektarchive, die per E-Mail zu groß sind, können Sie hier direkt an webkapp übertragen. Der Fortschritt bleibt die ganze Zeit sichtbar.
                            </p>
                            <div className="mt-8 flex flex-wrap gap-4">
                                <a href="#upload" className="rounded-[4px] bg-brand-yellow px-6 py-3 font-medium text-brand-black">
                                    Datei senden
                                </a>
                                <a href="https://www.webkapp.de" className="rounded-[4px] border-2 border-brand-yellow px-6 py-3 font-medium text-white">
                                    Zur Website
                                </a>
                            </div>
                        </div>
                    </div>
                    <div className="absolute right-0 bottom-0 hidden size-28 bg-brand-yellow md:block" />
                </section>

                <section id="upload" className="scroll-mt-24 bg-white py-20">
                    <div className="mx-auto max-w-3xl px-6">
                        <div className="mb-10">
                            <div className="mb-5 h-1 w-12 bg-brand-yellow" />
                            <h2 className="font-display text-3xl font-bold tracking-[-0.8px] uppercase sm:text-4xl">Upload</h2>
                            <p className="mt-4 text-lg leading-relaxed text-brand-black/70">
                                Bis zu {formatBytes(maxBytes)} pro Datei. Alles landet direkt im Upload-Ordner von webkapp, nicht in einem Postfach.
                            </p>
                        </div>

                        {access === 'loading' ? (
                            <p className="text-brand-black/60" role="status">Zugang wird geprüft …</p>
                        ) : access === 'locked' ? (
                            <AccessGate onUnlocked={() => setAccess('open')} />
                        ) : phase === 'done' ? (
                            <Success
                                name={name}
                                items={items}
                                onAgain={() => {
                                    setMessage('');
                                    resetForm();
                                }}
                            />
                        ) : (
                            <form onSubmit={onSubmit} className="rounded-[4px] border border-line bg-white p-6 shadow-[0_12px_40px_rgba(15,15,15,0.06)] sm:p-8">
                                <div className="grid gap-5 sm:grid-cols-2">
                                    <Field id="name" label="Name">
                                        <input id="name" name="name" autoComplete="name" required value={name} disabled={uploading} onChange={(event) => setName(event.target.value)} className={inputClass} />
                                    </Field>
                                    <Field id="email" label="E-Mail">
                                        <input id="email" name="email" type="email" autoComplete="email" required value={email} disabled={uploading} onChange={(event) => setEmail(event.target.value)} className={inputClass} />
                                    </Field>
                                </div>
                                <div className="mt-5">
                                    <Field id="message" label="Nachricht">
                                        <textarea id="message" name="message" rows="4" value={message} disabled={uploading} onChange={(event) => setMessage(event.target.value)} placeholder="Worum geht es? Optional." className={inputClass} />
                                    </Field>
                                </div>

                                <div
                                    className={`mt-6 rounded-[4px] border-2 border-dashed px-6 py-10 text-center transition ${dragOver ? 'border-brand-yellow bg-brand-yellow/10' : 'border-line bg-[#fafafa]'}`}
                                    onDragOver={(event) => {
                                        event.preventDefault();
                                        if (!uploading) {
                                            setDragOver(true);
                                        }
                                    }}
                                    onDragLeave={() => setDragOver(false)}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        setDragOver(false);
                                        if (!uploading) {
                                            addFiles(event.dataTransfer.files);
                                        }
                                    }}
                                >
                                    <p className="text-lg font-medium">Dateien hier ablegen oder auswählen</p>
                                    <p className="mt-2 text-sm text-brand-black/60">Mehrere Dateien gleichzeitig, zum Beispiel Videos, ZIP-Archive oder Druck-PDFs.</p>
                                    <button
                                        type="button"
                                        disabled={uploading}
                                        onClick={() => fileInput.current?.click()}
                                        className="mt-5 rounded-[4px] border-2 border-brand-yellow px-6 py-3 font-medium disabled:opacity-50"
                                    >
                                        Dateien auswählen
                                    </button>
                                    <input
                                        ref={fileInput}
                                        type="file"
                                        multiple
                                        className="sr-only"
                                        onChange={(event) => {
                                            addFiles(event.target.files);
                                            event.target.value = '';
                                        }}
                                    />
                                </div>

                                {items.length > 0 && (
                                    <ul className="mt-6 space-y-4">
                                        {items.map((item) => (
                                            <FileRow key={item.id} item={item} uploading={uploading} now={now} onRemove={() => removeItem(item.id)} />
                                        ))}
                                    </ul>
                                )}

                                {uploading && (
                                    <div className="mt-6">
                                        <div className="mb-2 flex flex-wrap items-center justify-between gap-3 text-sm font-medium">
                                            <span className="inline-flex items-center gap-2">
                                                <span className="size-2.5 animate-pulse rounded-full bg-brand-yellow" />
                                                Gesamt {percent} %
                                            </span>
                                            <span className="text-brand-black/60">
                                                {formatBytes(totals.loaded)} von {formatBytes(totals.total)}
                                                {speed > 0 ? ` · ${formatBytes(speed)}/s` : ''}
                                                {remaining > 1 ? ` · noch ${formatDuration(remaining)}` : ''}
                                            </span>
                                        </div>
                                        <Progress value={percent} label="Gesamtfortschritt" busy={Boolean(activeItem)} />
                                        {activeStatus && (
                                            <p className="mt-3 text-sm text-brand-black/70" role="status">{activeStatus}</p>
                                        )}
                                    </div>
                                )}

                                {notice && (
                                    <p className="mt-5 text-sm font-medium text-brand-black" role="status">{notice}</p>
                                )}

                                <div className="mt-8 flex flex-wrap gap-4">
                                    {uploading ? (
                                        <button type="button" onClick={cancelUpload} className="rounded-[4px] border-2 border-brand-black px-6 py-3 font-medium">
                                            Abbrechen
                                        </button>
                                    ) : (
                                        <>
                                            <button type="submit" className="rounded-[4px] bg-brand-yellow px-6 py-3 font-medium text-brand-black">
                                                Dateien senden
                                            </button>
                                            {items.length > 0 && (
                                                <button type="button" onClick={resetForm} className="rounded-[4px] border-2 border-brand-yellow px-6 py-3 font-medium">
                                                    Auswahl leeren
                                                </button>
                                            )}
                                        </>
                                    )}
                                </div>
                            </form>
                        )}
                    </div>
                </section>
            </main>

            <footer className="border-t border-line bg-brand-black text-white">
                <div className="mx-auto flex max-w-6xl flex-col gap-8 px-6 py-12 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <Logo light />
                        <p className="mt-4 max-w-sm text-sm leading-relaxed text-white/70">
                            Ihr Partner für digitale Lösungen. Große Dateien erreichen uns hier direkt.
                        </p>
                    </div>
                    <div className="text-sm leading-7">
                        <a className="block hover:text-brand-yellow" href="mailto:info@webkapp.de">info@webkapp.de</a>
                        <a className="block hover:text-brand-yellow" href="tel:+4953087078954">+49 (0) 5308 707 89 54</a>
                        <a className="block hover:text-brand-yellow" href="https://www.webkapp.de">webkapp.de</a>
                    </div>
                </div>
                <div className="border-t border-white/10">
                    <p className="mx-auto max-w-6xl px-6 py-5 text-sm text-white/50">© {new Date().getFullYear()} webkapp. Alle Rechte vorbehalten.</p>
                </div>
            </footer>
        </div>
    );
}

function AccessGate({ onUnlocked }) {
    const [code, setCode] = useState('');
    const [error, setError] = useState('');
    const [checking, setChecking] = useState(false);

    async function onSubmit(event) {
        event.preventDefault();
        setError('');

        if (code.trim() === '') {
            setError('Bitte geben Sie den Zugangscode ein.');
            return;
        }

        setChecking(true);

        try {
            await submitAccessCode(code.trim());
            onUnlocked();
        } catch (submitError) {
            setError(submitError.message || 'Der Zugangscode ist ungültig.');
        } finally {
            setChecking(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="rounded-[4px] border border-line bg-white p-6 shadow-[0_12px_40px_rgba(15,15,15,0.06)] sm:p-8">
            <h3 className="font-display text-2xl font-bold tracking-[-0.5px] uppercase">Zugangscode</h3>
            <p className="mt-3 leading-relaxed text-brand-black/70">
                Das Upload-Formular wird mit dem Code freigeschaltet, den Sie von webkapp erhalten haben.
            </p>
            <div className="mt-6">
                <Field id="access-code" label="Code">
                    <input
                        id="access-code"
                        name="code"
                        autoComplete="off"
                        spellCheck="false"
                        placeholder="K4NP-7QWM"
                        value={code}
                        disabled={checking}
                        onChange={(event) => setCode(event.target.value.toUpperCase())}
                        className={`${inputClass} font-display tracking-[0.2em] uppercase`}
                    />
                </Field>
            </div>
            {error && (
                <p className="mt-4 text-sm font-medium text-brand-black" role="alert">{error}</p>
            )}
            <button type="submit" disabled={checking} className="mt-6 rounded-[4px] bg-brand-yellow px-6 py-3 font-medium text-brand-black disabled:opacity-60">
                {checking ? 'Wird geprüft …' : 'Code prüfen'}
            </button>
        </form>
    );
}

function FileRow({ item, uploading, now, onRemove }) {
    const filePercent = item.file.size > 0 ? Math.min(100, Math.round((item.loaded / item.file.size) * 100)) : 0;
    const showBar = uploading || item.status === 'done' || item.status === 'error';
    const status = item.status === 'uploading' ? activityText(item, now) : '';

    return (
        <li className="rounded-[4px] border border-line px-4 py-3">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="truncate font-medium">{item.file.name}</p>
                    <p className="mt-1 text-sm text-brand-black/60">
                        {formatBytes(item.file.size)}
                        {item.status === 'uploading' ? ` · ${filePercent} %` : ''}
                        {status ? ` · ${status}` : ''}
                        {item.status === 'done' ? ' · angekommen' : ''}
                        {item.error ? ` · ${item.error}` : ''}
                    </p>
                </div>
                {!uploading && item.status !== 'done' && (
                    <button type="button" onClick={onRemove} className="shrink-0 text-sm font-medium text-brand-black/70 hover:text-brand-black">
                        Entfernen
                    </button>
                )}
            </div>
            {showBar && item.status !== 'error' && (
                <div className="mt-3">
                    <Progress value={item.status === 'done' ? 100 : filePercent} label={`Fortschritt von ${item.file.name}`} busy={item.status === 'uploading'} />
                </div>
            )}
        </li>
    );
}

function Progress({ value, label, busy = false }) {
    return (
        <div className="h-2 overflow-hidden rounded-full bg-[#f0f0f0]" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={value} aria-label={label}>
            <div className={`h-full bg-brand-yellow transition-[width] duration-150 ${busy ? 'progress-busy' : ''}`} style={{ width: `${value}%` }} />
        </div>
    );
}

function activityText(item, now) {
    const part = item.totalChunks > 0 ? `Teil ${item.chunk} von ${item.totalChunks}` : '';
    const waitingSeconds = item.activityAt > 0 ? Math.max(0, Math.round((now - item.activityAt) / 1000)) : 0;

    if (item.activity === 'saving') {
        return `${part} · Server speichert, die Übertragung läuft weiter${waitingSeconds > 2 ? ` (${waitingSeconds} Sek.)` : ''}`;
    }

    if (item.activity === 'retrying') {
        return `${part} · Verbindung kurz unterbrochen, der Dateiteil wird erneut gesendet`;
    }

    if (item.activity === 'assembling') {
        return 'Alle Teile sind angekommen. Die Datei wird zusammengesetzt.';
    }

    return part ? `${part} · Daten werden übertragen` : 'Daten werden übertragen';
}

function Success({ name, items, onAgain }) {
    return (
        <div className="rounded-[4px] border border-line bg-white p-6 shadow-[0_12px_40px_rgba(15,15,15,0.06)] sm:p-8" role="status">
            <div className="mb-5 h-1 w-12 bg-brand-yellow" />
            <h3 className="font-display text-3xl font-bold tracking-[-0.6px] uppercase">Angekommen</h3>
            <p className="mt-4 text-lg leading-relaxed text-brand-black/80">
                Vielen Dank{name ? `, ${name}` : ''}. Ihre Dateien sind bei webkapp angekommen. Bei Rückfragen genügt die Referenz.
            </p>
            <ul className="mt-6 space-y-3">
                {items.map((item) => (
                    <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 border border-line px-4 py-3">
                        <span className="min-w-0 truncate font-medium">{item.file.name}</span>
                        <span className="font-display text-sm font-bold tracking-wide">{item.reference}</span>
                    </li>
                ))}
            </ul>
            <button type="button" onClick={onAgain} className="mt-8 rounded-[4px] bg-brand-yellow px-6 py-3 font-medium text-brand-black">
                Weitere Dateien senden
            </button>
        </div>
    );
}

async function runPool(ids, limit, worker) {
    const queue = [...ids];
    const runners = Array.from({ length: Math.min(limit, queue.length) }, async () => {
        while (queue.length > 0) {
            const id = queue.shift();
            await worker(id);
        }
    });

    await Promise.all(runners);
}
