@php
    $translatorIds = old('translators', $edition->contributors->filter(fn ($c) => $c->pivot->role === 'translator')->pluck('id')->all());
    $editorIds = old('editors', $edition->contributors->filter(fn ($c) => $c->pivot->role === 'editor')->pluck('id')->all());
    $value = fn (string $field) => old($field, $edition->{$field});
@endphp
@extends('admin.layout')
@section('admin-title', 'Edition: '.$edition->title)

@section('admin')
    <p>
        Edition of <a href="{{ route('admin.works.edit', $edition->work_id) }}"><bdi>{{ $edition->work->original_title }}</bdi></a>
        · Status: <span class="badge rounded-pill status status--{{ $edition->status }}">{{ $edition->status }}</span>
        · <a href="{{ route('editions.show', $edition) }}">{{ $edition->isPublished() ? 'View public page' : 'Preview page (staff only)' }}</a>
    </p>

    {{-- ---------------- Publication ---------------- --}}
    <section aria-labelledby="publication-heading" class="card card-body mb-3">
        <h2 id="publication-heading">Publication</h2>

        @if ($edition->isPublished())
            <p>This edition is public since {{ $edition->published_at?->toDayDateTimeString() }}.</p>
            <form method="post" action="{{ route('admin.editions.unpublish', $edition->id) }}" class="d-inline-flex flex-wrap align-items-center gap-2">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">Unpublish (reversible)</button>
            </form>
        @else
            @if ($blockers)
                <p><strong>Not ready to publish:</strong></p>
                <ul>@foreach ($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
            @else
                <p>All publication requirements are met. Preview the page and the reader before publishing.</p>
            @endif
            <form method="post" action="{{ route('admin.editions.publish', $edition->id) }}" class="d-inline-flex flex-wrap align-items-center gap-2">
                @csrf
                <button type="submit" class="btn btn-primary" @disabled((bool) $blockers)>Publish</button>
            </form>
        @endif

        @if ($edition->status === 'withdrawn')
            <p class="alert alert-warning">Withdrawn {{ $edition->withdrawn_at?->toFormattedDateString() }}: {{ $edition->withdrawn_reason }}. Publishing again makes it public once more.</p>
        @elseif ($edition->published_at)
            <details>
                <summary>Withdraw this edition</summary>
                <p class="text-body-secondary small">Use this for rights problems. The edition disappears from the site and the reason is recorded. Copies people already downloaded or saved offline cannot be recalled.</p>
                <form method="post" action="{{ route('admin.editions.withdraw', $edition->id) }}" class="narrow">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="reason">Reason (kept in the audit log)</label>
                        <input class="form-control" id="reason" name="reason" type="text" required maxlength="255">
                    </div>
                    <button type="submit" class="btn btn-danger">Withdraw</button>
                </form>
            </details>
        @endif
    </section>

    {{-- ---------------- Files ---------------- --}}
    <section aria-labelledby="files-heading">
        <h2 id="files-heading">Files</h2>
        <p class="text-body-secondary small">
            An upload is held in quarantine, validated and (for EPUB) turned into a sanitized reading copy by the background task.
            It becomes available only after you approve it here, grant a permission and publish the edition.
            Uploading a file of a format that already exists creates a new version; the old one stays until you approve the new one.
        </p>

        @forelse ($edition->files as $file)
            @php $import = $imports[$file->id] ?? null; @endphp
            <article class="card card-body mb-3" aria-labelledby="file-{{ $file->id }}">
                <h3 id="file-{{ $file->id }}">
                    {{ strtoupper($file->format) }} · version {{ $file->version }}
                    <span class="badge rounded-pill status status--{{ $file->import_status }}">{{ $file->import_status }}</span>
                    @if ($file->is_current)<span class="badge rounded-pill status status--published">current</span>@elseif ($file->import_status === 'ready')<span class="badge rounded-pill status">not current</span>@endif
                </h3>
                <p class="text-body-secondary small">
                    <bdi>{{ $file->original_filename }}</bdi> · {{ $file->humanSize() }} · SHA-256 <code dir="ltr">{{ substr($file->sha256, 0, 16) }}…</code>
                    @if ($file->page_count) · {{ $file->page_count }} pages @endif
                    @if ($file->layout === 'fixed' && $file->format === 'epub') · fixed layout @endif
                    · downloads: {{ $file->download_count }}
                </p>

                @if ($file->import_status === 'failed')
                    <p class="alert alert-danger"><strong>Import failed:</strong> {{ $import?->error ?? 'No details recorded.' }}</p>
                @elseif (in_array($file->import_status, ['quarantined', 'processing'], true))
                    <p class="alert alert-info">Waiting for the background task ({{ $import?->stage ?? 'queued' }}). Reload this page in a minute.</p>
                @endif

                @if ($file->import_status === 'ready')
                    @foreach ($file->validation_report['warnings'] ?? [] as $warning)
                        <p class="alert alert-warning">{{ $warning }}</p>
                    @endforeach
                    @if (! empty($file->validation_report['notes']))
                        <details>
                            <summary>What the sanitizer changed ({{ count($file->validation_report['notes']) }})</summary>
                            <ul>@foreach ($file->validation_report['notes'] as $note)<li><bdi>{{ $note }}</bdi></li>@endforeach</ul>
                        </details>
                    @endif

                    <p>
                        @if ($file->isBrowserReadable())
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('read', ['edition' => $edition, 'format' => $file->format, 'file' => $file->id]) }}">
                                {{ $edition->isPublished() && $file->is_current ? 'Open in reader' : 'Preview in reader' }}
                            </a>
                        @endif
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('download', ['file' => $file->id]) }}">Download original</a>
                    </p>

                    @unless ($file->is_current)
                        <form method="post" action="{{ route('admin.files.approve', $file->id) }}" class="d-inline-flex flex-wrap align-items-center gap-2">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">Approve as current {{ strtoupper($file->format) }}</button>
                        </form>
                    @endunless

                    <form method="post" action="{{ route('admin.files.update', $file->id) }}">
                        @csrf @method('PUT')
                        <fieldset>
                            <legend>What visitors may do with this file</legend>
                            <p class="text-body-secondary small">These are access controls on this site, not copy protection.</p>
                            <div class="mb-3 form-check">
                                <input class="form-check-input" id="read-{{ $file->id }}" type="checkbox" name="can_read" value="1" @checked($file->can_read) @disabled(! $file->isBrowserReadable())>
                                <label class="form-check-label" for="read-{{ $file->id }}">Read in the browser @unless($file->isBrowserReadable())(not possible for this format)@endunless</label>
                            </div>
                            <div class="mb-3 form-check">
                                <input class="form-check-input" id="download-{{ $file->id }}" type="checkbox" name="can_download" value="1" @checked($file->can_download)>
                                <label class="form-check-label" for="download-{{ $file->id }}">Download</label>
                            </div>
                            <div class="mb-3 form-check">
                                <input class="form-check-input" id="offline-{{ $file->id }}" type="checkbox" name="can_offline" value="1" @checked($file->can_offline) @disabled(! $file->isBrowserReadable())>
                                <label class="form-check-label" for="offline-{{ $file->id }}">Save for offline reading (requires “Read in the browser”)</label>
                            </div>
                        </fieldset>
                        @if ($file->format === 'pdf')
                            <div class="mb-3">
                                <label class="form-label" for="text-{{ $file->id }}">Does this PDF have a text layer?</label>
                                <select class="form-select" id="text-{{ $file->id }}" name="has_text_layer">
                                    <option value="unknown" @selected($file->has_text_layer === null)>Not checked</option>
                                    <option value="yes" @selected($file->has_text_layer === true)>Yes — text can be searched and selected</option>
                                    <option value="no" @selected($file->has_text_layer === false)>No — it is a scan (readers are told)</option>
                                </select>
                            </div>
                        @endif
                        <div class="row row-cols-1 row-cols-md-2 gx-4">
                            <div class="mb-3">
                                <label class="form-label" for="license-{{ $file->id }}">License of this file, if different from the edition</label>
                                <input class="form-control" id="license-{{ $file->id }}" name="license_name" type="text" value="{{ $file->license_name }}" maxlength="255">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="notes-{{ $file->id }}">Rights notes for this file</label>
                                <input class="form-control" id="notes-{{ $file->id }}" name="rights_notes" type="text" value="{{ $file->rights_notes }}">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Save file settings</button>
                    </form>

                    @if ($import && $import->extracted_metadata)
                        @php $meta = $import->extracted_metadata; @endphp
                        <details>
                            <summary>Metadata found in the file — review, edit, apply</summary>
                            <p class="text-body-secondary small">Nothing is copied to the edition until you press “Apply”. Edit the values first if they are wrong.</p>
                            @if (! empty($meta['contributors']))
                                <p>People named in the file:
                                    @foreach ($meta['contributors'] as $person)<bdi>{{ $person['name'] }}</bdi> ({{ $person['role'] }})@if(! $loop->last), @endif @endforeach
                                    — add them with the contributor fields below.
                                </p>
                            @endif
                            @if (! empty($meta['rights']))<p>Rights statement in the file: <bdi>{{ $meta['rights'] }}</bdi> (not applied automatically).</p>@endif
                            <form method="post" action="{{ route('admin.files.apply-metadata', $file->id) }}">
                                @csrf
                                <div class="row row-cols-1 row-cols-md-2 gx-4">
                                    <div class="mb-3"><label class="form-label" for="m-title-{{ $file->id }}">Title</label><input class="form-control" id="m-title-{{ $file->id }}" name="title" type="text" value="{{ $meta['title'] ?? '' }}" dir="auto"></div>
                                    <div class="mb-3">
                                        <label class="form-label" for="m-lang-{{ $file->id }}">Language</label>
                                        <select class="form-select" id="m-lang-{{ $file->id }}" name="language_tag">
                                            <option value="">Leave unchanged</option>
                                            @foreach ($languages as $tag => $language)
                                                <option value="{{ $tag }}" @selected(strtolower(strtok($meta['language'] ?? '', '-')) === $tag)>{{ $language['english_name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="mb-3"><label class="form-label" for="m-pub-{{ $file->id }}">Publisher</label><input class="form-control" id="m-pub-{{ $file->id }}" name="publisher" type="text" value="{{ $meta['publisher'] ?? '' }}" dir="auto"></div>
                                    <div class="mb-3"><label class="form-label" for="m-date-{{ $file->id }}">Published</label><input class="form-control" id="m-date-{{ $file->id }}" name="published_date" type="text" value="{{ substr($meta['date'] ?? '', 0, 10) }}"></div>
                                    <div class="mb-3">
                                        <label class="form-label" for="m-prog-{{ $file->id }}">Page direction</label>
                                        <select class="form-select" id="m-prog-{{ $file->id }}" name="page_progression">
                                            <option value="">Leave unchanged</option>
                                            <option value="ltr" @selected(($meta['page_progression'] ?? '') === 'ltr')>Left to right</option>
                                            <option value="rtl" @selected(($meta['page_progression'] ?? '') === 'rtl')>Right to left</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3"><label class="form-label" for="m-desc-{{ $file->id }}">Description</label><textarea class="form-control" id="m-desc-{{ $file->id }}" name="description" rows="3" dir="auto">{{ $meta['description'] ?? '' }}</textarea></div>
                                <button type="submit" class="btn btn-primary btn-sm">Apply to the edition</button>
                            </form>
                            @if ($file->format === 'epub' && ! empty($file->manifest['cover']))
                                <form method="post" action="{{ route('admin.files.use-cover', $file->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Use the cover image from this EPUB</button>
                                </form>
                            @endif
                        </details>
                    @endif
                @endif

                <form method="post" action="{{ route('admin.files.destroy', $file->id) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-link p-0 align-baseline">Delete this file version</button>
                </form>
            </article>
        @empty
            <p class="text-body-secondary small">No files yet.</p>
        @endforelse

        <form method="post" action="{{ route('admin.files.store', $edition->id) }}" enctype="multipart/form-data" class="card card-body mb-3">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="file">Upload EPUB, PDF, TXT or HTML (up to {{ (int) (config('library.imports.max_upload_bytes') / 1048576) }} MB)</label>
                <input class="form-control" id="file" name="file" type="file" required accept=".epub,.pdf,.txt,.html,.htm,application/epub+zip,application/pdf,text/plain,text/html">
            </div>
            <div class="mb-3 form-check">
                <input class="form-check-input" id="allow_duplicate" type="checkbox" name="allow_duplicate" value="1">
                <label class="form-check-label" for="allow_duplicate">Allow a file that is byte-for-byte identical to one already stored</label>
            </div>
            <button type="submit" class="btn btn-primary">Upload</button>
        </form>
    </section>

    {{-- ---------------- Cover ---------------- --}}
    <section aria-labelledby="cover-heading">
        <h2 id="cover-heading">Cover</h2>
        @if ($edition->cover_path)
            <p><img src="{{ \Illuminate\Support\Facades\Storage::disk('covers')->url($edition->cover_path) }}" alt="Current cover" width="120"></p>
        @endif
        <form method="post" action="{{ route('admin.editions.cover', $edition->id) }}" enctype="multipart/form-data" class="d-inline-flex flex-wrap align-items-center gap-2">
            @csrf
            <label class="form-label mb-0" for="cover">JPEG, PNG, GIF or WebP</label>
            <input class="form-control w-auto" id="cover" name="cover" type="file" required accept="image/jpeg,image/png,image/gif,image/webp">
            <button type="submit" class="btn btn-primary btn-sm">Upload cover</button>
        </form>
    </section>

    {{-- ---------------- Metadata and rights ---------------- --}}
    <section aria-labelledby="details-heading">
        <h2 id="details-heading">Details and rights</h2>
        <form method="post" action="{{ route('admin.editions.update', $edition->id) }}">
            @csrf @method('PUT')

            <div class="row row-cols-1 row-cols-md-2 gx-4">
                <div class="mb-3"><label class="form-label" for="title">Title</label><input class="form-control" id="title" name="title" type="text" value="{{ $value('title') }}" required maxlength="255" dir="auto"></div>
                <div class="mb-3"><label class="form-label" for="subtitle">Subtitle</label><input class="form-control" id="subtitle" name="subtitle" type="text" value="{{ $value('subtitle') }}" maxlength="255" dir="auto"></div>
                <div class="mb-3"><label class="form-label" for="slug">URL slug</label><input class="form-control" id="slug" name="slug" type="text" value="{{ $value('slug') }}" required maxlength="190" dir="ltr"></div>
                <div class="mb-3">
                    <label class="form-label" for="language_tag">Language of the text</label>
                    <select class="form-select" id="language_tag" name="language_tag" required>
                        @foreach ($languages as $tag => $language)
                            <option value="{{ $tag }}" @selected($value('language_tag') === $tag)>{{ $language['english_name'] }} ({{ $tag }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="direction">Text direction</label>
                    <select class="form-select" id="direction" name="direction">
                        @foreach (['ltr' => 'Left to right', 'rtl' => 'Right to left', 'auto' => 'Mixed / decide per paragraph'] as $option => $label)
                            <option value="{{ $option }}" @selected($value('direction') === $option)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="page_progression">Page-turn direction in the reader</label>
                    <select class="form-select" id="page_progression" name="page_progression">
                        @foreach (['default' => 'As declared by the book', 'ltr' => 'Left to right', 'rtl' => 'Right to left'] as $option => $label)
                            <option value="{{ $option }}" @selected($value('page_progression') === $option)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3"><label class="form-label" for="publisher">Publisher</label><input class="form-control" id="publisher" name="publisher" type="text" value="{{ $value('publisher') }}" dir="auto"></div>
                <div class="mb-3"><label class="form-label" for="published_date">Publication date or year</label><input class="form-control" id="published_date" name="published_date" type="text" value="{{ $value('published_date') }}" maxlength="20"></div>
                <div class="mb-3"><label class="form-label" for="isbn">ISBN</label><input class="form-control" id="isbn" name="isbn" type="text" value="{{ $value('isbn') }}" maxlength="32" dir="ltr"></div>
                <div class="mb-3"><label class="form-label" for="edition_statement">Edition statement</label><input class="form-control" id="edition_statement" name="edition_statement" type="text" value="{{ $value('edition_statement') }}" dir="auto"></div>
            </div>
            <div class="mb-3"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="5" dir="auto">{{ $value('description') }}</textarea></div>

            <div class="row row-cols-1 row-cols-md-2 gx-4">
                <div class="mb-3">
                    <label class="form-label" for="translators">Translators</label>
                    <select class="form-select" id="translators" name="translators[]" multiple size="5">
                        @foreach ($contributors as $contributor)
                            <option value="{{ $contributor->id }}" @selected(in_array($contributor->id, $translatorIds))>{{ $contributor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="editors">Editors</label>
                    <select class="form-select" id="editors" name="editors[]" multiple size="5">
                        @foreach ($contributors as $contributor)
                            <option value="{{ $contributor->id }}" @selected(in_array($contributor->id, $editorIds))>{{ $contributor->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mb-3"><label class="form-label" for="new_translators">New translators (separate with ;)</label><input class="form-control" id="new_translators" name="new_translators" type="text" value="{{ old('new_translators') }}" dir="auto"></div>

            <fieldset>
                <legend>Rights — required before publishing</legend>
                <p class="text-body-secondary small">Record the rights of <em>this edition</em>. A translation has its own rights: the original being in the public domain does not make a translation free to publish.</p>
                <div class="row row-cols-1 row-cols-md-2 gx-4">
                    <div class="mb-3">
                        <label class="form-label" for="rights_status">Rights status</label>
                        <select class="form-select" id="rights_status" name="rights_status">
                            @foreach (['unknown' => 'Not established (cannot be published)', 'public_domain' => 'Public domain', 'open_license' => 'Open license', 'authorized' => 'Explicitly authorized by the rights holder'] as $option => $label)
                                <option value="{{ $option }}" @selected($value('rights_status') === $option)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label" for="rights_holder">Rights holder</label><input class="form-control" id="rights_holder" name="rights_holder" type="text" value="{{ $value('rights_holder') }}" dir="auto"></div>
                    <div class="mb-3"><label class="form-label" for="license_name">License name</label><input class="form-control" id="license_name" name="license_name" type="text" value="{{ $value('license_name') }}"></div>
                    <div class="mb-3"><label class="form-label" for="license_url">License link</label><input class="form-control" id="license_url" name="license_url" type="url" value="{{ $value('license_url') }}" dir="ltr"></div>
                    <div class="mb-3"><label class="form-label" for="source_name">Source</label><input class="form-control" id="source_name" name="source_name" type="text" value="{{ $value('source_name') }}" dir="auto"></div>
                    <div class="mb-3"><label class="form-label" for="source_url">Source link (shown as a link; never fetched by the server)</label><input class="form-control" id="source_url" name="source_url" type="url" value="{{ $value('source_url') }}" dir="ltr"></div>
                </div>
                <div class="mb-3"><label class="form-label" for="attribution">Attribution shown to readers</label><textarea class="form-control" id="attribution" name="attribution" rows="2" dir="auto">{{ $value('attribution') }}</textarea></div>
                <div class="mb-3"><label class="form-label" for="rights_notes">Internal rights notes (permission letters, dates) — not public</label><textarea class="form-control" id="rights_notes" name="rights_notes" rows="2" dir="auto">{{ $value('rights_notes') }}</textarea></div>
                <div class="mb-3">
                    <label class="form-label" for="territory_notes">Territory note (public)</label>
                    <textarea class="form-control" id="territory_notes" name="territory_notes" rows="2" dir="auto" aria-describedby="territory-hint">{{ $value('territory_notes') }}</textarea>
                    <p id="territory-hint" class="text-body-secondary small">Information only. This site does not restrict access by country; if that is required, do not publish until it has been implemented.</p>
                </div>
            </fieldset>

            <div class="mb-3 form-check">
                <input class="form-check-input" id="is_featured" type="checkbox" name="is_featured" value="1" @checked($value('is_featured'))>
                <label class="form-check-label" for="is_featured">Featured</label>
            </div>

            <button type="submit" class="btn btn-primary">Save edition</button>
        </form>
    </section>

    <section aria-labelledby="history-heading">
        <h2 id="history-heading">History</h2>
        <ul class="list-unstyled">
            @forelse ($audit as $event)
                <li><code>{{ $event->created_at->format('Y-m-d H:i') }}</code> {{ $event->action }} — <bdi>{{ $event->user?->name ?? 'system' }}</bdi>
                    @if ($event->data)<span class="text-body-secondary small" dir="ltr">{{ json_encode($event->data, JSON_UNESCAPED_UNICODE) }}</span>@endif</li>
            @empty
                <li class="text-body-secondary small">No recorded events.</li>
            @endforelse
        </ul>
    </section>

    @if ($edition->published_at === null)
        <form method="post" action="{{ route('admin.editions.destroy', $edition->id) }}">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm">Delete this draft edition</button>
        </form>
    @endif
@endsection
